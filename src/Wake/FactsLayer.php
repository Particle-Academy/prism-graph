<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

use Prism\Graph\Fact;
use Prism\Graph\Graph;

/** Built-in layer: facts in force about the given subjects. */
final readonly class FactsLayer implements WakeLayer
{
    /** @param list<string> $subjects */
    public function __construct(private string $name, private array $subjects) {}

    public function name(): string
    {
        return $this->name;
    }

    public function items(Graph $graph): array
    {
        $items = [];
        foreach ($this->subjects as $subject) {
            foreach ($graph->facts($subject) as $fact) {
                /** @var Fact $fact */
                $items[] = new WakeItem("{$fact->subject} {$fact->predicate} {$fact->object}", 'fact:'.$fact->id);
            }
        }

        return $items;
    }
}
