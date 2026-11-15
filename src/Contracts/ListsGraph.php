<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\Scope;

/**
 * Paginated, scoped listing of sources and their chunks (G-282), kept apart from GraphStore so an app's own store
 * does not have to implement it. Rows are already in the agreed shape (contract g282.1); every query applies the
 * scope's visibility predicate, so there is no unscoped listing.
 */
interface ListsGraph
{
    /**
     * Newest first. Only sources with at least one live chunk.
     *
     * @param  array{0: string, 1: string}|null  $after  [recordedAt, sourceId] of the last item already returned
     * @return list<array{sourceId: string, title: string, kind: string|null, createdAt: string, chunkCount: int, trust: string}>
     */
    public function listSources(Scope $reader, ?array $after, int $limit): array;

    /**
     * Live chunks of one visible source, in source order. A source outside the scope yields nothing.
     *
     * @return list<array{chunkId: string, sourceId: string, text: string, position: int, seq: int, provenance: array<string, mixed>}>
     */
    public function listChunks(Scope $reader, string $sourceId, ?int $afterSeq, int $limit): array;
}
