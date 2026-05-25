<?php

namespace TelescopeMongoDB\Driver\Tests\Unit;

use Illuminate\Support\Carbon;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Storage\EntryQueryOptions;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TelescopeMongoDB\Driver\Storage\MongoDbEntriesRepository;

class MongoDbEntriesRepositoryUnitTest extends TestCase
{
    public function test_build_filter_applies_default_should_display_on_index_when_no_targeted_filter(): void
    {
        $filter = $this->callBuildFilter(EntryType::REQUEST, new EntryQueryOptions);

        $this->assertSame(EntryType::REQUEST, $filter['type']);
        $this->assertTrue($filter['should_display_on_index']);
        $this->assertArrayNotHasKey('batch_id', $filter);
        $this->assertArrayNotHasKey('family_hash', $filter);
        $this->assertArrayNotHasKey('uuid', $filter);
    }

    public function test_build_filter_omits_should_display_when_batch_id_is_set(): void
    {
        $filter = $this->callBuildFilter(EntryType::REQUEST, (new EntryQueryOptions)->batchId('batch-1'));

        $this->assertSame('batch-1', $filter['batch_id']);
        $this->assertArrayNotHasKey('should_display_on_index', $filter);
    }

    public function test_build_filter_omits_should_display_when_family_hash_is_set(): void
    {
        $filter = $this->callBuildFilter(EntryType::EXCEPTION, (new EntryQueryOptions)->familyHash('fam-1'));

        $this->assertSame('fam-1', $filter['family_hash']);
        $this->assertArrayNotHasKey('should_display_on_index', $filter);
    }

    public function test_build_filter_passes_tag_through(): void
    {
        $filter = $this->callBuildFilter(EntryType::REQUEST, (new EntryQueryOptions)->tag('env:prod'));

        $this->assertSame('env:prod', $filter['tags']);
    }

    public function test_build_filter_uses_in_for_uuids_array(): void
    {
        $filter = $this->callBuildFilter(EntryType::REQUEST, (new EntryQueryOptions)->uuids(['a', 'b']));

        $this->assertSame(['$in' => ['a', 'b']], $filter['uuid']);
        $this->assertArrayNotHasKey('should_display_on_index', $filter);
    }

    public function test_build_filter_treats_before_sequence_as_object_id_when_valid(): void
    {
        $id = (string) new ObjectId;

        $filter = $this->callBuildFilter(EntryType::REQUEST, (new EntryQueryOptions)->beforeSequence($id));

        $this->assertArrayHasKey('_id', $filter);
        $this->assertArrayHasKey('$lt', $filter['_id']);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$lt']);
        $this->assertSame($id, (string) $filter['_id']['$lt']);
    }

    public function test_build_filter_falls_back_to_literal_before_sequence_when_not_object_id(): void
    {
        $filter = $this->callBuildFilter(EntryType::REQUEST, (new EntryQueryOptions)->beforeSequence('not-an-object-id'));

        $this->assertSame(['$lt' => 'not-an-object-id'], $filter['_id']);
    }

    public function test_build_filter_skips_type_when_null(): void
    {
        $filter = $this->callBuildFilter(null, new EntryQueryOptions);

        $this->assertArrayNotHasKey('type', $filter);
    }

    public function test_to_entry_result_maps_document_to_telescope_entry(): void
    {
        $oid = new ObjectId;
        $now = new UTCDateTime(1_700_000_000_000);

        $document = [
            '_id' => $oid,
            'uuid' => 'uuid-1',
            'batch_id' => 'batch-1',
            'family_hash' => 'fam-1',
            'should_display_on_index' => true,
            'type' => EntryType::REQUEST,
            'content' => ['uri' => '/users'],
            'tags' => ['status:200'],
            'created_at' => $now,
        ];

        $result = $this->callToEntryResult($document);

        $this->assertInstanceOf(EntryResult::class, $result);
        $this->assertSame('uuid-1', $result->id);
        $this->assertSame((string) $oid, $result->sequence);
        $this->assertSame('batch-1', $result->batchId);
        $this->assertSame(EntryType::REQUEST, $result->type);
        $this->assertSame('fam-1', $result->familyHash);
        $this->assertSame(['uri' => '/users'], $result->content);
        $this->assertInstanceOf(Carbon::class, $result->createdAt);
        $this->assertSame(1_700_000_000, $result->createdAt->getTimestamp());
        $this->assertSame(['status:200'], $result->jsonSerialize()['tags']);
    }

    public function test_to_entry_result_defaults_batch_id_to_empty_string_when_missing(): void
    {
        $result = $this->callToEntryResult([
            '_id' => new ObjectId,
            'uuid' => 'uuid-x',
            'type' => EntryType::REQUEST,
            'content' => [],
            'created_at' => new UTCDateTime,
        ]);

        $this->assertSame('', $result->batchId);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function callToEntryResult(array $document): EntryResult
    {
        $repo = $this->repository();
        $reflection = new ReflectionClass($repo);
        $method = $reflection->getMethod('toEntryResult');
        $method->setAccessible(true);

        return $method->invoke($repo, $document);
    }

    /**
     * @return array<string, mixed>
     */
    protected function callBuildFilter(?string $type, EntryQueryOptions $options): array
    {
        $repo = $this->repository();
        $reflection = new ReflectionClass($repo);
        $method = $reflection->getMethod('buildFilter');
        $method->setAccessible(true);

        return $method->invoke($repo, $type, $options);
    }

    protected function repository(): MongoDbEntriesRepository
    {
        return new MongoDbEntriesRepository('mongodb', 'telescope_entries', 'telescope_monitoring');
    }
}
