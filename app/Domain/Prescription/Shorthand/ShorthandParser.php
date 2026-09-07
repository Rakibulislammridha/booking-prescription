<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Data\ParsedLine;

/**
 * `ShorthandParser::parse(string $text, ?ParseContext $ctx): ParsedLine` — the server-side, authoritative parser of
 * the Rx shorthand grammar (PRESCRIPTION.md §2). Pure, deterministic, no I/O: Normalizer → Tokenizer → Classifier →
 * Assembler → QuantityCalculator. The TS parser runs the same fixture (tests/Fixtures/shorthand_cases.json).
 */
final class ShorthandParser
{
    public function parse(string $text, ?ParseContext $ctx): ParsedLine
    {
        $n = Normalizer::normalize($text);
        $tokens = Tokenizer::tokenize($n['body']);
        $line = (new Assembler)->assemble($n['raw'], $n['body'], $n['instruction'], $tokens, $ctx);
        QuantityCalculator::compute($line, $ctx);

        return $line;
    }

    /** Convenience for callers holding arrays (templates re-parse from dose_json.normalized). */
    public static function make(): self
    {
        return new self;
    }
}
