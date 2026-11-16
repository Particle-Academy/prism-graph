<?php

declare(strict_types=1);

namespace Prism\Graph\Contracts;

use Prism\Graph\Scope;

/**
 * Scoped lookup of one source by id (G-295), so a consumer labelling a few evidence chunks (each carries its
 * sourceId) does not have to page the whole listing. Kept apart from ListsGraph so adding it breaks no existing
 * implementer.
 *
 * A source is found if and only if ListsGraph::listSources would list it for the same reader: visible from that
 * scope, with at least one live chunk. Anything else (absent, out of scope, another tenant, every chunk retired)
 * is null, indistinguishably.
 */
interface FindsSources
{
    /**
     * @return array{sourceId: string, title: string, kind: string|null, createdAt: string, chunkCount: int, trust: string}|null
     */
    public function findSource(Scope $reader, string $sourceId): ?array;
}
