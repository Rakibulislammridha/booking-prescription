<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Enums\DosageFormCode;

/**
 * Maps DGDA / free-text dosage-form names (`Tablet`, `Tab.`, `Film coated tablet`) to dosage_forms.code (CATALOG.md §5.3).
 */
final class FormMapper
{
    /** @var array<string, string> normalised text → code */
    private const MAP = [
        'tab' => 'tab', 'tabs' => 'tab', 'tablet' => 'tab', 'tablets' => 'tab', 'fc tablet' => 'tab', 'film coated tablet' => 'tab',
        'film-coated tablet' => 'tab', 'coated tablet' => 'tab', 'chewable tablet' => 'tab', 'dispersible tablet' => 'tab',
        'effervescent tablet' => 'tab', 'orally disintegrating tablet' => 'tab', 'odt' => 'tab', 'xr tablet' => 'tab', 'sr tablet' => 'tab',
        'er tablet' => 'tab', 'extended release tablet' => 'tab', 'sustained release tablet' => 'tab', 'sublingual tablet' => 'tab',
        'cap' => 'cap', 'caps' => 'cap', 'capsule' => 'cap', 'capsules' => 'cap', 'soft gelatin capsule' => 'cap', 'sr capsule' => 'cap',
        'dr capsule' => 'cap', 'delayed release capsule' => 'cap',
        'syr' => 'syr', 'syrup' => 'syr', 'elixir' => 'syr', 'linctus' => 'syr',
        'susp' => 'susp', 'suspension' => 'susp', 'oral suspension' => 'susp', 'powder for suspension' => 'susp', 'pfs' => 'susp',
        'powder for oral suspension' => 'susp', 'dry syrup' => 'susp',
        'sol' => 'sol', 'solution' => 'sol', 'oral solution' => 'sol', 'topical solution' => 'sol',
        'oral drop' => 'oral_drop', 'oral drops' => 'oral_drop', 'paediatric drops' => 'oral_drop', 'pediatric drops' => 'oral_drop',
        'paediatric drop' => 'oral_drop', 'pediatric drop' => 'oral_drop', 'drops' => 'oral_drop', 'drop' => 'oral_drop',
        'eye drop' => 'eye_drop', 'eye drops' => 'eye_drop', 'ophthalmic solution' => 'eye_drop', 'ophthalmic suspension' => 'eye_drop',
        'eye ointment' => 'oint', 'ear drop' => 'ear_drop', 'ear drops' => 'ear_drop', 'otic solution' => 'ear_drop',
        'nasal drop' => 'nasal_drop', 'nasal drops' => 'nasal_drop', 'nasal spray' => 'nasal_spray',
        'inhaler' => 'inh_mdi', 'mdi' => 'inh_mdi', 'metered dose inhaler' => 'inh_mdi', 'hfa inhaler' => 'inh_mdi', 'aerosol inhaler' => 'inh_mdi',
        'dpi' => 'inh_dpi', 'dry powder inhaler' => 'inh_dpi', 'inhalation powder' => 'inh_dpi', 'cozycap' => 'inh_dpi', 'inhalation capsule' => 'inh_dpi',
        'nebuliser solution' => 'neb', 'nebulizer solution' => 'neb', 'respirator solution' => 'neb', 'nebule' => 'neb', 'respule' => 'neb',
        'nebules' => 'neb', 'respules' => 'neb', 'solution for nebulisation' => 'neb',
        'inj' => 'inj', 'injection' => 'inj', 'iv injection' => 'inj', 'im injection' => 'inj', 'iv infusion' => 'inj', 'infusion' => 'inj',
        'vial' => 'inj', 'ampoule' => 'inj', 'powder for injection' => 'inj', 'prefilled syringe' => 'inj',
        'insulin' => 'insulin', 'insulin injection' => 'insulin', 'insulin pen' => 'insulin', 'cartridge' => 'insulin',
        'cream' => 'cream', 'oint' => 'oint', 'ointment' => 'oint', 'gel' => 'gel', 'lotion' => 'lotion',
        'powder' => 'powder', 'dusting powder' => 'powder', 'shampoo' => 'shampoo', 'mouthwash' => 'mouthwash', 'mouth wash' => 'mouthwash',
        'gargle' => 'mouthwash', 'paint' => 'paint', 'mouth paint' => 'paint', 'supp' => 'supp', 'suppository' => 'supp', 'suppositories' => 'supp',
        'pessary' => 'pessary', 'pessaries' => 'pessary', 'vaginal tablet' => 'pessary', 'vaginal cream' => 'cream',
        'sachet' => 'sachet', 'sachets' => 'sachet', 'oral powder' => 'sachet', 'powder sachet' => 'sachet', 'ors' => 'sachet',
    ];

    public function code(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $key = strtolower(trim($text));
        $key = trim(preg_replace('/[.\s]+/', ' ', $key) ?? $key);

        if ($key === '') {
            return null;
        }

        if (DosageFormCode::tryFrom($key) !== null) {
            return $key;
        }

        return self::MAP[$key] ?? null;
    }
}
