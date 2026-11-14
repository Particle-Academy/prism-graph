<?php

declare(strict_types=1);

namespace Prism\Graph\Harness;

use Closure;
use Prism\Graph\Scope;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Sessions\Session;

/**
 * How a harness Session maps onto a memory Scope.
 *
 * The harness has no tenant: only the app knows which tenant a session
 * belongs to, so the app always supplies it. Everything else comes from the
 * session itself, never from anything a model said:
 *   owner  = the participant (type and id), hashed to a fixed-shape id
 *   agent  = the session's agent namespace; a session with none gets "a:-",
 *            which no real namespace can be (the harness forbids "-" alone)
 *   thread = the session's current thread (a new chat is a new thread)
 *
 * Long-term memory is recorded at the AGENT scope (see TurnRecorder), so a
 * person's earlier chats with an agent are recalled in later ones; the thread
 * level is what the wake-up reads from, which sees the agent level above it.
 */
final class HarnessScopes
{
    /**
     * @param  string|Closure(Session): string  $tenant  a fixed tenant, or how to find it for a session
     * @return Closure(Session): Scope
     */
    public static function standard(string|Closure $tenant): Closure
    {
        return static function (Session $session) use ($tenant): Scope {
            $participant = $session->participant();
            [$type, $id] = $participant instanceof ParticipantRef
                ? [$participant->type, $participant->id]
                : [$participant->getMorphClass(), (string) $participant->getKey()];
            $agent = $session->agent();

            return new Scope(
                is_string($tenant) ? $tenant : $tenant($session),
                // JSON, not a separator: no field content can shift a boundary.
                'p:'.substr(hash('sha256', json_encode([$type, $id], JSON_THROW_ON_ERROR)), 0, 40),
                $agent === null ? 'a:-' : self::id('a:', $agent),
                't:'.substr(hash('sha256', json_encode([$session->key(), (string) $session->thread()->getKey()], JSON_THROW_ON_ERROR)), 0, 40),
            );
        };
    }

    /** Keep readable ids readable; hash anything that does not fit a scope id. */
    private static function id(string $prefix, string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:@\/-]{1,150}$/D', $value) === 1 && $value !== '-'
            ? $prefix.$value
            : $prefix.'h'.substr(hash('sha256', $value), 0, 40);
    }
}
