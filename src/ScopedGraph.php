<?php

declare(strict_types=1);

namespace Prism\Graph;

use InvalidArgumentException;
use Prism\Graph\Contracts\AcceptancePolicy;
use Prism\Graph\Contracts\Chunker;
use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Contracts\GraphStore;
use Prism\Graph\Contracts\ListsGraph;
use Prism\Graph\Support\Digest;
use Prism\Graph\Contracts\VectorStore;
use Prism\Graph\Exceptions\GraphException;

/**
 * Memory as seen from one scope. Reads return what this scope can see (its
 * own rows and those shared from wider scopes above it); writes land at
 * exactly this scope. To write tenant-wide, an app opens a tenant scope on
 * purpose: nothing here widens a write.
 */
final class ScopedGraph
{
    public const MAX_LIMIT = 200;

    /** A query goes to the embedding provider and the full-text index, so it is bounded. */
    public const MAX_QUERY_BYTES = 4096;

    public const MAX_GET_IDS = 1000;

    /** Reciprocal-rank-fusion constant (Cormack et al.): dampens the head of each list. */
    private const RRF_K = 60;

    public function __construct(
        private readonly Scope $scope,
        private readonly GraphStore $store,
        private readonly ?VectorStore $vectors,
        private readonly ?Embedder $embedder,
        private readonly AcceptancePolicy $policy,
        private readonly int $listMax = self::DEFAULT_LIST_MAX,
    ) {}

    /** The listing contract (G-282). A consumer refuses a version it does not know. */
    public const CONTRACT_VERSION = 'g282.1';

    public const DEFAULT_LIST_LIMIT = 50;

    public const DEFAULT_LIST_MAX = 200;

