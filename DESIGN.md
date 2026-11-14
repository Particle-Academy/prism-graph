# G-267 — prism-graph 0.1.0 design (PHP first)

Status: building, owned by prism (Wish 2026-11-13: "you build it, don't wait for the slave"). The consumer is impactium's Aion agent. It adopts MemPalace's concepts; see `.ai/plans/g267-mempalace-review-2026-11-07.md`.

## Principles (each one becomes a test)

1. **Verbatim chunks are the floor.** Every source document is stored as exact text chunks. Derived structures (embeddings, triples, summaries) can rank results, but can never hide or replace a chunk, so a query can always fall back to the verbatim text.
2. **Content-addressed ids with a versioned recipe.** `chunk_id = sha256(recipe_version + source_id + byte_range + text)`. The same input under the same recipe always yields the same id, and changing the recipe changes the ids, never silently.
3. **Declared transforms.** Every derived row records the transform that produced it and whether it's lossy. Lossless means a pure index; lossy means a summary or extraction, which is flagged and never treated as the source.
4. **Pinned embedder per index.** An index records the provider, model, dimensions and recipe. Querying with a different embedder is refused, never mixed.
5. **Validity-window triples.** `(subject, predicate, object, valid_from, valid_to, evidence_chunk_ids)`. Supersede is atomic: close the old window and open the new one in one transaction. Every triple must cite at least one chunk.
6. **Query sanitizing.** User and model query text is bound as parameters, never interpolated. FTS query syntax is escaped, and limits are capped.
7. **Time-budgeted layered wake-up.** `wake(budgetMs, tokenBudget)` returns the layers in order: pinned facts, recent or active triples, top chunks. It stops when the budget is reached and reports what it omitted.

Rejected: AAAK compression (lossy), read-driven "Hebbian" strengthening (reads must not mutate), and benchmark practice without a clean held-out split.

## Storage

The `GraphStore` contract has one implementation in 0.1.0, `SqliteGraphStore` (PDO SQLite plus sqlite-vec when loadable). When sqlite-vec isn't available, it uses brute-force cosine in PHP over BLOB vectors, labelled as such, not as a silent quality drop. Tables: `sources`, `chunks` (with FTS5), `embeddings`, `indexes`, `nodes`, `edges`, `triples`.

## API (PHP)

- `Graph::ingest(Source $s, ChunkRecipe $r): IngestReport` (idempotent, by content-addressed ids)
- `Graph::embed(IndexSpec $i)` (via `Prism::embeddings()`)
- `Graph::search(Query $q): Hits` (vector + FTS; verbatim chunks are always returned or reachable)
- `Graph::get(array $chunkIds)` (retrieve-by-id; prism-graph owns this, and prism-memory stays unchanged)
- `Graph::assert(Triple $t)` / `Graph::supersede(...)` / `Graph::facts(subject, at)`
- `Graph::wake(budgetMs, tokens): WakeReport`
- Harness tools: `graph_search`, `graph_get`, `graph_facts`. Write tools are off by default; enabling them must be an explicit choice in the mode.

## Chunking recipe v1

Split on paragraph boundaries into windows of at most N characters (default 2,000) with an overlap of O (default 200). Split mid-paragraph only at sentence ends, and as a last resort at a hard cut, which is recorded. Each chunk keeps the byte range of its source.

## Done means

Tests for each principle, including hostile cases: an id that's stable across reruns, a mixed-embedder refusal, a triple with no evidence refused, atomic supersede under failure, SQL/FTS injection, and a wake that respects its budget. Then publish 0.1.0 on Packagist and notify impactium.

## Additions from impactium (2026-11-14), part of 0.1.0

1. **Chunking recipe v1 is markdown-aware.** It splits on headings and keeps each chunk's heading path. The default window is 800–1,500 characters (target 1,200) with a 150-character overlap, recorded in the recipe. Each chunk keeps its byte AND line spans. The first ingest must accept Aion's pending documents over 6,000 bytes.
2. **Provenance per chunk is passed through, not recomputed:** `source_path`, `file_sha256`, `commit`, `dirty`, taken from the caller's snapshot. If the stored file hash disagrees with the text, the chunk is refused.
3. **Every source has an authority class** (for example `corpus`, `public_docs`, `teaching`). Results report the class each answer rests on. Teachings are append-only: superseding one closes its validity window but keeps it in the graph, marked not in force.
4. **Wake layers are fixed as** L0 identity, L1 recent or still-changing facts, L2 a scope-filtered fetch, L3 search, each with its own token budget. Derived layers may rank but never hide a chunk.
5. **Reads never mutate.** A test asserts that search, get, facts and wake leave every stored weight and timestamp untouched.

## REVISION (Wish, 2026-11-14): a general graph system, NOT shaped to one consumer

prism-graph is a graph system that ANY app builds on. impactium and Aion are one consumer: their app imports their corpus into the graph to make it a "living corpus". Their spec does NOT dictate the package. So the impactium additions above become examples of configuration, not built-in behaviour:
- **Storage is pluggable.** A `GraphStore` contract (sources, chunks, nodes, edges, triples) with adapters for **SQLite** and **PostgreSQL** in 0.1.0. Others can implement the contract.
- **Vectors are OPTIONAL and separate.** A `VectorStore` contract with adapters for **sqlite-vec** and **pgvector**, and the contract itself so any vector DB (Qdrant, Milvus and so on) can plug in. A graph with no VectorStore works fully, using FTS and graph traversal only. The pinned-embedder rule applies only when a VectorStore is configured.
- **Chunkers are pluggable.** A `Chunker` contract plus built-ins (paragraph, and markdown-aware with a heading path). The recipe and its version are part of the chunk id.
- **Source metadata is open.** Apps attach their own typed metadata (provenance, authority class and so on) through a schema they declare. The package doesn't hardcode impactium's fields.
- **Wake-up layers are configurable.** Apps define the layers, their order and their budgets. Aion's L0–L3 is one example configuration, not a default.
Unchanged, because they're general and part of the trust model: the verbatim floor, content-addressed ids, declared lossy transforms, triples that cite evidence with atomic supersede, query sanitizing, and reads that never mutate.
