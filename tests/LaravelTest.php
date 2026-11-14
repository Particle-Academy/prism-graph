<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Graph;
use Prism\Graph\Laravel\PrismGraphServiceProvider;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Trust;

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
            $table->string('department')->nullable();
        });
        Schema::table('kb_passages', function (Blueprint $table) {
            $table->boolean('reviewed')->default(false);
        });

        // The renamed chunks table must also carry its full-text index, or search finds nothing.
        $memory = $this->app->make(Graph::class)->scope(Scope::tenant('acme')->owner('u1'));
        $report = $memory->ingest('doc-1', 'file://doc-1.md', "# Title\n\nThe heron nests by the river.\n", new MarkdownChunker, new Origin(Trust::Document, 'importer'));
        $this->assertGreaterThan(0, $report->stored);

        $hits = $memory->search('heron river');
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('heron', $hits[0]->chunk->text);
    }

    public function test_the_repair_command_restores_search_after_an_altering_migration(): void
    {
        $this->migrate();
        // An app migration that SQLite can only do by rebuilding the table drops our triggers.
        Schema::table('kb_passages', function (Blueprint $table) {
            $table->string('reviewed_by', 50)->nullable();
        });
        Schema::table('kb_passages', function (Blueprint $table) {
            $table->text('reviewed_by')->nullable()->change();
        });
        $triggers = \Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'kb_passages'");
        $this->assertSame([], $triggers, 'precondition: the table rebuild dropped the full-text triggers');
        $memory = $this->app->make(Graph::class)->scope(Scope::tenant('acme')->owner('u1'));
        $memory->ingest('doc', 'f://d', "The heron nests by the river.\n", new MarkdownChunker, new Origin(Trust::Document, 'importer'));

        $this->artisan('prism-graph:repair-fulltext')->assertSuccessful();

        $this->assertNotEmpty($memory->search('heron'));
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
