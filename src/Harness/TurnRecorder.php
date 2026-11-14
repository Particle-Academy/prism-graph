<?php

declare(strict_types=1);

namespace Prism\Graph\Harness;

use Closure;
use InvalidArgumentException;
use PDOException;
use Prism\Graph\Chunking\WindowChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Graph;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;
use Prism\Graph\Trust;
use Prism\Harness\Context\TurnObserver;
use Prism\Harness\Sessions\Session;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

/**
 * Writes every recorded harness turn into long-term memory as episodes.
 *
 * WHERE, by default:
 *   what the user and the agent SAID -> the agent level of the session's
 *       channel (see HarnessScopes): recalled in this person's later chats in
 *       the same channel, never another person's or another channel's;
 *   TOOL output -> the thread only. Fetched text is someone else's words; an
 *       injection in it must not persist into every later chat.
 * Pass 'thread' or 'agent' for either to change that.
 *
 * TRUST comes from the message TYPE and the session, never from content:
 *   user message                 -> user, or what $userTrust decides
 *   user message in a SUB-AGENT  -> agent   (the parent model wrote the task)
 *   assistant message            -> agent
 *   tool result                  -> tool
 *   system message               -> not recorded
 * The sub-agent rule sees only threads the harness linked beneath a parent.
 * An app that sends model-written prompts any other way (orchestration loops,
 * agent-to-agent delivery) must say so through $userTrust.
 *
 * KEYS are content-addressed per message, so the harness calling this once
 * per step and again on an approval resume never collides two messages;
 * recording the same message twice is a no-op.
 *
 * Episodes are verbatim. Oversized messages are cut on a character boundary,
 * invalid UTF-8 is replaced, and both are DECLARED in the source metadata.
 * A STORAGE failure on one message is reported and the rest of the batch is
 * still stored; any other error (a bug) propagates to the harness, which
 * reports it and keeps the turn.
 */
final readonly class TurnRecorder implements TurnObserver
{
    /**
     * @param  Closure(Session): Scope  $scope
     * @param  (Closure(Session, UserMessage): Trust)|null  $userTrust  who really wrote a user message
     */
    public function __construct(
        private Graph $graph,
        private Closure $scope,
        private int $maxBytes = 65536,
        private string $said = 'agent',
        private string $tools = 'thread',
        private ?Closure $userTrust = null,
    ) {
        foreach (['said' => $said, 'tools' => $tools] as $what => $level) {
            if (! in_array($level, ['agent', 'thread'], true)) {
                throw new InvalidArgumentException("Record {$what} at 'agent' or 'thread' level.");
            }
        }
    }

    public function recorded(Session $session, string $runId, array $messages): void
    {
        $thread = ($this->scope)($session);
        $channel = new Scope($thread->tenant, $thread->owner, $thread->agent);
        $at = fn (string $level): ScopedGraph => $this->graph->scope($level === 'thread' ? $thread : $channel);
        $run = preg_replace('/[^A-Za-z0-9_.:-]/', '', $runId) ?: 'run';
        $delegated = $session->thread()->parent_thread_id !== null;

        foreach ($messages as $message) {
            $episodes = match (true) {
                $message instanceof UserMessage => [[$message->text(), $this->userOrigin($session, $message, $delegated, $thread), $this->said]],
                $message instanceof AssistantMessage => [[$message->content, new Origin(Trust::Agent, 'agent:'.$thread->agent), $this->said]],
                $message instanceof ToolResultMessage => array_map(
                    fn ($result) => [
                        $result->toolName.': '.(is_string($result->result) ? $result->result : json_encode($result->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                        new Origin(Trust::Tool, 'tool:'.(preg_replace('/[^A-Za-z0-9_.:@\/-]/', '_', $result->toolName) ?: 'unknown')),
                        $this->tools,
                    ],
                    $message->toolResults,
                ),
                default => [],
            };
            foreach (array_values($episodes) as $part => [$text, $origin, $level]) {
                try {
                    $this->store($at($level), $run, $thread, $message::class, $part, (string) $text, $origin);
                } catch (GraphException|PDOException|InvalidArgumentException|RuntimeException $failure) {
                    // A storage failure: keep the rest of the batch, and say so.
                    report($failure);
                }
            }
        }
    }

    private function userOrigin(Session $session, UserMessage $message, bool $delegated, Scope $thread): Origin
    {
        if ($delegated) {
            return new Origin(Trust::Agent, 'agent:'.$thread->agent.'/parent');
        }
        $trust = $this->userTrust === null ? Trust::User : ($this->userTrust)($session, $message);

        return new Origin($trust, $trust === Trust::User ? 'user:'.$thread->owner : $trust->value.':'.$thread->agent);
    }

    private function store(ScopedGraph $memory, string $run, Scope $thread, string $kind, int $part, string $text, Origin $origin): void
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
        $digest = substr(hash('sha256', json_encode([$kind, $origin->trust->value, $text, $metadata['truncated_from_bytes'] ?? null], JSON_THROW_ON_ERROR)), 0, 24);
        $memory->ingest("turn:{$run}:{$digest}:{$part}", 'harness://'.$run, $text, new WindowChunker, $origin, $metadata);
    }
}
