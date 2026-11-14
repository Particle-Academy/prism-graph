# prism-graph

Long-term memory for AI agents, built for agents on
[prism-harness](https://github.com/Particle-Academy/prism-harness) and the
Agent Client Protocol, and usable by any PHP app.

It keeps what an agent has heard, read and concluded, across chats, for months:
- verbatim episodes, kept as evidence;
- facts that must cite that evidence;
- every read and write scoped to a tenant, an owner, an agent and a thread.

Memory is a security boundary, so the design starts there.

> **prism-graph and prism-memory are different jobs.** `prism-memory` is
> short-term memory: what survives compaction, scoped to one chat.
> `prism-graph` is long-term memory across chats.

## What it guarantees

- **Scope is enforced in SQL, on every query.** There is no unscoped read, so
  one owner's memory is never fetched for another, and one tenant's never
  for another.
- **App keys are namespaced per scope,** so two tenants using `doc-1` get two
  rows.
- **Verbatim is the floor.** Search returns stored text, not a paraphrase.
  Derived data (vectors, facts) ranks results and never hides them.
- **Every source records where it came from:** a trust class (`user`,
  `document`, `agent`, `tool`, `subagent`, `consolidator`, `system`) and the
  writer. The caller binds both, never a model's tool arguments.
- **Facts cite evidence, and a policy decides their status:**
  - **accepted** when every cited chunk came from a user or a document;
  - a **belief** when the evidence is only the agent's own notes;
  - a **proposal** otherwise, which waits for the app.

  One trusted chunk cited beside an injected tool result does not launder the
  injection into a fact.
- **An accepted fact can only be superseded by a fact the policy also
  accepts.** A tool result that says "the user's email is now X" cannot
  overwrite what the user said.
- **Facts are bi-temporal.** *Valid* time records when something held in the
  world; *recorded* time records when memory held it. You can ask "what was
  true in March" and "what did the agent believe in March", and get
  different answers.
- **Erasure cascades:** chunks, vectors, full-text index entries, evidence
  links, and every fact left with no evidence. It returns counts you can
  keep as proof.
- **Recalled text is fenced as data.** Agent tools mark invisible characters
  visibly, and wake-up text sits inside a fence that stored content cannot
  close.
- **Reads never write, and nothing creates tables at runtime.**

## Scopes

```php
use Prism\Graph\Scope;

$tenant = Scope::tenant('acme');                              // shared by everyone in acme
$owner  = $tenant->owner('user-7');                           // this person's memory
$agent  = $owner->agent('support');                           // what this agent knows about them
$thread = $agent->thread('chat-123');                         // this conversation
```

A reader sees its own level and every level above it, never sideways. A
thread sees the thread, the agent's memory of the owner, the owner's memory
and the tenant's shared memory. It does not see another thread, another agent
or another owner.

An agent scope requires an owner, so no row can be visible across owners
except a tenant-wide one that you wrote on purpose.

Writes land at exactly the scope you opened. To share something tenant-wide,
open the tenant scope and write there.

## Install (Laravel)

```bash
composer require particle-academy/prism-graph
php artisan vendor:publish --tag=prism-graph-config
php artisan vendor:publish --tag=prism-graph-migrations
php artisan migrate
```

The published migrations are yours to own:
- add your own columns, or rename tables and point `config/prism-graph.php` →
  `tables` at them;
- keep the scope columns, because every read filters on them.

Full-text search runs on FTS5 on SQLite, which folds case and accents. On
PostgreSQL it runs on a generated tsvector with the `simple` configuration,
which folds case only.

```php
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Graph;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Trust;

$memory = app(Graph::class)->scope(Scope::tenant('acme')->owner('user-7')->agent('support'));

$memory->ingest('handbook', 'file://handbook.md', $markdown, new MarkdownChunker,
    new Origin(Trust::Document, 'importer'));

foreach ($memory->search('refund policy') as $hit) {
    $hit->chunk->text;        // the exact stored text
    $hit->chunk->sourceKey;   // 'handbook'
    $hit->chunk->trust;       // Trust::Document
    $hit->method;             // 'text', a vector method, or 'hybrid'
}
```

Search is hybrid. Full-text ranking (BM25, or `ts_rank` on PostgreSQL) and
vector similarity, when configured, are fused by reciprocal rank. A chunk
whose embedding failed is still found by text.

## Facts

```php
use Prism\Graph\Fact;

$said = $memory->search('my email is')[0]->chunk;       // a user turn
$fact = $memory->assert(new Fact('email', 'user-7', 'email', 'a@example.com', [$said->id], '2026-01-01T00:00:00Z'));
$fact->status;   // FactStatus::Accepted

$memory->facts('user-7');                                   // accepted, in force now
$memory->facts('user-7', at: '2026-03-01T00:00:00Z');       // what held then
$memory->facts('user-7', knownAt: '2026-03-01T00:00:00Z');  // what memory held then
$memory->facts('user-7', unaccepted: true);                 // also proposals and beliefs, labelled
$memory->timeline('user-7');                                // every version, oldest first

$memory->supersede('email', $newFact);   // the world changed
$memory->retract('email');               // we were wrong
$memory->accept('office');               // an app decision: a proposal becomes accepted
```

Bind your own `Prism\Graph\Contracts\AcceptancePolicy` to change what is
accepted. Times are UTC ISO 8601, and they come back in one fixed-width form.

## Erasure

```php
$report = $memory->erase('handbook');
// sourceFound, chunks, vectors, evidenceLinks, factsErased, factsKept
```

`retireSource()` is the non-destructive option: the source leaves search but
stays readable by id. Re-ingesting a changed source retires only the text it
no longer contains.

## Agent tools

```php
use Prism\Graph\Tools\MemoryTools;

$tools = (new MemoryTools($memory, writer: 'agent:support'))->prismTools();
```

| tool | what it does |
|---|---|
| `memory_search` | Searches memory and returns verbatim passages, each with chunk id, source, trust and line span. |
| `memory_get` | Reads passages by chunk id, within the scope. |
| `memory_facts` | Returns accepted facts with their evidence. `include_beliefs` adds proposals and beliefs, labelled. |
| `memory_timeline` | Returns every version of the facts about a subject. |
| `memory_note` | Saves the agent's own note, with trust `agent`. A note never becomes an accepted fact by being written. |

How the tools behave:
- The scope and the writer are fixed when the tools are built, from your
  session. A model can only search what that scope can see.
- No tool asserts, accepts, supersedes or erases. Those are app decisions.
- Tool names may not contain the verbs reserved for the human: confirm,
  approve, accept, reject, deny.
- A tool never throws at the model. Database errors become a fixed message.

## With prism-harness (0.24+)

The harness's memory seams plug the graph into every session. Register them
in a service provider:

```php
use Prism\Graph\Graph;
use Prism\Graph\Harness\{HarnessScopes, TurnRecorder, WakeContext};
use Prism\Harness\Context\MemoryHooks;

$graph = app(Graph::class);
$scopes = HarnessScopes::standard(tenant: 'acme');    // or fn (Session $s) => $s->...->tenant

app(MemoryHooks::class)
    ->provide(new WakeContext($graph, $scopes))       // recall at every model call
    ->observe(new TurnRecorder($graph, $scopes));     // record every turn
```

**What the adapter does:**
- `TurnRecorder` stores each turn verbatim at the session's **agent** scope:
  this person, with this agent, across all of their chats. Pass
  `level: 'thread'` to keep episodes per chat instead.
- Trust comes from the message type and the session, never from what the text
  claims:
  - user messages are `user`;
  - the model's replies are `agent`;
  - tool results are `tool`;
  - in a **sub-agent**, the "user" message is a task the parent model wrote,
    so it is `agent` too.

  A fetched page that says "I am the user" is still a tool result.
- `WakeContext` searches memory for the new message and adds what it finds to
  the system prompt, fenced as data. Each item is labelled with its trust
  class. It adds nothing when memory is empty.
- **Recall is text-only by default** (the database alone), because it runs
  before every model call. Pass your own layers to recall with vectors,
  knowing the embedding call is then on every turn's path.

**How a session maps to a scope:** you supply the tenant. The owner is the
participant, the agent is the session's agent namespace, and the thread is
the session's current chat. So a person's earlier chats are recalled in later
ones, and nobody else's are.

Memory failures never fail a turn: the harness reports them and carries on.

## Wake-up context

```php
use Prism\Graph\Wake\{Wake, FactsLayer, SearchLayer};

$report = (new Wake($memory))
    ->layer(new FactsLayer('known', ['user-7']), 400)
    ->layer(new SearchLayer('relevant', $openingMessage), 800)
    ->assemble(timeBudgetMs: 150);

$systemPrompt .= $report->fenced();   // data inside a hash-tagged fence
$report->omitted;                     // what did not fit, and why
```

## Vectors (optional)

Set `PRISM_GRAPH_VECTORS` before running the vector migration:

| value | uses |
|---|---|
| `pdo` | Exact cosine search, run in PHP, on SQLite or PostgreSQL. |
| `sqlite-vec` | [sqlite-vec](https://github.com/asg017/sqlite-vec), on the same tables as `pdo`. The app loads the extension (PHP 8.4+). |
| `pgvector` | PostgreSQL with [pgvector](https://github.com/pgvector/pgvector). |
| a container binding | Your own `Prism\Graph\Contracts\VectorStore`. |

Vector indexes:
- **Partitioned per tenant,** so another tenant's vectors can neither appear
  in your results nor crowd them out.
- **Pinned to one embedder,** so vectors from a different model are refused.

The embedder is set by `PRISM_GRAPH_EMBED_PROVIDER`, `_MODEL` and
`_DIMENSIONS`.

## Without Laravel

```php
$pdo = new PDO('sqlite:memory.db');
foreach (\Prism\Graph\Storage\BaseSchema::statements('sqlite') as $sql) { $pdo->exec($sql); }  // from your own migration tooling

$graph = new \Prism\Graph\Graph(new \Prism\Graph\Storage\PdoGraphStore($pdo));
```

## Upgrading from 0.1

0.2 is a breaking release:
- **Every read and write goes through `$graph->scope(...)`.**
- **`ingest()` takes an `Origin`.**
- **The tables changed.** There are new scope, trust and recorded-time
  columns; nodes and edges are removed; full-text search is added.
- **`GraphTools` is now `MemoryTools`.**

0.1 data has no scope or trust, so there is no automatic migration: re-ingest
your sources into a 0.2 schema.

## Testing

```bash
composer install
vendor/bin/phpunit
```

- **PostgreSQL tests:** set `PRISM_GRAPH_TEST_PG_DSN`, `_USER` and `_PASSWORD`
  to a disposable database.
- **sqlite-vec tests:** need PHP 8.4 and `PRISM_GRAPH_SQLITE_VEC`.
- **Adversarial scope tests** are in `tests/ScopeTest.php`.

## License

MIT
