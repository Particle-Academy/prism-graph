<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunk;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Chunking\WindowChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\IndexSpec;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Storage\PgVectorStore;
use Prism\Graph\Tools\MemoryTools;

/** One test per finding of the 0.1.0 pre-release security review, carried into 0.2. */
final class HardeningTest extends TestCase
{
    use Fixture;

    private ScopedGraph $memory;

    protected function setUp(): void
    {
        $this->freshPdo();
        $this->memory = $this->graph()->scope(self::acme()->owner('alice'));
    }

    public function test_vectors_that_underflow_or_overflow_float32_are_refused(): void
    {
        $store = new PdoVectorStore($this->pdo);
        $spec = new IndexSpec('main', 't', 'm', 2);
        $store->ensureIndex($spec);
        $store->upsert($spec, [str_repeat('a', 64) => [1.0, 0.0]]);

        foreach ([[1e-46, 1e-46], [1e39, 1.0]] as $bad) {
            try {
                $store->upsert($spec, [str_repeat('b', 64) => $bad]);
                $this->fail('a vector that is zero or infinite as float32 was stored');
            } catch (GraphException) {
            }
        }
        $this->assertSame(str_repeat('a', 64), $store->nearest($spec, [1.0, 0.0], 1)[0]['chunk_id']);
    }

    public function test_a_stored_row_that_cannot_be_scored_does_not_break_search(): void
    {
        $store = new PdoVectorStore($this->pdo);
        $spec = new IndexSpec('main', 't', 'm', 2);
        $store->ensureIndex($spec);
        $store->upsert($spec, [str_repeat('a', 64) => [1.0, 0.0]]);
        $this->pdo->prepare('INSERT INTO graph_vectors (index_name, chunk_id, vector) VALUES (?, ?, ?)')
            ->execute(['main', str_repeat('z', 64), pack('g*', 0.0, 0.0)]);

        $this->assertSame([str_repeat('a', 64)], array_column($store->nearest($spec, [1.0, 0.0], 5), 'chunk_id'));
    }

    public function test_queries_are_bounded_before_reaching_the_provider_or_the_database(): void
    {
        $this->memory->ingest('d', 'f://d', "A heron by the river.\n", new MarkdownChunker, self::user());

        $this->expectException(InvalidArgumentException::class);
        $this->memory->search(str_repeat('heron ', 1000));
    }

    public function test_a_single_term_at_the_query_cap_is_answered_not_crashed(): void
    {
        $this->memory->ingest('d', 'f://d', "A heron by the river.\n", new MarkdownChunker, self::user());

        $this->assertSame([], $this->memory->search(str_repeat('x', ScopedGraph::MAX_QUERY_BYTES)));
    }

    public function test_the_tools_turn_every_failure_into_a_bounded_error_without_internals(): void
    {
        $tools = new MemoryTools($this->memory, 'agent:support');
        $this->assertArrayHasKey('error', json_decode($tools->search(str_repeat('a ', 5000)), true));

        $this->pdo->exec('DROP TABLE graph_fact_evidence');
        $this->pdo->exec('DROP TABLE graph_facts');
        $error = json_decode($tools->facts('anything'), true)['error'];
        $this->assertStringNotContainsString('graph_facts', $error);
        $this->assertStringNotContainsStringIgnoringCase('sql', $error);
    }

    public function test_invisible_characters_reach_the_model_visibly(): void
    {
        $hidden = "\u{E0049}\u{E0047}\u{E004E}";
        $this->memory->ingest('d', 'f://d', "Opening hours{$hidden} are 9 to 5, \u{202E}reversed.\n", new MarkdownChunker, self::user());

        $text = json_decode((new MemoryTools($this->memory, 'agent:support'))->search('opening hours'), true)['results'][0]['text'];
        $this->assertStringNotContainsString("\u{E0049}", $text);
        $this->assertStringNotContainsString("\u{202E}", $text);
        $this->assertStringContainsString('<U+E0049>', $text);
        $this->assertStringContainsString('<U+202E>', $text);
    }

