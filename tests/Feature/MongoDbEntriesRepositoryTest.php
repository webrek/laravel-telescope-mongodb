<?php

namespace TelescopeMongoDB\Driver\Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Storage\EntryQueryOptions;
use TelescopeMongoDB\Driver\Storage\MongoDbEntriesRepository;
use TelescopeMongoDB\Driver\Tests\TestCase;

class MongoDbEntriesRepositoryTest extends TestCase
{
    protected MongoDbEntriesRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessMongoAvailable();

        $this->repository = $this->app->make(MongoDbEntriesRepository::class);

        $database = DB::connection(config('telescope-mongodb.connection'))->getDatabase();
        $database->dropCollection(config('telescope-mongodb.collections.entries'));
        $database->dropCollection(config('telescope-mongodb.collections.monitoring'));
    }

    public function test_it_stores_and_retrieves_a_single_entry(): void
    {
        $entry = $this->makeEntry(EntryType::REQUEST, ['uri' => '/users'], ['status:200']);

        $this->repository->store(Collection::make([$entry]));

        $result = $this->repository->find($entry->uuid);

        $this->assertSame($entry->uuid, $result->uuid);
        $this->assertSame(EntryType::REQUEST, $result->type);
        $this->assertSame('/users', $result->content['uri']);
        $this->assertSame(['status:200'], $result->tags);
    }

    public function test_it_filters_by_type_and_tag_and_excludes_hidden_entries(): void
    {
        $visible = $this->makeEntry(EntryType::REQUEST, ['uri' => '/visible'], ['env:prod']);
        $hidden = $this->makeEntry(EntryType::REQUEST, ['uri' => '/hidden'], ['env:prod']);
        $hidden->shouldDisplayOnIndex = false;
        $other = $this->makeEntry(EntryType::QUERY, ['sql' => 'select 1'], ['env:prod']);

        $this->repository->store(Collection::make([$visible, $hidden, $other]));

        $options = (new EntryQueryOptions())->tag('env:prod')->limit(50);

        $results = $this->repository->get(EntryType::REQUEST, $options);

        $this->assertCount(1, $results);
        $this->assertSame($visible->uuid, $results->first()->uuid);
    }

    public function test_it_paginates_with_before_sequence(): void
    {
        $entries = Collection::times(5, fn () => $this->makeEntry(EntryType::REQUEST, ['n' => 1]));
        $this->repository->store($entries);

        $first = $this->repository->get(EntryType::REQUEST, (new EntryQueryOptions())->limit(2));
        $this->assertCount(2, $first);

        $cursor = $first->last()->sequence;

        $next = $this->repository->get(
            EntryType::REQUEST,
            (new EntryQueryOptions())->beforeSequence($cursor)->limit(2),
        );

        $this->assertCount(2, $next);
        $this->assertNotEquals($first->pluck('uuid'), $next->pluck('uuid'));
    }

    public function test_it_hides_previous_exceptions_with_same_family_hash(): void
    {
        $first = $this->makeEntry(EntryType::EXCEPTION, ['class' => 'BoomException', 'message' => 'one']);
        $first->familyHash = 'fam-1';

        $second = $this->makeEntry(EntryType::EXCEPTION, ['class' => 'BoomException', 'message' => 'two']);
        $second->familyHash = 'fam-1';

        $this->repository->store(Collection::make([$first]));
        $this->repository->store(Collection::make([$second]));

        $listing = $this->repository->get(EntryType::EXCEPTION, new EntryQueryOptions());

        $this->assertCount(1, $listing);
        $this->assertSame($second->uuid, $listing->first()->uuid);

        $byFamily = $this->repository->get(
            EntryType::EXCEPTION,
            (new EntryQueryOptions())->familyHash('fam-1'),
        );

        $this->assertCount(2, $byFamily);
    }

    public function test_it_updates_content_and_tags(): void
    {
        $entry = $this->makeEntry(EntryType::REQUEST, ['uri' => '/users', 'duration' => null]);
        $this->repository->store(Collection::make([$entry]));

        $update = new EntryUpdate($entry->uuid, EntryType::REQUEST, ['duration' => 42]);
        $update->tags = ['user:7'];

        $failed = $this->repository->update(Collection::make([$update]));

        $this->assertNull($failed);

        $result = $this->repository->find($entry->uuid);

        $this->assertSame(42, $result->content['duration']);
        $this->assertSame('/users', $result->content['uri']);
        $this->assertContains('user:7', $result->tags);
    }

    public function test_update_returns_failed_when_entry_missing(): void
    {
        $update = new EntryUpdate((string) Str::uuid(), EntryType::REQUEST, ['duration' => 1]);

        $failed = $this->repository->update(Collection::make([$update]));

        $this->assertNotNull($failed);
        $this->assertCount(1, $failed);
    }

    public function test_monitoring_tags_roundtrip(): void
    {
        $this->repository->monitor(['critical', 'auth']);

        $this->assertTrue($this->repository->isMonitoring(['auth']));
        $this->assertFalse($this->repository->isMonitoring(['nope']));

        $this->repository->stopMonitoring(['auth']);

        $repo = $this->app->make(MongoDbEntriesRepository::class);
        $this->assertFalse($repo->isMonitoring(['auth']));
        $this->assertTrue($repo->isMonitoring(['critical']));
    }

    public function test_prune_removes_old_entries_and_can_keep_exceptions(): void
    {
        $old = $this->makeEntry(EntryType::REQUEST, ['uri' => '/old']);
        $old->createdAt = now()->subDays(10);

        $recent = $this->makeEntry(EntryType::REQUEST, ['uri' => '/recent']);

        $oldException = $this->makeEntry(EntryType::EXCEPTION, ['class' => 'X']);
        $oldException->createdAt = now()->subDays(10);

        $this->repository->store(Collection::make([$old, $recent, $oldException]));

        $deleted = $this->repository->prune(now()->subDays(5), keepExceptions: true);

        $this->assertSame(1, $deleted);

        $stillThere = $this->repository->get(EntryType::EXCEPTION, new EntryQueryOptions());
        $this->assertCount(1, $stillThere);
    }

    public function test_clear_empties_collections(): void
    {
        $entry = $this->makeEntry(EntryType::REQUEST, ['uri' => '/x']);
        $this->repository->store(Collection::make([$entry]));
        $this->repository->monitor(['watched']);

        $this->repository->clear();

        $this->assertCount(0, $this->repository->get(EntryType::REQUEST, new EntryQueryOptions()));
        $this->assertSame([], $this->repository->monitoring());
    }

    protected function makeEntry(string $type, array $content, array $tags = []): IncomingEntry
    {
        $entry = IncomingEntry::make($content);
        $entry->type = $type;
        $entry->tags($tags);

        return $entry;
    }
}
