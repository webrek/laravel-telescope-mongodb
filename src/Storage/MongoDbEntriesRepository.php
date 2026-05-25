<?php

namespace TelescopeMongoDB\Driver\Storage;

use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Storage\EntryQueryOptions;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection as MongoCollection;
use MongoDB\Driver\Exception\InvalidArgumentException as MongoInvalidArgumentException;

class MongoDbEntriesRepository implements Contract, ClearableRepository, PrunableRepository, TerminableRepository
{
    protected const ARRAY_TYPE_MAP = [
        'typeMap' => [
            'root' => 'array',
            'document' => 'array',
            'array' => 'array',
        ],
    ];

    protected array $monitoredTags = [];

    protected bool $monitoredTagsLoaded = false;

    public function __construct(
        protected string $connection,
        protected string $entriesCollection,
        protected string $monitoringCollection,
    ) {
    }

    public function find($id): EntryResult
    {
        $document = $this->entries()->findOne(
            ['uuid' => (string) $id],
            self::ARRAY_TYPE_MAP,
        );

        if ($document === null) {
            throw (new ModelNotFoundException)->setModel(EntryResult::class, [$id]);
        }

        return $this->toEntryResult($document);
    }

    public function get($type, EntryQueryOptions $options)
    {
        $filter = $this->buildFilter($type, $options);

        $cursor = $this->entries()->find($filter, array_merge(self::ARRAY_TYPE_MAP, [
            'sort' => ['_id' => -1],
            'limit' => max(1, (int) ($options->limit ?? 50)),
        ]));

        return Collection::make(iterator_to_array($cursor, false))
            ->map(fn (array $document) => $this->toEntryResult($document))
            ->values();
    }

    public function store(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        [$exceptions, $standard] = $entries->partition(fn (IncomingEntry $entry) => $entry->isException());

        $this->storeExceptions(Collection::make($exceptions));

        $standard = Collection::make($standard);

        if ($standard->isEmpty()) {
            return;
        }

        $standard->chunk($this->chunkSize('store'))->each(function (Collection $chunk): void {
            $this->entries()->insertMany(
                $chunk->map(fn (IncomingEntry $entry) => $this->toDocument($entry))->values()->all()
            );
        });
    }

    public function update(Collection $updates): ?Collection
    {
        $failed = Collection::make();

        foreach ($updates as $update) {
            /** @var EntryUpdate $update */
            $existing = $this->entries()->findOne(
                ['uuid' => $update->uuid, 'type' => $update->type],
                self::ARRAY_TYPE_MAP,
            );

            if ($existing === null) {
                $failed->push($update);

                continue;
            }

            $mergedContent = array_merge(
                $existing['content'] ?? [],
                $update->changes,
            );

            $set = ['content' => $mergedContent];

            $operations = ['$set' => $set];

            if (! empty($update->tags)) {
                $operations['$addToSet'] = [
                    'tags' => ['$each' => array_values(array_unique($update->tags))],
                ];
            }

            $this->entries()->updateOne(
                ['uuid' => $update->uuid, 'type' => $update->type],
                $operations,
            );
        }

        return $failed->isEmpty() ? null : $failed;
    }

    public function loadMonitoredTags(): void
    {
        $cursor = $this->monitoringStore()->find([], self::ARRAY_TYPE_MAP);

        $this->monitoredTags = Collection::make(iterator_to_array($cursor, false))
            ->pluck('tag')
            ->filter()
            ->values()
            ->all();

        $this->monitoredTagsLoaded = true;
    }

    public function isMonitoring(array $tags): bool
    {
        if (! $this->monitoredTagsLoaded) {
            $this->loadMonitoredTags();
        }

        return count(array_intersect($tags, $this->monitoredTags)) > 0;
    }

    public function monitoring(): array
    {
        return Collection::make(iterator_to_array(
            $this->monitoringStore()->find([], self::ARRAY_TYPE_MAP),
            false
        ))->pluck('tag')->values()->all();
    }

    public function monitor(array $tags): void
    {
        $existing = $this->monitoring();

        $new = array_values(array_diff(array_unique($tags), $existing));

        if ($new === []) {
            return;
        }

        $this->monitoringStore()->insertMany(
            array_map(fn (string $tag): array => ['tag' => $tag], $new),
        );

        $this->monitoredTagsLoaded = false;
    }

    public function stopMonitoring(array $tags): void
    {
        $this->monitoringStore()->deleteMany(['tag' => ['$in' => array_values($tags)]]);

        $this->monitoredTagsLoaded = false;
    }

    public function clear(): void
    {
        $this->entries()->deleteMany([]);
        $this->monitoringStore()->deleteMany([]);
    }

