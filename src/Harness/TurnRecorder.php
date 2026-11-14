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
use Throwable;

/**
 * Writes every recorded harness turn into long-term memory as episodes.
 *
 * WHERE: at the session's AGENT scope (tenant, owner, agent), so the person's
 * later chats with this agent recall it; never another person's, never
 * another agent's. Pass $level = 'thread' to keep episodes per chat instead.
 *
 * TRUST comes from the message TYPE and the session, never from content:
 *   user message, top-level session  -> user
 *   user message in a SUB-AGENT       -> agent   (the parent model wrote it)
 *   assistant message                 -> agent   (the model's own words)
 *   tool result                       -> tool    (third-party text)
 *   system message                    -> not recorded
 *
 * KEYS are content-addressed per message (run, kind, digest, part), so the
 * harness calling this once per step, and again on an approval resume, can
 * never collide one message with another or overwrite an earlier episode;
 * recording the same message twice is a no-op.
 *
 * Episodes are verbatim. A message over $maxBytes is cut on a character
 * boundary and invalid UTF-8 is replaced, and both are DECLARED in the
 * source's metadata, so the floor is never silently lossy. One message that
 * fails to store is reported and does not stop the rest of the batch.
 */
final readonly class TurnRecorder implements TurnObserver
{
    /** @param Closure(Session): Scope $scope */
    public function __construct(
        private Graph $graph,
        private Closure $scope,
        private int $maxBytes = 65536,
        private string $level = 'agent',
    ) {
        if (! in_array($level, ['agent', 'thread'], true)) {
            throw new \InvalidArgumentException("Record at 'agent' or 'thread' scope.");
        }
    }

    public function recorded(Session $session, string $runId, array $messages): void
    {
        $thread = ($this->scope)($session);
        $memory = $this->graph->scope($this->level === 'thread' ? $thread : new Scope($thread->tenant, $thread->owner, $thread->agent));
        $run = preg_replace('/[^A-Za-z0-9_.:-]/', '', $runId) ?: 'run';
        $delegated = $session->thread()->parent_thread_id !== null;

        foreach ($messages as $message) {
            $episodes = match (true) {
                $message instanceof UserMessage => [[$message->text(), $delegated
                    ? new Origin(Trust::Agent, 'agent:'.$thread->agent.'/parent')
                    : new Origin(Trust::User, 'user:'.$thread->owner)]],
                $message instanceof AssistantMessage => [[$message->content, new Origin(Trust::Agent, 'agent:'.$thread->agent)]],
                $message instanceof ToolResultMessage => array_map(
                    fn ($result) => [
                        $result->toolName.': '.(is_string($result->result) ? $result->result : json_encode($result->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                        new Origin(Trust::Tool, 'tool:'.(preg_replace('/[^A-Za-z0-9_.:@\/-]/', '_', $result->toolName) ?: 'unknown')),
                    ],
                    $message->toolResults,
                ),
                default => [],
            };
            foreach (array_values($episodes) as $part => [$text, $origin]) {
                try {
                    $this->store($memory, $run, $thread, $message::class, $part, (string) $text, $origin);
                } catch (Throwable $failure) {
                    function_exists('report') ? report($failure) : throw $failure;
                }
            }
        }
    }

    private function store(\Prism\Graph\ScopedGraph $memory, string $run, Scope $thread, string $kind, int $part, string $text, Origin $origin): void
    {
        if (trim($text) === '') {
            return;
        }
        $metadata = ['run' => $run, 'thread' => $thread->thread];
        if (! mb_check_encoding($text, 'UTF-8')) {
            $metadata['invalid_utf8_replaced'] = true;
            $text = mb_scrub($text, 'UTF-8');
        }
        if (strlen($text) > $this->maxBytes) {
            $metadata['truncated_from_bytes'] = strlen($text);
            $text = mb_strcut($text, 0, $this->maxBytes, 'UTF-8');
        }
        $digest = substr(hash('sha256', json_encode([$kind, $origin->trust->value, $text], JSON_THROW_ON_ERROR)), 0, 24);
        $memory->ingest("turn:{$run}:{$digest}:{$part}", 'harness://'.$run, $text, new WindowChunker, $origin, $metadata);
    }
}
