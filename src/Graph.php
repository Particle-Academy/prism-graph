<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;
use Prism\Graph\Contracts\AcceptancePolicy;
use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Contracts\VectorStore;

/**
 * Long-term memory for agents. This object only holds the wiring (storage,
 * optional vectors and embedder, acceptance policy); it has NO read or write
 * methods of its own. Everything goes through a scope:
 *
 *   $memory = $graph->scope(Scope::tenant('acme')->owner('user-7')->agent('support'));
 *
 * so an unscoped query cannot be written, by an app or by a model.
 */
final class Graph
{
    public function __construct(
        private readonly GraphStore $store,
        private readonly ?VectorStore $vectors = null,
        private readonly ?Embedder $embedder = null,
        private readonly AcceptancePolicy $policy = new DefaultAcceptancePolicy,
    ) {
        if (($vectors === null) !== ($embedder === null)) {
            throw new InvalidArgumentException('Configure a VectorStore and an Embedder together, or neither.');
        }
    }

    public function scope(Scope $scope): ScopedGraph
    {
        return new ScopedGraph($scope, $this->store, $this->vectors, $this->embedder, $this->policy);
    }
}
