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
use Prism\Graph\Wake\FactsLayer;
use Prism\Graph\Wake\SearchLayer;
use Prism\Graph\Wake\Wake;
use Prism\Graph\Wake\WakeItem;
use Prism\Graph\Wake\WakeLayer;

final class WakeTest extends TestCase
{
    private Graph $graph;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach (BaseSchema::statements('sqlite') as $sql) {
            $pdo->exec($sql);
        }
        $this->graph = new Graph(new PdoGraphStore($pdo), $pdo);
        $this->graph->ingest('doc', 'file://d', "# A\nsourdough bread daily\n## B\nsourdough starter care\n", new MarkdownChunker(300, 400, 0));
        $chunk = $this->graph->search('daily')[0]->chunk;
        $this->graph->assert(new Fact('f1', 'shop', 'sells', 'bread', [$chunk->id], '2026-01-01T00:00:00Z'));
    }

    public function test_layers_come_in_order_within_their_budgets_and_overflow_is_reported(): void
    {
        $identity = new class implements WakeLayer
        {
            public function name(): string
            {
                return 'identity';
            }

            public function items(Graph $graph): array
            {
                return [new WakeItem('I am the shop assistant.', 'app:persona')];
            }
        };

        $report = (new Wake($this->graph))
            ->layer($identity, 100)
            ->layer(new FactsLayer('facts', ['shop']), 100)
            ->layer(new SearchLayer('search', 'sourdough'), 8)
            ->assemble(1000);

        $this->assertSame(['identity', 'facts', 'search'], array_values(array_unique(array_column($report->included, 'layer'))));
        $search = array_values(array_filter($report->included, static fn (array $r) => $r['layer'] === 'search'));
        $this->assertCount(1, $search, 'only one chunk fits an 8-token budget');
        $this->assertArrayHasKey('search', $report->omitted);
        $this->assertStringContainsString('shop sells bread', $report->text());
    }

    public function test_a_zero_time_budget_includes_nothing_and_says_why(): void
    {
        $report = (new Wake($this->graph))->layer(new SearchLayer('search', 'sourdough'), 1000)->assemble(-1);

        $this->assertSame([], $report->included);
        $this->assertSame(['search' => 'time budget reached before this layer'], $report->omitted);
    }
}
