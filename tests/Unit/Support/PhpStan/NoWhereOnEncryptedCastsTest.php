<?php

declare(strict_types=1);

namespace Tests\Unit\Support\PhpStan;

use App\Support\PhpStan\NoWhereOnEncryptedCasts;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * ARCHITECTURE §8.2 — a WHERE on an ENC column can never match, so it is a silent-empty-result bug.
 *
 * The fixture also pins the cases the rule must stay quiet about: plain columns, `whereNull`/`whereNotNull` (NULL
 * survives the cast), a non-literal column name, and `DB::table()` where there is no model to read casts from.
 *
 * @extends RuleTestCase<NoWhereOnEncryptedCasts>
 */
final class NoWhereOnEncryptedCastsTest extends RuleTestCase
{
    private const TIP = 'ARCHITECTURE §8.2: ENC columns are never indexed and never in a WHERE. Filter on a plain column (or a deterministic hash column) instead, or load the rows and compare the decrypted attribute in PHP.';

    #[WithoutErrorHandler]
    public function test_it_reports_a_where_on_an_encrypted_column_however_the_model_is_reached(): void
    {
        $this->analyse([__DIR__.'/data/where-on-encrypted.inc'], [
            ["Column 'national_id' of App\Models\Tenant\Patient is cast 'encrypted': where() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.", 16, self::TIP],
            ["Column 'notes' of App\Models\Tenant\Patient is cast 'encrypted': where() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.", 21, self::TIP],
            ["Column 'national_id' of App\Models\Tenant\Patient is cast 'encrypted': whereIn() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.", 27, self::TIP],
            ["Column 'notes' of App\Models\Tenant\PatientAllergy is cast 'encrypted': where() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.", 32, self::TIP],
            ["Column 'private_notes' of App\Models\Tenant\Visit is cast 'encrypted': orWhere() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.", 37, self::TIP],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return array_merge(parent::getAdditionalConfigFiles(), [__DIR__.'/rules.neon']);
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoWhereOnEncryptedCasts::class);
    }
}
