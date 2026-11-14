<?php

declare(strict_types=1);

namespace Prism\Graph\Tests;

use Orchestra\Testbench\TestCase;
use Prism\Graph\Embedding\PrismEmbedder;
use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismServiceProvider;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;

/** Runs through Prism core's real embeddings builder against its fake provider. */
final class PrismEmbedderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrismServiceProvider::class];
    }

    private function spec(int $dimensions = 3): IndexSpec
    {
        return new IndexSpec('main', 'openai', 'text-embedding-3-small', $dimensions);
    }

    /** @param list<list<float>> $vectors */
    private function response(array $vectors): EmbeddingsResponseFake
    {
        return EmbeddingsResponseFake::make()->withEmbeddings(array_map(fn ($v) => Embedding::fromArray($v), $vectors));
    }

    public function test_it_batches_and_returns_vectors_in_input_order(): void
    {
        $fake = Prism::fake([
            $this->response([[1, 0, 0], [0, 1, 0]]),
            $this->response([[0, 0, 1]]),
        ]);

        $vectors = (new PrismEmbedder($this->spec(), batchSize: 2))->embed(['a', 'b', 'c']);

        $this->assertSame([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]], $vectors);
        $fake->assertCallCount(2);
    }

    public function test_a_provider_returning_the_wrong_count_is_refused(): void
    {
        Prism::fake([$this->response([[1, 0, 0]])]);
        $this->expectException(GraphException::class);
        (new PrismEmbedder($this->spec()))->embed(['a', 'b']);
    }

    public function test_a_vector_of_the_wrong_width_is_refused_not_stored(): void
    {
        Prism::fake([$this->response([[1, 0]])]);
        $this->expectException(GraphException::class);
        (new PrismEmbedder($this->spec(3)))->embed(['a']);
    }
}