    /**
     * Sources this scope can see, newest first, a page at a time (G-282).
     *
     * @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function listSources(?string $cursor = null, ?int $limit = null): array
    {
        $limit = $this->listLimit($limit);
        $after = $cursor === null ? null : $this->readCursor($cursor, 'sources');
        if ($after !== null && (count($after) !== 2 || ! is_string($after[0]) || ! is_string($after[1]))) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        $rows = $this->lister()->listSources($this->scope, $after, $limit + 1);
        $more = count($rows) > $limit;
        $items = array_slice($rows, 0, $limit);
        $last = end($items);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'items' => $items,
            'nextCursor' => $more && $last !== false ? $this->writeCursor('sources', [$last['createdAt'], $last['sourceId']]) : null,
        ];
    }

    /**
     * Live chunks of one source, in source order, a page at a time (G-282). A source outside this scope yields an
     * empty page, never an error that would confirm it exists.
     *
     * @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function listChunks(string $sourceId, ?string $cursor = null, ?int $limit = null): array
    {
        $limit = $this->listLimit($limit);
        $after = $cursor === null ? null : $this->readCursor($cursor, 'chunks:'.$sourceId);
        if ($after !== null && (count($after) !== 1 || ! is_int($after[0]) || $after[0] < 0)) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        $rows = $this->lister()->listChunks($this->scope, $sourceId, $after[0] ?? null, $limit + 1);
        $more = count($rows) > $limit;
        $items = array_slice($rows, 0, $limit);
        $last = end($items);
        $nextCursor = $more && $last !== false ? $this->writeCursor('chunks:'.$sourceId, [$last['seq']]) : null;

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            // seq is the store's paging key; the contract's order is `position`.
            'items' => array_map(static function (array $row): array {
                unset($row['seq']);

                return $row;
            }, $items),
            'nextCursor' => $nextCursor,
        ];
    }

    private function lister(): ListsGraph
    {
        if (! $this->store instanceof ListsGraph) {
            throw new GraphException('This graph store does not support listing (implement Prism\Graph\Contracts\ListsGraph).');
        }

        return $this->store;
    }

    private function listLimit(?int $limit): int
    {
        $limit ??= min(self::DEFAULT_LIST_LIMIT, $this->listMax);
        if ($limit < 1 || $limit > $this->listMax) {
            throw new InvalidArgumentException("limit must be between 1 and {$this->listMax}.");
        }

        return $limit;
    }

    /**
     * Opaque, and bound to this scope and listing: a cursor from another scope or listing is refused. It needs no
     * secret, because every listing query applies the scope predicate regardless of the cursor.
     *
     * @param  list<string|int>  $key
     */
    private function writeCursor(string $listing, array $key): string
    {
        $json = json_encode(['k' => $key, 'b' => $this->cursorBinding($listing)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return list<mixed> */
    private function readCursor(string $cursor, string $listing): array
    {
        $json = strlen($cursor) <= 1024 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        $data = is_string($json) ? json_decode($json, true, 4) : null;
        if (! is_array($data) || ! array_is_list($data['k'] ?? null) || ($data['b'] ?? null) !== $this->cursorBinding($listing)) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return $data['k'];
    }

    private function cursorBinding(string $listing): string
    {
        return substr(Digest::of('prism-graph/cursor@1', [...array_values(array_map(
            static fn ($v) => $v ?? '', $this->scope->toArray(),
        )), $listing]), 0, 16);
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    /**
     * Store a source and its verbatim chunks at this scope. Idempotent per key:
     * re-ingesting unchanged text stores and embeds nothing new; text the source
     * no longer contains is retired from search.
     *
     * @param  array<string, mixed>  $metadata  app-defined, stored as given
     */
    public function ingest(string $key, string $uri, string $text, Chunker $chunker, Origin $origin, array $metadata = [], ?string $sha256 = null, ?string $occurredAt = null): IngestReport
    {
        $actual = hash('sha256', $text);
        if ($sha256 !== null && ! hash_equals(strtolower($sha256), $actual)) {
            throw new GraphException("The provided sha256 for [{$key}] does not match its text.");
        }
        $chunks = $chunker->chunk($this->scope->qualify('source', $key), $text);
        ['stored' => $stored, 'retired' => $retired] = $this->store->storeSource($this->scope, $key, $uri, $actual, $metadata, $origin, $occurredAt, $chunks);

        // Verbatim is stored first and never waits on embedding. Only chunks with
        // no vector yet are embedded, so a failed embedding heals on the next
        // ingest and unchanged chunks are not paid for twice.
        if ($this->vectors !== null && $chunks !== []) {
            $spec = $this->spec();
            $this->vectors->ensureIndex($spec);
            $byId = [];
            foreach ($chunks as $chunk) {
                $byId[$chunk->id] = $chunk;
            }
            foreach (array_chunk(array_keys($byId), 500) as $ids) {
                $todo = $this->vectors->missing($spec, $ids);
                if ($todo === []) {
                    continue;
                }
                $vectors = $this->embedder->embed(array_map(static fn (string $id) => $byId[$id]->text, $todo));
                if (count($vectors) !== count($todo)) {
                    throw new GraphException('The embedder returned a different number of vectors than chunks.');
                }
                $this->vectors->upsert($spec, array_combine($todo, $vectors));
                // An erase that ran while the provider was embedding has already
                // removed these chunks and forgotten their vectors, BEFORE this
                // upsert wrote them back. Erased text must not live on as an
                // embedding, so drop the vectors of chunks that are gone.
                $present = array_map(static fn (Chunk $c) => $c->id, $this->store->getChunks($this->scope, $todo));
                $gone = array_values(array_diff($todo, $present));
                if ($gone !== []) {
                    $this->vectors->forget($gone);
                }
            }
        }

        return new IngestReport($key, $actual, $chunker->recipe(), count($chunks), $stored, $retired);
    }

    /** Take a source at this scope out of search; it stays readable by id. */
    public function retireSource(string $key): int
    {
        return $this->store->retireSource($this->scope, $key);
    }

    /**
     * Erase a source stored at this scope: chunks, vectors, evidence links, and
     * every fact left with no evidence. The report is the proof.
     */
    public function erase(string $key): EraseReport
    {
        $report = $this->store->eraseSource($this->scope, $key);

        return $this->vectors === null || $report->chunkIds === []
            ? $report
            : $report->withVectors($this->vectors->forget($report->chunkIds));
    }

    /** Chunks visible from this scope, by id. @param list<string> $chunkIds @return list<Chunk> */
    public function get(array $chunkIds): array
    {
        if (count($chunkIds) > self::MAX_GET_IDS) {
            throw new InvalidArgumentException('Pass at most '.self::MAX_GET_IDS.' chunk ids.');
        }

        return $this->store->getChunks($this->scope, $chunkIds);
    }

    /**
     * Hybrid search over what this scope can see: full-text (BM25 / ts_rank)
     * and, when configured, vectors, fused by reciprocal rank. A chunk with no
     * vector yet still ranks through text: derived indexes rank, never hide.
     *
     * Pass $vectors = false for a text-only search with no call to the
     * embedding provider: bounded by the database alone, which is what a
     * caller on a latency budget (wake-up before every model call) wants.
     *
     * @return list<Hit>
     */
    public function search(string $query, int $limit = 10, bool $vectors = true): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }
        if (strlen($query) > self::MAX_QUERY_BYTES) {
            throw new InvalidArgumentException('A query may be at most '.self::MAX_QUERY_BYTES.' bytes.');
        }
        if (! mb_check_encoding($query, 'UTF-8')) {
            throw new InvalidArgumentException('A query must be valid UTF-8.');
        }

        $lists = ['text' => array_column($this->store->textSearch($this->scope, $query, $limit * 3), 'chunk_id')];
        if ($vectors && $this->vectors !== null && trim($query) !== '') {
            // The index is partitioned by tenant (see spec()), and over-fetched
            // because rows of other owners in the same tenant are filtered next.
            $nearest = $this->vectors->nearest($this->spec(), $this->embedder->embed([$query])[0], min(1000, $limit * 10));
            $lists[$this->vectors->method()] = array_column($nearest, 'chunk_id');
        }

        $fused = [];
        $methods = [];
        foreach ($lists as $method => $ids) {
            foreach (array_values($ids) as $rank => $id) {
                $fused[$id] = ($fused[$id] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
                $methods[$id][] = $method;
            }
        }
        if ($fused === []) {
            return [];
        }
        // Visibility and retirement are applied by the store, in SQL: a vector
        // hit from another owner or a retired chunk simply does not come back.
        $chunks = array_filter($this->store->getChunks($this->scope, array_keys($fused)), static fn (Chunk $c) => $c->retiredAt === null);
        $hits = array_map(
            static fn (Chunk $c) => new Hit($c, $fused[$c->id], count($methods[$c->id]) > 1 ? 'hybrid' : $methods[$c->id][0]),
            array_values($chunks),
        );
        usort($hits, static fn (Hit $a, Hit $b) => [$b->score, $a->chunk->id] <=> [$a->score, $b->chunk->id]);

        return array_slice($hits, 0, $limit);
    }

    /** @return list<Fact> */
    public function facts(string $subject, ?string $at = null, ?string $knownAt = null, bool $unaccepted = false): array
    {
        return $this->store->facts($this->scope, $subject, $at, $knownAt, $unaccepted);
    }

    /** @return list<Fact> */
    public function timeline(string $subject): array
    {
        return $this->store->timeline($this->scope, $subject);
    }

    /** Store a fact at this scope; its status comes from the acceptance policy. */
    public function assert(Fact $fact): Fact
    {
        return $this->store->assertFact($this->scope, $fact, $this->policy);
    }

    public function supersede(string $oldKey, Fact $replacement): Fact
    {
        return $this->store->supersede($this->scope, $oldKey, $replacement, $this->policy);
    }

    public function retract(string $key): void
    {
        $this->store->retract($this->scope, $key);
    }

    /** An app decision, never a tool: a proposed fact becomes accepted. */
    public function accept(string $key): void
    {
        $this->store->accept($this->scope, $key);
    }

    /**
     * The vector index for this tenant. Partitioning by tenant means another
     * tenant's vectors can neither crowd this tenant's nearest neighbours nor
     * appear in them.
     */
    private function spec(): IndexSpec
    {
        $base = $this->embedder->spec();

        return new IndexSpec(
            substr($base->name, 0, 80).'.t'.substr(hash('sha256', $this->scope->tenant), 0, 16),
            $base->provider, $base->model, $base->dimensions, $base->recipe,
        );
    }
}
