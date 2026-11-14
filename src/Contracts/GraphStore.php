<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\Chunk;
use Prism\Graph\Fact;

/**
 * Persistence for the graph's core columns. Reads never mutate stored data.
 */
interface GraphStore
{
    /** Idempotent: a source with the same id is updated only in sha256/uri/metadata. @param array<string, mixed> $metadata */
    public function putSource(string $id, string $uri, string $sha256, array $metadata = []): void;

    /** Idempotent: chunks are content-addressed, so an existing id is left as is. @param list<Chunk> $chunks */
    public function putChunks(array $chunks): int;

    /**
     * Chunks by id, in the order asked. Unknown ids are omitted.
     *
     * @param  list<string>  $ids
     * @return list<Chunk>
     */
    public function getChunks(array $ids): array;

    /** @return list<Chunk> */
    public function chunksForSource(string $sourceId): array;

    public function putNode(string $id, string $type): void;

    public function putEdge(string $id, string $from, string $to, string $type): void;

    /** Refuses a fact whose evidence cites no existing chunk. */
    public function assertFact(Fact $fact): void;

    /** Atomically closes $oldId's validity window and records $replacement in one transaction. */
    public function supersede(string $oldId, Fact $replacement): void;

    /**
     * Facts about a subject. $at null means facts in force now; otherwise those valid at $at.
     *
     * @return list<Fact>
     */
    public function factsAbout(string $subject, ?string $at = null): array;
}
