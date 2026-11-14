<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Graph;
use Prism\Graph\Laravel\PrismGraphServiceProvider;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrismGraphServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        // The app renames a table: the graph must follow the config, not a default.
        $app['config']->set('prism-graph.tables.chunks', 'kb_passages');
    }

    private function migrate(): void
    {
        foreach (glob(__DIR__.'/../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    public function test_nothing_is_created_until_the_app_runs_its_migrations(): void
    {
        $this->app->make(Graph::class);
        $this->assertFalse(Schema::hasTable('graph_sources'));
        $this->assertFalse(Schema::hasTable('kb_passages'));
    }

    public function test_published_migrations_follow_the_configured_table_names(): void
    {
        $this->migrate();
        $this->assertTrue(Schema::hasTable('kb_passages'));
        $this->assertFalse(Schema::hasTable('graph_chunks'));
        // Vectors are optional and off by default: no vector tables.
        $this->assertFalse(Schema::hasTable('graph_vectors'));
    }

    public function test_the_app_can_add_its_own_columns_and_the_graph_still_works(): void
    {
        $this->migrate();
        Schema::table('graph_sources', function (Blueprint $table) {
            $table->string('tenant_id')->nullable();
        });
        Schema::table('kb_passages', function (Blueprint $table) {
            $table->boolean('reviewed')->default(false);
        });

        $graph = $this->app->make(Graph::class);
        $report = $graph->ingest('doc-1', 'file://doc-1.md', "# Title\n\nThe heron nests by the river.\n", new MarkdownChunker);
        $this->assertGreaterThan(0, $report->stored);

        $hits = $graph->search('heron river');
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('heron', $hits[0]->chunk->text);
    }

    public function test_vector_migration_creates_the_pdo_tables_when_enabled(): void
    {
        $this->app['config']->set('prism-graph.vectors', 'pdo');
        $this->migrate();
        $this->assertTrue(Schema::hasTable('graph_vector_indexes'));
        $this->assertTrue(Schema::hasTable('graph_vectors'));
    }

    public function test_an_unknown_vector_driver_is_refused(): void
    {
        $this->app['config']->set('prism-graph.vectors', 'nope');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(Graph::class);
    }
}
