<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\Chunk;

interface Chunker
{
    /** Name and version of the recipe, recorded in every chunk id (e.g. "markdown@1;target=1200;overlap=150"). */
    public function recipe(): string;

    /**
     * Split a source into verbatim chunks. Concatenating each chunk's byte span
     * must cover the whole source; overlap is allowed, gaps are not.
     *
     * @return list<Chunk>
     */
    public function chunk(string $sourceId, string $text): array;
}
