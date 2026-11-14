<?php

declare(strict_types=1);

namespace Prism\Graph\Embedding;

use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;
use Prism\Prism\Facades\Prism;

/**
 * Embeds through Prism core (particle-academy/prism): any provider Prism
 * supports for embeddings. The IndexSpec pins provider, model and dimensions;
 * a provider returning a different length is refused, not stored.
 */
final readonly class PrismEmbedder implements Embedder
{
    public function __construct(
        private IndexSpec $spec,
        private int $batchSize = 64,
    ) {
        if (! class_exists(Prism::class)) {
            throw new GraphException('PrismEmbedder needs particle-academy/prism installed.');
        }
        if ($batchSize < 1) {
            throw new GraphException('Batch size must be at least 1.');
        }
    }

    public function spec(): IndexSpec
    {
        return $this->spec;
    }

    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), $this->batchSize) as $batch) {
            $response = Prism::embeddings()
                ->using($this->spec->provider, $this->spec->model)
                ->fromArray($batch)
                ->asEmbeddings();
            if (count($response->embeddings) !== count($batch)) {
                throw new GraphException('The provider returned a different number of embeddings than inputs.');
            }
            foreach ($response->embeddings as $embedding) {
                $vector = array_values($embedding->embedding);
                if (count($vector) !== $this->spec->dimensions) {
                    throw new GraphException(sprintf(
                        'Index [%s] is pinned to %d dimensions; %s:%s returned %d.',
                        $this->spec->name, $this->spec->dimensions, $this->spec->provider, $this->spec->model, count($vector),
                    ));
                }
                $vectors[] = array_map('floatval', $vector);
            }
        }

        return $vectors;
    }
}
