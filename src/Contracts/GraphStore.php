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
     * Idempotent per (scope, key). Returns the internal source id.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function putSource(Scope $scope, string $key, string $uri, string $sha256, array $metadata, Origin $origin, ?string $occurredAt = null): string;

    /** Content-addressed: an existing chunk id is left as is. @param list<Chunk> $chunks */
    public function putChunks(array $chunks): int;

    /** Retire every chunk of the source not in $keepIds; un-retire those in it. @param list<string> $keepIds */
    public function retireExcept(string $sourceId, array $keepIds): int;

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

    /** We were wrong: end the fact's RECORDED time. It stays answerable by knownAt. */
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
