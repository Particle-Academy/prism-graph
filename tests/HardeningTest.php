<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunk;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Chunking\WindowChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Storage\PgVectorStore;
use Prism\Graph\Tools\GraphTools;

/** One test per finding of the 0.1.0 pre-release security review. */
final class HardeningTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach ([...BaseSchema::statements('sqlite'), ...PdoVectorStore::schema('sqlite')] as $sql) {
            $this->pdo->exec($sql);
        }
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
        // And the index still answers.
        $this->assertSame(str_repeat('a', 64), $store->nearest($spec, [1.0, 0.0], 1)[0]['chunk_id']);
    }

    public function test_a_stored_row_that_cannot_be_scored_does_not_break_search(): void
    {
        $store = new PdoVectorStore($this->pdo);
        $spec = new IndexSpec('main', 't', 'm', 2);
        $store->ensureIndex($spec);
        $store->upsert($spec, [str_repeat('a', 64) => [1.0, 0.0]]);
        // Written behind the store's back, e.g. by an older version.
        $this->pdo->prepare('INSERT INTO graph_vectors (index_name, chunk_id, vector) VALUES (?, ?, ?)')
            ->execute(['main', str_repeat('z', 64), pack('g*', 0.0, 0.0)]);

        $this->assertSame([str_repeat('a', 64)], array_column($store->nearest($spec, [1.0, 0.0], 5), 'chunk_id'));
    }

    public function test_queries_are_bounded_before_reaching_the_provider_or_the_database(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('d', 'file://d', "A heron by the river.\n", new MarkdownChunker);

        $this->expectException(InvalidArgumentException::class);
        $graph->search(str_repeat('heron ', 1000));
    }

    public function test_a_single_term_at_the_query_cap_is_answered_not_crashed(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('d', 'file://d', "A heron by the river.\n", new MarkdownChunker);

        $this->assertSame([], $graph->search(str_repeat('x', Graph::MAX_QUERY_BYTES)));
    }

    public function test_the_tools_turn_every_failure_into_a_bounded_error_without_internals(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $tools = new GraphTools($graph);

        $this->assertArrayHasKey('error', json_decode($tools->search(str_repeat('a ', 5000)), true));

        $this->pdo->exec('DROP TABLE graph_fact_evidence');
        $this->pdo->exec('DROP TABLE graph_facts');
        $error = json_decode($tools->facts('anything'), true)['error'];
        $this->assertStringNotContainsString('graph_facts', $error);
        $this->assertStringNotContainsStringIgnoringCase('sql', $error);
    }

    public function test_invisible_characters_reach_the_model_visibly(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $hidden = "\u{E0049}\u{E0047}\u{E004E}"; // tag characters, invisible when rendered
        $graph->ingest('d', 'file://d', "Opening hours{$hidden} are 9 to 5, \u{202E}reversed.\n", new MarkdownChunker);

        $text = json_decode((new GraphTools($graph))->search('opening hours'), true)['results'][0]['text'];
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
    }

    public function test_a_chunk_whose_text_is_not_its_span_is_refused(): void
    {
        $store = new PdoGraphStore($this->pdo);
        $store->putSource('d', 'file://d', hash('sha256', 'x'));
        $text = 'the real text';
        $lie = new Chunk(Chunk::idFor('r', 'd', 0, 3, $text), 'd', $text, 0, 3, 1, 1, [], 'r');

        $this->expectException(GraphException::class);
        $store->putChunks([$lie]);
    }

    public function test_times_compare_correctly_whatever_their_precision(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('d', 'file://d', "Shop closes Mondays.\n", new MarkdownChunker);
        $chunk = $graph->search('closes')[0]->chunk;

        $graph->assert(new Fact('f1', 'shop', 'closes', 'monday', [$chunk->id], '2026-01-01T00:00:00.9Z'));
        // As strings, "...00Z" sorts AFTER "...00.9Z"; as times it is before.
        try {
            $graph->supersede('f1', new Fact('f2', 'shop', 'closes', 'sunday', [$chunk->id], '2026-01-01T00:00:00Z'));
            $this->fail('a replacement starting before the fact it replaces was accepted');
        } catch (GraphException) {
        }
        $this->assertSame([], $graph->facts('shop', '2026-01-01T00:00:00.5Z'));
        $this->assertSame('f1', $graph->facts('shop', '2026-01-01T00:00:01Z')[0]->id);
    }

    public function test_impossible_dates_and_inverted_windows_are_refused(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('d', 'file://d', "Shop closes Mondays.\n", new MarkdownChunker);
        $id = $graph->search('closes')[0]->chunk->id;

        foreach ([
            new Fact('a', 's', 'p', 'o', [$id], '2026-13-99T99:99:99Z'),
            new Fact('b', 's', 'p', 'o', [$id], "2026-01-01T00:00:00Z\n"),
            new Fact('c', 's', 'p', 'o', [$id], '2026-06-01T00:00:00Z', '2026-01-01T00:00:00Z'),
            new Fact('d', 's', 'p', 'o', [$id], '2026-01-01T00:00:00Z', null, 'nothing'),
        ] as $bad) {
            try {
                $graph->assert($bad);
                $this->fail("fact [{$bad->id}] was accepted");
            } catch (GraphException|InvalidArgumentException) {
            }
        }
        $this->assertSame([], $graph->facts('s'));
    }

    public function test_a_window_chunker_cannot_be_configured_to_split_a_character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WindowChunker(1, 2, 0);
    }

    public function test_text_removed_from_a_source_stops_being_served_but_stays_citable(): void
    {
        $graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $graph->ingest('d', 'file://d', "# Plan\nPublic roadmap.\n# Draft\nThe secret codename is heron.\n", new MarkdownChunker(300, 400, 0));
        $secret = $graph->search('secret codename')[0]->chunk;
        $graph->assert(new Fact('f', 'project', 'codename', 'heron', [$secret->id], '2026-01-01T00:00:00Z'));

        $report = $graph->ingest('d', 'file://d', "# Plan\nPublic roadmap.\n", new MarkdownChunker(300, 400, 0));
        $this->assertSame(1, $report->retired);
        $this->assertSame([], $graph->search('secret codename'));
        $this->assertNotNull($graph->get([$secret->id])[0]->retiredAt, 'a citation must still resolve');
        $this->assertSame([$secret->id], $graph->facts('project')[0]->evidence);

        // Restoring the text brings it back.
        $graph->ingest('d', 'file://d', "# Plan\nPublic roadmap.\n# Draft\nThe secret codename is heron.\n", new MarkdownChunker(300, 400, 0));
        $this->assertSame($secret->id, $graph->search('secret codename')[0]->chunk->id);

        $this->assertSame(2, $graph->retireSource('d'));
        $this->assertSame([], $graph->search('roadmap'));
    }
}
