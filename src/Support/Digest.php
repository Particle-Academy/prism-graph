<?php

declare(strict_types=1);

namespace Prism\Graph\Support;

/** @internal */
final class Digest
{
    /**
     * sha256 of a length-prefixed tuple. A separator byte would let two
     * different tuples hash the same when a field contains the separator;
     * a length prefix cannot be forged that way.
     *
     * @param  list<string|int>  $fields
     */
    public static function of(string $domain, array $fields): string
    {
        $encoded = strlen($domain).':'.$domain;
        foreach ($fields as $field) {
            $field = (string) $field;
            $encoded .= strlen($field).':'.$field;
        }

        return hash('sha256', $encoded);
    }
}
