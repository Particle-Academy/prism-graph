<?php

declare(strict_types=1);

namespace Prism\Graph;

final readonly class IngestReport
{
    public function __construct(
        public string $sourceId,
        public string $sha256,
        public string $recipe,
        public int $chunks,
        public int $stored,
    ) {}
}
