<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Graph;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;

/** G-282: scoped, paginated listing of sources and chunks, in the shape agreed with Fancy (g282.1). */
final class ListingTest extends TestCase
{
    use Fixture;

    protected function setUp(): void
    {
        $this->freshPdo();
    }

    private function alice(?Graph $graph = null): ScopedGraph
    {
        return ($graph ?? $this->graph())->scope(Scope::tenant('acme')->owner('alice'));
    }

    public function test_lists_sources_newest_first_with_the_agreed_fields(): void
    {
        $alice = $this->alice();
        $this->now = '2026-01-01T00:00:00Z';
        $alice->ingest('old', 'f://old', "Old note.\n", new MarkdownChunker, self::user('alice'), ['title' => 'Old note', 'kind' => 'note']);
        $this->now = '2026-01-02T00:00:00Z';
        $alice->ingest('new', 'f://new', "# A\nOne.\n\n# B\nTwo.\n", new MarkdownChunker, self::tool());

        $page = $alice->listSources();

        $this->assertSame('g282.1', $page['contractVersion']);
        $this->assertNull($page['nextCursor']);
        $this->assertSame(['f://new', 'Old note'], array_column($page['items'], 'title'));
        $new = $page['items'][0];
        $this->assertSame(['sourceId', 'title', 'kind', 'createdAt', 'chunkCount', 'trust'], array_keys($new));
        $this->assertNull($new['kind'], 'no metadata kind falls back to null');
        $this->assertSame('tool', $new['trust']);
        $this->assertSame(2, $new['chunkCount']);
        $this->assertSame('note', $page['items'][1]['kind']);
    }

    public function test_never_lists_another_owner_or_tenant(): void
    {
        $graph = $this->graph();
        $this->alice($graph)->ingest('a', 'f://a', "Alice private.\n", new MarkdownChunker, self::user('alice'));
        $graph->scope(Scope::tenant('acme')->owner('bob'))->ingest('b', 'f://b', "Bob private.\n", new MarkdownChunker, self::user('bob'));
        $graph->scope(Scope::tenant('globex')->owner('alice'))->ingest('g', 'f://g', "Other tenant.\n", new MarkdownChunker, self::user('alice'));
        $graph->scope(Scope::tenant('acme'))->ingest('shared', 'f://shared', "Tenant-wide.\n", new MarkdownChunker, self::user('admin'));

        $uris = array_column($this->alice($graph)->listSources()['items'], 'title');
        sort($uris);
        $this->assertSame(['f://a', 'f://shared'], $uris);

        $bobsId = $graph->scope(Scope::tenant('acme')->owner('bob'))->listSources()['items'][0]['sourceId'];
        $this->assertSame([], $this->alice($graph)->listChunks($bobsId)['items'], 'a source id from another owner reads as empty');
    }

    public function test_pages_through_every_source_exactly_once(): void
    {
        $alice = $this->alice();
        foreach (range(1, 7) as $i) {
            $this->now = sprintf('2026-01-%02dT00:00:00Z', $i % 3 + 1); // ties on recorded_at break on id
            $alice->ingest("s{$i}", "f://s{$i}", "Note {$i}.\n", new MarkdownChunker, self::user('alice'));
        }
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $alice->listSources($cursor, 3);
            $this->assertLessThanOrEqual(3, count($page['items']));
            $seen = [...$seen, ...array_column($page['items'], 'sourceId')];
            $cursor = $page['nextCursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    public function test_lists_chunks_in_order_with_provenance_and_pages(): void
    {
        $alice = $this->alice();
        $alice->ingest('doc', 'f://doc', "# A\nOne.\n\n# B\nTwo.\n\n# C\nThree.\n", new MarkdownChunker, self::user('alice'));
        $sourceId = $alice->listSources()['items'][0]['sourceId'];

        $first = $alice->listChunks($sourceId, null, 2);
        $this->assertSame('g282.1', $first['contractVersion']);
        $this->assertSame([1, 2], array_column($first['items'], 'position'));
        $this->assertSame(['chunkId', 'sourceId', 'text', 'position', 'provenance'], array_keys($first['items'][0]));
        $provenance = $first['items'][0]['provenance'];
        $this->assertSame('f://doc', $provenance['uri']);
        $this->assertSame('user', $provenance['trust']);
        $this->assertSame('alice', $provenance['writer']);
        $this->assertSame(['A'], $provenance['headingPath']);
        $this->assertNotNull($first['nextCursor']);

        $second = $alice->listChunks($sourceId, $first['nextCursor'], 2);
        $this->assertSame([3], array_column($second['items'], 'position'));
        $this->assertNull($second['nextCursor']);
    }

    public function test_excludes_retired_sources_and_chunks(): void
    {
        $alice = $this->alice();
        $alice->ingest('gone', 'f://gone', "Retire me.\n", new MarkdownChunker, self::user('alice'));
        $alice->ingest('kept', 'f://kept', "Keep me.\n", new MarkdownChunker, self::user('alice'));
        $alice->retireSource('gone');

        $this->assertSame(['f://kept'], array_column($alice->listSources()['items'], 'title'));
    }

    public function test_refuses_a_cursor_from_another_scope_or_listing_and_a_forged_one(): void
    {
        $graph = $this->graph();
        $alice = $this->alice($graph);
        foreach (range(1, 3) as $i) {
            $alice->ingest("s{$i}", "f://s{$i}", "Note {$i}.\n", new MarkdownChunker, self::user('alice'));
        }
        $cursor = $alice->listSources(null, 1)['nextCursor'];
        $this->assertNotNull($cursor);

        $bob = $graph->scope(Scope::tenant('acme')->owner('bob'));
        foreach ([
            fn () => $bob->listSources($cursor),
            fn () => $alice->listChunks('any', $cursor),
            fn () => $alice->listSources('not-a-cursor'),
            fn () => $alice->listSources(rtrim(strtr(base64_encode('{"k":["x","y"],"b":"0000000000000000"}'), '+/', '-_'), '=')),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a foreign or forged cursor was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid cursor.', $e->getMessage());
            }
        }
    }

    public function test_caps_the_page_size(): void
    {
        $alice = $this->alice();
        $this->assertSame(0, count($alice->listSources(null, 200)['items']));
        $this->expectException(InvalidArgumentException::class);
        $alice->listSources(null, 201);
    }

    public function test_the_cap_is_configurable(): void
    {
        $small = (new Graph($this->store(), listMax: 5))->scope(Scope::tenant('acme')->owner('alice'));
        $this->expectException(InvalidArgumentException::class);
        $small->listSources(null, 6);
    }
}
