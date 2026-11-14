<?php

declare(strict_types=1);

namespace Prism\Graph;

/**
 * What an erasure removed, as counts that can be shown as evidence: "delete
 * this person's data" is a claim someone may later have to prove.
 */
final readonly class EraseReport
{
    /**
     * @param  list<string>  $chunkIds  the erased chunk ids (vectors are removed by these)
     */
    public function __construct(
        public bool $sourceFound,
        public int $chunks,
        public int $evidenceLinks,
        public int $factsErased,
        public int $factsKept,
        public array $chunkIds = [],
        public int $vectors = 0,
    ) {}

    public function withVectors(int $vectors): self
    {
        return new self($this->sourceFound, $this->chunks, $this->evidenceLinks, $this->factsErased, $this->factsKept, $this->chunkIds, $vectors);
    }
}
