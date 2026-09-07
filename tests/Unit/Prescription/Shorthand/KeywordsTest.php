<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Shorthand;

use App\Domain\Prescription\Shorthand\Keywords;
use PHPUnit\Framework\TestCase;

/** keywords.json: no duplicate tokens across categories; every token appears in a fixture row (PRESCRIPTION.md §9.1). */
final class KeywordsTest extends TestCase
{
    public function test_no_token_belongs_to_two_categories(): void
    {
        $seen = [];
        $all = Keywords::all();
        $groups = [
            'frequency' => array_keys($all['frequency']),
            'stat' => $all['stat'], 'sos' => $all['sos'], 'hs' => $all['hs'], 'max' => $all['max'],
            'unit' => array_keys(Keywords::unitAliases()),
            'duration' => array_keys(Keywords::durationWords()),
            'timing' => array_keys(Keywords::timingTokens()),
            'route' => Keywords::routes(),
            'pack' => array_keys(Keywords::packWords()),
        ];

        foreach ($groups as $category => $tokens) {
            foreach ($tokens as $token) {
                // §2.2 lists `vial` / `amp` as both a dose unit and a pack word: pack words are only recognised after `x<int>`.
                if ($category === 'pack' && ($seen[$token] ?? null) === 'unit' && in_array($token, ['vial', 'amp'], true)) {
                    continue;
                }

                $this->assertArrayNotHasKey($token, $seen, "token `{$token}` is both ".($seen[$token] ?? '?')." and {$category}");
                $seen[$token] = $category;
            }
        }

        // `hs` is a timing after a schedule and a schedule on its own (§2.4/§2.6): the token is owned by `hs` alone;
        // `timing.hs` only carries the timing column value + label.
        $this->assertSame([], $all['timing']['hs']['tokens']);
        $this->assertSame(['hs'], $all['hs']);
    }

    public function test_every_token_appears_in_a_fixture_input(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/shorthand_cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $haystack = ' '.mb_strtolower(implode(' ', array_map(fn ($c) => preg_replace('/(\d)([a-z])/u', '$1 $2', (string) $c['input']), $fixture['cases']))).' ';
        $missing = [];

        foreach (array_keys(Keywords::vocabulary()) as $token) {
            if (! str_contains($haystack, ' '.$token.' ') && ! str_contains($haystack, ' '.$token.'+') && ! str_contains($haystack, '+'.$token.' ')) {
                $missing[] = $token;
            }
        }

        $this->assertSame([], $missing, 'keywords without a fixture row: '.implode(', ', $missing));
    }

    public function test_forms_cover_every_catalog_dosage_form_code(): void
    {
        $codes = ['tab', 'cap', 'syr', 'susp', 'sol', 'oral_drop', 'eye_drop', 'ear_drop', 'nasal_drop', 'nasal_spray', 'inh_mdi', 'inh_dpi', 'neb', 'inj', 'insulin', 'cream', 'oint', 'gel', 'lotion', 'powder', 'shampoo', 'mouthwash', 'paint', 'supp', 'pessary', 'sachet'];

        $this->assertSame([], array_diff($codes, array_keys(Keywords::forms())));

        foreach (Keywords::forms() as $code => $form) {
            $this->assertNotSame('', $form['default_unit'], $code);
            $this->assertSame([], array_diff($form['routes'], Keywords::routes()), "{$code} routes must be grammar routes");
        }
    }

    public function test_messages_exist_in_both_languages(): void
    {
        foreach (Keywords::all()['messages'] as $key => $m) {
            $this->assertNotSame('', $m['en'], $key);
            $this->assertNotSame('', $m['bn'], $key);
        }
    }
}
