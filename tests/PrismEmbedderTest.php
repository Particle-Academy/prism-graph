<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use PHPUnit\Framework\TestCase;
use Prism\Graph\Embedding\PrismEmbedder;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;

final class PrismEmbedderTest extends TestCase
{
    public function test_it_requires_prism_core_rather_than_failing_later(): void
    {
        if (class_exists(\Prism\Prism\Facades\Prism::class)) {
            $this->markTestSkipped('Prism core is installed; this checks the missing-dependency path.');
        }
        $this->expectException(GraphException::class);
        new PrismEmbedder(new IndexSpec('main', 'openai', 'text-embedding-3-small', 1536));
    }
}
