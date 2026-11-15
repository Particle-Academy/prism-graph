<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use Prism\Graph\Laravel\PrismGraphServiceProvider;
use Prism\Graph\Laravel\SqliteVecLoader;
use Throwable;

/**
 * G-281: sqlite-vec loaded on named Laravel connections when they open. Loading the real extension needs PHP 8.4
 * and PRISM_GRAPH_SQLITE_VEC (CI sets PRISM_GRAPH_REQUIRE_SQLITE_VEC=1, so a missing binary fails there).
 */
final class SqliteVecLoaderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrismGraphServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('database.connections.pg', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => '', 'prefix' => '']);
    }

    private static function vecPath(): ?string
    {
        $path = getenv('PRISM_GRAPH_SQLITE_VEC') ?: '';

        return $path !== '' && PHP_VERSION_ID >= 80400 ? $path : null;
    }

    protected function withVec($app): void
    {
        $path = self::vecPath();
        if ($path === null) {
            return;
        }
        $app['config']->set('prism-graph.sqlite_vec.extension_path', $path);
        $app['config']->set('prism-graph.sqlite_vec.connections', ['testing', 'pg']);
    }

    private function requireVec(): void
    {
        if (self::vecPath() !== null) {
            return;
        }
        if (getenv('PRISM_GRAPH_REQUIRE_SQLITE_VEC') === '1') {
            $this->fail('PRISM_GRAPH_REQUIRE_SQLITE_VEC=1 but sqlite-vec is not available.');
        }
        $this->markTestSkipped('sqlite-vec not available.');
    }

    #[DefineEnvironment('withVec')]
    public function test_loads_on_a_named_connection_and_not_on_another(): void
    {
        $this->requireVec();
        $this->assertNotEmpty(DB::connection('testing')->selectOne('select vec_version() as v')->v);
        $this->expectException(Throwable::class);
        DB::connection('other')->selectOne('select vec_version() as v');
    }

    #[DefineEnvironment('withVec')]
    public function test_refuses_a_named_connection_that_is_not_sqlite(): void
    {
        $this->requireVec();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a sqlite connection');
        DB::connection('pg');
    }

    public function test_loads_nothing_when_no_path_is_configured(): void
    {
        $this->assertFalse($this->app->bound(SqliteVecLoader::class));
        $this->expectException(Throwable::class);
        DB::connection('testing')->selectOne('select vec_version() as v');
    }

    public function test_refuses_a_relative_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute');
        SqliteVecLoader::make('vec0.so', ['testing']);
    }

    public function test_refuses_a_path_that_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('existing file');
        SqliteVecLoader::make(sys_get_temp_dir().'/prism-graph-no-such-vec0.so', ['testing']);
    }

    public function test_refuses_a_directory_and_an_empty_connection_list(): void
    {
        try {
            SqliteVecLoader::make(sys_get_temp_dir(), ['testing']);
            $this->fail('a directory was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('existing file', $e->getMessage());
        }
        if (PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('the connection check runs after the PHP 8.4 check');
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one connection');
        SqliteVecLoader::make(__FILE__, []);
    }
}
