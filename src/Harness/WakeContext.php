<?php

declare(strict_types=1);

namespace Prism\Graph\Harness;

use Closure;
use Prism\Graph\Graph;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;
use Prism\Graph\Wake\SearchLayer;
use Prism\Graph\Wake\Wake;
use Prism\Harness\Context\ContextProvider;
use Prism\Harness\Sessions\Session;

/**
 * Wakes a harness agent up with what memory already holds, at every model
 * call: what this thread, this agent's memory of the person, the person's
 * own memory and the tenant's shared memory say about the new message.
 *
 * Output is the Wake report FENCED as data (hash-tagged, hidden characters
 * shown), because it lands in the system prompt, which outranks the chat.
 * Returns '' when nothing is recalled, so an empty memory adds nothing.
 *
 * Pass $layers to choose what is recalled; the default is a search on the
 * new user message under an 800-token budget.
 */
final readonly class WakeContext implements ContextProvider
{
    /**
     * @param  Closure(Session): Scope  $scope
     * @param  (Closure(ScopedGraph, Session, string): Wake)|null  $layers
     */
    public function __construct(
        private Graph $graph,
        private Closure $scope,
        private ?Closure $layers = null,
        private int $timeBudgetMs = 150,
    ) {}

    public function context(Session $session, string $prompt): string
    {
        $memory = $this->graph->scope(($this->scope)($session));
        $wake = $this->layers !== null
            ? ($this->layers)($memory, $session, $prompt)
            : $this->defaultLayers($memory, $prompt);
        if ($wake === null) {
            return '';
        }
        $report = $wake->assemble($this->timeBudgetMs);

        return $report->included === [] ? '' : $report->fenced();
    }

    private function defaultLayers(ScopedGraph $memory, string $prompt): ?Wake
    {
        $query = mb_strcut(trim($prompt), 0, ScopedGraph::MAX_QUERY_BYTES, 'UTF-8');
        if ($query === '') {
            return null;
        }

        return (new Wake($memory))->layer(new SearchLayer('recalled', $query, 8), 800);
    }
}
