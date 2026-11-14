<?php

declare(strict_types=1);

namespace Prism\Graph;

/**
 * Where a memory came from. Recorded on every source and never inferred from
 * the content: published memory-poisoning attacks (MINJA, AgentPoison) work by
 * getting ordinary content treated as trusted. Tool output in particular is
 * someone else's text and is never promoted to a fact on its own.
 */
enum Trust: string
{
    /** What the person said. */
    case User = 'user';
    /** A document the app ingested on purpose. */
    case Document = 'document';
    /** The model's own words or notes. */
    case Agent = 'agent';
    /** Output of a tool call: third-party text. */
    case Tool = 'tool';
    /** A sub-agent's report back to its parent. */
    case Subagent = 'subagent';
    /** Something a background consolidator derived. */
    case Consolidator = 'consolidator';
    /** Harness or scheduler bookkeeping. */
    case System = 'system';
}
