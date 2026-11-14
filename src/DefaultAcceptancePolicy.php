<?php

declare(strict_types=1);

namespace Prism\Graph;

use Prism\Graph\Contracts\AcceptancePolicy;

/**
 * Accepted only when EVERY piece of evidence is what a person said or a
 * document the app chose to ingest. Evidence that is only the agent's own
 * words makes a belief. Anything else (tool output, sub-agent reports,
 * consolidator output, or a mix) is a proposal the app must accept.
 *
 * Every-not-any is the point: one trusted chunk cited beside an injected tool
 * result must not launder the injection into a fact.
 */
final class DefaultAcceptancePolicy implements AcceptancePolicy
{
    public function decide(array $evidence): FactStatus
    {
        $trusted = static fn (Trust $t) => $t === Trust::User || $t === Trust::Document;
        if ($evidence !== [] && array_filter($evidence, static fn (Trust $t) => ! $trusted($t)) === []) {
            return FactStatus::Accepted;
        }
        if ($evidence !== [] && array_filter($evidence, static fn (Trust $t) => $t !== Trust::Agent) === []) {
            return FactStatus::Belief;
        }

        return FactStatus::Proposed;
    }
}
