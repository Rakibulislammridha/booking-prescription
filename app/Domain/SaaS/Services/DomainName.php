<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

/**
 * Hostname handling for `public.domains` (SCHEMA §2.7: `domain = lower(domain)`, ≤ 253 chars).
 *
 * The apex/subdomain distinction is not cosmetic — it changes the record the customer has to create. An apex
 * (`hospital.com`) cannot carry a CNAME (RFC 1034 §3.6.2: a CNAME may not coexist with the SOA/NS records every
 * zone apex has), so it needs an A record; `queue.hospital.com` should CNAME to the platform host so the address
 * follows us. Both prove ownership the same way, with a TXT record.
 */
final class DomainName
{
    public const TXT_PREFIX = 'bp-verify=';

    /** The label the TXT record lives under, so an apex zone's existing SPF/DMARC TXT records are left alone. */
    public const TXT_LABEL = '_bp-verify';

    /** Lower-cased, trailing dot and port removed, IDN encoded to punycode. Empty string when unusable. */
    public static function normalise(string $host): string
    {
        $host = mb_strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host, 2)[0];
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');

        if ($host === '') {
            return '';
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? $ascii : $host;
        }

        return $host;
    }

    /** A syntactically usable public hostname: at least two labels, LDH only, ≤ 253 chars. */
    public static function isValid(string $host): bool
    {
        return $host !== ''
            && mb_strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    /**
     * Two labels = apex (`hospital.com`). Three or more = a subdomain, EXCEPT for the well-known two-part public
     * suffixes a Bangladeshi clinic actually buys (`hospital.com.bd`, `clinic.gov.bd`), where three labels are
     * still the apex. There is no PSL dependency: this list covers the suffixes on offer in .bd plus the two
     * generic ones people hit, and being wrong only changes the DNS instructions we display, never the check.
     */
    public static function isApex(string $host): bool
    {
        $labels = explode('.', $host);
        $count = count($labels);

        if ($count <= 2) {
            return true;
        }

        $suffix = $labels[$count - 2].'.'.$labels[$count - 1];

        return $count === 3 && in_array($suffix, self::TWO_LABEL_SUFFIXES, true);
    }

    /** @var array<int, string> */
    private const TWO_LABEL_SUFFIXES = [
        'com.bd', 'net.bd', 'org.bd', 'edu.bd', 'gov.bd', 'ac.bd', 'mil.bd', 'info.bd',
        'co.uk', 'org.uk', 'com.au', 'co.in',
    ];

    /** The TXT value the customer must publish. */
    public static function expectedTxt(string $token): string
    {
        return self::TXT_PREFIX.$token;
    }

    /**
     * Names the verifier asks for, in order: the dedicated `_bp-verify` label first, the host itself as a
     * fallback for panels that cannot create an underscore label.
     *
     * @return array<int, string>
     */
    public static function txtCandidates(string $host): array
    {
        return [self::TXT_LABEL.'.'.$host, $host];
    }

    /** Is this host the platform's own (central, super, or a tenant's `{slug}.{central}` subdomain)? */
    public static function isPlatformHost(string $host, string $centralDomain): bool
    {
        $central = mb_strtolower($centralDomain);

        return $host === $central || str_ends_with($host, '.'.$central);
    }
}
