<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Storage\PgVectorStore;

/**
 * Runs only against a DISPOSABLE PostgreSQL with pgvector, named by
 * PRISM_GRAPH_TEST_PG_DSN (CI provides one). Every test works in its own
 * fresh schema and drops it afterwards. Never point this at a real database.
 */
final class PostgresTest extends TestCase
{
    private ?PDO $pdo = null;

    private string $schema = '';

    protected function setUp(): void
    {
        $dsn = getenv('PRISM_GRAPH_TEST_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            if (getenv('PRISM_GRAPH_REQUIRE_PG') === '1') {
                $this->fail('PRISM_GRAPH_REQUIRE_PG is set but no PRISM_GRAPH_TEST_PG_DSN was given: refusing to skip.');
            }
            $this->markTestSkipped('Set PRISM_GRAPH_TEST_PG_DSN to a disposable PostgreSQL to run these.');
        }
        $this->pdo = new PDO($dsn, getenv('PRISM_GRAPH_TEST_PG_USER') ?: null, getenv('PRISM_GRAPH_TEST_PG_PASSWORD') ?: null);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        $this->schema = 'pg_'.bin2hex(random_bytes(6));
        $this->pdo->exec("CREATE SCHEMA {$this->schema}");
        $this->pdo->exec("SET search_path TO {$this->schema}, public");
        foreach ([...BaseSchema::statements('pgsql'), ...array_slice(PgVectorStore::schema(), 1)] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->schema !== '') {
            $this->pdo->exec("DROP SCHEMA {$this->schema} CASCADE");
        }
    }

    public function test_store_and_facts_behave_the_same_on_postgres(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $report = $graph->ingest('doc', 'file://d', "# A\nsourdough daily\n## B\nclosed mondays\n", new MarkdownChunker(300, 400, 0));
        $again = $graph->ingest('doc', 'file://d', "# A\nsourdough daily\n## B\nclosed mondays\n", new MarkdownChunker(300, 400, 0));
        $this->assertSame([2, 0], [$report->stored, $again->stored]);

        $chunk = $graph->search('mondays')[0]->chunk;
        $this->assertSame(['A', 'B'], $chunk->headingPath);
        $graph->assert(new Fact('f1', 'shop', 'closes', 'monday', [$chunk->id], '2026-01-01T00:00:00Z'));
        $graph->supersede('f1', new Fact('f2', 'shop', 'closes', 'sunday', [$chunk->id], '2026-06-01T00:00:00Z'));
        $this->assertSame('f2', $graph->facts('shop')[0]->id);
        $this->assertSame('f1', $graph->facts('shop', '2026-03-01T00:00:00Z')[0]->id);

        $this->expectException(GraphException::class);
        $graph->supersede('f1', new Fact('f3', 'shop', 'closes', 'x', [$chunk->id], '2026-07-01T00:00:00Z'));
    }

    public function test_pgvector_ranks_by_cosine_and_refuses_a_different_embedder(): void
    {
        $store = new PgVectorStore($this->pdo);
        $spec = new IndexSpec('main', 'test', 'm', 3);
        $store->ensureIndex($spec);
        $store->upsert($spec, [str_repeat('a', 64) => [1, 0, 0], str_repeat('b', 64) => [0, 1, 0], str_repeat('c', 64) => [0.9, 0.1, 0]]);

        $hits = $store->nearest($spec, [1, 0, 0], 2);
        $this->assertSame([str_repeat('a', 64), str_repeat('c', 64)], array_column($hits, 'chunk_id'));
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 1e-6);

        $this->expectException(GraphException::class);
        $store->nearest(new IndexSpec('main', 'test', 'other-model', 3), [1, 0, 0], 1);
    }
}
