<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

/** A piece of wake-up context, with where it came from. */
final readonly class WakeItem
{
    public function __construct(
        public string $text,
        public string $ref,
    ) {}
}
