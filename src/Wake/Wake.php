<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

use InvalidArgumentException;
use Prism\Graph\ScopedGraph;

/**
 * Assembles layers in the order given, each under its own token budget, and
 * stops early when the overall time budget runs out. What doesn't fit is
 * reported as omitted, never silently dropped. Reads only.
 */
final class Wake
{
    /** @var list<array{WakeLayer, int}> */
    private array $layers = [];

    /** @param callable(string): int $tokens  token estimator; default is 4 bytes per token */
    public function __construct(private readonly ScopedGraph $graph, private $tokens = null)
    {
        $this->tokens ??= static fn (string $text): int => (int) ceil(strlen($text) / 4);
    }

    public function layer(WakeLayer $layer, int $tokenBudget): self
    {
        if ($tokenBudget < 0) {
            throw new InvalidArgumentException('A layer budget cannot be negative.');
        }
        $this->layers[] = [$layer, $tokenBudget];

        return $this;
    }

    public function assemble(int $timeBudgetMs): WakeReport
    {
        $deadline = hrtime(true) + $timeBudgetMs * 1_000_000;
        $included = [];
        $omitted = [];
        foreach ($this->layers as [$layer, $budget]) {
            if (hrtime(true) > $deadline) {
                $omitted[$layer->name()] = 'time budget reached before this layer';

                continue;
            }
            $used = 0;
            $skipped = 0;
            foreach ($layer->items($this->graph) as $item) {
                $cost = ($this->tokens)($item->text);
                if ($used + $cost > $budget) {
                    $skipped++;

                    continue;
                }
                $used += $cost;
                $included[] = ['layer' => $layer->name(), 'item' => $item, 'tokens' => $cost];
            }
            if ($skipped > 0) {
                $omitted[$layer->name()] = "{$skipped} item(s) over the {$budget}-token budget";
            }
        }

        return new WakeReport($included, $omitted);
    }
}
