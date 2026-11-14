<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Storage\SqliteVecStore;

/**
 * Needs PHP 8.4 (Pdo\Sqlite::loadExtension) and PRISM_GRAPH_SQLITE_VEC set to
 * the vec0 binary. CI sets PRISM_GRAPH_REQUIRE_SQLITE_VEC=1 so a missing
 * binary fails instead of skipping.
 */
final class SqliteVecStoreTest extends TestCase
{
    private function connection(bool $load = true): PDO
    {
        $path = getenv('PRISM_GRAPH_SQLITE_VEC') ?: '';
        if ($path === '' || ! method_exists(PDO::class, 'connect')) {
            if (getenv('PRISM_GRAPH_REQUIRE_SQLITE_VEC') === '1') {
                $this->fail('PRISM_GRAPH_REQUIRE_SQLITE_VEC=1 but sqlite-vec is not available (needs PHP 8.4 and PRISM_GRAPH_SQLITE_VEC).');
            }
            $this->markTestSkipped('sqlite-vec not available.');
        }
        $pdo = PDO::connect('sqlite::memory:');
        if ($load) {
            $pdo->loadExtension($path);
        }
        foreach (PdoVectorStore::schema('sqlite') as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /** @return array<string, list<float>> */
    private function vectors(): array
    {
        mt_srand(7);
        $out = [];
        for ($i = 0; $i < 60; $i++) {
            $out[hash('sha256', (string) $i)] = array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 8));
        }

        return $out;
    }

    public function test_it_ranks_exactly_like_the_reference_brute_force_store(): void
    {
        $pdo = $this->connection();
        $spec = new IndexSpec('main', 'test', 'm', 8);
        $vec = new SqliteVecStore($pdo);
        $vec->ensureIndex($spec);
        $vec->upsert($spec, $this->vectors());
        $reference = new PdoVectorStore($pdo);

        $query = [0.3, -0.2, 0.9, 0.1, 0.0, -0.5, 0.4, 0.2];
        $got = $vec->nearest($spec, $query, 10);
        $want = $reference->nearest($spec, $query, 10);

        $this->assertSame(array_column($want, 'chunk_id'), array_column($got, 'chunk_id'));
        foreach ($want as $i => $row) {
            $this->assertEqualsWithDelta($row['score'], $got[$i]['score'], 1e-5);
        }
        $this->assertSame('sqlite-vec', $vec->method());
    }

    public function test_the_pinned_embedder_still_holds(): void
    {
        $pdo = $this->connection();
        $vec = new SqliteVecStore($pdo);
        $vec->ensureIndex(new IndexSpec('main', 'test', 'm', 8));

        $this->expectException(GraphException::class);
        $vec->nearest(new IndexSpec('main', 'test', 'other-model', 8), array_fill(0, 8, 0.5), 5);
    }

    public function test_it_refuses_to_start_without_the_extension(): void
    {
        $pdo = $this->connection(load: false);
        $this->expectException(GraphException::class);
        new SqliteVecStore($pdo);
    }
}
