<?php

declare(strict_types=1);

namespace Prism\Graph;

/**
 * One verbatim slice of a source. The text is exactly the source bytes in
 * [byteStart, byteEnd); nothing derived ever replaces it.
 */
final readonly class Chunk
{
    /**
     * @param  list<string>  $headingPath
     */
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $text,
        public int $byteStart,
        public int $byteEnd,
        public int $lineStart,
        public int $lineEnd,
        public array $headingPath,
        public string $recipe,
    ) {}

    /**
     * Content-addressed: the same recipe, source, span and text always give
     * the same id, and a recipe change always gives a different one.
     */
    public static function idFor(string $recipe, string $sourceId, int $byteStart, int $byteEnd, string $text): string
    {
        return hash('sha256', implode("\x1f", [$recipe, $sourceId, (string) $byteStart, (string) $byteEnd, $text]));
    }
}
