<?php

declare(strict_types=1);

namespace Prism\Graph\Support;

use Prism\Graph\Exceptions\GraphException;
use Prism\Graph\IndexSpec;

/** @internal */
final class Vectors
{
    /**
     * Validate a vector AS IT WILL BE STORED: every store keeps float32, so the
     * checks run after conversion. A value that is finite and non-zero as a PHP
     * float can still underflow to 0 or overflow to INF in float32, and one
     * such stored vector would poison every later search of the index.
     *
     * @param  list<int|float>  $vector
     * @return list<float> the float32 values
     */
    public static function float32(IndexSpec $spec, mixed $vector): array
    {
        if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== $spec->dimensions) {
            throw new GraphException("Expected a list of {$spec->dimensions} numbers for index [{$spec->name}].");
        }
        foreach ($vector as $x) {
            if ((! is_int($x) && ! is_float($x)) || ! is_finite((float) $x)) {
                throw new GraphException('Vectors must contain only finite numbers.');
            }
        }
        $stored = array_values(unpack('g*', pack('g*', ...$vector)));
        $sum = 0.0;
        foreach ($stored as $x) {
            if (! is_finite($x)) {
                throw new GraphException('A vector value is too large to store as float32.');
            }
            $sum += $x * $x;
        }
        if (! is_finite($sum) || $sum === 0.0) {
            throw new GraphException('A vector must have a non-zero, finite length once stored as float32.');
        }

        return $stored;
    }
}
