<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use PDO;

/** @internal Shared "which ids have no vector" and "forget these" queries for the PDO-backed stores. */
final class Missing
{
    /** @param list<string> $chunkIds */
    public static function forget(PDO $pdo, string $table, array $chunkIds): int
    {
        $removed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('strval', $chunkIds))), self::MAX_IDS) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $statement = $pdo->prepare("DELETE FROM {$table} WHERE chunk_id IN ({$marks})");
            $statement->execute($batch);
            $removed += $statement->rowCount();
        }

        return $removed;
    }

    public const MAX_IDS = 500;

    /** @param list<string> $chunkIds @return list<string> */
    public static function filter(PDO $pdo, string $table, string $index, array $chunkIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $chunkIds)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::MAX_IDS) {
            throw new InvalidArgumentException('Pass at most '.self::MAX_IDS.' chunk ids per call.');
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare("SELECT chunk_id FROM {$table} WHERE index_name = ? AND chunk_id IN ({$marks})");
        $statement->execute([$index, ...$ids]);
        $have = array_flip(array_map(static fn ($id) => trim((string) $id), $statement->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_filter($ids, static fn (string $id) => ! isset($have[$id])));
    }
}
