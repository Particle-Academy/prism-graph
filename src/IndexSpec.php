<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;
use Prism\Graph\Support\Digest;

/**
 * The embedder an index is pinned to. Vectors from a different provider,
 * model, dimension count or recipe are refused, never mixed into the index.
 */
final readonly class IndexSpec
{
    public function __construct(
        public string $name,
        public string $provider,
        public string $model,
        public int $dimensions,
        public string $recipe = 'raw@1',
    ) {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $name) !== 1) {
            throw new InvalidArgumentException('Index names are 1-100 of A-Z a-z 0-9 _ . : -');
        }
        if ($dimensions < 1 || $dimensions > 65536) {
            throw new InvalidArgumentException('Dimensions must be between 1 and 65536.');
        }
    }

    /** Stable identity of the embedder: two specs are compatible only if this matches. */
    public function fingerprint(): string
    {
        return Digest::of('prism-graph/index@1', [$this->provider, $this->model, $this->dimensions, $this->recipe]);
    }
}
