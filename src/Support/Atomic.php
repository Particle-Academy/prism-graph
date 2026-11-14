<?php

declare(strict_types=1);

namespace Prism\Graph\Support;

use PDO;
use Throwable;

/**
 * Runs work all-or-nothing, including when the CALLER already holds a
 * transaction.
 *
 * Joining an outer transaction without a savepoint is not atomic: when our
 * work throws, nothing of ours is rolled back, and if the caller catches the
 * exception and commits, our partial writes land. For memory that is a
 * security bug, because a refused supersede would leave the accepted fact
 * closed and the refused replacement stored (reported by impactium against
 * 0.2.0). So inside an outer transaction the work runs in a SAVEPOINT, which
 * SQLite and PostgreSQL both support: a failure rolls back exactly our work
 * and leaves the caller's transaction usable.
 *
 * @internal
 */
final class Atomic
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        if (! $pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $result = $work();
                $pdo->commit();

                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $savepoint = 'prism_graph_sp_'.(++self::$depth);
        $pdo->exec("SAVEPOINT {$savepoint}");
        try {
            $result = $work();
            $pdo->exec("RELEASE SAVEPOINT {$savepoint}");

            return $result;
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            throw $e;
        } finally {
            self::$depth--;
        }
    }
}
