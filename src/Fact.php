<?php

declare(strict_types=1);

namespace Prism\Graph;

/**
 * A subject-predicate-object statement, bi-temporal:
 *  - VALID time [validFrom, validTo): when it held in the world;
 *  - RECORDED time [recordedAt, expiredAt): when the memory held it.
 * So "what was true in March" and "what did the agent believe in March" are
 * different questions with different answers, and both can be asked.
 *
 * Every fact cites at least one chunk. Its status is decided by the
 * acceptance policy from the trust of that evidence, never by the writer.
 *
 * The app passes id, subject, predicate, object, evidence and valid times;
 * the store fills status and recorded times.
 */
final readonly class Fact
{
    /**
     * @param  list<string>  $evidence  chunk ids
     */
    public function __construct(
        public string $id,
        public string $subject,
        public string $predicate,
        public string $object,
        public array $evidence,
        public string $validFrom,
        public ?string $validTo = null,
        public ?string $supersededBy = null,
        public ?FactStatus $status = null,
        public ?string $recordedAt = null,
        public ?string $expiredAt = null,
    ) {}

    public function inForce(): bool
    {
        return $this->validTo === null;
    }
}
