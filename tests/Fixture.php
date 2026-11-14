<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PDO;
use Prism\Graph\Contracts\Embedder;
use Prism\Graph\Contracts\VectorStore;
use Prism\Graph\Graph;
use Prism\Graph\IndexSpec;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Storage\PdoVectorStore;
use Prism\Graph\Trust;

trait Fixture
{
    protected PDO $pdo;

    /** The clock the store reads; tests move it forward to test recorded time. */
    protected string $now = '2026-01-01T00:00:00Z';

    protected function freshPdo(): PDO
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach ([...BaseSchema::statements('sqlite'), ...PdoVectorStore::schema('sqlite')] as $sql) {
            $this->pdo->exec($sql);
        }

        return $this->pdo;
    }

    protected function store(): PdoGraphStore
    {
        return new PdoGraphStore($this->pdo, clock: fn (): string => $this->now);
    }

    protected function graph(?VectorStore $vectors = null, ?Embedder $embedder = null): Graph
    {
        return new Graph($this->store(), $vectors, $embedder);
    }

    protected static function user(string $who = 'user-7'): Origin
    {
        return new Origin(Trust::User, $who);
    }

    protected static function tool(): Origin
    {
        return new Origin(Trust::Tool, 'tool:web_fetch');
    }

    protected static function agentWriter(): Origin
    {
        return new Origin(Trust::Agent, 'agent:support');
    }

    protected static function acme(): Scope
    {
        return Scope::tenant('acme');
    }

    /** A deterministic letter-count embedder for vector tests. */
    protected static function letters(int $dimensions = 3): Embedder
    {
        return new class($dimensions) implements Embedder
        {
            /** @var list<string> */
            public array $seen = [];

            public bool $fail = false;

            public function __construct(private readonly int $dimensions) {}

            public function spec(): IndexSpec
            {
                return new IndexSpec('main', 'test', 'letters', $this->dimensions);
            }

            public function embed(array $texts): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('embedding provider down');
                }
                array_push($this->seen, ...$texts);

                return array_map(fn (string $t) => array_map(
                    static fn (string $l) => 1 + substr_count(strtolower($t), $l),
                    array_slice(['a', 'e', 'o', 'i', 'u'], 0, $this->dimensions),
                ), $texts);
            }
        };
    }
}
