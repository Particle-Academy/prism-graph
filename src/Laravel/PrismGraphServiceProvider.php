<?php

declare(strict_types=1);

namespace Prism\Graph\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Contracts\VectorStore;
use Prism\Graph\Embedding\PrismEmbedder;
use Prism\Graph\Graph;
use Prism\Graph\IndexSpec;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Storage\PgVectorStore;
use Prism\Graph\Storage\SqliteVecStore;
use Prism\Graph\TableMap;

/**
 * Laravel wiring. Migrations are PUBLISHED, never auto-run: the app owns its
 * schema. Publish with:
 *   php artisan vendor:publish --tag=prism-graph-config
 *   php artisan vendor:publish --tag=prism-graph-migrations
 */
final class PrismGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/prism-graph.php', 'prism-graph');

        $this->app->singleton(TableMap::class, fn (Application $app) => TableMap::from($app['config']->get('prism-graph.tables', [])));
        $this->app->singleton(GraphStore::class, fn (Application $app) => new PdoGraphStore($this->pdo($app), $app->make(TableMap::class)));
        $this->app->singleton(Graph::class, function (Application $app): Graph {
            $vectors = $this->vectors($app);

            return new Graph(
                $app->make(GraphStore::class),
                $this->pdo($app),
                $app->make(TableMap::class),
                $vectors,
                $vectors === null ? null : new PrismEmbedder($this->spec($app)),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/prism-graph.php' => config_path('prism-graph.php')], 'prism-graph-config');
        $this->publishes([__DIR__.'/../../database/migrations' => database_path('migrations')], 'prism-graph-migrations');
    }

    private function pdo(Application $app): \PDO
    {
        return $app['db']->connection($app['config']->get('prism-graph.connection'))->getPdo();
    }

    private function vectors(Application $app): ?VectorStore
    {
        $driver = $app['config']->get('prism-graph.vectors');

        return match (true) {
            $driver === null || $driver === '' => null,
            $driver === 'pdo' => new PdoVectorStore($this->pdo($app)),
            $driver === 'pgvector' => new PgVectorStore($this->pdo($app)),
            // The app loads the extension on the connection (Pdo\Sqlite::loadExtension).
            $driver === 'sqlite-vec' => new SqliteVecStore($this->pdo($app)),
            $app->bound($driver) => $app->make($driver),
            default => throw new InvalidArgumentException("Unknown prism-graph vector driver [{$driver}]."),
        };
    }

    private function spec(Application $app): IndexSpec
    {
        $index = $app['config']->get('prism-graph.index');

        return new IndexSpec((string) $index['name'], (string) $index['provider'], (string) $index['model'], (int) $index['dimensions']);
    }
}
