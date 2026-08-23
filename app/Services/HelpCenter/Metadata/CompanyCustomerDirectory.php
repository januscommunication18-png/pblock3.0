<?php

namespace App\Services\HelpCenter\Metadata;

use App\Models\HelpCenterCompany;
use App\Models\HelpCenterCustomer;
use App\Models\HelpCenterCustomFieldValue;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use Illuminate\Support\Collection;

/**
 * The rows behind Company & Customer (docs/features/help-center.md, P75 §11–§13).
 *
 * Every list on that screen wants per-record COUNTS — Open Tickets, Total Tickets, Last Ticket —
 * and computing them off the model would be three correlated sub-queries per row. So they are
 * computed once, for the whole page, as grouped aggregates and merged onto the rows here.
 *
 * This is also the one place that knows Customers and Companies are workspace-scoped while the
 * feature is switched on per SPACE (HC-D51, HC-D57): the counts are restricted to the Spaces
 * that have it on, so a workspace running Company & Customer in Billing only does not show
 * Support's ticket numbers on a customer's profile.
 */
class CompanyCustomerDirectory
{
    /** How many rows a list returns before it asks for a narrower search. */
    private const LIMIT = 200;

    /**
     * The Spaces this workspace runs Company & Customer in.
     *
     * @return Collection<int, HelpCenterSpace>
     */
    public function enabledSpaces(): Collection
    {
        return HelpCenterSpace::query()
            ->active()
            ->with('settings')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (HelpCenterSpace $space) => $space->featureEnabled('company'))
            ->values();
    }

    /** @return array<int, int> */
    private function enabledSpaceIds(): array
    {
        return $this->enabledSpaces()->map(fn (HelpCenterSpace $s) => (int) $s->id)->all();
    }

    /**
     * The Customers tab (§11).
     *
     * @return array<int, array<string, mixed>>
     */
    public function customers(?string $search = null): array
    {
        $rows = HelpCenterCustomer::query()
            ->search($search)
            ->with('companyRecord')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $counts = $this->counts('help_center_customer_id', $rows->pluck('id')->all());

        return $rows->map(function (HelpCenterCustomer $customer) use ($counts) {
            $count = $counts[$customer->id] ?? [];

            return [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'email' => $customer->email,
                'initial' => mb_strtoupper(mb_substr($customer->displayName(), 0, 1)) ?: '?',
                'company_id' => $customer->help_center_company_id,
                'company' => $customer->companyRecord?->displayName() ?? $customer->company,
                'open_tickets' => (int) ($count['open'] ?? 0),
                'total_tickets' => (int) ($count['total'] ?? 0),
                'last_ticket' => $count['last'] ?? null,
                'created' => $customer->created_at?->format('M j, Y'),
            ];
        })->all();
    }

    /**
     * The Companies tab (§11).
     *
     * @return array<int, array<string, mixed>>
     */
    public function companies(?string $search = null): array
    {
        $rows = HelpCenterCompany::query()
            ->search($search)
            ->withCount('customers')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $counts = $this->counts('help_center_company_id', $rows->pluck('id')->all());

        return $rows->map(function (HelpCenterCompany $company) use ($counts) {
            $count = $counts[$company->id] ?? [];

            return [
                'id' => $company->id,
                'name' => $company->displayName(),
                'domain' => $company->domain,
                'initial' => mb_strtoupper(mb_substr($company->displayName(), 0, 1)) ?: '?',
                'customers' => (int) $company->customers_count,
                'open_tickets' => (int) ($count['open'] ?? 0),
                'total_tickets' => (int) ($count['total'] ?? 0),
                'last_activity' => $count['last'] ?? $company->last_activity_at?->format('M j, Y'),
                'created' => $company->created_at?->format('M j, Y'),
            ];
        })->all();
    }

    /**
     * Open, total and last-ticket for a set of ids, in ONE query.
     *
     * `$column` is `help_center_customer_id` or `help_center_company_id` — the two are the same
     * three aggregates over the same table, and writing them twice would be two places to get
     * the spam and closed conditions right.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{open: int, total: int, last: ?string}>
     */
    private function counts(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $spaces = $this->enabledSpaceIds();

        if ($spaces === []) {
            return [];
        }

        $rows = HelpCenterRequest::query()
            ->selectRaw($column.' as owner_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN closed_at IS NULL AND is_spam = 0 THEN 1 ELSE 0 END) as open_count')
            ->selectRaw('MAX(last_message_at) as last_at')
            ->whereIn($column, $ids)
            ->whereIn('help_center_space_id', $spaces)
            ->groupBy($column)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->owner_id] = [
                'open' => (int) $row->open_count,
                'total' => (int) $row->total,
                'last' => $row->last_at === null ? null : date('M j, Y', strtotime((string) $row->last_at)),
            ];
        }

        return $out;
    }

    /**
     * One Customer's profile (§12) — the record, its custom fields and its tickets.
     *
     * The custom fields are the UNION of every enabled Space's Customer fields, because the
     * record is the workspace's while the field lists are each Space's (HC-D51). Grouped by
     * Space so the profile can say whose question each one is rather than showing two Spaces'
     * "Account Tier" as one repeated row.
     *
     * @return array<string, mixed>
     */
    public function customerProfile(HelpCenterCustomer $customer): array
    {
        return [
            'customer' => $customer->toPanel(),
            'tags' => $customer->tagList(),
            'created' => $customer->created_at?->format('M j, Y'),
            'fields' => $this->fieldGroups(HelpCenterCustomFieldValue::KIND_CUSTOMER, (int) $customer->id),
            'tickets' => $this->tickets('help_center_customer_id', (int) $customer->id),
        ];
    }

    /**
     * One Company's profile (§13) — the record, its custom fields, its customers, its tickets.
     *
     * @return array<string, mixed>
     */
    public function companyProfile(HelpCenterCompany $company): array
    {
        return [
            'company' => $company->toPanel(),
            'tags' => $company->tagList(),
            'created' => $company->created_at?->format('M j, Y'),
            'fields' => $this->fieldGroups(HelpCenterCustomFieldValue::KIND_COMPANY, (int) $company->id),
            'customers' => $company->customers()
                ->orderByDesc('last_activity_at')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (HelpCenterCustomer $c) => [
                    'id' => $c->id,
                    'name' => $c->displayName(),
                    'email' => $c->email,
                    'initial' => mb_strtoupper(mb_substr($c->displayName(), 0, 1)) ?: '?',
                ])->all(),
            // Every ticket from anybody at the company (§13), which is the Company link on the
            // Request rather than a join through its customers — the two agree, and the direct
            // column is one index lookup instead of a subquery.
            'tickets' => $this->tickets('help_center_company_id', (int) $company->id),
        ];
    }

    /**
     * A record's custom fields with its answers, grouped by the Space that asks them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fieldGroups(string $kind, int $ownerId): array
    {
        $values = HelpCenterCustomFieldValue::mapFor($kind, $ownerId);
        $switch = $kind === HelpCenterCustomFieldValue::KIND_COMPANY
            ? 'company_custom_fields'
            : 'customer_custom_fields';

        $out = [];

        foreach ($this->enabledSpaces() as $space) {
            if (! $space->featureEnabled($switch)) {
                continue;
            }

            $fields = ($kind === HelpCenterCustomFieldValue::KIND_COMPANY
                ? $space->companyFields()
                : $space->customerFields())->where('is_active', true)->get();

            if ($fields->isEmpty()) {
                continue;
            }

            $out[] = [
                'space_id' => $space->id,
                'space' => $space->name,
                'fields' => $fields->map(fn ($field) => $field->toPayload() + [
                    'value' => $values[$field->id] ?? null,
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * The tickets on a profile (§12, §13).
     *
     * Restricted to the enabled Spaces for the same reason the counts are: a profile opened from
     * Company & Customer should not be a way to read a Space that does not run the feature.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tickets(string $column, int $ownerId): array
    {
        $spaces = $this->enabledSpaceIds();

        if ($spaces === []) {
            return [];
        }

        return HelpCenterRequest::query()
            ->where($column, $ownerId)
            ->whereIn('help_center_space_id', $spaces)
            ->with(['status', 'space'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (HelpCenterRequest $request) => [
                'id' => $request->id,
                'number' => $request->ticket_number,
                'subject' => $request->subject ?: '(no subject)',
                'space' => $request->space?->name,
                'status' => $request->status?->name,
                'status_color' => $request->status?->color,
                'closed' => $request->closed_at !== null,
                'last_message' => $request->last_message_at?->format('M j, Y'),
                'url' => route('help-center.spaces.requests.page', [
                    'space' => $request->help_center_space_id,
                    'request' => $request->id,
                ]),
            ])->all();
    }
}
