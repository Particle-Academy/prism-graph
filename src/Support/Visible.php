<?php

declare(strict_types=1);

namespace Prism\Graph\Support;

/**
 * Makes characters a reader cannot see visible, as <U+XXXX>, before stored
 * text reaches a model. Hidden characters are how instructions get smuggled
 * past a human reviewer: Unicode tag characters spell ASCII invisibly, bidi
 * controls reorder what is shown, and variation selectors carry payloads
 * inside emoji.
 *
 * Covered: every format character (\p{Cf}: zero-width marks, bidi controls,
 * tag characters, soft hyphen, BOM), variation selectors (FE00-FE0F,
 * E0100-E01EF, Mongolian 180B-180F), the combining grapheme joiner (034F),
 * the braille blank (2800), the Hangul fillers
 * (115F, 1160, 3164, FFA0) and the line and paragraph separators (2028,
 * 2029). The stored text is untouched; ids still address its exact bytes.
 *
 * @internal
 */
final class Visible
{
    private const HIDDEN = '/[\p{Cf}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{034F}\x{180B}-\x{180F}\x{2800}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2028}\x{2029}]/u';

    public static function mark(string $text): string
    {
        return (string) preg_replace_callback(
            self::HIDDEN,
            static fn (array $m) => sprintf('<U+%04X>', mb_ord($m[0], 'UTF-8')),
            mb_scrub($text, 'UTF-8'),
        );
    }
}
