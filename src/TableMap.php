<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;

/**
 * Which app-owned tables play each graph role. The app's own migrations create
 * and extend these tables; the package only reads and writes the core columns
 * it documents, so apps keep full control of their data's shape.
 *
 * Names are capped at 50 characters so the derived names (the chunks table's
 * "_fts" index and the "_..." index names) stay inside PostgreSQL's
 * 63-character identifier limit.
 */
final readonly class TableMap
{
    public function __construct(
        public string $sources = 'graph_sources',
        public string $chunks = 'graph_chunks',
        public string $facts = 'graph_facts',
        public string $evidence = 'graph_fact_evidence',
    ) {
        foreach (get_object_vars($this) as $role => $table) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,49}$/D', $table) !== 1) {
                throw new InvalidArgumentException("Invalid table name for {$role}.");
            }
        }
    }

    /** @param array<string, string> $map */
    public static function from(array $map): self
    {
        return new self(...$map);
    }

    /** The full-text index over chunks (an FTS5 table on SQLite). */
    public function fts(): string
    {
        return $this->chunks.'_fts';
    }
}
