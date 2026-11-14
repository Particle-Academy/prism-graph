<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;
use Prism\Graph\Support\Digest;

/**
 * Where a memory lives and who can read it. Every read and write goes through
 * one; there is no unscoped query.
 *
 * Levels nest: tenant > owner > agent > thread. An agent needs an owner and a
 * thread needs an agent, so no row can be visible across owners except a
 * tenant-wide one written deliberately at tenant level.
 *
 * A reader at (t, o, a, th) sees rows whose tenant is t and whose every
 * narrower level is either unset (shared upward) or exactly its own:
 *   tenant-shared   (t, -, -, -)
 *   owner memory    (t, o, -, -)
 *   agent's memory  (t, o, a, -)  of this owner
 *   this thread     (t, o, a, th)
 */
final readonly class Scope
{
    private const ID = '/^[A-Za-z0-9_.:@\/-]{1,191}$/D';

    public function __construct(
        public string $tenant,
        public ?string $owner = null,
        public ?string $agent = null,
        public ?string $thread = null,
    ) {
        foreach (['tenant' => $tenant, 'owner' => $owner, 'agent' => $agent, 'thread' => $thread] as $level => $value) {
            if ($value !== null && preg_match(self::ID, $value) !== 1) {
                throw new InvalidArgumentException("Scope {$level} must be 1-191 of A-Z a-z 0-9 _ . : @ / -");
            }
        }
        if ($agent !== null && $owner === null) {
            throw new InvalidArgumentException('An agent scope needs an owner: agent memory is always about someone.');
        }
        if ($thread !== null && $agent === null) {
            throw new InvalidArgumentException('A thread scope needs an agent.');
        }
    }

    public static function tenant(string $tenant): self
    {
        return new self($tenant);
    }

    public function owner(string $owner): self
    {
        return new self($this->tenant, $owner);
    }

    public function agent(string $agent): self
    {
        if ($this->owner === null) {
            throw new InvalidArgumentException('Set the owner before the agent.');
        }

        return new self($this->tenant, $this->owner, $agent);
    }

    public function thread(string $thread): self
    {
        return new self($this->tenant, $this->owner, $this->agent, $thread);
    }

    public function level(): string
    {
        return match (true) {
            $this->thread !== null => 'thread',
            $this->agent !== null => 'agent',
            $this->owner !== null => 'owner',
            default => 'tenant',
        };
    }

    /** Can a reader at this scope see a row stored at $row? */
    public function canSee(self $row): bool
    {
        return $row->tenant === $this->tenant
            && ($row->owner === null || $row->owner === $this->owner)
            && ($row->agent === null || $row->agent === $this->agent)
            && ($row->thread === null || $row->thread === $this->thread);
    }

    /**
     * The internal id for an app-supplied key at this scope. Two tenants (or
     * two threads) using the same key get different rows, never each other's.
     */
    public function qualify(string $kind, string $key): string
    {
        return Digest::of('prism-graph/'.$kind.'@1', [$this->tenant, $this->owner ?? '', $this->agent ?? '', $this->thread ?? '', $key]);
    }

    /** @return array{tenant: string, owner: ?string, agent: ?string, thread: ?string} */
    public function toArray(): array
    {
        return ['tenant' => $this->tenant, 'owner' => $this->owner, 'agent' => $this->agent, 'thread' => $this->thread];
    }
}
