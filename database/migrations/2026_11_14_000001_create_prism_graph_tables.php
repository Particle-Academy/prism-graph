<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core columns prism-graph reads and writes. This migration is YOURS once
 * published: add domain columns, indexes or soft deletes in your own
 * migrations, and keep config('prism-graph.tables') pointing at the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('prism-graph.tables');

        Schema::create($t['sources'], function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->text('uri');
            $table->char('sha256', 64);
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create($t['chunks'], function (Blueprint $table) use ($t) {
            $table->char('id', 64)->primary();
            $table->string('source_id', 191);
            $table->text('text');
            $table->integer('byte_start');
            $table->integer('byte_end');
            $table->integer('line_start');
            $table->integer('line_end');
            $table->json('heading_path');
            $table->string('recipe', 191);
            $table->char('retired_at', 27)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('source_id')->references('id')->on($t['sources']);
            $table->index(['source_id', 'byte_start']);
        });
        Schema::create($t['nodes'], function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('type', 100);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create($t['edges'], function (Blueprint $table) use ($t) {
            $table->string('id', 191)->primary();
            $table->string('from_id', 191);
            $table->string('to_id', 191);
            $table->string('type', 100);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('from_id')->references('id')->on($t['nodes']);
            $table->foreign('to_id')->references('id')->on($t['nodes']);
            $table->index(['from_id', 'type']);
            $table->index(['to_id', 'type']);
        });
        Schema::create($t['facts'], function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('subject', 191);
            $table->string('predicate', 191);
            $table->text('object');
            $table->char('valid_from', 27);
            $table->char('valid_to', 27)->nullable();
            $table->string('superseded_by', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject', 'valid_to']);
        });
        Schema::create($t['evidence'], function (Blueprint $table) use ($t) {
            $table->string('fact_id', 191);
            $table->char('chunk_id', 64);
            $table->primary(['fact_id', 'chunk_id']);
            $table->foreign('fact_id')->references('id')->on($t['facts']);
            $table->foreign('chunk_id')->references('id')->on($t['chunks']);
        });
    }

    public function down(): void
    {
        $t = config('prism-graph.tables');
        foreach (['evidence', 'facts', 'edges', 'nodes', 'chunks', 'sources'] as $role) {
            Schema::dropIfExists($t[$role]);
        }
    }
};
