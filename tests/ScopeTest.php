<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Scope;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Tools\MemoryTools;

/**
 * Adversarial: every test tries to cross a scope boundary and asserts it
 * cannot. Agreement that "isolation works" on the happy path proves nothing.
 */
final class ScopeTest extends TestCase
{
    use Fixture;

    protected function setUp(): void
    {
        $this->freshPdo();
    }

    public function test_one_tenant_never_reads_another(): void
    {
        $graph = $this->graph();
        $graph->scope(Scope::tenant('acme')->owner('u1'))->ingest('doc', 'f://d', "Acme's launch code is heron.\n", new MarkdownChunker, self::user());
        $rival = $graph->scope(Scope::tenant('globex')->owner('u1'));

        $this->assertSame([], $rival->search('launch code heron'));
        $id = $graph->scope(Scope::tenant('acme')->owner('u1'))->search('heron')[0]->chunk->id;
        $this->assertSame([], $rival->get([$id]), 'a chunk id from another tenant must not be readable by id');
    }

    public function test_the_same_key_in_two_tenants_is_two_rows_not_one_overwritten(): void
    {
        $graph = $this->graph();
        $graph->scope(Scope::tenant('acme')->owner('u1'))->ingest('doc', 'f://a', "Acme text.\n", new MarkdownChunker, self::user());
        $graph->scope(Scope::tenant('globex')->owner('u1'))->ingest('doc', 'f://g', "Globex text.\n", new MarkdownChunker, self::user());

        $this->assertNotEmpty($graph->scope(Scope::tenant('acme')->owner('u1'))->search('acme'));
        $this->assertSame([], $graph->scope(Scope::tenant('acme')->owner('u1'))->search('globex'));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_sources')->fetchColumn());
    }

    public function test_owners_in_a_tenant_are_isolated_and_tenant_shared_memory_reaches_all(): void
    {
        $graph = $this->graph();
        $graph->scope(self::acme())->ingest('policy', 'f://p', "Refunds within thirty days.\n", new MarkdownChunker, new \Prism\Graph\Origin(\Prism\Graph\Trust::Document, 'admin'));
        $graph->scope(self::acme()->owner('alice'))->ingest('diary', 'f://a', "Alice's allergy is peanuts.\n", new MarkdownChunker, self::user('alice'));

        $bob = $graph->scope(self::acme()->owner('bob')->agent('support')->thread('t9'));
        $this->assertNotEmpty($bob->search('refunds'), 'tenant-shared memory is visible to every owner');
        $this->assertSame([], $bob->search('allergy peanuts'), "bob must not see alice's memory");
        $this->assertNotEmpty($graph->scope(self::acme()->owner('alice')->agent('support'))->search('allergy'));
    }

    public function test_threads_and_agents_only_see_upward_never_sideways(): void
    {
        $graph = $this->graph();
        $base = self::acme()->owner('alice');
        $graph->scope($base->agent('support')->thread('t1'))->ingest('m1', 'f://1', "Thread one secret: kestrel.\n", new MarkdownChunker, self::user('alice'));
        $graph->scope($base->agent('sales'))->ingest('m2', 'f://2', "Sales agent memory: falcon.\n", new MarkdownChunker, self::agentWriter());

        $this->assertSame([], $graph->scope($base->agent('support')->thread('t2'))->search('kestrel'), 'a sibling thread is sideways');
        $this->assertSame([], $graph->scope($base->agent('support'))->search('kestrel'), 'a wider scope does not see a narrower one');
        $this->assertSame([], $graph->scope($base->agent('support')->thread('t1'))->search('falcon'), 'another agent is sideways');
    }

