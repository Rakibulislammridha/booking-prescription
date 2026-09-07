<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use App\Domain\Prescription\Shorthand\NumberFormat;

/**
 * Section captions for the printed sheet. Deliberately NOT resources/lang/*.json: print language is a property of
 * the prescription (`snapshot.prescription.language` / the pad's `default_language`, PRESCRIPTION.md §7.2), not of
 * the staff member's UI locale — the same document must print identically for a Bangla receptionist and an English
 * doctor. Drug names are never translated (§7.2), so no label here names a molecule or a brand.
 */
final readonly class PrintLabels
{
    private const LABELS = [
        'prescription' => ['en' => 'Prescription', 'bn' => 'প্রেসক্রিপশন'],
        'patient' => ['en' => 'Patient', 'bn' => 'রোগী'],
        'age' => ['en' => 'Age', 'bn' => 'বয়স'],
        'sex' => ['en' => 'Sex', 'bn' => 'লিঙ্গ'],
        'date' => ['en' => 'Date', 'bn' => 'তারিখ'],
        'serial' => ['en' => 'Serial', 'bn' => 'সিরিয়াল'],
        'id' => ['en' => 'ID', 'bn' => 'আইডি'],
        'weight' => ['en' => 'Weight', 'bn' => 'ওজন'],
        'vitals' => ['en' => 'Vitals', 'bn' => 'শারীরিক পরিমাপ'],
        'bp' => ['en' => 'BP', 'bn' => 'রক্তচাপ'],
        'pulse' => ['en' => 'Pulse', 'bn' => 'নাড়ি'],
        'temp' => ['en' => 'Temp', 'bn' => 'তাপমাত্রা'],
        'spo2' => ['en' => 'SpO₂', 'bn' => 'অক্সিজেন'],
        'height' => ['en' => 'Height', 'bn' => 'উচ্চতা'],
        'bmi' => ['en' => 'BMI', 'bn' => 'বিএমআই'],
        'complaints' => ['en' => 'Chief complaints', 'bn' => 'প্রধান সমস্যা'],
        'examination' => ['en' => 'On examination', 'bn' => 'পরীক্ষায় প্রাপ্ত'],
        'diagnosis' => ['en' => 'Diagnosis', 'bn' => 'রোগ নির্ণয়'],
        'allergies' => ['en' => 'Allergies', 'bn' => 'অ্যালার্জি'],
        'investigations' => ['en' => 'Investigations', 'bn' => 'পরীক্ষা-নিরীক্ষা'],
        'total' => ['en' => 'Total', 'bn' => 'মোট'],
        'urgent' => ['en' => 'urgent', 'bn' => 'জরুরি'],
        'advice' => ['en' => 'Advice', 'bn' => 'পরামর্শ'],
        'follow_up' => ['en' => 'Next visit', 'bn' => 'পরবর্তী সাক্ষাৎ'],
        'referral' => ['en' => 'Referred to', 'bn' => 'রেফার করা হলো'],
        'signature' => ['en' => 'Signature', 'bn' => 'স্বাক্ষর'],
        'quantity' => ['en' => 'Qty', 'bn' => 'পরিমাণ'],
        'drug' => ['en' => 'Medicine', 'bn' => 'ওষুধ'],
        'instruction' => ['en' => 'See prescription for instructions', 'bn' => 'নির্দেশনা প্রেসক্রিপশনে দেখুন'],
        'pharmacy_copy' => ['en' => 'Pharmacy copy', 'bn' => 'ফার্মেসি কপি'],
        'more_info' => ['en' => 'More information', 'bn' => 'আরও তথ্য'],
        'verify_hint' => ['en' => 'Scan to verify this prescription', 'bn' => 'যাচাই করতে স্ক্যান করুন'],
        'verification_code' => ['en' => 'Verification code', 'bn' => 'যাচাই কোড'],
        'typed_rx' => ['en' => 'Rx (typed)', 'bn' => 'Rx (টাইপ করা)'],
        'handwritten' => ['en' => 'Handwritten', 'bn' => 'হাতে লেখা'],
        'diagram' => ['en' => 'Diagram', 'bn' => 'চিত্র'],
        'page' => ['en' => 'Page', 'bn' => 'পৃষ্ঠা'],
        'of' => ['en' => 'of', 'bn' => 'এর'],
        'valid' => ['en' => 'Valid prescription', 'bn' => 'বৈধ প্রেসক্রিপশন'],
        'not_prescription' => ['en' => 'Not a prescription — verified copy', 'bn' => 'যাচাইকৃত অনুলিপি'],
        'version' => ['en' => 'Version', 'bn' => 'সংস্করণ'],
    ];

    public function __construct(private string $language = 'both') {}

    /** The caption in the print language; `both` prints "English / বাংলা" so neither reader is guessing. */
    public function __invoke(string $key): string
    {
        return $this->get($key);
    }

    public function get(string $key): string
    {
        $label = self::LABELS[$key] ?? ['en' => $key, 'bn' => $key];

        return match ($this->language) {
            'en' => $label['en'],
            'bn' => $label['bn'],
            default => $label['en'].' / '.$label['bn'],
        };
    }

    public function in(string $language, string $key): string
    {
        $label = self::LABELS[$key] ?? ['en' => $key, 'bn' => $key];

        return $language === 'bn' ? $label['bn'] : $label['en'];
    }

    /** Digits follow the language: a Bangla line with ASCII numerals reads as broken to a Bangla patient. */
    public function digits(string $text): string
    {
        return $this->language === 'bn' ? NumberFormat::bnDigits($text) : $text;
    }

    public function language(): string
    {
        return $this->language;
    }
}
