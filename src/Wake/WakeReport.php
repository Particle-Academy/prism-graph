<?php

declare(strict_types=1);

namespace Prism\Graph\Wake;

final readonly class WakeReport
{
    /**
     * @param  list<array{layer: string, item: WakeItem, tokens: int}>  $included
     * @param  array<string, string>  $omitted  layer => reason
     */
    public function __construct(
        public array $included,
        public array $omitted,
    ) {}

    public function tokens(): int
    {
        return array_sum(array_column($this->included, 'tokens'));
    }

    public function text(): string
    {
        return implode("\n\n", array_map(static fn (array $row) => $row['item']->text, $this->included));
    }
}
