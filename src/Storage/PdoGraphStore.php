<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use Closure;
use InvalidArgumentException;
use PDO;
use Prism\Graph\Chunk;
use Prism\Graph\Contracts\AcceptancePolicy;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\EraseReport;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\FactStatus;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Support\Atomic;
use Prism\Graph\TableMap;
use Prism\Graph\Trust;

/**
 * GraphStore over PDO for SQLite (3.24+, with FTS5) and PostgreSQL. Every value
 * is a bound parameter; table names come only from a validated TableMap.
 *
 * SCOPE IS PART OF EVERY QUERY. Reads apply the visibility predicate from
 * {@see Scope} in SQL, not in PHP after the fact, so a row outside the reader's
 * scope is never fetched at all.
 */
final class PdoGraphStore implements GraphStore
{
    public const MAX_QUERY_TERMS = 16;

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/D';

    private const KEY = '/^[\x21-\x7E]{1,191}$/D';

    private readonly string $driver;

    private readonly Closure $clock;

    public function __construct(private readonly PDO $pdo, private readonly TableMap $tables = new TableMap, ?Closure $clock = null)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($this->driver, ['sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException("Unsupported PDO driver [{$this->driver}]; use sqlite or pgsql.");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock ?? static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function storeSource(Scope $scope, string $key, string $uri, string $sha256, array $metadata, Origin $origin, ?string $occurredAt, array $chunks): array
    {
        $this->key($key);
        $id = $scope->qualify('source', $key);
        $occurred = $occurredAt === null ? null : $this->time($occurredAt);
        $stored = 0;
        $retired = 0;

        // ONE transaction for the source, its chunks and their retirement, so an
        // erase can never land between them and leave orphaned text behind.
        $this->transaction(function () use ($scope, $key, $id, $uri, $sha256, $metadata, $origin, $occurred, $chunks, &$stored, &$retired): void {
            // TRUST IS IMMUTABLE, and enforced by the upsert itself: the update
            // only applies when the stored trust equals the new one, so there is
            // no read-then-write window in which two first-time ingests of one
            // key (one "document", one "tool") can both pass a check. A key first
            // written as tool output would otherwise be relabelled, and the
            // acceptance policy reads that label. To change trust, erase the
            // source and ingest it again.
            $written = $this->run(
                "INSERT INTO {$this->tables->sources}
                 (id, source_key, tenant_id, owner_id, agent_id, thread_id, uri, sha256, metadata, trust, writer, occurred_at, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET uri = excluded.uri, sha256 = excluded.sha256, metadata = excluded.metadata,
                    writer = excluded.writer, occurred_at = excluded.occurred_at
                 WHERE {$this->tables->sources}.trust = excluded.trust",
                [$id, $key, ...array_values($scope->toArray()), $uri, $sha256, json_encode($metadata, JSON_THROW_ON_ERROR),
                    $origin->trust->value, $origin->writer, $occurred, $this->now()],
            );
            if ($written !== 1) {
                $stored = $this->select("SELECT trust FROM {$this->tables->sources} WHERE id = ?", [$id])[0]['trust'] ?? 'unknown';
                throw new GraphException("Source [{$key}] was stored with trust [{$stored}]; trust cannot change. Erase it and ingest again.");
            }

            $statement = $this->pdo->prepare(
                "INSERT INTO {$this->tables->chunks}
                 (id, source_id, text, byte_start, byte_end, line_start, line_end, heading_path, recipe)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING",
            );
            $keep = [];
            foreach ($chunks as $chunk) {
                if (! $chunk instanceof Chunk) {
                    throw new InvalidArgumentException('storeSource() takes Chunk instances.');
                }
                // A chunk can only be written into the source being stored: the
                // caller cannot use this source's scope to write into another.
                if ($chunk->sourceId !== $id) {
                    throw new GraphException('A chunk belongs to a different source than the one being stored.');
                }
                $expected = Chunk::idFor($chunk->recipe, $chunk->sourceId, $chunk->byteStart, $chunk->byteEnd, $chunk->text);
                if (! hash_equals($expected, $chunk->id)) {
                    throw new GraphException('A chunk id does not match its content; ids are content-addressed.');
                }
                if ($chunk->byteStart < 0 || strlen($chunk->text) !== $chunk->byteEnd - $chunk->byteStart) {
                    throw new GraphException('A chunk text must be exactly the bytes of its span.');
                }
                $statement->execute([
                    $chunk->id, $chunk->sourceId, $chunk->text, $chunk->byteStart, $chunk->byteEnd,
                    $chunk->lineStart, $chunk->lineEnd, json_encode($chunk->headingPath, JSON_THROW_ON_ERROR), $chunk->recipe,
                ]);
                $stored += $statement->rowCount();
                $keep[] = $chunk->id;
            }
            $retired = $this->retireExcept($id, $keep);
        });

        return ['source' => $id, 'stored' => $stored, 'retired' => $retired];
    }

    public function retireSource(Scope $scope, string $key): int
    {
        $retired = 0;
        $this->transaction(function () use ($scope, $key, &$retired): void {
            $retired = $this->retireExcept($scope->qualify('source', $key), []);
        });

        return $retired;
    }

    /** @param list<string> $keepIds */
    private function retireExcept(string $sourceId, array $keepIds): int
    {
        $this->run("UPDATE {$this->tables->chunks} SET retired_at = ? WHERE source_id = ? AND retired_at IS NULL", [$this->now(), $sourceId]);
        foreach (array_chunk(array_values(array_unique($keepIds)), 500) as $batch) {
            $this->run("UPDATE {$this->tables->chunks} SET retired_at = NULL WHERE source_id = ? AND id IN ({$this->marks($batch)})", [$sourceId, ...$batch]);
        }

        return (int) $this->select(
            "SELECT COUNT(*) AS n FROM {$this->tables->chunks} WHERE source_id = ? AND retired_at IS NOT NULL",
            [$sourceId],
        )[0]['n'];
    }

    public function getChunks(Scope $reader, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach (array_chunk($ids, 500) as $batch) {
            [$visible, $bindings] = $this->visible('s', $reader);
            $rows = $this->select(
                "SELECT c.*, s.source_key, s.trust FROM {$this->tables->chunks} c
                 JOIN {$this->tables->sources} s ON s.id = c.source_id
                 WHERE c.id IN ({$this->marks($batch)}) AND {$visible}",
                [...$batch, ...$bindings],
            );
            foreach ($rows as $row) {
                $byId[trim((string) $row['id'])] = $this->chunk($row);
            }
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }

    public function textSearch(Scope $reader, string $query, int $limit): array
    {
        $terms = array_slice(array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn (string $t) => $t !== '',
        ))), 0, self::MAX_QUERY_TERMS);
        if ($terms === []) {
            return [];
        }
        [$visible, $bindings] = $this->visible('s', $reader);
        $limit = max(1, min(1000, $limit));

        if ($this->driver === 'pgsql') {
            // Each term is its own plainto_tsquery: a bound value, never tsquery syntax.
            $query = implode(' || ', array_fill(0, count($terms), "plainto_tsquery('simple', ?)"));
            $rows = $this->select(
                "SELECT c.id AS chunk_id, ts_rank_cd(c.search, q.q) AS score
                 FROM {$this->tables->chunks} c
                 JOIN {$this->tables->sources} s ON s.id = c.source_id
                 CROSS JOIN (SELECT ({$query}) AS q) q
                 WHERE c.search @@ q.q AND c.retired_at IS NULL AND {$visible}
                 ORDER BY score DESC, c.id LIMIT {$limit}",
                [...$terms, ...$bindings],
            );
        } else {
            $match = self::ftsMatch($terms);
            $fts = $this->tables->fts();
            $rows = $this->select(
                "SELECT c.id AS chunk_id, -bm25({$fts}) AS score
                 FROM {$fts} JOIN {$this->tables->chunks} c ON c.seq = {$fts}.rowid
                 JOIN {$this->tables->sources} s ON s.id = c.source_id
                 WHERE {$fts} MATCH ? AND c.retired_at IS NULL AND {$visible}
                 ORDER BY score DESC, c.id LIMIT {$limit}",
                [$match, ...$bindings],
            );
        }

        return array_map(static fn (array $r) => ['chunk_id' => trim((string) $r['chunk_id']), 'score' => (float) $r['score']], $rows);
    }

    /**
     * An FTS5 MATCH expression in which every term is a quoted string (inner
     * quotes doubled): a literal, never an operator, column filter or prefix.
     * ORed, so BM25 ranks partial matches instead of dropping them.
     *
     * Public so the quoting is tested directly. The tokenizer in textSearch()
     * already strips punctuation, so no input reaches this through search
     * today; this is the second wall, and a wall nothing tests is not one.
     *
     * @param  list<string>  $terms
     */
    public static function ftsMatch(array $terms): string
    {
        return implode(' OR ', array_map(static fn (string $t) => '"'.str_replace('"', '""', $t).'"', $terms));
    }

    public function assertFact(Scope $scope, Fact $fact, AcceptancePolicy $policy): Fact
    {
        $stored = null;
        $this->transaction(function () use ($scope, $fact, $policy, &$stored): void {
            $stored = $this->insertFact($scope, $fact, $policy);
        });

        return $stored;
    }

    public function supersede(Scope $scope, string $oldKey, Fact $replacement, AcceptancePolicy $policy): Fact
    {
        $stored = null;
        $this->transaction(function () use ($scope, $oldKey, $replacement, $policy, &$stored): void {
            // DECIDE BEFORE WRITING. The replacement's status comes from its
            // evidence alone, so it is known before anything changes; the close
            // below then refuses, in the same statement, to close an accepted
            // fact for a replacement that would not be accepted. A refused
            // supersede writes nothing, whether or not the caller holds a
            // transaction (impactium, against 0.2.0: the old order closed the
            // fact first and relied on a rollback that an outer transaction
            // swallowed).
            $prepared = $this->prepareFact($scope, $replacement, $policy);
            $from = $prepared['from'];
            $oldId = $scope->qualify('fact', $oldKey);
            $closed = $this->run(
                "UPDATE {$this->tables->facts} SET valid_to = ?, superseded_by = ?
                 WHERE id = ? AND valid_to IS NULL AND expired_at IS NULL AND valid_from <= ?
                   AND (status <> ? OR ? = ?)",
                [$from, $replacement->id, $oldId, $from,
                    FactStatus::Accepted->value, $prepared['status']->value, FactStatus::Accepted->value],
            );
            if ($closed !== 1) {
                $old = $this->select("SELECT status, valid_to, expired_at, valid_from FROM {$this->tables->facts} WHERE id = ?", [$oldId])[0] ?? null;
                if ($old !== null && $old['valid_to'] === null && $old['expired_at'] === null
                    && $old['status'] === FactStatus::Accepted->value && $prepared['status'] !== FactStatus::Accepted) {
                    throw new GraphException('An accepted fact can only be superseded by one the policy also accepts; this replacement would be a '.$prepared['status']->value.'.');
                }
                throw new GraphException('Only a fact still in force, valid before the replacement starts, can be superseded.');
            }
            $stored = $this->writeFact($scope, $replacement, $prepared);
        });

        return $stored;
    }

    public function retract(Scope $scope, string $key): void
    {
        $changed = $this->run(
            "UPDATE {$this->tables->facts} SET expired_at = ? WHERE id = ? AND expired_at IS NULL",
            [$this->now(), $scope->qualify('fact', $key)],
        );
        if ($changed !== 1) {
            throw new GraphException("No current fact [{$key}] at this scope to retract.");
        }
    }

    public function accept(Scope $scope, string $key): void
    {
        $changed = $this->run(
            "UPDATE {$this->tables->facts} SET status = ? WHERE id = ? AND status = ? AND expired_at IS NULL AND valid_to IS NULL",
            [FactStatus::Accepted->value, $scope->qualify('fact', $key), FactStatus::Proposed->value],
        );
        if ($changed !== 1) {
            throw new GraphException("No proposed fact [{$key}] at this scope to accept.");
        }
    }

    public function facts(Scope $reader, string $subject, ?string $at = null, ?string $knownAt = null, bool $unaccepted = false): array
    {
        [$visible, $bindings] = $this->visible('f', $reader);
        $where = ['f.subject = ?', $visible];
        $values = [$subject, ...$bindings];
        if ($at === null) {
            $where[] = 'f.valid_to IS NULL';
        } else {
            $at = $this->time($at);
            $where[] = 'f.valid_from <= ? AND (f.valid_to IS NULL OR f.valid_to > ?)';
            array_push($values, $at, $at);
        }
        if ($knownAt === null) {
            $where[] = 'f.expired_at IS NULL';
        } else {
            $knownAt = $this->time($knownAt);
            $where[] = 'f.recorded_at <= ? AND (f.expired_at IS NULL OR f.expired_at > ?)';
            array_push($values, $knownAt, $knownAt);
        }
        if (! $unaccepted) {
            $where[] = 'f.status = ?';
            $values[] = FactStatus::Accepted->value;
        }

        return array_map(
            fn (array $row) => $this->fact($row),
            $this->select("SELECT f.* FROM {$this->tables->facts} f WHERE ".implode(' AND ', $where).' ORDER BY f.valid_from, f.fact_key', $values),
        );
    }

    public function timeline(Scope $reader, string $subject): array
    {
        [$visible, $bindings] = $this->visible('f', $reader);

        return array_map(
            fn (array $row) => $this->fact($row),
            $this->select("SELECT f.* FROM {$this->tables->facts} f WHERE f.subject = ? AND {$visible} ORDER BY f.valid_from, f.recorded_at, f.fact_key", [$subject, ...$bindings]),
        );
    }

    public function eraseSource(Scope $scope, string $key): EraseReport
    {
        $report = null;
        $this->transaction(function () use ($scope, $key, &$report): void {
            $sourceId = $scope->qualify('source', $key);
            $found = $this->select("SELECT id FROM {$this->tables->sources} WHERE id = ?", [$sourceId]) !== [];
            if (! $found) {
                $report = new EraseReport(false, 0, 0, 0, 0);

                return;
            }
            $chunkIds = array_map(static fn ($id) => trim((string) $id), array_column(
                $this->select("SELECT id FROM {$this->tables->chunks} WHERE source_id = ?", [$sourceId]),
                'id',
            ));
            $links = 0;
            $affected = [];
            foreach (array_chunk($chunkIds, 500) as $batch) {
                foreach ($this->select("SELECT DISTINCT fact_id FROM {$this->tables->evidence} WHERE chunk_id IN ({$this->marks($batch)})", $batch) as $row) {
                    $affected[trim((string) $row['fact_id'])] = true;
                }
                $links += $this->run("DELETE FROM {$this->tables->evidence} WHERE chunk_id IN ({$this->marks($batch)})", $batch);
            }
            $erased = 0;
            $kept = 0;
            foreach (array_chunk(array_keys($affected), 500) as $batch) {
                $orphans = array_map(static fn ($id) => trim((string) $id), array_column($this->select(
                    "SELECT f.id FROM {$this->tables->facts} f WHERE f.id IN ({$this->marks($batch)})
                     AND NOT EXISTS (SELECT 1 FROM {$this->tables->evidence} e WHERE e.fact_id = f.id)",
                    $batch,
                ), 'id'));
                if ($orphans !== []) {
                    $erased += $this->run("DELETE FROM {$this->tables->facts} WHERE id IN ({$this->marks($orphans)})", $orphans);
                }
                $kept += count($batch) - count($orphans);
            }
            $chunks = $this->run("DELETE FROM {$this->tables->chunks} WHERE source_id = ?", [$sourceId]);
            $this->run("DELETE FROM {$this->tables->sources} WHERE id = ?", [$sourceId]);
            $report = new EraseReport(true, $chunks, $links, $erased, $kept, $chunkIds);
        });

        return $report;
    }

    private function insertFact(Scope $scope, Fact $fact, AcceptancePolicy $policy): Fact
    {
        return $this->writeFact($scope, $fact, $this->prepareFact($scope, $fact, $policy));
    }

    /**
     * Validate a fact and decide its status, READING ONLY.
     *
     * @return array{from: string, to: ?string, evidence: list<string>, status: FactStatus}
     */
    private function prepareFact(Scope $scope, Fact $fact, AcceptancePolicy $policy): array
    {
        $this->key($fact->id);
        $from = $this->time($fact->validFrom);
        $to = $fact->validTo === null ? null : $this->time($fact->validTo);
        if ($to !== null && $to < $from) {
            throw new GraphException('A fact cannot end before it starts.');
        }
        if ($fact->supersededBy !== null || $fact->status !== null || $fact->recordedAt !== null || $fact->expiredAt !== null) {
            throw new GraphException('Status, recorded times and superseded_by are set by the store, never asserted.');
        }
        $evidence = array_values(array_unique($fact->evidence));
        if ($evidence === []) {
            throw new GraphException('A fact must cite at least one chunk as evidence.');
        }
        // Evidence must be visible from the FACT's scope: a tenant-wide fact can
        // never cite one person's private thread, so reading a fact can never
        // expose evidence its reader could not see.
        $chunks = $this->getChunks($scope, $evidence);
        if (count($chunks) !== count($evidence)) {
            throw new GraphException('Every evidence id must name a stored chunk visible from the fact\'s scope.');
        }
        // Text its source no longer contains cannot become NEW evidence: a
        // retired chunk is history, kept so old citations resolve.
        if (array_filter($chunks, static fn (Chunk $c) => $c->retiredAt !== null) !== []) {
            throw new GraphException('A retired chunk cannot be cited as new evidence.');
        }
        $status = $policy->decide(array_map(static fn (Chunk $c) => $c->trust ?? Trust::System, $chunks));

        return ['from' => $from, 'to' => $to, 'evidence' => $evidence, 'status' => $status];
    }

    /** @param array{from: string, to: ?string, evidence: list<string>, status: FactStatus} $prepared */
    private function writeFact(Scope $scope, Fact $fact, array $prepared): Fact
    {
        ['from' => $from, 'to' => $to, 'evidence' => $evidence, 'status' => $status] = $prepared;
        $now = $this->now();
        $id = $scope->qualify('fact', $fact->id);
        $this->run(
            "INSERT INTO {$this->tables->facts}
             (id, fact_key, tenant_id, owner_id, agent_id, thread_id, subject, predicate, object, status, valid_from, valid_to, superseded_by, recorded_at, expired_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL)",
            [$id, $fact->id, ...array_values($scope->toArray()), $fact->subject, $fact->predicate, $fact->object, $status->value, $from, $to, $now],
        );
        $statement = $this->pdo->prepare("INSERT INTO {$this->tables->evidence} (fact_id, chunk_id) VALUES (?, ?)");
        foreach ($evidence as $chunkId) {
            $statement->execute([$id, $chunkId]);
        }

        return new Fact($fact->id, $fact->subject, $fact->predicate, $fact->object, $evidence, $from, $to, null, $status, $now, null);
    }

    /**
     * The visibility predicate for a reader, over a table aliased $alias that
     * carries the four scope columns.
     *
     * @return array{0: string, 1: list<string|null>}
     */
    private function visible(string $alias, Scope $reader): array
    {
        return [
            "{$alias}.tenant_id = ?"
            ." AND ({$alias}.owner_id IS NULL OR {$alias}.owner_id = ?)"
            ." AND ({$alias}.agent_id IS NULL OR {$alias}.agent_id = ?)"
            ." AND ({$alias}.thread_id IS NULL OR {$alias}.thread_id = ?)",
            [$reader->tenant, $reader->owner, $reader->agent, $reader->thread],
        ];
    }

    private function key(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('Keys are 1-191 printable ASCII characters with no spaces.');
        }
    }

