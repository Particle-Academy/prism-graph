<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Only needed when config('prism-graph.vectors') is set. For 'pgvector' this
 * enables the extension and adds an unsized vector column; add a sized column
 * and an HNSW index in your own migration once an index is large.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = config('prism-graph.vectors');
        if ($driver === null) {
            return;
        }
        Schema::create('graph_vector_indexes', function (Blueprint $table) {
            $table->string('name', 100)->primary();
            $table->char('fingerprint', 64);
            $table->string('provider', 100);
            $table->string('model', 191);
            $table->integer('dimensions');
            $table->string('recipe', 191);
        });
        if ($driver === 'pgvector') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('CREATE TABLE graph_pgvectors (index_name VARCHAR(100) NOT NULL REFERENCES graph_vector_indexes (name),
                chunk_id CHAR(64) NOT NULL, embedding vector NOT NULL, PRIMARY KEY (index_name, chunk_id))');

            return;
        }
        Schema::create('graph_vectors', function (Blueprint $table) {
            $table->string('index_name', 100);
            $table->char('chunk_id', 64);
            $table->binary('vector');
            $table->primary(['index_name', 'chunk_id']);
            $table->foreign('index_name')->references('name')->on('graph_vector_indexes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_pgvectors');
        Schema::dropIfExists('graph_vectors');
        Schema::dropIfExists('graph_vector_indexes');
    }
};
