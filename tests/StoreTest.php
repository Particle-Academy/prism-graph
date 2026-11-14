<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunk;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\TableMap;

final class StoreTest extends TestCase
{
    private PDO $pdo;

    private PdoGraphStore $store;

    /** @var list<Chunk> */
    private array $chunks;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach (BaseSchema::statements('sqlite') as $sql) {
            $this->pdo->exec($sql);
        }
        $this->store = new PdoGraphStore($this->pdo);
        $this->store->putSource('doc', 'file://doc.md', str_repeat('a', 64), ['class' => 'corpus']);
        $this->chunks = (new MarkdownChunker(300, 400, 0))->chunk('doc', "# A\nalpha text\n## B\nbeta text\n");
        $this->store->putChunks($this->chunks);
    }

    public function test_chunks_are_idempotent_and_returned_by_id_in_order(): void
    {
        $this->assertSame(0, $this->store->putChunks($this->chunks), 're-ingest inserts nothing');
        $ids = [$this->chunks[1]->id, 'missing', $this->chunks[0]->id];
        $this->assertSame([$this->chunks[1]->id, $this->chunks[0]->id], array_map(fn (Chunk $c) => $c->id, $this->store->getChunks($ids)));
        $this->assertEquals($this->chunks, $this->store->chunksForSource('doc'));
    }

    public function test_a_chunk_whose_id_does_not_match_its_content_is_refused(): void
    {
        $c = $this->chunks[0];
        $forged = new Chunk($c->id, $c->sourceId, $c->text.' tampered', $c->byteStart, $c->byteEnd, $c->lineStart, $c->lineEnd, $c->headingPath, $c->recipe);

        $this->expectException(GraphException::class);
        $this->store->putChunks([$forged]);
    }

    public function test_a_fact_must_cite_stored_chunks(): void
    {
        foreach ([[], ['not-a-chunk'], [$this->chunks[0]->id, 'not-a-chunk']] as $evidence) {
            try {
                $this->store->assertFact(new Fact('f-'.count($evidence), 's', 'p', 'o', $evidence, '2026-01-01T00:00:00Z'));
                $this->fail('a fact without valid evidence was stored');
            } catch (GraphException) {
            }
        }
        $this->assertSame([], $this->store->factsAbout('s'));
    }

    public function test_supersede_is_atomic_and_keeps_history(): void
    {
        $ev = [$this->chunks[0]->id];
        $this->store->assertFact(new Fact('f1', 'aion', 'tone', 'formal', $ev, '2026-01-01T00:00:00Z'));
        $this->store->supersede('f1', new Fact('f2', 'aion', 'tone', 'warm', $ev, '2026-06-01T00:00:00Z'));

        $this->assertSame(['f2'], array_map(fn (Fact $f) => $f->id, $this->store->factsAbout('aion')));
        $this->assertSame(['f1'], array_map(fn (Fact $f) => $f->id, $this->store->factsAbout('aion', '2026-03-01T00:00:00Z')));
        $old = $this->store->factsAbout('aion', '2026-03-01T00:00:00Z')[0];
        $this->assertSame(['2026-06-01T00:00:00Z', 'f2'], [$old->validTo, $old->supersededBy]);

        // A replacement with bad evidence rolls back the close: f2 stays in force.
        try {
            $this->store->supersede('f2', new Fact('f3', 'aion', 'tone', 'curt', ['nope'], '2026-09-01T00:00:00Z'));
            $this->fail('supersede with bad evidence succeeded');
        } catch (GraphException) {
        }
        $this->assertSame(['f2'], array_map(fn (Fact $f) => $f->id, $this->store->factsAbout('aion')));

        // A fact already superseded cannot be superseded again.
        $this->expectException(GraphException::class);
        $this->store->supersede('f1', new Fact('f4', 'aion', 'tone', 'x', $ev, '2026-07-01T00:00:00Z'));
    }

    public function test_hostile_values_are_data_not_sql(): void
    {
        $evil = "x'); DROP TABLE graph_chunks; --";
        $this->store->putNode($evil, $evil);
        $this->store->assertFact(new Fact($evil, $evil, $evil, $evil, [$this->chunks[0]->id], '2026-01-01T00:00:00Z'));

        $this->assertSame($evil, $this->store->factsAbout($evil)[0]->object);
        $this->assertCount(count($this->chunks), $this->store->chunksForSource('doc'));
    }

    public function test_reads_never_mutate(): void
    {
        $this->store->assertFact(new Fact('f1', 's', 'p', 'o', [$this->chunks[0]->id], '2026-01-01T00:00:00Z'));
        $before = $this->snapshot();
        $this->store->getChunks([$this->chunks[0]->id]);
        $this->store->chunksForSource('doc');
        $this->store->factsAbout('s');
        $this->store->factsAbout('s', '2026-02-01T00:00:00Z');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_table_names_are_validated_and_mappable(): void
    {
        $this->assertSame('app_chunks', TableMap::from(['chunks' => 'app_chunks'])->chunks);
        $this->expectException(\InvalidArgumentException::class);
        new TableMap(chunks: 'chunks; DROP TABLE x');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['graph_sources', 'graph_chunks', 'graph_nodes', 'graph_facts', 'graph_fact_evidence'] as $table) {
            $out[$table] = $this->pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $out;
    }
}
