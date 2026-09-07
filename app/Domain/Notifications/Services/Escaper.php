<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

/**
 * Context escaping for interpolated values. The dangerous contexts here are not HTML: an IVR flow renders text into
 * SSML/XML and a gateway renders it into a URL query, so a patient name containing `<break/>` or `&amp;api_key=` must
 * be neutralised before it leaves the process.
 */
final class Escaper
{
    public static function for(string $context, string $value): string
    {
        return match ($context) {
            'html' => self::html($value),
            'speech' => self::speech($value),
            'url' => self::url($value),
            default => self::text($value),
        };
    }

    /** SMS / WhatsApp / push: strip control characters, keep the newlines the author wrote. */
    public static function text(string $value): string
    {
        return self::stripControl($value);
    }

    public static function html(string $value): string
    {
        return htmlspecialchars(self::stripControl($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * IVR: the value ends up inside an XML/SSML document a telephony vendor renders. Drop every markup character
     * and every control character, then collapse whitespace — a name is spoken, not marked up.
     */
    public static function speech(string $value): string
    {
        $value = self::stripControl($value);
        $value = (string) preg_replace('/[<>&"\'\\\\{}\[\]$`|;]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /** Any value a driver puts into a URL path or query. */
    public static function url(string $value): string
    {
        return rawurlencode(self::stripControl($value));
    }

    /** Removes C0/C1 controls except \n and \t, and normalises CRLF. */
    public static function stripControl(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $value);
    }
}
