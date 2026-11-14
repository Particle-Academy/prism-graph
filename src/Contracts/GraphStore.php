<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\Chunk;
use Prism\Graph\EraseReport;
use Prism\Graph\Fact;
use Prism\Graph\Origin;
use Prism\Graph\Scope;

/**
 * Storage for scoped memory. Every read takes the READER's scope and returns
 * only rows visible from it (see Scope); every write takes the scope it writes
 * at. There is deliberately no method that reads across scopes.
 */
interface GraphStore
{
    /**
     * Store a source and its verbatim chunks at $scope, in ONE transaction:
     * the source row, every new chunk (content-addressed, so existing ones are
     * left as they are), and retirement of chunks the source no longer
     * contains. Every chunk must belong to this source
     * (sourceId === $scope->qualify('source', $key)).
     *
     * A source's trust is fixed when it is first stored: storing the same key
     * with a different trust is refused (erase, then ingest again).
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<Chunk>  $chunks
     * @return array{source: string, stored: int, retired: int}
     */
    public function storeSource(Scope $scope, string $key, string $uri, string $sha256, array $metadata, Origin $origin, ?string $occurredAt, array $chunks): array;

    /** Retire every chunk of a source stored at exactly $scope. Returns how many are retired. */
    public function retireSource(Scope $scope, string $key): int;

    /** Chunks visible from $reader, in the order asked. @param list<string> $ids @return list<Chunk> */
    public function getChunks(Scope $reader, array $ids): array;

    /**
     * Full-text search over live chunks visible from $reader, best first.
     *
     * @return list<array{chunk_id: string, score: float}>
     */
    public function textSearch(Scope $reader, string $query, int $limit): array;

    /** Store a fact at $scope with the status the policy decides. Returns it as stored. */
    public function assertFact(Scope $scope, Fact $fact, AcceptancePolicy $policy): Fact;

    /** The world changed: close $oldKey's valid time and record the replacement, atomically. */
    public function supersede(Scope $scope, string $oldKey, Fact $replacement, AcceptancePolicy $policy): Fact;

    /**
     * We were wrong: end the fact's RECORDED time. It stays answerable by knownAt.
     *
     * An APP decision, never a tool. Unlike supersede() it does NOT check the
     * acceptance policy: retracting an accepted fact and asserting a new one
     * bypasses the overwrite guard supersede() enforces, and can replace an
     * accepted fact with weaker evidence. Do that only as a deliberate app
     * decision; to record that the world changed, use supersede().
     */
    public function retract(Scope $scope, string $key): void;

    /** An app decision: a proposed fact becomes accepted. */
    public function accept(Scope $scope, string $key): void;

    /**
     * Facts about $subject visible from $reader.
     *
     * @param  ?string  $at  valid time ("what held then"); null means in force now
     * @param  ?string  $knownAt  recorded time ("what memory held then"); null means now
     * @param  bool  $unaccepted  include proposals and beliefs, labelled by status
     * @return list<Fact>
     */
    public function facts(Scope $reader, string $subject, ?string $at = null, ?string $knownAt = null, bool $unaccepted = false): array;

    /** Every version of every fact about $subject visible from $reader, oldest first. @return list<Fact> */
    public function timeline(Scope $reader, string $subject): array;

    /**
     * Erase a source stored at exactly $scope: its chunks, their evidence
     * links, and every fact left with no evidence. Returns what went.
     */
    public function eraseSource(Scope $scope, string $key): EraseReport;
}