    public function prune(DateTimeInterface $before, $keepExceptions = false): int
    {
        $filter = [
            'created_at' => ['$lt' => new UTCDateTime((int) ($before->getTimestamp() * 1000))],
        ];

        if ($keepExceptions) {
            $filter['type'] = ['$ne' => EntryType::EXCEPTION];
        }

        $totalDeleted = 0;
        $chunkSize = $this->chunkSize('prune');

        do {
            $cursor = $this->entries()->find($filter, [
                'projection' => ['_id' => 1],
                'limit' => $chunkSize,
            ]);

            $ids = [];

            foreach ($cursor as $document) {
                $ids[] = $document->_id;
            }

            if ($ids === []) {
                break;
            }

            $result = $this->entries()->deleteMany(['_id' => ['$in' => $ids]]);
            $totalDeleted += $result->getDeletedCount();
        } while (count($ids) === $chunkSize);

        return $totalDeleted;
    }

    public function terminate(): void
    {
        $this->monitoredTags = [];
        $this->monitoredTagsLoaded = false;
    }

    protected function buildFilter(?string $type, EntryQueryOptions $options): array
    {
        $filter = [];

        if ($type !== null) {
            $filter['type'] = $type;
        }

        if ($options->batchId) {
            $filter['batch_id'] = $options->batchId;
        }

        if (! empty($options->uuids)) {
            $filter['uuid'] = ['$in' => array_values($options->uuids)];
        }

        if ($options->familyHash) {
            $filter['family_hash'] = $options->familyHash;
        }

        if (! $options->familyHash && ! $options->batchId && empty($options->uuids)) {
            $filter['should_display_on_index'] = true;
        }

        if ($options->tag) {
            $filter['tags'] = $options->tag;
        }

        if ($options->beforeSequence) {
            try {
                $filter['_id'] = ['$lt' => new ObjectId((string) $options->beforeSequence)];
            } catch (MongoInvalidArgumentException) {
                $filter['_id'] = ['$lt' => $options->beforeSequence];
            }
        }

        return $filter;
    }

    protected function storeExceptions(Collection $exceptions): void
    {
        if ($exceptions->isEmpty()) {
            return;
        }

        $exceptions->groupBy('familyHash')->each(function (Collection $group, string $hash): void {
            if ($hash !== '') {
                $this->entries()->updateMany(
                    ['family_hash' => $hash],
                    ['$set' => ['should_display_on_index' => false]],
                );
            }

            $this->entries()->insertMany(
                $group->map(fn (IncomingEntry $entry) => $this->toDocument($entry))->values()->all(),
            );
        });
    }

    protected function toDocument(IncomingEntry $entry): array
    {
        $createdAt = $entry->createdAt
            ? Carbon::parse($entry->createdAt)
            : Carbon::now();

        return [
            '_id' => new ObjectId(),
            'uuid' => (string) $entry->uuid,
            'batch_id' => $entry->batchId,
            'family_hash' => $entry->familyHash,
            'should_display_on_index' => $entry->shouldDisplayOnIndex,
            'type' => $entry->type,
            'content' => $entry->content,
            'tags' => array_values(array_unique($entry->tags)),
            'created_at' => new UTCDateTime((int) ($createdAt->getTimestamp() * 1000)),
        ];
    }

    protected function toEntryResult(array $document): EntryResult
    {
        $id = isset($document['_id']) ? (string) $document['_id'] : null;
        $createdAt = $document['created_at'] ?? null;

        if ($createdAt instanceof UTCDateTime) {
            $createdAt = Carbon::instance($createdAt->toDateTime());
        } elseif (is_string($createdAt)) {
            $createdAt = Carbon::parse($createdAt);
        }

        return new EntryResult(
            $id,
            $id,
            (string) ($document['uuid'] ?? ''),
            $document['batch_id'] ?? null,
            (string) ($document['type'] ?? ''),
            $document['family_hash'] ?? null,
            (array) ($document['content'] ?? []),
            $createdAt,
            array_values((array) ($document['tags'] ?? [])),
        );
    }

    protected function entries(): MongoCollection
    {
        return $this->mongoDatabase()->selectCollection($this->entriesCollection);
    }

    protected function monitoringStore(): MongoCollection
    {
        return $this->mongoDatabase()->selectCollection($this->monitoringCollection);
    }

    protected function mongoDatabase()
    {
        $connection = DB::connection($this->connection);

        if (method_exists($connection, 'getDatabase')) {
            return $connection->getDatabase();
        }

        if (method_exists($connection, 'getMongoDB')) {
            return $connection->getMongoDB();
        }

        throw new \RuntimeException(sprintf(
            'Connection [%s] is not a MongoDB connection. Install mongodb/laravel-mongodb and configure the connection.',
            $this->connection,
        ));
    }

    protected function chunkSize(string $key): int
    {
        $value = config("telescope-mongodb.chunk.$key", $key === 'prune' ? 5000 : 1000);

        return max(1, (int) $value);
    }
}
