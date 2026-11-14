<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;
use PDO;
use Prism\Graph\Contracts\Chunker;
use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Contracts\VectorStore;
use Prism\Graph\Exceptions\GraphException;

/**
 * The app-facing entry point. Storage, vectors (optional) and chunking are
 * injected; the app owns its schema. Search results are always verbatim
 * chunks, and no read changes stored data.
 */
final class Graph
{
    public const MAX_LIMIT = 200;

    public const MAX_QUERY_TERMS = 16;

    public function __construct(
        private readonly GraphStore $store,
        private readonly PDO $pdo,
        private readonly TableMap $tables = new TableMap,
        private readonly ?VectorStore $vectors = null,
        private readonly ?Embedder $embedder = null,
    ) {
        if (($vectors === null) !== ($embedder === null)) {
            throw new InvalidArgumentException('Configure a VectorStore and an Embedder together, or neither.');
        }
    }

    /**
     * Store a source and its verbatim chunks. Idempotent: re-ingesting the same
     * text under the same recipe stores nothing new.
     *
     * @param  array<string, mixed>  $metadata  app-defined, stored as given
     */
    public function ingest(string $sourceId, string $uri, string $text, Chunker $chunker, array $metadata = [], ?string $sha256 = null): IngestReport
    {
        $actual = hash('sha256', $text);
        if ($sha256 !== null && ! hash_equals(strtolower($sha256), $actual)) {
            throw new GraphException("The provided sha256 for [{$sourceId}] does not match its text.");
        }
        $chunks = $chunker->chunk($sourceId, $text);
        $this->store->putSource($sourceId, $uri, $actual, $metadata);
        $stored = $this->store->putChunks($chunks);

        if ($this->vectors !== null && $stored > 0) {
            $spec = $this->embedder->spec();
            $this->vectors->ensureIndex($spec);
            $vectors = $this->embedder->embed(array_map(static fn (Chunk $c) => $c->text, $chunks));
            if (count($vectors) !== count($chunks)) {
                throw new GraphException('The embedder returned a different number of vectors than chunks.');
            }
            $this->vectors->upsert($spec, array_combine(array_map(static fn (Chunk $c) => $c->id, $chunks), $vectors));
        }

        return new IngestReport($sourceId, $actual, $chunker->recipe(), count($chunks), $stored);
    }

    /** @param list<string> $chunkIds @return list<Chunk> */
    public function get(array $chunkIds): array
    {
        return $this->store->getChunks($chunkIds);
    }

    /**
     * Vector search when configured, otherwise text search. Either way the
     * results are the verbatim chunks themselves.
     *
     * @return list<Hit>
     */
    public function search(string $query, int $limit = 10): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }
        if ($this->vectors !== null) {
            $spec = $this->embedder->spec();
            $nearest = $this->vectors->nearest($spec, $this->embedder->embed([$query])[0], $limit);
            $chunks = $this->store->getChunks(array_column($nearest, 'chunk_id'));
            $scores = array_column($nearest, 'score', 'chunk_id');
            $hits = array_map(fn (Chunk $c) => new Hit($c, (float) $scores[$c->id], $this->vectors->method()), $chunks);

            // Derived indexes may rank but never hide: a chunk with no vector yet
            // (failed or pending embedding) stays reachable through text search.
            $seen = array_flip(array_map(static fn (Hit $h) => $h->chunk->id, $hits));
            foreach ($this->textSearch($query, $limit) as $hit) {
                if (count($hits) >= $limit) {
                    break;
                }
                if (! isset($seen[$hit->chunk->id])) {
                    $hits[] = $hit;
                }
            }

            return $hits;
        }

        return $this->textSearch($query, $limit);
    }

    /** @return list<Fact> */
    public function facts(string $subject, ?string $at = null): array
    {
        return $this->store->factsAbout($subject, $at);
    }

    public function assert(Fact $fact): void
    {
        $this->store->assertFact($fact);
    }

    public function supersede(string $oldId, Fact $replacement): void
    {
        $this->store->supersede($oldId, $replacement);
    }

    /**
     * Portable AND-of-terms text search: terms are escaped literals, never
     * patterns or SQL. Ranked by how many times the terms occur.
     *
     * @return list<Hit>
     */
    private function textSearch(string $query, int $limit): array
    {
        $terms = array_slice(array_values(array_unique(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [],
            static fn (string $t) => $t !== '',
        ))), 0, self::MAX_QUERY_TERMS);
        if ($terms === []) {
            return [];
        }
        $where = [];
        $bindings = [];
        foreach ($terms as $term) {
            $where[] = "LOWER(text) LIKE ? ESCAPE '!'";
            $bindings[] = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        $statement = $this->pdo->prepare(
            "SELECT id FROM {$this->tables->chunks} WHERE ".implode(' AND ', $where).' ORDER BY id LIMIT '.(self::MAX_LIMIT * 5),
        );
        $statement->execute($bindings);
        $chunks = $this->store->getChunks(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));

        $hits = [];
        foreach ($chunks as $chunk) {
            $text = mb_strtolower($chunk->text);
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count($text, $term);
            }
            $hits[] = new Hit($chunk, (float) $score, 'text');
        }
        usort($hits, static fn (Hit $a, Hit $b) => [$b->score, $a->chunk->id] <=> [$a->score, $b->chunk->id]);

        return array_slice($hits, 0, $limit);
    }
}
