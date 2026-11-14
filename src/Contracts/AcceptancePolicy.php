<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\FactStatus;
use Prism\Graph\Trust;

/** Decides the status of a new fact from the trust of the evidence it cites. */
interface AcceptancePolicy
{
    /** @param non-empty-list<Trust> $evidence the trust class of each cited chunk's source */
    public function decide(array $evidence): FactStatus;
}
