<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use Prism\Graph\TableMap;

/**
 * The minimal core columns the plumbing needs, as plain SQL for SQLite and
 * PostgreSQL. Apps publish the matching migration and then OWN it: add columns
 * and indexes, or point roles at existing tables through a TableMap. The
 * package never creates tables at runtime; this is a reference, used by the
 * shipped migration and by tests.
 */
final class BaseSchema
{
    /** @return list<string> */
    public static function statements(string $driver, TableMap $t = new TableMap): array
    {
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException("No base schema for [{$driver}].");
        }
        $json = $driver === 'pgsql' ? 'JSONB' : 'TEXT';
        $now = $driver === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';

        return [
            "CREATE TABLE {$t->sources} (id VARCHAR(191) PRIMARY KEY, uri TEXT NOT NULL, sha256 CHAR(64) NOT NULL,
                metadata {$json} NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT {$now})",
            "CREATE TABLE {$t->chunks} (id CHAR(64) PRIMARY KEY, source_id VARCHAR(191) NOT NULL REFERENCES {$t->sources} (id),
                text TEXT NOT NULL, byte_start INTEGER NOT NULL, byte_end INTEGER NOT NULL,
                line_start INTEGER NOT NULL, line_end INTEGER NOT NULL, heading_path {$json} NOT NULL,
                recipe VARCHAR(191) NOT NULL, retired_at CHAR(27), created_at TIMESTAMP NOT NULL DEFAULT {$now})",
            "CREATE INDEX {$t->chunks}_source ON {$t->chunks} (source_id, byte_start)",
            "CREATE TABLE {$t->nodes} (id VARCHAR(191) PRIMARY KEY, type VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT {$now})",
            "CREATE TABLE {$t->edges} (id VARCHAR(191) PRIMARY KEY, from_id VARCHAR(191) NOT NULL REFERENCES {$t->nodes} (id),
                to_id VARCHAR(191) NOT NULL REFERENCES {$t->nodes} (id), type VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT {$now})",
            "CREATE INDEX {$t->edges}_from ON {$t->edges} (from_id, type)",
            "CREATE INDEX {$t->edges}_to ON {$t->edges} (to_id, type)",
            "CREATE TABLE {$t->facts} (id VARCHAR(191) PRIMARY KEY, subject VARCHAR(191) NOT NULL,
                predicate VARCHAR(191) NOT NULL, object TEXT NOT NULL, valid_from CHAR(27) NOT NULL,
                valid_to CHAR(27), superseded_by VARCHAR(191), created_at TIMESTAMP NOT NULL DEFAULT {$now})",
            "CREATE INDEX {$t->facts}_subject ON {$t->facts} (subject, valid_to)",
            "CREATE TABLE {$t->evidence} (fact_id VARCHAR(191) NOT NULL REFERENCES {$t->facts} (id),
                chunk_id CHAR(64) NOT NULL REFERENCES {$t->chunks} (id), PRIMARY KEY (fact_id, chunk_id))",
        ];
    }
}
