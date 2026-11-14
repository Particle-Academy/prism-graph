<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\PdoVectorStore;

final class VectorStoreTest extends TestCase
{
    private PdoVectorStore $store;

    private IndexSpec $spec;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach (PdoVectorStore::schema('sqlite') as $sql) {
            $pdo->exec($sql);
        }
        $this->store = new PdoVectorStore($pdo);
        $this->spec = new IndexSpec('main', 'openai', 'text-embedding-3-small', 3);
        $this->store->ensureIndex($this->spec);
        $this->store->upsert($this->spec, ['a' => [1, 0, 0], 'b' => [0, 1, 0], 'c' => [0.9, 0.1, 0]]);
    }

    public function test_nearest_ranks_by_cosine_and_respects_k(): void
    {
        $hits = $this->store->nearest($this->spec, [1, 0, 0], 2);

        $this->assertSame(['a', 'c'], array_column($hits, 'chunk_id'));
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 1e-6);
        $this->assertSame('brute-force', $this->store->method());
    }

    public function test_upsert_replaces_a_chunks_vector(): void
    {
        $before = $this->store->nearest($this->spec, [0, 1, 0], 1);
        $this->assertSame('b', $before[0]['chunk_id'], 'b starts as the [0,1,0] match');

        $this->store->upsert($this->spec, ['b' => [1, 0, 0]]);

        $scores = array_column($this->store->nearest($this->spec, [1, 0, 0], 3), 'score', 'chunk_id');
        $this->assertEqualsWithDelta(1.0, $scores['b'], 1e-6, 'b now points along [1,0,0]');
        $this->assertCount(3, $scores, 'upsert replaced b rather than adding a row');
        $this->assertLessThan(0.5, $this->store->nearest($this->spec, [0, 1, 0], 3)[0]['score'], 'no vector points along [0,1,0] any more');
    }

    public function test_a_different_embedder_is_refused_not_mixed(): void
    {
        foreach ([
            new IndexSpec('main', 'openai', 'text-embedding-3-large', 3),
            new IndexSpec('main', 'mistral', 'text-embedding-3-small', 3),
            new IndexSpec('main', 'openai', 'text-embedding-3-small', 3, 'other@1'),
        ] as $other) {
            foreach ([
                fn () => $this->store->ensureIndex($other),
                fn () => $this->store->upsert($other, ['d' => [1, 0, 0]]),
                fn () => $this->store->nearest($other, [1, 0, 0], 1),
            ] as $call) {
                try {
                    $call();
                    $this->fail('a different embedder was accepted');
                } catch (GraphException) {
                }
            }
        }
        $this->assertCount(3, $this->store->nearest($this->spec, [1, 0, 0], 10));
    }

    public function test_bad_vectors_are_refused(): void
    {
        foreach ([[1, 0], [1, 0, 0, 0], [0, 0, 0], [NAN, 0, 0], [INF, 0, 0], ['x' => 1, 'y' => 0, 'z' => 0]] as $bad) {
            try {
                $this->store->upsert($this->spec, ['bad' => $bad]);
                $this->fail('a bad vector was stored: '.json_encode($bad, JSON_PARTIAL_OUTPUT_ON_ERROR));
            } catch (GraphException) {
            }
        }
        $this->assertCount(3, $this->store->nearest($this->spec, [1, 0, 0], 10));
    }

    public function test_k_is_bounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->nearest($this->spec, [1, 0, 0], PdoVectorStore::MAX_K + 1);
    }
}
