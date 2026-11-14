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
 *   owner  = the participant (type and id)
 *   agent  = the session's agent namespace AND its session scope
 *   thread = the session's current thread (a new chat is a new thread)
 *
 * WHY THE SESSION SCOPE IS PART OF THE AGENT LEVEL: a harness scope names a
 * conversation channel ("chat", "ticket-4711", a sub-agent's
 * "parent::sub::research"). Long-term memory spans the chats WITHIN a channel
 * (newConversation() keeps it), but never crosses channels: a support agent's
 * ticket for one customer must not recall another customer's ticket, and a
 * sub-agent's fetched text must not seep into its parent's later chats.
 *
 * A session with no agent namespace uses "-", which no real namespace can be.
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

            return new Scope(
                is_string($tenant) ? $tenant : $tenant($session),
                // JSON, not a separator: no field content can shift a boundary.
                'p:'.self::digest([$type, $id]),
                'a:'.self::digest([$session->agent() ?? '-', $session->scope()]),
                't:'.self::digest([$session->key(), (string) $session->thread()->getKey()]),
            );
        };
    }

    /** @param list<string> $fields */
    private static function digest(array $fields): string
    {
        return substr(hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)), 0, 40);
    }
}
