<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;

/**
 * Who wrote a memory and how far to trust it. Bound by the CALLER (the
 * harness adapter knows the session actor; the memory tools are constructed
 * with theirs), never taken from a model's tool arguments.
 */
final readonly class Origin
{
    public function __construct(public Trust $trust, public string $writer)
    {
        if (preg_match('/^[A-Za-z0-9_.:@\/-]{1,191}$/D', $writer) !== 1) {
            throw new InvalidArgumentException('A writer is 1-191 of A-Z a-z 0-9 _ . : @ / -');
        }
    }
}
