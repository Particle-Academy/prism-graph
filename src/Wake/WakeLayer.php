<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

use Prism\Graph\ScopedGraph;

/**
 * One app-defined layer of a wake-up context (e.g. identity, recent facts,
 * a scoped fetch, search). Returns candidate items best first; the assembler
 * enforces the budget, so a layer never has to.
 */
interface WakeLayer
{
    public function name(): string;

    /** @return list<WakeItem> */
    public function items(ScopedGraph $graph): array;
}
