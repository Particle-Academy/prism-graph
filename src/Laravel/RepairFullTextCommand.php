<?php

declare(strict_types=1);

namespace Prism\Graph\Laravel;

use Illuminate\Console\Command;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\TableMap;

/**
 * Restores prism-graph's full-text index after a migration that altered the
 * chunks table (on SQLite such a migration drops the index's triggers), and
 * rebuilds it from the stored chunks. Safe to run repeatedly; a natural last
 * step after `php artisan migrate`.
 */
final class RepairFullTextCommand extends Command
{
    protected $signature = 'prism-graph:repair-fulltext';

    protected $description = "Restore and rebuild prism-graph's full-text index (run after migrations that alter the chunks table)";

    public function handle(TableMap $tables): int
    {
        $connection = $this->laravel['db']->connection($this->laravel['config']->get('prism-graph.connection'));
        $statements = BaseSchema::repairFullText($connection->getDriverName(), $tables);
        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
        $this->info('prism-graph full-text index restored and rebuilt ('.count($statements).' statements).');

        return self::SUCCESS;
    }
}