    public function test_scopes_that_would_be_visible_across_owners_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Scope('acme', null, 'support'); // an agent with no owner would be read by every owner
    }

    public function test_a_fact_cannot_cite_evidence_its_readers_could_not_see(): void
    {
        $graph = $this->graph();
        $private = $graph->scope(self::acme()->owner('alice')->agent('support')->thread('t1'));
        $private->ingest('m', 'f://m', "Alice earns ninety thousand.\n", new MarkdownChunker, self::user('alice'));
        $chunk = $private->search('earns')[0]->chunk;

        $this->expectException(GraphException::class);
        // Written tenant-wide, this fact would put a private thread in front of every owner.
        $graph->scope(self::acme())->assert(new Fact('salary', 'alice', 'earns', '90k', [$chunk->id], '2026-01-01T00:00:00Z'));
    }

    public function test_facts_obey_scope_like_chunks_do(): void
    {
        $graph = $this->graph();
        $alice = $graph->scope(self::acme()->owner('alice'));
        $alice->ingest('m', 'f://m', "I prefer tea.\n", new MarkdownChunker, self::user('alice'));
        $alice->assert(new Fact('drink', 'alice', 'prefers', 'tea', [$alice->search('tea')[0]->chunk->id], '2026-01-01T00:00:00Z'));

        $this->assertCount(1, $alice->facts('alice'));
        $this->assertSame([], $graph->scope(self::acme()->owner('bob'))->facts('alice'));
        $this->assertSame([], $graph->scope(Scope::tenant('globex')->owner('alice'))->facts('alice'));
    }

    public function test_erase_reaches_only_the_scope_it_is_called_from(): void
    {
        $graph = $this->graph();
        $graph->scope(self::acme()->owner('alice'))->ingest('doc', 'f://a', "Alice's copy.\n", new MarkdownChunker, self::user('alice'));
        $graph->scope(self::acme()->owner('bob'))->ingest('doc', 'f://b', "Bob's copy.\n", new MarkdownChunker, self::user('bob'));

        $report = $graph->scope(self::acme()->owner('bob'))->erase('doc');
        $this->assertTrue($report->sourceFound);
        $this->assertNotEmpty($graph->scope(self::acme()->owner('alice'))->search('copy'), "erasing bob's doc must not touch alice's");
    }

    public function test_vectors_of_another_tenant_never_appear_or_crowd_out_results(): void
    {
        $vectors = new PdoVectorStore($this->pdo);
        $graph = $this->graph($vectors, self::letters());
        // 80 rival chunks, each an exact vector match for the query, more than the
        // 10x over-fetch (50 for limit 5), while acme's chunk is a weaker match.
        // Without a per-tenant index the rivals take every slot and acme's chunk
        // is found by text alone.
        for ($i = 0; $i < 80; $i++) {
            $graph->scope(Scope::tenant('globex')->owner('u'))->ingest("g{$i}", 'f://g', "zzz {$i}\n", new MarkdownChunker, self::user());
        }
        $graph->scope(Scope::tenant('acme')->owner('u'))->ingest('a', 'f://a', "zzz a\n", new MarkdownChunker, self::user());

        $hits = $graph->scope(Scope::tenant('acme')->owner('u'))->search('zzz', 5);
        $this->assertSame(['a'], array_values(array_unique(array_map(static fn ($h) => $h->chunk->sourceKey, $hits))));
        $this->assertSame('hybrid', $hits[0]->method, 'found by vectors too: the rival tenant did not crowd it out');
    }

    public function test_memory_tools_cannot_be_steered_out_of_their_scope(): void
    {
        $graph = $this->graph();
        $graph->scope(self::acme()->owner('alice'))->ingest('d', 'f://d', "Alice's PIN is 4821.\n", new MarkdownChunker, self::user('alice'));
        $id = $graph->scope(self::acme()->owner('alice'))->search('pin')[0]->chunk->id;
        $bobTools = new MemoryTools($graph->scope(self::acme()->owner('bob')->agent('support')), 'agent:support');

        $this->assertSame([], json_decode($bobTools->search('PIN 4821'), true)['results']);
        $this->assertSame([], json_decode($bobTools->get($id), true)['chunks'], 'knowing the id is not enough');
        $this->assertSame([], json_decode($bobTools->facts('alice', null, true), true)['facts']);
    }
}
