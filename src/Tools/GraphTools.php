<?php

declare(strict_types=1);

namespace Prism\Graph\Tools;

use Prism\Graph\Chunk;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\Fact;
use Prism\Graph\Graph;

/**
 * READ-ONLY agent tools over a graph: search, get, facts. There is no write
 * tool here on purpose; an app that wants an agent to assert facts builds that
 * tool itself, with its own review step.
 *
 * Every result is JSON and cites chunk ids, so an answer can be checked
 * against the verbatim text. Returned text is stored DATA, not instructions.
 */
final readonly class GraphTools
{
    public const MAX_RESULTS = 20;

    public const MAX_IDS = 50;

    public function __construct(private Graph $graph) {}

    public function search(string $query, int|float|string $limit = 8): string
    {
        $limit = max(1, min(self::MAX_RESULTS, (int) $limit));
        $hits = $this->graph->search($query, $limit);

        return $this->json(['results' => array_map(
            fn ($hit) => $this->chunk($hit->chunk) + ['score' => round($hit->score, 4), 'method' => $hit->method],
            $hits,
        )]);
    }

    public function get(string $ids): string
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $ids)), fn ($id) => $id !== ''));
        if ($list === [] || count($list) > self::MAX_IDS) {
            return $this->json(['error' => 'Pass between 1 and '.self::MAX_IDS.' comma-separated chunk ids.']);
        }
        foreach ($list as $id) {
            if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
                return $this->json(['error' => 'Chunk ids are 64 lowercase hex characters.']);
            }
        }

        return $this->json(['chunks' => array_map(fn (Chunk $c) => $this->chunk($c), array_values($this->graph->get($list)))]);
    }

    public function facts(string $subject, ?string $at = null): string
    {
        try {
            $facts = $this->graph->facts($subject, $at === '' ? null : $at);
        } catch (GraphException|\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }

        return $this->json(['facts' => array_map(fn (Fact $f) => [
            'id' => $f->id,
            'subject' => $f->subject,
            'predicate' => $f->predicate,
            'object' => $f->object,
            'evidence' => $f->evidence,
            'valid_from' => $f->validFrom,
            'valid_to' => $f->validTo,
        ], $facts)]);
    }

    /**
     * The same three tools as Prism core Tool objects.
     *
     * @return list<\Prism\Prism\Tool>
     */
    public function prismTools(string $prefix = 'graph_'): array
    {
        if (! class_exists(\Prism\Prism\Tool::class)) {
            throw new GraphException('prismTools() needs particle-academy/prism.');
        }

        return [
            (new \Prism\Prism\Tool)->as($prefix.'search')
                ->for('Search the knowledge graph. Returns verbatim passages with their chunk ids, source and line span.')
                ->withStringParameter('query', 'What to look for')
                ->withNumberParameter('limit', 'Results to return, 1 to '.self::MAX_RESULTS, false)
                ->using(fn (string $query, int|float|null $limit = null): string => $this->search($query, $limit ?? 8)),
            (new \Prism\Prism\Tool)->as($prefix.'get')
                ->for('Read passages by chunk id, exactly as stored.')
                ->withStringParameter('ids', 'Comma-separated chunk ids')
                ->using(fn (string $ids): string => $this->get($ids)),
            (new \Prism\Prism\Tool)->as($prefix.'facts')
                ->for('Facts about a subject, each citing the chunk ids that support it. Pass "at" (UTC ISO 8601) to see what held at that time.')
                ->withStringParameter('subject', 'The subject to look up')
                ->withStringParameter('at', 'Optional point in time, UTC ISO 8601', false)
                ->using(fn (string $subject, ?string $at = null): string => $this->facts($subject, $at)),
        ];
    }

    /** @return array<string, mixed> */
    private function chunk(Chunk $c): array
    {
        return [
            'id' => $c->id,
            'source' => $c->sourceId,
            'lines' => [$c->lineStart, $c->lineEnd],
            'heading' => $c->headingPath,
            'text' => $c->text,
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
