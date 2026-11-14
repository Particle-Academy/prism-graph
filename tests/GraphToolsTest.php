<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Tools\GraphTools;

final class GraphToolsTest extends TestCase
{
    private PDO $pdo;

    private Graph $graph;

    private GraphTools $tools;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach (BaseSchema::statements('sqlite') as $sql) {
            $this->pdo->exec($sql);
        }
        $this->graph = new Graph(new PdoGraphStore($this->pdo), $this->pdo);
        $this->graph->ingest('doc', 'file://doc.md', "# Bakery\nWe bake sourdough daily.\n## Hours\nOpen 7 to 3, closed Mondays.\n", new MarkdownChunker(300, 400, 0));
        $this->tools = new GraphTools($this->graph);
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_search_returns_verbatim_text_with_citable_ids_and_get_reads_them_back(): void
    {
        $results = $this->decode($this->tools->search('closed mondays'))['results'];
        $this->assertCount(1, $results);
        $this->assertSame('Open 7 to 3, closed Mondays.', trim(explode("\n", $results[0]['text'], 2)[1]));

        $chunks = $this->decode($this->tools->get($results[0]['id']))['chunks'];
        $this->assertSame($results[0]['text'], $chunks[0]['text']);
    }

    public function test_limits_are_clamped_whatever_the_model_asks_for(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->graph->ingest("d{$i}", "file://d{$i}", "Shared term number {$i}.\n", new MarkdownChunker);
        }
        $this->assertCount(GraphTools::MAX_RESULTS, $this->decode($this->tools->search('shared term', 10_000))['results']);
        $this->assertCount(1, $this->decode($this->tools->search('shared term', -5))['results']);
    }

    public function test_get_refuses_malformed_or_too_many_ids_instead_of_querying(): void
    {
        $this->assertArrayHasKey('error', $this->decode($this->tools->get("x' OR 1=1 --")));
        $this->assertArrayHasKey('error', $this->decode($this->tools->get('')));
        $this->assertArrayHasKey('error', $this->decode($this->tools->get(implode(',', array_fill(0, GraphTools::MAX_IDS + 1, str_repeat('a', 64))))));
    }

    public function test_facts_cite_evidence_and_a_bad_time_is_an_error_not_a_crash(): void
    {
        $chunk = $this->graph->search('sourdough')[0]->chunk;
        $this->graph->assert(new Fact('f1', 'bakery', 'bakes', 'sourdough', [$chunk->id], '2026-01-01T00:00:00Z'));

        $facts = $this->decode($this->tools->facts('bakery'))['facts'];
        $this->assertSame([$chunk->id], $facts[0]['evidence']);
        $this->assertArrayHasKey('error', $this->decode($this->tools->facts('bakery', 'yesterday')));
    }

    public function test_the_tools_never_write(): void
    {
        $count = fn () => array_map(fn ($t) => (int) $this->pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(), ['graph_sources', 'graph_chunks', 'graph_facts']);
        $before = $count();
        $this->tools->search('bakery');
        $this->tools->get(str_repeat('a', 64));
        $this->tools->facts('bakery');
        $this->assertSame($before, $count());
    }
}
