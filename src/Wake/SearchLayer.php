<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

use Prism\Graph\Graph;
use Prism\Graph\Hit;

/** Built-in layer: verbatim chunks matching a query. */
final readonly class SearchLayer implements WakeLayer
{
    public function __construct(private string $name, private string $query, private int $limit = 10) {}

    public function name(): string
    {
        return $this->name;
    }

    public function items(Graph $graph): array
    {
        return array_map(
            static fn (Hit $hit) => new WakeItem($hit->chunk->text, 'chunk:'.$hit->chunk->id),
            $graph->search($this->query, $this->limit),
        );
    }
}
