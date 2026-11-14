<?php

declare(strict_types=1);

namespace Prism\Graph\Storage;

use InvalidArgumentException;
use PDO;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;

/**
 * SQLite with the sqlite-vec extension (https://github.com/asg017/sqlite-vec).
 *
 * Same tables as PdoVectorStore: sqlite-vec reads packed little-endian float32,
 * which is the format already stored, so switching between the two needs no
 * migration. Distance runs in C inside SQLite rather than in PHP.
 *
 * Loading the extension is the app's job, because only the app knows where the
 * binary is. On PHP 8.4+: $pdo->loadExtension('/path/to/vec0'), with $pdo a
 * Pdo\Sqlite. This store refuses to start if the extension is missing rather
 * than failing on the first query.
 */
final class SqliteVecStore extends PdoVectorStore
{
    public function __construct(PDO $pdo, string $indexes = 'graph_vector_indexes', string $vectors = 'graph_vectors')
    {
        parent::__construct($pdo, $indexes, $vectors);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new GraphException('SqliteVecStore needs a SQLite connection.');
        }
        try {
            $version = $pdo->query('SELECT vec_version()')->fetchColumn();
        } catch (\PDOException) {
            $version = false;
        }
        if ($version === false) {
            throw new GraphException('The sqlite-vec extension is not loaded on this connection.');
        }
    }

    public function method(): string
    {
        return 'sqlite-vec';
    }

    public function nearest(IndexSpec $spec, array $query, int $k): array
    {
        $this->assertPinned($spec, $this->fingerprintOf($spec->name));
        if ($k < 1 || $k > self::MAX_K) {
            throw new InvalidArgumentException('k must be between 1 and '.self::MAX_K.'.');
        }
        $statement = $this->pdo->prepare(
            "SELECT chunk_id, 1.0 - vec_distance_cosine(vector, ?) AS score FROM {$this->vectors}
             WHERE index_name = ? ORDER BY score DESC, chunk_id LIMIT ?",
        );
        $statement->bindValue(1, $this->pack($spec, $query), PDO::PARAM_LOB);
        $statement->bindValue(2, $spec->name);
        $statement->bindValue(3, $k, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row) => ['chunk_id' => (string) $row['chunk_id'], 'score' => (float) $row['score']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
