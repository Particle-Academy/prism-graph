<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunk;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Chunking\WindowChunker;
use Prism\Graph\Contracts\Chunker;

final class ChunkingTest extends TestCase
{
    /** @return iterable<string, array{Chunker, string}> */
    public static function inputs(): iterable
    {
        $md = "# Title\n\nIntro paragraph.\n\n## Part A\n\n".str_repeat('Alpha beta gamma. ', 200)
            ."\n\n```\n# not a heading\n```\n\n### Deep\n\nTail ünïcödé text — done.\n";
        $oneLine = str_repeat('x', 5000);
        $utf8 = str_repeat('日本語のテキスト', 400);

        yield 'markdown' => [new MarkdownChunker(300, 400, 50), $md];
        yield 'window long line' => [new WindowChunker(300, 400, 50), $oneLine];
        yield 'window utf8' => [new WindowChunker(300, 400, 50), $utf8];
        yield 'no overlap' => [new WindowChunker(300, 400, 0), $md];
    }

    #[DataProvider('inputs')]
    public function test_every_chunk_is_verbatim_and_spans_cover_the_whole_source(Chunker $chunker, string $text): void
    {
        $chunks = $chunker->chunk('src-1', $text);
        $this->assertNotEmpty($chunks);
        $covered = 0;
        foreach ($chunks as $chunk) {
            $this->assertSame(substr($text, $chunk->byteStart, $chunk->byteEnd - $chunk->byteStart), $chunk->text);
            $this->assertLessThanOrEqual($covered, $chunk->byteStart, 'gap before a chunk');
            $this->assertGreaterThan($chunk->byteStart, $chunk->byteEnd);
            $covered = max($covered, $chunk->byteEnd);
            $this->assertTrue(mb_check_encoding($chunk->text, 'UTF-8'), 'a chunk split a UTF-8 character');
        }
        $this->assertSame(strlen($text), $covered, 'source not fully covered');
    }

    public function test_ids_are_stable_and_change_with_the_recipe(): void
    {
        $text = str_repeat('Stable content. ', 300);
        $first = array_map(fn (Chunk $c) => $c->id, (new WindowChunker(300, 400, 50))->chunk('s', $text));
        $again = array_map(fn (Chunk $c) => $c->id, (new WindowChunker(300, 400, 50))->chunk('s', $text));
        $other = array_map(fn (Chunk $c) => $c->id, (new WindowChunker(301, 400, 50))->chunk('s', $text));

        $this->assertSame($first, $again);
        $this->assertEmpty(array_intersect($first, $other), 'a recipe change must change every id');
    }

    public function test_markdown_heading_path_handles_skipped_levels_and_ignores_fences(): void
    {
        $text = "# A\ntext\n### C\nunder c\n```\n## fenced\n```\n## B\nunder b\n";
        $paths = array_map(fn (Chunk $c) => $c->headingPath, (new MarkdownChunker(300, 400, 0))->chunk('s', $text));

        $this->assertSame([['A'], ['A', 'C'], ['A', 'B']], $paths);
    }

    public function test_lines_are_one_based_and_inclusive(): void
    {
        $chunks = (new MarkdownChunker(300, 400, 0))->chunk('s', "# H\nline2\n## I\nline4\n");

        $this->assertSame([1, 2], [$chunks[0]->lineStart, $chunks[0]->lineEnd]);
        $this->assertSame([3, 4], [$chunks[1]->lineStart, $chunks[1]->lineEnd]);
    }
}