    private function now(): string
    {
        return $this->time(($this->clock)());
    }

    /**
     * Validate a UTC ISO 8601 time and return it in ONE fixed-width form
     * (microseconds, Z). Stored times are compared as strings, so every time
     * must share a precision: otherwise "...00Z" sorts after "...00.9Z".
     */
    private function time(string $value): string
    {
        if (preg_match(self::TIME, $value, $m) !== 1) {
            throw new InvalidArgumentException('Times must be UTC ISO 8601, e.g. 2026-11-14T00:00:00Z.');
        }
        $base = substr($value, 0, 19);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $base, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s') !== $base) {
            throw new InvalidArgumentException('Times must name a real UTC date and time.');
        }

        return $base.'.'.str_pad(substr($m[1] ?? '', 1), 6, '0').'Z';
    }

    /** @param array<string, mixed> $row */
    private function chunk(array $row): Chunk
    {
        return new Chunk(
            trim((string) $row['id']), trim((string) $row['source_id']), (string) $row['text'],
            (int) $row['byte_start'], (int) $row['byte_end'], (int) $row['line_start'], (int) $row['line_end'],
            json_decode((string) $row['heading_path'], true, 16, JSON_THROW_ON_ERROR), (string) $row['recipe'],
            isset($row['retired_at']) ? trim((string) $row['retired_at']) : null,
            isset($row['source_key']) ? (string) $row['source_key'] : null,
            isset($row['trust']) ? Trust::tryFrom((string) $row['trust']) : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function fact(array $row): Fact
    {
        $evidence = array_map(static fn ($id) => trim((string) $id), array_column(
            $this->select("SELECT chunk_id FROM {$this->tables->evidence} WHERE fact_id = ? ORDER BY chunk_id", [$row['id']]),
            'chunk_id',
        ));
        $opt = static fn (mixed $v): ?string => $v === null ? null : trim((string) $v);

        return new Fact(
            (string) $row['fact_key'], (string) $row['subject'], (string) $row['predicate'], (string) $row['object'],
            $evidence, trim((string) $row['valid_from']), $opt($row['valid_to']), $opt($row['superseded_by']),
            FactStatus::from((string) $row['status']), trim((string) $row['recorded_at']), $opt($row['expired_at']),
        );
    }

    /** @param list<mixed> $values */
    private function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /** @param list<mixed> $bindings @return list<array<string, mixed>> */
    private function select(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<mixed> $bindings */
    private function run(string $sql, array $bindings): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /** All-or-nothing, including inside a caller's transaction (savepoint). */
    private function transaction(callable $work): void
    {
        Atomic::run($this->pdo, $work);
    }
}
