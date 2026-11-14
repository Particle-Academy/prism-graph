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
 * VectorStore on PostgreSQL with the pgvector extension. Distance is computed
 * in the database (cosine, `<=>`). The vector column is unsized so one table
 * serves several indexes; add a sized column plus an HNSW or IVFFlat index in
 * your own migration when an index grows large.
 */
final class PgVectorStore implements VectorStore
{
    public const MAX_K = 1000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $indexes = 'graph_vector_indexes',
        private readonly string $vectors = 'graph_pgvectors',
    ) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgumentException('PgVectorStore needs a PostgreSQL connection.');
        }
        foreach ([$indexes, $vectors] as $table) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid table name.');
            }
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Reference schema; requires the pgvector extension. @return list<string> */
    public static function schema(string $indexes = 'graph_vector_indexes', string $vectors = 'graph_pgvectors'): array
    {
        return [
            'CREATE EXTENSION IF NOT EXISTS vector',
            "CREATE TABLE {$indexes} (name VARCHAR(100) PRIMARY KEY, fingerprint CHAR(64) NOT NULL, provider VARCHAR(100) NOT NULL,
                model VARCHAR(191) NOT NULL, dimensions INTEGER NOT NULL, recipe VARCHAR(191) NOT NULL)",
            "CREATE TABLE {$vectors} (index_name VARCHAR(100) NOT NULL REFERENCES {$indexes} (name), chunk_id CHAR(64) NOT NULL,
                embedding vector NOT NULL, PRIMARY KEY (index_name, chunk_id))",
        ];
    }

    public function method(): string
    {
        return 'pgvector';
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
        $statement = $this->pdo->prepare(
            "INSERT INTO {$this->vectors} (index_name, chunk_id, embedding) VALUES (?, ?, CAST(? AS vector))
             ON CONFLICT (index_name, chunk_id) DO UPDATE SET embedding = excluded.embedding",
        );
        foreach ($vectors as $chunkId => $vector) {
            $statement->execute([$spec->name, (string) $chunkId, $this->literal($spec, $vector)]);
        }
    }

    public function nearest(IndexSpec $spec, array $query, int $k): array
    {
        $this->assertPinned($spec, $this->fingerprintOf($spec->name));
        if ($k < 1 || $k > self::MAX_K) {
            throw new InvalidArgumentException('k must be between 1 and '.self::MAX_K.'.');
        }
        $statement = $this->pdo->prepare(
            "SELECT chunk_id, 1 - (embedding <=> CAST(? AS vector)) AS score FROM {$this->vectors}
             WHERE index_name = ? ORDER BY embedding <=> CAST(? AS vector), chunk_id LIMIT ".$k,
        );
        $literal = $this->literal($spec, $query);
        $statement->execute([$literal, $spec->name, $literal]);

        return array_map(
            static fn (array $row) => ['chunk_id' => trim((string) $row['chunk_id']), 'score' => (float) $row['score']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
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

    private function fingerprintOf(string $name): ?string
    {
        $statement = $this->pdo->prepare("SELECT fingerprint FROM {$this->indexes} WHERE name = ?");
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function assertPinned(IndexSpec $spec, ?string $stored): void
    {
        if ($stored === null) {
            throw new GraphException("Index [{$spec->name}] does not exist; call ensureIndex() first.");
        }
        if (! hash_equals($stored, $spec->fingerprint())) {
            throw new GraphException("Index [{$spec->name}] is pinned to a different embedder; vectors are never mixed.");
        }
    }

    /** A pgvector text literal built only from validated finite numbers. @param list<float> $vector */
    private function literal(IndexSpec $spec, array $vector): string
    {
        // %H, not %g: %g follows the C locale, and under de_DE "0.5" becomes
        // "0,5", which pgvector's unsized column reads as two dimensions.
        $parts = array_map(static fn (float $x) => sprintf('%.9H', $x), Vectors::float32($spec, $vector));

        return '['.implode(',', $parts).']';
    }
}
