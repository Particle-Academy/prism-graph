<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\TableMap;

/**
 * The core columns prism-graph reads and writes. This migration is YOURS once
 * published: add domain columns, indexes or soft deletes in your own
 * migrations, and keep config('prism-graph.tables') pointing at the tables.
 *
 * Scope columns (tenant_id, owner_id, agent_id, thread_id) are filtered on by
 * every read; do not drop them. Full-text search is FTS5 on SQLite and a
 * generated tsvector on PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('prism-graph.tables');
        $scope = function (Blueprint $table): void {
            $table->string('tenant_id', 191);
            $table->string('owner_id', 191)->nullable();
            $table->string('agent_id', 191)->nullable();
            $table->string('thread_id', 191)->nullable();
        };

        Schema::create($t['sources'], function (Blueprint $table) use ($scope) {
            $table->char('id', 64)->primary();
            $table->string('source_key', 191);
            $scope($table);
            $table->text('uri');
            $table->char('sha256', 64);
            $table->json('metadata');
            $table->string('trust', 20);
            $table->string('writer', 191);
            $table->char('occurred_at', 27)->nullable();
            $table->char('recorded_at', 27);
            $table->index(['tenant_id', 'owner_id', 'agent_id', 'thread_id']);
        });
        Schema::create($t['chunks'], function (Blueprint $table) use ($t) {
            $table->char('id', 64)->primary();
            $table->char('source_id', 64);
            $table->text('text');
            $table->integer('byte_start');
            $table->integer('byte_end');
            $table->integer('line_start');
            $table->integer('line_end');
            $table->json('heading_path');
            $table->string('recipe', 191);
            $table->char('retired_at', 27)->nullable();
            $table->foreign('source_id')->references('id')->on($t['sources']);
            $table->index(['source_id', 'byte_start']);
        });
        Schema::create($t['facts'], function (Blueprint $table) use ($scope) {
            $table->char('id', 64)->primary();
            $table->string('fact_key', 191);
            $scope($table);
            $table->string('subject', 191);
            $table->string('predicate', 191);
            $table->text('object');
            $table->string('status', 20);
            $table->char('valid_from', 27);
            $table->char('valid_to', 27)->nullable();
            $table->string('superseded_by', 191)->nullable();
            $table->char('recorded_at', 27);
            $table->char('expired_at', 27)->nullable();
            $table->index(['tenant_id', 'subject', 'valid_to']);
        });
        Schema::create($t['evidence'], function (Blueprint $table) use ($t) {
            $table->char('fact_id', 64);
            $table->char('chunk_id', 64);
            $table->primary(['fact_id', 'chunk_id']);
            $table->foreign('fact_id')->references('id')->on($t['facts']);
            $table->foreign('chunk_id')->references('id')->on($t['chunks']);
            $table->index('chunk_id');
        });

        foreach (BaseSchema::fullText(DB::connection()->getDriverName(), TableMap::from($t)) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $t = config('prism-graph.tables');
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS '.TableMap::from($t)->fts());
        }
        foreach (['evidence', 'facts', 'chunks', 'sources'] as $role) {
            Schema::dropIfExists($t[$role]);
        }
    }
};
