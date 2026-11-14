<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use PDO;
use Prism\Graph\Chunk;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\TableMap;
use Throwable;

/**
 * GraphStore over PDO for SQLite (3.24+) and PostgreSQL. Every value is a bound
 * parameter; table names come only from a validated TableMap. Reads only SELECT.
 */
final class PdoGraphStore implements GraphStore
{
    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/D';

    private readonly string $driver;

    public function __construct(private readonly PDO $pdo, private readonly TableMap $tables = new TableMap)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($this->driver, ['sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException("Unsupported PDO driver [{$this->driver}]; use sqlite or pgsql.");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function putSource(string $id, string $uri, string $sha256, array $metadata = []): void
    {
        $this->run(
            "INSERT INTO {$this->tables->sources} (id, uri, sha256, metadata) VALUES (?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET uri = excluded.uri, sha256 = excluded.sha256, metadata = excluded.metadata",
            [$id, $uri, $sha256, json_encode($metadata, JSON_THROW_ON_ERROR)],
        );
    }

    public function putChunks(array $chunks): int
    {
        $inserted = 0;
        $this->transaction(function () use ($chunks, &$inserted): void {
            $statement = $this->pdo->prepare(
                "INSERT INTO {$this->tables->chunks}
                 (id, source_id, text, byte_start, byte_end, line_start, line_end, heading_path, recipe)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING",
            );
            foreach ($chunks as $chunk) {
                if (! $chunk instanceof Chunk) {
                    throw new InvalidArgumentException('putChunks() takes Chunk instances.');
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
                $inserted += $statement->rowCount();
            }
        });

        return $inserted;
    }

    public function retireExcept(string $sourceId, array $keepIds): int
    {
        $retired = 0;
        $this->transaction(function () use ($sourceId, $keepIds, &$retired): void {
            $now = gmdate('Y-m-d\TH:i:s').'.000000Z';
            $this->run("UPDATE {$this->tables->chunks} SET retired_at = ? WHERE source_id = ? AND retired_at IS NULL", [$now, $sourceId]);
            foreach (array_chunk(array_values(array_unique(array_map('strval', $keepIds))), 500) as $batch) {
                $marks = implode(',', array_fill(0, count($batch), '?'));
                $this->run("UPDATE {$this->tables->chunks} SET retired_at = NULL WHERE source_id = ? AND id IN ({$marks})", [$sourceId, ...$batch]);
            }
            $retired = (int) $this->select(
                "SELECT COUNT(*) AS n FROM {$this->tables->chunks} WHERE source_id = ? AND retired_at IS NOT NULL",
                [$sourceId],
            )[0]['n'];
        });

        return $retired;
    }

    public function getChunks(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->select("SELECT * FROM {$this->tables->chunks} WHERE id IN ({$marks})", $ids);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $this->chunk($row);
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }

    public function chunksForSource(string $sourceId): array
    {
        return array_map(
            fn (array $row) => $this->chunk($row),
            $this->select("SELECT * FROM {$this->tables->chunks} WHERE source_id = ? ORDER BY byte_start, byte_end", [$sourceId]),
        );
    }

    public function putNode(string $id, string $type): void
    {
        $this->run("INSERT INTO {$this->tables->nodes} (id, type) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET type = excluded.type", [$id, $type]);
    }

    public function putEdge(string $id, string $from, string $to, string $type): void
    {
        $this->run(
            "INSERT INTO {$this->tables->edges} (id, from_id, to_id, type) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING",
            [$id, $from, $to, $type],
        );
    }

    public function assertFact(Fact $fact): void
    {
        $this->transaction(fn () => $this->insertFact($fact));
    }

    public function supersede(string $oldId, Fact $replacement): void
    {
        $this->transaction(function () use ($oldId, $replacement): void {
            $from = $this->time($replacement->validFrom);
            // A write first, so SQLite takes the writer lock before any read in this transaction.
            $closed = $this->run(
                "UPDATE {$this->tables->facts} SET valid_to = ?, superseded_by = ?
                 WHERE id = ? AND valid_to IS NULL AND valid_from <= ?",
                [$from, $replacement->id, $oldId, $from],
            );
            if ($closed !== 1) {
                throw new GraphException('Only a fact still in force, valid before the replacement starts, can be superseded.');
            }
            $this->insertFact($replacement);
        });
    }

    public function factsAbout(string $subject, ?string $at = null): array
    {
        if ($at === null) {
            $rows = $this->select("SELECT * FROM {$this->tables->facts} WHERE subject = ? AND valid_to IS NULL ORDER BY valid_from, id", [$subject]);
        } else {
            $at = $this->time($at);
            $rows = $this->select(
                "SELECT * FROM {$this->tables->facts} WHERE subject = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?) ORDER BY valid_from, id",
                [$subject, $at, $at],
            );
        }

        return array_map(fn (array $row) => $this->fact($row), $rows);
    }

    private function insertFact(Fact $fact): void
    {
        $from = $this->time($fact->validFrom);
        $to = $fact->validTo === null ? null : $this->time($fact->validTo);
        if ($to !== null && $to < $from) {
            throw new GraphException('A fact cannot end before it starts.');
        }
        if ($fact->supersededBy !== null) {
            throw new GraphException('superseded_by is set only by supersede(), never asserted.');
        }
        $evidence = array_values(array_unique($fact->evidence));
        if ($evidence === []) {
            throw new GraphException('A fact must cite at least one chunk as evidence.');
        }
        if (count($this->getChunks($evidence)) !== count($evidence)) {
            throw new GraphException('Every evidence id must name a stored chunk.');
        }
        $this->run(
            "INSERT INTO {$this->tables->facts} (id, subject, predicate, object, valid_from, valid_to, superseded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$fact->id, $fact->subject, $fact->predicate, $fact->object, $from, $to, null],
        );
        $statement = $this->pdo->prepare("INSERT INTO {$this->tables->evidence} (fact_id, chunk_id) VALUES (?, ?)");
        foreach ($evidence as $chunkId) {
            $statement->execute([$fact->id, $chunkId]);
        }
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
            (string) $row['id'], (string) $row['source_id'], (string) $row['text'],
            (int) $row['byte_start'], (int) $row['byte_end'], (int) $row['line_start'], (int) $row['line_end'],
            json_decode((string) $row['heading_path'], true, 16, JSON_THROW_ON_ERROR), (string) $row['recipe'],
            isset($row['retired_at']) ? trim((string) $row['retired_at']) : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function fact(array $row): Fact
    {
        $evidence = array_column(
            $this->select("SELECT chunk_id FROM {$this->tables->evidence} WHERE fact_id = ? ORDER BY chunk_id", [$row['id']]),
            'chunk_id',
        );

        return new Fact(
            (string) $row['id'], (string) $row['subject'], (string) $row['predicate'], (string) $row['object'],
            array_map('strval', $evidence), (string) $row['valid_from'],
            $row['valid_to'] === null ? null : (string) $row['valid_to'],
            $row['superseded_by'] === null ? null : (string) $row['superseded_by'],
        );
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

    private function transaction(callable $work): void
    {
        $owns = ! $this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $work();
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
