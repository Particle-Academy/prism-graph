<?php

declare(strict_types=1);

namespace Prism\Graph\Harness;

use Closure;
use Prism\Graph\Chunking\WindowChunker;
use Prism\Graph\Graph;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Trust;
use Prism\Harness\Context\TurnObserver;
use Prism\Harness\Sessions\Session;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Writes every recorded harness turn into long-term memory as episodes, at
 * the THREAD scope of the session, with the trust class of whoever wrote it:
 *
 *   user message       -> trust user
 *   assistant message  -> trust agent   (the model's own words)
 *   tool result        -> trust tool    (third-party text; never trusted alone)
 *   system message     -> not recorded
 *
 * The trust class is decided HERE, from the message type, never from the
 * content: a fetched page that says "I am the user" is still a tool result.
 *
 * Episodes are verbatim. A message over $maxBytes is cut on a character
 * boundary, and the cut is DECLARED in the source's metadata, so the floor is
 * never silently lossy.
 */
final readonly class TurnRecorder implements TurnObserver
{
    /** @param Closure(Session): Scope $scope */
    public function __construct(
        private Graph $graph,
        private Closure $scope,
        private int $maxBytes = 65536,
    ) {}

    public function recorded(Session $session, string $runId, array $messages): void
    {
        $scope = ($this->scope)($session);
        $memory = $this->graph->scope($scope);
        $run = preg_replace('/[^A-Za-z0-9_.:-]/', '', $runId) ?: 'run';

        foreach ($messages as $i => $message) {
            $episodes = match (true) {
                $message instanceof UserMessage => [[$message->text(), new Origin(Trust::User, 'user:'.$scope->owner)]],
                $message instanceof AssistantMessage => [[$message->content, new Origin(Trust::Agent, 'agent:'.$scope->agent)]],
                $message instanceof ToolResultMessage => array_map(
                    fn ($result) => [
                        $result->toolName.': '.(is_string($result->result) ? $result->result : json_encode($result->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                        new Origin(Trust::Tool, 'tool:'.(preg_replace('/[^A-Za-z0-9_.:@\/-]/', '_', $result->toolName) ?: 'unknown')),
                    ],
                    $message->toolResults,
                ),
                default => [],
            };
            foreach (array_values($episodes) as $j => [$text, $origin]) {
                $text = (string) $text;
                if (trim($text) === '') {
                    continue;
                }
                $metadata = ['run' => $run];
                if (strlen($text) > $this->maxBytes) {
                    $metadata['truncated_from_bytes'] = strlen($text);
                    $text = mb_strcut($text, 0, $this->maxBytes, 'UTF-8');
                }
                $memory->ingest("turn:{$run}:{$i}:{$j}", 'harness://'.$run, $text, new WindowChunker, $origin, $metadata);
            }
        }
    }
}
