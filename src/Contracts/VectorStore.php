<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\IndexSpec;

/**
 * Optional nearest-neighbour storage for chunk embeddings. A graph works fully
 * without one (full-text and traversal only). Implementations exist for
 * brute force over PDO, sqlite-vec and pgvector; any vector database can
 * implement this contract.
 */
interface VectorStore
{
    /** Create or open an index. Opening an existing index with a different embedder fingerprint is refused. */
    public function ensureIndex(IndexSpec $spec): void;

    /**
     * Store vectors for chunk ids. Idempotent per (index, chunk id).
     *
     * @param  array<string, list<float>>  $vectors  chunk id => vector
     */
    public function upsert(IndexSpec $spec, array $vectors): void;

    /**
     * The k nearest chunk ids by cosine similarity, best first.
     *
     * @param  list<float>  $query
     * @return list<array{chunk_id: string, score: float}>
     */
    public function nearest(IndexSpec $spec, array $query, int $k): array;

    /** Name of the search method, reported with results (e.g. "brute-force", "sqlite-vec", "pgvector"). */
    public function method(): string;
}
