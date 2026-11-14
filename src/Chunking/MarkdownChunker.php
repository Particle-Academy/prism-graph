<?php

declare(strict_types=1);

namespace Prism\Graph\Chunking;

/**
 * Markdown-aware: windows never cross an ATX heading (# .. ######), and each
 * chunk carries the heading path above it. Headings inside fenced code blocks
 * are ignored. The heading line belongs to the section it opens.
 */
final class MarkdownChunker extends WindowChunker
{
    protected function name(): string
    {
        return 'markdown';
    }

    protected function sections(string $text): array
    {
        $sections = [];
        $stack = [];
        $sectionStart = 0;
        $sectionPath = [];
        $offset = 0;
        $inFence = false;
        $length = strlen($text);

        while ($offset < $length) {
            $nl = strpos($text, "\n", $offset);
            $lineEnd = $nl === false ? $length : $nl + 1;
            $line = rtrim(substr($text, $offset, $lineEnd - $offset), "\r\n");

            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;
            } elseif (! $inFence && preg_match('/^\s{0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$/', $line, $m) === 1) {
                if ($offset > $sectionStart) {
                    $sections[] = [$sectionStart, $offset, $sectionPath];
                }
                // level => title; a heading closes every deeper level, even across skipped levels.
                $level = strlen($m[1]);
                $stack = array_filter($stack, static fn (int $l): bool => $l < $level, ARRAY_FILTER_USE_KEY);
                $stack[$level] = trim($m[2]);
                ksort($stack);
                $sectionStart = $offset;
                $sectionPath = array_values($stack);
            }
            $offset = $lineEnd;
        }
        if ($length > $sectionStart) {
            $sections[] = [$sectionStart, $length, $sectionPath];
        }

        return $sections;
    }
}
