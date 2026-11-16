<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Contracts\FindsSources;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Contracts\ListsGraph;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Graph;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;

/** G-295: one source by id, found exactly when listSources would list it for the same reader. */
final class GetSourceTest extends TestCase
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

    /** The id a scope's own listing reports for the source it ingested under $key. */
    private static function idOf(ScopedGraph $memory, string $title): string
    {
        foreach ($memory->listSources(null, 200)['items'] as $item) {
            if ($item['title'] === $title) {
                return $item['sourceId'];
            }
        }
        self::fail("{$title} is not listed");
    }

    public function test_another_owner_tenant_or_narrower_scope_reads_as_null(): void
    {
        $graph = $this->graph();
        $bob = $graph->scope(Scope::tenant('acme')->owner('bob'));
        $globex = $graph->scope(Scope::tenant('globex')->owner('alice'));
        $thread = $graph->scope(Scope::tenant('acme')->owner('alice')->agent('support')->thread('t-1'));
        $bob->ingest('b', 'f://b', "Bob private.\n", new MarkdownChunker, self::user('bob'));
        $globex->ingest('g', 'f://g', "Other tenant, same owner name.\n", new MarkdownChunker, self::user('alice'));
        $thread->ingest('t', 'f://t', "One thread only.\n", new MarkdownChunker, self::user('alice'));

        $alice = $this->alice($graph);
        foreach ([[$bob, 'f://b'], [$globex, 'f://g'], [$thread, 'f://t']] as [$owner, $title]) {
            $id = self::idOf($owner, $title);
            $this->assertNotNull($owner->getSource($id)['item'], "{$title} is found by its own scope");
            $this->assertSame(['contractVersion' => 'g282.1', 'item' => null], $alice->getSource($id), "{$title} is invisible to alice");
        }
    }

    public function test_the_found_item_equals_the_listed_item_exactly(): void
    {
        $graph = $this->graph();
        $alice = $this->alice($graph);
        $alice->ingest('note', 'f://note', "# A\nOne.\n\n# B\nTwo.\n", new MarkdownChunker, self::tool(), ['title' => 'A note', 'kind' => 'note']);
        $alice->ingest('bare', 'f://bare', "Bare.\n", new MarkdownChunker, self::user('alice'));
        $graph->scope(Scope::tenant('acme'))->ingest('shared', 'f://shared', "Tenant-wide.\n", new MarkdownChunker, self::user('admin'));
        // A partly retired source: its count must agree with the listing's, not count retired chunks.
        $alice->ingest('shrunk', 'f://shrunk', "# A\nOne.\n\n# B\nTwo.\n", new MarkdownChunker, self::user('alice'));
        $alice->ingest('shrunk', 'f://shrunk', "# A\nOne.\n", new MarkdownChunker, self::user('alice'));

        $listed = $alice->listSources()['items'];
        $this->assertCount(4, $listed);
        foreach ($listed as $item) {
            $found = $alice->getSource($item['sourceId']);
            $this->assertSame('g282.1', $found['contractVersion']);
            $this->assertSame($item, $found['item']);
        }
        $this->assertSame(1, $alice->getSource(self::idOf($alice, 'f://shrunk'))['item']['chunkCount']);
    }

    public function test_a_source_with_every_chunk_retired_reads_as_null(): void
    {
        $alice = $this->alice();
        $alice->ingest('gone', 'f://gone', "Retire me.\n", new MarkdownChunker, self::user('alice'));
        $id = self::idOf($alice, 'f://gone');
        $alice->retireSource('gone');

        $this->assertNull($alice->getSource($id)['item']);
    }

    public function test_an_unknown_or_erased_id_reads_as_null(): void
    {
        $alice = $this->alice();
        $alice->ingest('erased', 'f://erased', "Erase me.\n", new MarkdownChunker, self::user('alice'));
        $id = self::idOf($alice, 'f://erased');
        $alice->erase('erased');

        $this->assertNull($alice->getSource($id)['item']);
        $this->assertNull($alice->getSource(str_repeat('0', 64))['item']);
        $this->assertNull($alice->getSource('not-a-digest')['item']);
    }

    public function test_sql_metacharacters_are_data_not_query(): void
    {
        $alice = $this->alice();
        $alice->ingest('a', 'f://a', "Something to match.\n", new MarkdownChunker, self::user('alice'));
        $id = self::idOf($alice, 'f://a');

        foreach (["' OR 1=1 --", "' OR '1'='1", '" OR ""="', "x' UNION SELECT 1 --", '%', '_', substr($id, 0, 59)."' --", "' OR s.id LIKE '%"] as $probe) {
            $this->assertNull($alice->getSource($probe)['item'], "probe [{$probe}] must not match");
        }
        $this->assertSame($id, $alice->getSource($id)['item']['sourceId'], 'the real id still matches');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte past a digest' => [str_repeat('a', 65)];
        yield '256 bytes' => [str_repeat('a', 256)];
        yield 'NUL' => ["abc\x00def"];
        yield 'newline' => ["abc\ndef"];
        yield 'tab' => ["abc\tdef"];
        yield 'DEL' => ["abc\x7Fdef"];
        yield 'invalid UTF-8' => ["abc\xFFdef"];
    }

    #[DataProvider('invalidIds')]
    public function test_refuses_an_invalid_id_before_any_query(string $id): void
    {
        $store = $this->createMockForIntersectionOfInterfaces([GraphStore::class, FindsSources::class]);
        $store->expects($this->never())->method('findSource');
        $memory = (new Graph($store))->scope(Scope::tenant('acme')->owner('alice'));

        $this->expectException(InvalidArgumentException::class);
        $memory->getSource($id);
    }

    public function test_a_store_without_finds_sources_is_refused(): void
    {
        // A third-party store that lists but predates G-295 must fail loudly, never fall back to a page scan.
        $store = $this->createStubForIntersectionOfInterfaces([GraphStore::class, ListsGraph::class]);
        $memory = (new Graph($store))->scope(Scope::tenant('acme')->owner('alice'));

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('FindsSources');
        $memory->getSource(str_repeat('a', 64));
    }
}
