<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

use Prism\Graph\Hit;
use Prism\Graph\ScopedGraph;

/**
 * Built-in layer: verbatim chunks matching a query. Each item's ref names its
 * trust class, so in the fenced block a recalled tool result can be told apart
 * from something the user said.
 *
 * $vectors = false searches text only (no embedding-provider call), which
 * keeps a layer inside a latency budget.
 */
final readonly class SearchLayer implements WakeLayer
{
    public function __construct(
        private string $name,
        private string $query,
        private int $limit = 10,
        private bool $vectors = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function items(ScopedGraph $graph): array
    {
        return array_map(
            static fn (Hit $hit) => new WakeItem(
                $hit->chunk->text,
                'chunk:'.$hit->chunk->id.' trust:'.($hit->chunk->trust?->value ?? 'unknown'),
            ),
            $graph->search($this->query, $this->limit, $this->vectors),
        );
    }
}
