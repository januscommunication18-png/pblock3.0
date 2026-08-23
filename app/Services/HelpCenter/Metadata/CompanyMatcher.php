<?php

namespace App\Services\HelpCenter\Metadata;

use App\Models\HelpCenterCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finding the Company an incoming request belongs to (P75 §7).
 *
 * "Matching may use External Company ID, company domain, company name, configured mapping
 * field", in that order — most specific identifier first, so that a request carrying a CRM id
 * lands on the right row even when somebody has typed the name three different ways.
 *
 * NAME is last and is matched exactly, case-insensitively. Fuzzy name matching is the thing that
 * merges "Acme Ltd" with "Acme Holdings Ltd", and there is no undo for a merge.
 */
class CompanyMatcher
{
    /**
     * The Company for these values, created if the request carries enough to create one.
     *
     * Returns null when nothing identifies a company — which is the common case for a personal
     * address, and is not a failure: a Request with no Company still renders.
     *
     * @param  array<string, ?string>  $values  Mapped Company attributes — `external_id`,
     *                                          `domain`, `name`, `phone`.
     * @param  bool  $create  False when Company Management is off for the Space: an existing
     *                        Company is still linked, but none is invented.
     */
    public function match(string $tenantId, array $values, bool $create = true): ?HelpCenterCompany
    {
        $externalId = self::clean($values['external_id'] ?? null);
        $domain = HelpCenterCompany::normaliseDomain($values['domain'] ?? null);
        $name = self::clean($values['name'] ?? null);

        $company = $this->find($tenantId, $externalId, $domain, $name);

        if ($company !== null || ! $create) {
            return $company;
        }

        /*
         * A Company needs something to BE.
         *
         * A row with no domain, no external id and no name is not a company; it is an empty
         * record that every later match would fail to find and every list would show as a blank
         * line. The requirement's own wording is permissive — "MAY create the Company
         * automatically" — and this is where that judgement lives.
         */
        if ($domain === null && $externalId === null && $name === null) {
            return null;
        }

        return $this->create($tenantId, [
            'name' => $name ?? $domain ?? $externalId,
            'domain' => $domain,
            'external_id' => $externalId,
            'phone' => self::clean($values['phone'] ?? null),
        ], $externalId, $domain, $name);
    }

    /** The three lookups, in the requirement's own priority order. */
    private function find(string $tenantId, ?string $externalId, ?string $domain, ?string $name): ?HelpCenterCompany
    {
        $base = fn (): Builder => HelpCenterCompany::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if ($externalId !== null) {
            $found = $base()->where('external_id', $externalId)->first();

            if ($found !== null) {
                return $found;
            }
        }

        if ($domain !== null) {
            $found = $base()->where('domain', $domain)->first();

            if ($found !== null) {
                return $found;
            }
        }

        // Exact, case-insensitively — which is what the column's collation already gives on
        // MySQL/MariaDB. Written as a plain `where` rather than a `LOWER()` call so the index
        // on (tenant_id, name) is still usable.
        return $name === null ? null : $base()->where('name', $name)->first();
    }

    /**
     * Create, and survive losing the race.
     *
     * Two workers ingesting two emails from one new domain both find nothing and both insert.
     * The unique index on `(tenant_id, domain)` makes the loser's insert fail — and the right
     * answer for the loser is the row the winner just wrote, not an exception that fails an
     * ingest and makes Postmark retry the message.
     *
     * @param  array<string, ?string>  $attributes
     */
    private function create(
        string $tenantId,
        array $attributes,
        ?string $externalId,
        ?string $domain,
        ?string $name,
    ): ?HelpCenterCompany {
        try {
            return HelpCenterCompany::create($attributes + [
                'tenant_id' => $tenantId,
                'first_seen_at' => now(),
                'last_activity_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($tenantId, $externalId, $domain, $name);
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
