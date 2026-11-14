<?php

declare(strict_types=1);

namespace Prism\Graph\Chunking;

use InvalidArgumentException;
use Prism\Graph\Chunk;
use Prism\Graph\Contracts\Chunker;

/**
 * Splits text into overlapping byte windows that never break a UTF-8 character
 * and prefer paragraph, then sentence, then word boundaries. Every chunk's text
 * is a substring of the source, so chunks are always verbatim.
 *
 * Sizes are in BYTES. Subclasses decide the sections windows may not cross.
 */
class WindowChunker implements Chunker
{
    public function __construct(
        protected int $target = 1200,
        protected int $max = 1500,
        protected int $overlap = 150,
    ) {
        if ($target < 1 || $max < $target || $overlap < 0 || $overlap >= $target) {
            throw new InvalidArgumentException('Chunk sizes need 0 <= overlap < target <= max.');
        }
    }

    public function recipe(): string
    {
        return sprintf('%s@1;target=%d;max=%d;overlap=%d', $this->name(), $this->target, $this->max, $this->overlap);
    }

    protected function name(): string
    {
        return 'window';
    }

    public function chunk(string $sourceId, string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = $this->lineStarts($text);
        $chunks = [];
        foreach ($this->sections($text) as [$start, $end, $path]) {
            foreach ($this->windows($text, $start, $end) as [$s, $e]) {
                $slice = substr($text, $s, $e - $s);
                $chunks[] = new Chunk(
                    Chunk::idFor($this->recipe(), $sourceId, $s, $e, $slice),
                    $sourceId, $slice, $s, $e,
                    $this->lineAt($lines, $s), $this->lineAt($lines, $e - 1),
                    $path, $this->recipe(),
                );
            }
        }

        return $chunks;
    }

    /**
     * Sections windows may not cross: [start, end, headingPath].
     *
     * @return list<array{int, int, list<string>}>
     */
    protected function sections(string $text): array
    {
        return [[0, strlen($text), []]];
    }

    /** @return list<array{int, int}> */
    private function windows(string $text, int $start, int $end): array
    {
        $windows = [];
        $pos = $start;
        while ($pos < $end) {
            $stop = $end - $pos <= $this->max ? $end : $this->breakAt($text, $pos, $end);
            $windows[] = [$pos, $stop];
            if ($stop >= $end) {
                break;
            }
            $next = $this->charBoundary($text, max($pos + 1, $stop - $this->overlap));
            // Start the overlap on a word boundary, never inside a word.
            $space = strpos($text, ' ', $next);
            if ($this->overlap > 0 && $space !== false && $space < $stop) {
                $next = $space + 1;
            }
            $pos = $next > $pos ? $next : $stop;
        }

        return $windows;
    }

    private function breakAt(string $text, int $pos, int $end): int
    {
        $limit = min($pos + $this->max, $end);
        $floor = $pos + intdiv($this->target, 2);
        $window = substr($text, $pos, $limit - $pos);
        foreach (["\n\n", ". ", "! ", "? ", "\n", ' '] as $mark) {
            $at = strrpos($window, $mark);
            if ($at !== false && $pos + $at + strlen($mark) > $floor) {
                return $pos + $at + strlen($mark);
            }
        }

        // Hard cut, on a character boundary.
        return max($pos + 1, $this->charBoundary($text, $limit));
    }

    /** Move back to the start of a UTF-8 character. */
    private function charBoundary(string $text, int $at): int
    {
        while ($at > 0 && $at < strlen($text) && (ord($text[$at]) & 0xC0) === 0x80) {
            $at--;
        }

        return $at;
    }

    /** @return list<int> */
    private function lineStarts(string $text): array
    {
        $starts = [0];
        $offset = 0;
        while (($nl = strpos($text, "\n", $offset)) !== false) {
            $starts[] = $nl + 1;
            $offset = $nl + 1;
        }

        return $starts;
    }

    /** 1-based line of a byte offset. @param list<int> $starts */
    private function lineAt(array $starts, int $byte): int
    {
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $starts[$mid] <= $byte ? $lo = $mid : $hi = $mid - 1;
        }

        return $lo + 1;
    }
}
