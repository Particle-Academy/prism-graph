<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\IndexSpec;

interface Embedder
{
    /** The embedder this produces vectors for; must match the index it writes to. */
    public function spec(): IndexSpec;

    /**
     * One vector per text, in order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;
}
