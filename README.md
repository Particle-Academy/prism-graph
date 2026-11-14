# prism-graph

Knowledge-graph plumbing for PHP apps: chunk documents verbatim, store them in
**your** tables, search them (text, plus optional vectors), and record facts
that must cite the passages they came from.

It provides the plumbing and the tools. It does not decide your data's shape:
the tables are yours, created by migrations you publish and own.

- **Storage:** SQLite 3.24+ or PostgreSQL, over PDO.
- **Vectors (optional):** none, exact search in the same database, sqlite-vec,
  pgvector, or any vector database through the `VectorStore` contract.
- **Embeddings:** through [Prism](https://github.com/Particle-Academy/prism)
  (`Prism::embeddings()`), or any `Embedder` you write.

## Guarantees

- **Verbatim is the floor.** A chunk is an exact byte span of its source.
  Derived data (vectors, facts) ranks results. It never replaces or hides text.
- **Content-addressed chunks.** A chunk id is the sha256 of its recipe, source,
  byte span and text. The same input gives the same id, so re-ingesting is a no-op.
  A forged id is refused.
- **Pinned embedders.** Each vector index is pinned to one provider, model,
  dimension and chunk recipe. Vectors from a different embedder are refused, never mixed.
- **Facts cite evidence.** A fact needs at least one stored chunk behind it.
  Superseding a fact closes the old one and keeps it as history, in one
  transaction.
- **Reads never write.** Search, get and facts only run SELECTs.
- **Nothing creates tables at runtime.**

## Install (Laravel)

```bash
composer require particle-academy/prism-graph
php artisan vendor:publish --tag=prism-graph-config
php artisan vendor:publish --tag=prism-graph-migrations
php artisan migrate
```

The published migrations create the core columns the graph reads and writes.
From there they are your migrations:

- Add columns such as tenant, owner, review state or soft deletes in your own
  migrations.
- Rename any table, then point `config/prism-graph.php` → `tables` at it.

The graph only touches the columns it owns.

Embedding with Prism needs `particle-academy/prism` installed and a provider key
in your app's Prism config.

```php
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Graph;

$graph = app(Graph::class);

$graph->ingest('handbook', 'file://handbook.md', $markdown, new MarkdownChunker);

foreach ($graph->search('refund policy') as $hit) {
    echo $hit->chunk->text;            // exact text
    echo $hit->chunk->sourceId;        // where it came from
    echo $hit->chunk->lineStart;       // and where in it
}
```

### Vectors

Vectors are off by default: search is text-only and no vector tables are
created. To enable them, set `PRISM_GRAPH_VECTORS` **before** running the
vector migration:

| value | what it uses |
|---|---|
| `pdo` | Exact cosine search in PHP. Any SQLite or PostgreSQL database, no extension needed. Fine for small and medium indexes. |
| `sqlite-vec` | [sqlite-vec](https://github.com/asg017/sqlite-vec), with distance computed in C. It uses the same tables as `pdo`, so switching needs no migration. Your app loads the extension on the connection (PHP 8.4+: `Pdo\Sqlite::loadExtension`). |
| `pgvector` | PostgreSQL with [pgvector](https://github.com/pgvector/pgvector). Add a sized column and an HNSW index in your own migration once the index is large. |
| a container binding | Your own `Prism\Graph\Contracts\VectorStore`, for Qdrant, Pinecone, Weaviate and the like. |

The embedder is set by `PRISM_GRAPH_EMBED_PROVIDER`, `_MODEL` and `_DIMENSIONS`.
To change models, use a new index name: an existing index refuses a different
embedder.

Search with vectors on returns the vector hits first. It then fills from text
search, so a chunk that has no vector yet can still be found.

## Without Laravel

```php
use Prism\Graph\Graph;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;

$pdo = new PDO('sqlite:graph.db');
// Run these once, from your own migration tooling:
foreach (BaseSchema::statements('sqlite') as $sql) { $pdo->exec($sql); }

$graph = new Graph(new PdoGraphStore($pdo), $pdo);
```

A `TableMap` maps each role (`sources`, `chunks`, `nodes`, `edges`, `facts`,
`evidence`) onto your own table names.

## Facts

```php
use Prism\Graph\Fact;

$chunk = $graph->search('closed mondays')[0]->chunk;
$graph->assert(new Fact('f1', 'shop', 'closes_on', 'monday', [$chunk->id], '2026-01-01T00:00:00Z'));

// Later, the source changes:
$graph->supersede('f1', new Fact('f2', 'shop', 'closes_on', 'sunday', [$newChunk->id], '2026-06-01T00:00:00Z'));

$graph->facts('shop');                          // what holds now
$graph->facts('shop', '2026-03-01T00:00:00Z');  // what held then
```

Times are UTC ISO 8601.

## Agent tools

`GraphTools` exposes three **read-only** tools, `graph_search`, `graph_get` and
`graph_facts`, as Prism `Tool` objects:

```php
use Prism\Graph\Tools\GraphTools;

$tools = (new GraphTools(app(Graph::class)))->prismTools();
```

- **Results:** JSON, with every passage carrying its chunk id so an answer can
  be checked against the source.
- **Limits:** clamped, whatever the model asks for.
- **Ids:** malformed ids are refused before any query runs.
- **Writes:** no tool writes. If an agent should assert facts, build that tool
  in your app, with your own review step.
- **Prompt injection:** passages are stored text. Treat them as data in your
  prompts, not as instructions.

## Wake context

`Wake` assembles a context block from layers under a token budget, for example
the facts about a subject plus the top search hits. Each layer has its own
budget, and the report lists what was left out rather than silently truncating.

## Chunking

- `WindowChunker` splits into byte windows with overlap. It prefers paragraph,
  then sentence, then line breaks, and never splits a UTF-8 character.
- `MarkdownChunker` does the same within heading sections and records the
  heading path.

A chunker's settings are part of its recipe, so changing them produces new
chunk ids rather than silently re-labelling old ones.

## Testing

```bash
composer install
vendor/bin/phpunit
```

- **PostgreSQL tests:** set `PRISM_GRAPH_TEST_PG_DSN`, `_USER` and `_PASSWORD`.
  Each test creates and drops its own `graph_test_*` schema.
- **sqlite-vec tests:** need PHP 8.4 and `PRISM_GRAPH_SQLITE_VEC` set to the
  `vec0` binary.

## License

MIT
