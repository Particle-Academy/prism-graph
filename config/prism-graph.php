<?php

return [
    // The database connection the graph uses. null means the app's default.
    'connection' => env('PRISM_GRAPH_CONNECTION'),

    // App-owned tables for each role. Publish the migrations, then rename,
    // extend or point these at tables you already have.
    'tables' => [
        'sources' => 'graph_sources',
        'chunks' => 'graph_chunks',
        'nodes' => 'graph_nodes',
        'edges' => 'graph_edges',
        'facts' => 'graph_facts',
        'evidence' => 'graph_fact_evidence',
    ],

    // Vector search is OPTIONAL. null: text search and traversal only.
    // 'pdo': exact search stored in the same database (any SQLite/PostgreSQL).
    // 'pgvector': PostgreSQL with the pgvector extension.
    // Any other value: a container binding implementing Prism\Graph\Contracts\VectorStore.
    'vectors' => env('PRISM_GRAPH_VECTORS'),

    // The embedder an index is pinned to (used when 'vectors' is set).
    'index' => [
        'name' => env('PRISM_GRAPH_INDEX', 'main'),
        'provider' => env('PRISM_GRAPH_EMBED_PROVIDER', 'openai'),
        'model' => env('PRISM_GRAPH_EMBED_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('PRISM_GRAPH_EMBED_DIMENSIONS', 1536),
    ],
];
