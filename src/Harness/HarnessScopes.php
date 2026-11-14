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
 *   agent  = the session's agent namespace ('default' when it has none)
 *   thread = the session's current thread (a new chat is a new thread)
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
                'p:'.substr(hash('sha256', $type."\0".$id), 0, 40),
                self::id('a:', $session->agent() ?? 'default'),
                't:'.substr(hash('sha256', $session->key()."\0".(string) $session->thread()->getKey()), 0, 40),
            );
        };
    }

    /** Keep readable ids readable; hash anything that does not fit a scope id. */
    private static function id(string $prefix, string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:@\/-]{1,150}$/D', $value) === 1
            ? $prefix.$value
            : $prefix.substr(hash('sha256', $value), 0, 40);
    }
}