    public function test_pgvector_literals_ignore_the_process_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        $german = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'German_Germany.1252', 'German');
        if ($german === false) {
            if (getenv('PRISM_GRAPH_REQUIRE_LOCALE') === '1') {
                $this->fail('PRISM_GRAPH_REQUIRE_LOCALE=1 but no German locale is installed.');
            }
            $this->markTestSkipped('No German locale installed to prove this with.');
        }
        try {
            $this->assertSame('0,5', sprintf('%.1f', 0.5), 'the locale did not take effect, so this proves nothing');
            $store = (new \ReflectionClass(PgVectorStore::class))->newInstanceWithoutConstructor();
            $literal = (new \ReflectionMethod(PgVectorStore::class, 'literal'))->invoke($store, new IndexSpec('m', 't', 'm', 2), [0.5, 0.25]);
            $this->assertSame('[0.5,0.25]', $literal);
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    public function test_hashes_cannot_be_forged_by_moving_a_separator_between_fields(): void
    {
        $this->assertNotSame(
            (new IndexSpec('i', 'openai', "x\x1f8", 4))->fingerprint(),
            (new IndexSpec('i', 'openai', 'x', 8, "4\x1fraw@1"))->fingerprint(),
        );
        $this->assertNotSame(Chunk::idFor("r\x1fs", 't', 0, 1, 'a'), Chunk::idFor('r', "s\x1ft", 0, 1, 'a'));
        $this->assertNotSame((new Scope('a', 'b/c'))->qualify('source', 'd'), (new Scope('a', 'b'))->qualify('source', 'c/d'));
    }

    public function test_a_chunk_whose_text_is_not_its_span_is_refused(): void
    {
        $sourceId = $this->store()->putSource(self::acme(), 'd', 'f://d', hash('sha256', 'x'), [], self::user());
        $text = 'the real text';
        $lie = new Chunk(Chunk::idFor('r', $sourceId, 0, 3, $text), $sourceId, $text, 0, 3, 1, 1, [], 'r');

        $this->expectException(GraphException::class);
        $this->store()->putChunks([$lie]);
    }

    public function test_times_compare_correctly_whatever_their_precision(): void
    {
        $this->memory->ingest('d', 'f://d', "Shop closes Mondays.\n", new MarkdownChunker, self::user());
        $chunk = $this->memory->search('closes')[0]->chunk;

        $this->memory->assert(new Fact('f1', 'shop', 'closes', 'monday', [$chunk->id], '2026-01-01T00:00:00.9Z'));
        try {
            $this->memory->supersede('f1', new Fact('f2', 'shop', 'closes', 'sunday', [$chunk->id], '2026-01-01T00:00:00Z'));
            $this->fail('a replacement starting before the fact it replaces was accepted');
        } catch (GraphException) {
        }
        $this->assertSame([], $this->memory->facts('shop', '2026-01-01T00:00:00.5Z'));
        $this->assertSame('f1', $this->memory->facts('shop', '2026-01-01T00:00:01Z')[0]->id);
    }

    public function test_impossible_dates_and_inverted_windows_are_refused(): void
    {
        $this->memory->ingest('d', 'f://d', "Shop closes Mondays.\n", new MarkdownChunker, self::user());
        $id = $this->memory->search('closes')[0]->chunk->id;

        foreach ([
            new Fact('a', 's', 'p', 'o', [$id], '2026-13-99T99:99:99Z'),
            new Fact('b', 's', 'p', 'o', [$id], "2026-01-01T00:00:00Z\n"),
            new Fact('c', 's', 'p', 'o', [$id], '2026-06-01T00:00:00Z', '2026-01-01T00:00:00Z'),
            new Fact('d', 's', 'p', 'o', [$id], '2026-01-01T00:00:00Z', null, 'nothing'),
        ] as $bad) {
            try {
                $this->memory->assert($bad);
                $this->fail("fact [{$bad->id}] was accepted");
            } catch (GraphException|InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->memory->facts('s', unaccepted: true));
    }

    public function test_a_window_chunker_cannot_be_configured_to_split_a_character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WindowChunker(1, 2, 0);
    }
}
