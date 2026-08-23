<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The tenant's customer-facing subdomain (docs/features/workspace-subdomain.md, P72).
 *
 * ONE place that answers what a subdomain may be, because the question is asked in four:
 * the live availability check as somebody types, the validator on submit, the two creation
 * forms, and anything that later builds a URL from it. Four copies of a hostname rule is how a
 * name that the checker called free gets refused by the validator — or worse, accepted by both
 * and unroutable in DNS.
 */
class TenantSubdomain
{
    /**
     * Everything a name must be before it can be a host.
     *
     * The requirement's character rule is "a-z, 0-9, - … no leading or trailing hyphens".
     * `[a-z0-9]+(?:-[a-z0-9]+)*` says exactly that, and also rules out a double hyphen — though
     * NOT for the punycode case, since `normalize()` has already collapsed `--` to `-` by the
     * time this runs. `refusal()` rejects the `xn-` prefix by name for that reason.
     */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Lowercase, trimmed, and stripped of anything that cannot be in a hostname.
     *
     * Deliberately does NOT try to rescue a bad name into a good one beyond case and spacing —
     * `Acme Inc` becomes `acme-inc`, which is what the requirement asks for, but a name that is
     * still wrong afterwards is REFUSED rather than mangled into something the user did not
     * choose. This is going to be their public address.
     */
    public static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        // A full URL or host pasted in — keep the first label, which is what they meant.
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = explode('/', $value)[0];
        $value = explode('.', $value)[0];

        /*
         * TRANSLITERATED, not stripped.
         *
         * `Str::ascii` turns `ü` into `u`, so a German or French company gets the name they
         * meant. Dropping non-ASCII instead — which this did at first — turned `ünïcode` into
         * `ncode`: a hostname the customer never chose, silently, on the field that becomes
         * their public address.
         */
        $value = mb_strtolower(Str::ascii($value));

        // Spaces and underscores become hyphens; everything else that cannot be in a label goes.
        $value = (string) preg_replace('/[\s_]+/', '-', $value);
        $value = (string) preg_replace('/[^a-z0-9-]/', '', $value);
        $value = (string) preg_replace('/-{2,}/', '-', $value);

        return trim($value, '-');
    }

    /** Why this name cannot be used, or null when it can. */
    public static function refusal(string $normalized): ?string
    {
        $min = (int) config('workspace.subdomain.min', 3);
        $max = (int) config('workspace.subdomain.max', 63);

        if ($normalized === '') {
            return 'Enter a subdomain.';
        }

        if (mb_strlen($normalized) < $min) {
            return 'A subdomain must be at least '.$min.' characters.';
        }

        if (mb_strlen($normalized) > $max) {
            return 'A subdomain can be at most '.$max.' characters.';
        }

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            return 'Use lowercase letters, numbers and hyphens only, and do not start or end with a hyphen.';
        }

        if (in_array($normalized, self::reserved(), true)) {
            return 'That subdomain is reserved. Please choose another.';
        }

        /*
         * The punycode namespace, refused explicitly.
         *
         * `xn--` prefixes an internationalised domain, and a tenant holding one could present a
         * hostname that renders in a browser as somebody else's. The PATTERN above was supposed
         * to catch it by forbidding a double hyphen — and did not, because `normalize()` runs
         * first and collapses `xn--80ak…` to `xn-80ak…`, which passes. Two rules that each
         * assumed the other was doing the work.
         */
        if (str_starts_with($normalized, 'xn-')) {
            return 'That subdomain is reserved. Please choose another.';
        }

        return null;
    }

    /** Free to claim right now — shape, reserved list and the tenants table. */
    public static function isAvailable(string $normalized, ?string $exceptTenantId = null): bool
    {
        if (self::refusal($normalized) !== null) {
            return false;
        }

        return ! Workspace::query()
            ->where('subdomain', $normalized)
            ->when($exceptTenantId !== null, fn ($q) => $q->whereKeyNot($exceptTenantId))
            ->exists();
    }

    /**
     * The validator's rules — the same facts as above, phrased for a FormRequest.
     *
     * `Rule::unique` is here as well as the index on the table: the index is what actually
     * guarantees uniqueness under a race, and this is what turns the second submission into a
     * message somebody can act on rather than a 500.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            'min:'.(int) config('workspace.subdomain.min', 3),
            'max:'.(int) config('workspace.subdomain.max', 63),
            'regex:'.self::PATTERN,
            /*
             * The punycode prefix, refused here TOO.
             *
             * `refusal()` already rejects it, and this list did not — so the live check called
             * `xn--evil` reserved while the validator accepted it on submit. Two lists that were
             * supposed to be one rule, which is the exact failure this class exists to prevent
             * and which it committed on its first outing.
             */
            'not_regex:/^xn-/',
            Rule::notIn(self::reserved()),
            Rule::unique('tenants', 'subdomain'),
        ];
    }

    /** `https://acme.projectblock.app` — built here so no screen composes its own. */
    public static function url(string $normalized): string
    {
        return config('workspace.subdomain.scheme', 'https').'://'.self::host($normalized).self::port();
    }

    /** `acme.projectblock.app` — the HOST, which is what routing and Host headers deal in. */
    public static function host(string $normalized): string
    {
        return $normalized.'.'.self::root();
    }

    /** The zone, host-only. Never carries a port — see the note in config/workspace.php. */
    public static function root(): string
    {
        return (string) config('workspace.subdomain.root', 'projectblock.app');
    }

    /** `:8000` locally, empty everywhere else. Display only. */
    public static function port(): string
    {
        $port = trim((string) config('workspace.subdomain.port', ''));

        return $port === '' ? '' : ':'.$port;
    }

    /**
     * The tenant label in a host, or null when the host is not one of ours.
     *
     * `acme.projectblock.app` → `acme`. Used by the middleware that resolves a request to a
     * tenant, so the parsing rule lives with every other rule about what a subdomain is.
     */
    public static function fromHost(?string $host): ?string
    {
        $host = mb_strtolower(trim((string) $host));
        $suffix = '.'.mb_strtolower(self::root());

        if ($host === '' || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = mb_substr($host, 0, -mb_strlen($suffix));

        // A single label only: `a.b.projectblock.app` is not a tenant, it is somebody probing.
        return ($label === '' || str_contains($label, '.')) ? null : $label;
    }

    /**
     * Which apps oblige a tenant to have one.
     *
     * @return array<int, string>
     */
    public static function requiredBy(): array
    {
        return (array) config('workspace.subdomain.required_by', []);
    }

    /** True when the chosen apps make this workspace customer-facing. */
    public static function requiredFor(array $apps): bool
    {
        return array_intersect($apps, self::requiredBy()) !== [];
    }

    /** @return array<int, string> */
    private static function reserved(): array
    {
        return array_map('strtolower', (array) config('workspace.subdomain.reserved', []));
    }
}
