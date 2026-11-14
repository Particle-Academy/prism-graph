<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\FactStatus;
use Prism\Graph\Graph;
use Prism\Graph\IndexSpec;
use Prism\Graph\Scope;
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
    use Fixture;

    private string $schema = '';

    private bool $connected = false;

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
        $this->connected = true;
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        // "pg_" is reserved for PostgreSQL's own schemas.
        $this->schema = 'graph_test_'.bin2hex(random_bytes(6));
        $this->pdo->exec("CREATE SCHEMA {$this->schema}");
        $this->pdo->exec("SET search_path TO {$this->schema}, public");
        foreach ([...BaseSchema::statements('pgsql'), ...array_slice(PgVectorStore::schema(), 1)] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connected && $this->schema !== '') {
            $this->pdo->exec("DROP SCHEMA {$this->schema} CASCADE");
        }
    }

    public function test_memory_behaves_the_same_on_postgres(): void
    {
        $memory = $this->graph()->scope(self::acme()->owner('alice'));
        $report = $memory->ingest('doc', 'f://d', "# A\nSourdough daily\n## B\nClosed MONDAYS\n", new MarkdownChunker(300, 400, 0), self::user());
        $again = $memory->ingest('doc', 'f://d', "# A\nSourdough daily\n## B\nClosed MONDAYS\n", new MarkdownChunker(300, 400, 0), self::user());
        $this->assertSame([2, 0], [$report->stored, $again->stored]);

        $chunk = $memory->search('mondays')[0]->chunk;
        $this->assertSame('doc', $chunk->sourceKey, 'tsvector search folds case');
        $this->assertSame(64, strlen($chunk->id), 'CHAR(64) padding is trimmed');
        foreach (["') OR 1=1 --", '!&|:*', 'a & b'] as $hostile) {
            $memory->search($hostile); // plainto_tsquery: never tsquery syntax
        }

        $memory->assert(new Fact('f1', 'shop', 'closes', 'monday', [$chunk->id], '2026-01-01T00:00:00Z'));
        $memory->supersede('f1', new Fact('f2', 'shop', 'closes', 'sunday', [$chunk->id], '2026-06-01T00:00:00Z'));
        $this->assertSame('f2', $memory->facts('shop')[0]->id);
        $this->assertSame('f1', $memory->facts('shop', '2026-03-01T00:00:00Z')[0]->id);
        $this->assertSame(FactStatus::Accepted, $memory->facts('shop')[0]->status);

        $this->expectException(GraphException::class);
        $memory->supersede('f1', new Fact('f3', 'shop', 'closes', 'never', [$chunk->id], '2026-07-01T00:00:00Z'));
    }

    public function test_scope_isolation_holds_on_postgres(): void
    {
        $graph = $this->graph();
        $graph->scope(Scope::tenant('acme')->owner('alice'))->ingest('d', 'f://a', "Alice secret kestrel.\n", new MarkdownChunker, self::user());
        $graph->scope(Scope::tenant('acme'))->ingest('p', 'f://p', "Tenant policy falcon.\n", new MarkdownChunker, self::user());

        $bob = $graph->scope(Scope::tenant('acme')->owner('bob'));
        $this->assertSame([], $bob->search('kestrel'));
        $this->assertNotEmpty($bob->search('falcon'));
        $this->assertSame([], $graph->scope(Scope::tenant('globex')->owner('alice'))->search('kestrel falcon'));
    }

    public function test_erase_cascades_on_postgres(): void
    {
        $vectors = new PgVectorStore($this->pdo);
        $memory = $this->graph($vectors, self::letters())->scope(self::acme()->owner('alice'));
        $memory->ingest('d', 'f://d', "Diagnosed with asthma.\n", new MarkdownChunker, self::user());
        $chunk = $memory->search('asthma')[0]->chunk;
        $memory->assert(new Fact('h', 'alice', 'has', 'asthma', [$chunk->id], '2026-01-01T00:00:00Z'));

        $report = $memory->erase('d');

        $this->assertSame([1, 1, 1, 1], [$report->chunks, $report->evidenceLinks, $report->factsErased, $report->vectors]);
        $this->assertSame([], $memory->search('asthma'));
        $this->assertSame([], $memory->facts('alice', unaccepted: true));
    }

    // PostgreSQL aborts the whole transaction after an error unless a savepoint
    // is rolled back: the nested path must leave the caller's transaction usable.
    public function test_a_failed_write_inside_the_callers_transaction_leaves_it_usable_on_postgres(): void
    {
        $memory = $this->graph()->scope(self::acme()->owner('alice'));
        $memory->ingest('a', 'f://a', "Shop closes Mondays.\n", new MarkdownChunker, self::user());
        $memory->ingest('t', 'f://t', "Shop closes Sundays now, says a page.\n", new MarkdownChunker, self::tool());
        $memory->assert(new Fact('f1', 'shop', 'closes', 'monday', [$memory->search('mondays')[0]->chunk->id], '2026-01-01T00:00:00Z'));
        $memory->assert(new Fact('taken', 'shop', 'opens', 'nine', [$memory->search('mondays')[0]->chunk->id], '2026-01-01T00:00:00Z'));

        $this->pdo->beginTransaction();
        foreach ([
            new Fact('f2', 'shop', 'closes', 'sunday', [$memory->search('sundays')[0]->chunk->id], '2026-06-01T00:00:00Z'), // refused: tool evidence
            new Fact('taken', 'shop', 'closes', 'sunday', [$memory->search('mondays')[0]->chunk->id], '2026-06-01T00:00:00Z'), // fails after the close
        ] as $replacement) {
            try {
                $memory->supersede('f1', $replacement);
                $this->fail('the supersede was not refused');
            } catch (\Throwable) {
            }
        }
        $memory->ingest('b', 'f://b', "Still usable.\n", new MarkdownChunker, self::user()); // would fail in an aborted transaction
        $this->pdo->commit();

        $this->assertSame('monday', $memory->facts('shop')[0]->object);
        $this->assertNotEmpty($memory->search('usable'));
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
        $this->assertSame([str_repeat('d', 64)], $store->missing($spec, [str_repeat('a', 64), str_repeat('d', 64)]));
        $this->assertSame(1, $store->forget([str_repeat('b', 64)]));

        $this->expectException(GraphException::class);
        $store->nearest(new IndexSpec('main', 'test', 'other-model', 3), [1, 0, 0], 1);
    }
}
