<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use Prism\Graph\TableMap;

/**
 * The core columns the plumbing needs, as plain SQL for SQLite and
 * PostgreSQL. Apps publish the matching migration and then OWN it: add columns
 * and indexes, or point roles at existing tables through a TableMap. The
 * package never creates tables at runtime; this is a reference, used by the
 * shipped migration and by tests.
 *
 * Scope columns (tenant_id, owner_id, agent_id, thread_id) are on sources and
 * facts; chunks inherit their source's scope through source_id. Full-text
 * search is FTS5 (unicode61, which folds case and diacritics) on SQLite and a
 * generated tsvector with the 'simple' configuration on PostgreSQL.
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
        $scope = 'tenant_id VARCHAR(191) NOT NULL, owner_id VARCHAR(191), agent_id VARCHAR(191), thread_id VARCHAR(191)';
        $serial = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY';

        $statements = [
            "CREATE TABLE {$t->sources} (id CHAR(64) PRIMARY KEY, source_key VARCHAR(191) NOT NULL, {$scope},
                uri TEXT NOT NULL, sha256 CHAR(64) NOT NULL, metadata {$json} NOT NULL,
                trust VARCHAR(20) NOT NULL, writer VARCHAR(191) NOT NULL,
                occurred_at CHAR(27), recorded_at CHAR(27) NOT NULL)",
            "CREATE INDEX {$t->sources}_scope ON {$t->sources} (tenant_id, owner_id, agent_id, thread_id)",
            // seq is the explicit integer key the SQLite full-text index joins on:
            // an implicit rowid is not guaranteed stable across VACUUM.
            "CREATE TABLE {$t->chunks} (seq {$serial}, id CHAR(64) NOT NULL UNIQUE, source_id CHAR(64) NOT NULL REFERENCES {$t->sources} (id),
                text TEXT NOT NULL, byte_start INTEGER NOT NULL, byte_end INTEGER NOT NULL,
                line_start INTEGER NOT NULL, line_end INTEGER NOT NULL, heading_path {$json} NOT NULL,
                recipe VARCHAR(191) NOT NULL, retired_at CHAR(27))",
            "CREATE INDEX {$t->chunks}_source ON {$t->chunks} (source_id, byte_start)",
            "CREATE TABLE {$t->facts} (id CHAR(64) PRIMARY KEY, fact_key VARCHAR(191) NOT NULL, {$scope},
                subject VARCHAR(191) NOT NULL, predicate VARCHAR(191) NOT NULL, object TEXT NOT NULL,
                status VARCHAR(20) NOT NULL, valid_from CHAR(27) NOT NULL, valid_to CHAR(27),
                superseded_by VARCHAR(191), recorded_at CHAR(27) NOT NULL, expired_at CHAR(27))",
            "CREATE INDEX {$t->facts}_subject ON {$t->facts} (tenant_id, subject, valid_to)",
            "CREATE TABLE {$t->evidence} (fact_id CHAR(64) NOT NULL REFERENCES {$t->facts} (id),
                chunk_id CHAR(64) NOT NULL REFERENCES {$t->chunks} (id), PRIMARY KEY (fact_id, chunk_id))",
            "CREATE INDEX {$t->evidence}_chunk ON {$t->evidence} (chunk_id)",
        ];

        return [...$statements, ...self::fullText($driver, $t)];
    }

    /**
     * Restore the full-text index after an app migration broke it, then
     * rebuild its contents from the chunks table. Safe to run at any time.
     *
     * Why this exists: on SQLite, altering a table in ways SQLite cannot do in
     * place (Laravel then copies the table and renames it) DROPS the table's
     * triggers. Our full-text triggers on the chunks table vanish, new chunks
     * stop being indexed, and search silently stops finding them: a derived
     * index hiding text, which this package must never do. Run this after any
     * migration that alters the chunks table (or `php artisan
     * prism-graph:repair-fulltext`). PostgreSQL's generated column survives
     * such migrations, so there it only re-creates the index if missing.
     *
     * @return list<string>
     */
    public static function repairFullText(string $driver, TableMap $t = new TableMap): array
    {
        if ($driver === 'pgsql') {
            return ["CREATE INDEX IF NOT EXISTS {$t->chunks}_search ON {$t->chunks} USING GIN (search)"];
        }
        $fts = $t->fts();
        [$virtual, $insert, $delete, $update] = self::fullText('sqlite', $t);

        return [
            str_replace('CREATE VIRTUAL TABLE', 'CREATE VIRTUAL TABLE IF NOT EXISTS', $virtual),
            "DROP TRIGGER IF EXISTS {$t->chunks}_ai",
            "DROP TRIGGER IF EXISTS {$t->chunks}_ad",
            "DROP TRIGGER IF EXISTS {$t->chunks}_au",
            $insert,
            $delete,
            $update,
            // Re-read every chunk, including any inserted while the triggers were gone.
            "INSERT INTO {$fts} ({$fts}) VALUES ('rebuild')",
        ];
    }

    /** @return list<string> */
    public static function fullText(string $driver, TableMap $t = new TableMap): array
    {
        if ($driver === 'pgsql') {
            return [
                "ALTER TABLE {$t->chunks} ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', text)) STORED",
                "CREATE INDEX {$t->chunks}_search ON {$t->chunks} USING GIN (search)",
            ];
        }
        $fts = $t->fts();

        // External-content FTS5 kept in step by triggers: the text lives once,
        // in the chunks table, and the index can always be rebuilt from it.
        return [
            "CREATE VIRTUAL TABLE {$fts} USING fts5(text, content='{$t->chunks}', content_rowid='seq', tokenize='unicode61')",
            "CREATE TRIGGER {$t->chunks}_ai AFTER INSERT ON {$t->chunks} BEGIN
                INSERT INTO {$fts} (rowid, text) VALUES (new.seq, new.text); END",
            "CREATE TRIGGER {$t->chunks}_ad AFTER DELETE ON {$t->chunks} BEGIN
                INSERT INTO {$fts} ({$fts}, rowid, text) VALUES ('delete', old.seq, old.text); END",
            "CREATE TRIGGER {$t->chunks}_au AFTER UPDATE OF text ON {$t->chunks} BEGIN
                INSERT INTO {$fts} ({$fts}, rowid, text) VALUES ('delete', old.seq, old.text);
                INSERT INTO {$fts} (rowid, text) VALUES (new.seq, new.text); END",
        ];
    }
}
