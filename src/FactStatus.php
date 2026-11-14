<?php

declare(strict_types=1);

namespace Prism\Graph;

enum FactStatus: string
{
    /** Held as true; returned by default. */
    case Accepted = 'accepted';
    /** Derived from evidence the policy does not trust alone; waits for the app. */
    case Proposed = 'proposed';
    /** The agent's own conclusion; returned only when asked for, and labelled. */
    case Belief = 'belief';
}
