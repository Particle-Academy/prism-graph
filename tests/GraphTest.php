<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\Hit;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Storage\PdoVectorStore;

final class GraphTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach ([...BaseSchema::statements('sqlite'), ...PdoVectorStore::schema('sqlite')] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function text(): string
    {
        return "# Bakery\nWe bake sourdough daily.\n## Hours\nOpen 7 to 3, closed Mondays.\n## Coffee\nEspresso and pour-over.\n";
    }

    public function test_text_only_graph_ingests_idempotently_and_searches_verbatim(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $first = $graph->ingest('doc', 'file://doc.md', $this->text(), new MarkdownChunker(300, 400, 0), ['class' => 'corpus']);
        $again = $graph->ingest('doc', 'file://doc.md', $this->text(), new MarkdownChunker(300, 400, 0));

        $this->assertSame([3, 3, 0], [$first->stored, $again->chunks, $again->stored]);
        $hits = $graph->search('closed mondays');
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('closed Mondays', $hits[0]->chunk->text);
        $this->assertSame(['Bakery', 'Hours'], $hits[0]->chunk->headingPath);
        $this->assertSame('text', $hits[0]->method);
    }

    public function test_a_wrong_sha256_is_refused_before_anything_is_stored(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        try {
            $graph->ingest('doc', 'file://doc.md', $this->text(), new MarkdownChunker, sha256: str_repeat('0', 64));
            $this->fail('a mismatched sha256 was accepted');
        } catch (GraphException) {
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_chunks')->fetchColumn());
    }

    public function test_text_search_treats_pattern_and_sql_characters_as_literals(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('doc', 'file://doc.md', "# T\n100% pure_cotton\n## U\nplain text\n", new MarkdownChunker(300, 400, 0));

        $this->assertCount(1, $graph->search('100%'));
        $this->assertCount(1, $graph->search('pure_cotton'));
        $this->assertSame([], $graph->search('%'.'_'.'%'));
        $this->assertSame([], $graph->search("x' OR '1'='1"));
        $this->assertSame([], $graph->search('   '));
    }

    public function test_vector_search_never_hides_a_chunk_without_a_vector(): void
    {
        $embedder = new class implements Embedder
        {
            public bool $fail = false;

            public function spec(): IndexSpec
            {
                return new IndexSpec('main', 'test', 'bag-of-letters', 3);
            }

            public function embed(array $texts): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('embedding provider down');
                }

                return array_map(static fn (string $t) => [1 + substr_count($t, 'a'), 1 + substr_count($t, 'e'), 1 + substr_count($t, 'o')], $texts);
            }
        };
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo, vectors: new PdoVectorStore($this->pdo), embedder: $embedder);
        $graph->ingest('doc', 'file://doc.md', $this->text(), new MarkdownChunker(300, 400, 0));

        // A second source whose embedding fails is still stored verbatim and found by text.
        $embedder->fail = true;
        try {
            $graph->ingest('late', 'file://late.md', "# Late\nzebra crossing notes\n", new MarkdownChunker(300, 400, 0));
        } catch (\RuntimeException) {
        }
        $embedder->fail = false;

        $methods = array_map(static fn (Hit $h) => $h->method, $graph->search('zebra', 10));
        $texts = array_map(static fn (Hit $h) => $h->chunk->text, $graph->search('zebra', 10));
        $this->assertContains('brute-force', $methods);
        $this->assertNotEmpty(array_filter($texts, static fn (string $t) => str_contains($t, 'zebra')), 'the unembedded chunk was hidden');
    }

    public function test_facts_flow_through_the_graph_and_reads_do_not_mutate(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('doc', 'file://doc.md', $this->text(), new MarkdownChunker(300, 400, 0));
        $chunk = $graph->search('sourdough')[0]->chunk;
        $graph->assert(new Fact('f1', 'bakery', 'bakes', 'sourdough', [$chunk->id], '2026-01-01T00:00:00Z'));

        $before = $this->pdo->query('SELECT * FROM graph_facts')->fetchAll(PDO::FETCH_ASSOC);
        $graph->search('sourdough');
        $graph->get([$chunk->id]);
        $facts = $graph->facts('bakery');
        $this->assertSame($before, $this->pdo->query('SELECT * FROM graph_facts')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([$chunk->id], $facts[0]->evidence);
    }

    public function test_vectors_and_embedder_must_be_configured_together(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Graph(new PdoGraphStore($this->pdo), $this->pdo, vectors: new PdoVectorStore($this->pdo));
    }
}
