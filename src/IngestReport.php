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
        // Chunks of this source that are retired after the ingest: text the
        // source no longer contains. They leave search; ids still resolve.
        public int $retired = 0,
    ) {}
}
