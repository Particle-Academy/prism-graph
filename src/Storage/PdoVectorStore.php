<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use PDO;
use Prism\Graph\Contracts\VectorStore;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;
use Prism\Graph\Support\Vectors;

/**
 * Exact (brute-force) cosine search over vectors stored as packed float32 in
 * SQLite or PostgreSQL. No extension needed; correct at small-to-medium scale.
 * Use the sqlite-vec or pgvector stores for larger indexes.
 */
class PdoVectorStore implements VectorStore
{
    public const MAX_K = 1000;

    public function __construct(
        protected readonly PDO $pdo,
        protected readonly string $indexes = 'graph_vector_indexes',
        protected readonly string $vectors = 'graph_vectors',
    ) {
        foreach ([$indexes, $vectors] as $table) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid table name.');
            }
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Reference schema; apps publish and own the matching migration. @return list<string> */
    public static function schema(string $driver, string $indexes = 'graph_vector_indexes', string $vectors = 'graph_vectors'): array
    {
        $blob = $driver === 'pgsql' ? 'BYTEA' : 'BLOB';

        return [
            "CREATE TABLE {$indexes} (name VARCHAR(100) PRIMARY KEY, fingerprint CHAR(64) NOT NULL, provider VARCHAR(100) NOT NULL,
                model VARCHAR(191) NOT NULL, dimensions INTEGER NOT NULL, recipe VARCHAR(191) NOT NULL)",
            "CREATE TABLE {$vectors} (index_name VARCHAR(100) NOT NULL REFERENCES {$indexes} (name), chunk_id CHAR(64) NOT NULL,
                vector {$blob} NOT NULL, PRIMARY KEY (index_name, chunk_id))",
        ];
    }

    public function method(): string
    {
        return 'brute-force';
    }

    public function ensureIndex(IndexSpec $spec): void
    {
        $existing = $this->fingerprintOf($spec->name);
        if ($existing === null) {
            $this->pdo->prepare("INSERT INTO {$this->indexes} (name, fingerprint, provider, model, dimensions, recipe) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$spec->name, $spec->fingerprint(), $spec->provider, $spec->model, $spec->dimensions, $spec->recipe]);

            return;
        }
        $this->assertPinned($spec, $existing);
    }

    public function upsert(IndexSpec $spec, array $vectors): void
    {
        $this->assertPinned($spec, $this->fingerprintOf($spec->name));
        $owns = ! $this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO {$this->vectors} (index_name, chunk_id, vector) VALUES (?, ?, ?)
                 ON CONFLICT (index_name, chunk_id) DO UPDATE SET vector = excluded.vector",
            );
            foreach ($vectors as $chunkId => $vector) {
                $statement->bindValue(1, $spec->name);
                $statement->bindValue(2, (string) $chunkId);
                $statement->bindValue(3, $this->pack($spec, $vector), PDO::PARAM_LOB);
                $statement->execute();
            }
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function nearest(IndexSpec $spec, array $query, int $k): array
    {
        $this->assertPinned($spec, $this->fingerprintOf($spec->name));
        if ($k < 1 || $k > self::MAX_K) {
            throw new InvalidArgumentException('k must be between 1 and '.self::MAX_K.'.');
        }
        $q = $this->unpack($this->pack($spec, $query));
        $qn = $this->norm($q);
        $statement = $this->pdo->prepare("SELECT chunk_id, vector FROM {$this->vectors} WHERE index_name = ?");
        $statement->execute([$spec->name]);
        $scores = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $bytes = is_resource($row['vector']) ? stream_get_contents($row['vector']) : $row['vector'];
            $v = $this->unpack((string) $bytes);
            $denominator = count($v) === count($q) ? $qn * $this->norm($v) : 0.0;
            // A row written by something other than this store can still be
            // unscorable. Skip it rather than fail every search: its chunk
            // stays reachable through the graph's text-search fill.
            if (! is_finite($denominator) || $denominator <= 0.0) {
                continue;
            }
            $dot = 0.0;
            foreach ($q as $i => $x) {
                $dot += $x * $v[$i];
            }
            $score = $dot / $denominator;
            if (is_finite($score)) {
                $scores[(string) $row['chunk_id']] = $score;
            }
        }
        arsort($scores);

        $out = [];
        foreach (array_slice($scores, 0, $k, true) as $id => $score) {
            $out[] = ['chunk_id' => (string) $id, 'score' => $score];
        }

        return $out;
    }

    public function missing(IndexSpec $spec, array $chunkIds): array
    {
        $this->assertPinned($spec, $this->fingerprintOf($spec->name));

        return Missing::filter($this->pdo, $this->vectors, $spec->name, $chunkIds);
    }

    public function forget(array $chunkIds): int
    {
        return Missing::forget($this->pdo, $this->vectors, $chunkIds);
    }

    protected function fingerprintOf(string $name): ?string
    {
        $statement = $this->pdo->prepare("SELECT fingerprint FROM {$this->indexes} WHERE name = ?");
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    protected function assertPinned(IndexSpec $spec, ?string $stored): void
    {
        if ($stored === null) {
            throw new GraphException("Index [{$spec->name}] does not exist; call ensureIndex() first.");
        }
        if (! hash_equals($stored, $spec->fingerprint())) {
            throw new GraphException("Index [{$spec->name}] is pinned to a different embedder; vectors are never mixed.");
        }
    }

    /** @param list<float> $vector */
    protected function pack(IndexSpec $spec, array $vector): string
    {
        return pack('g*', ...Vectors::float32($spec, $vector));
    }

    /** @return list<float> */
    private function unpack(string $bytes): array
    {
        return array_values(unpack('g*', $bytes));
    }

    /** @param list<float> $v */
    private function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }

        return sqrt($sum);
    }
}
