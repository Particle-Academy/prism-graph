<?php

declare(strict_types=1);

namespace Prism\Graph;

/** A search result: always the verbatim chunk, plus how it was found. */
final readonly class Hit
{
    public function __construct(
        public Chunk $chunk,
        public float $score,
        public string $method,
    ) {}
}
