<?php

declare(strict_types=1);

namespace Prism\Graph;

/**
 * A subject-predicate-object statement that holds for [validFrom, validTo).
 * validTo null means still in force. Every fact cites at least one chunk.
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
    ) {}

    public function inForce(): bool
    {
        return $this->validTo === null;
    }
}
