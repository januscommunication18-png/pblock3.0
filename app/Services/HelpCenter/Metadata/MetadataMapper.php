<?php

namespace App\Services\HelpCenter\Metadata;

use App\Models\HelpCenterCompany;
use App\Models\HelpCenterCustomer;
use App\Models\HelpCenterCustomFieldValue;
use App\Models\HelpCenterMetadataMapping;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\Inbound\CustomerMatcher;
use App\Services\HelpCenter\RequestActivity;
use Illuminate\Support\Collection;

/**
 * Company & Customer mapping, applied to one incoming Request (P75 §5–§9).
 *
 * The requirement's flow, in order:
 *
 *   Parse Ticket Data → Run Mapping → Find/Create Customer → Find/Create Company
 *   → Link Customer to Company → Link both to the Ticket → Store Mapped Metadata
 *
 * Two rules govern every write and are stated once, here, rather than at each destination:
 *
 * 1. A matched record LEARNS an empty field; it never overwrites one that already has a value.
 *    Somebody typed that value into a panel, and there is no way after the fact to tell it from
 *    something a mail header said this morning. `last_activity_at` is the exception, because it
 *    is a fact about the ticket rather than a claim about the person.
 * 2. A mapping whose source carried nothing does NOTHING. Absent is not the same as blank, and
 *    writing null over a stored phone number because this particular email had no signature is
 *    the single most destructive thing this class could do.
 */
class MetadataMapper
{
    public function __construct(
        private readonly CustomerMatcher $customers,
        private readonly CompanyMatcher $companies,
        private readonly RequestActivity $activity,
    ) {}

    /**
     * Run the Space's mappings over one Request's metadata and link what comes out.
     *
     * Saves the Request. Returns silently — and does nothing at all — when the Space has the
     * feature off, which is what makes this safe to call unconditionally from ingest.
     */
    public function apply(HelpCenterRequest $request, HelpCenterSpace $space, TicketMetadata $metadata): void
    {
        // Always stored, even when every switch is off: §8 asks for the original inbound
        // metadata to stay available, and Reprocess (§14) needs something to re-run over if the
        // feature is switched on later.
        $request->inbound_metadata = $metadata->all();

        if (! $space->featureEnabled('company')) {
            $request->save();

            return;
        }

        $mappings = $this->mappingsFor($space);
        $values = $this->resolve($mappings, $metadata);

        $customer = $space->featureEnabled('customer_management')
            ? $this->customer($request, $space, $metadata, $values, $mappings)
            : null;

        $company = $space->featureEnabled('company_management')
            ? $this->company($request, $space, $metadata, $values, $mappings, $customer)
            : null;

        // Link Customer to Company, then both to the Ticket — the requirement's own order, and
        // the only order in which the Customer's own link is written before it is read back.
        if ($customer !== null && $company !== null && $customer->help_center_company_id === null) {
            $customer->forceFill(['help_center_company_id' => $company->id])->save();
            $this->log($request, 'company', null, $company->displayName());
        }

        $request->forceFill([
            'help_center_customer_id' => $customer?->id ?? $request->help_center_customer_id,
            'help_center_company_id' => $company?->id
                ?? $customer?->help_center_company_id
                ?? $request->help_center_company_id,
        ])->save();
    }

    /**
     * The mappings this Space applies, active only.
     *
     * When Ticket Metadata Mapping is switched OFF the configured rows are ignored and the
     * packaged defaults are used instead. That is not a fallback for a missing feature — it is
     * what "Customer Management, no mapping table" has to mean: an email still has a sender
     * address and a sender name, and refusing to read them would leave the Customer record the
     * requirement asks for permanently blank.
     *
     * @return Collection<int, HelpCenterMetadataMapping>
     */
    private function mappingsFor(HelpCenterSpace $space): Collection
    {
        if (! $space->featureEnabled('ticket_metadata_mapping')) {
            return collect((array) config('help-center.mapping_defaults'))
                ->map(fn (array $row) => new HelpCenterMetadataMapping($row + [
                    'help_center_space_id' => $space->id,
                    'is_active' => true,
                ]));
        }

        return $space->metadataMappings()->active()->ordered()->get();
    }

    /**
     * Every mapping's source value, bucketed by record type and destination.
     *
     * Resolved in ONE pass before anything is written, because the Customer write needs the
     * Company's identifiers to be known (matching runs on them) and the Company write needs the
     * Customer's. Interleaving resolution with writing would mean each half seeing only what
     * came before it.
     *
     * The first mapping to produce a value for a destination wins — `position` order, which is
     * the order the person authored and is looking at.
     *
     * @param  Collection<int, HelpCenterMetadataMapping>  $mappings
     * @return array<string, array<string, string>>
     */
    private function resolve(Collection $mappings, TicketMetadata $metadata): array
    {
        $out = ['customer' => [], 'company' => []];

        foreach ($mappings as $mapping) {
            $value = $metadata->get($mapping->metadataKey());

            if ($value === null) {
                continue;
            }

            $type = (string) $mapping->record_type;

            if (! array_key_exists($type, $out)) {
                continue;
            }

            $key = $mapping->writesCustomField()
                ? 'custom:'.$mapping->custom_field_id
                : (string) $mapping->destination;

            $out[$type][$key] ??= $value;
        }

        return $out;
    }

    /**
     * Find or create the Customer, then fill what it does not already know.
     *
     * Matching is `CustomerMatcher`'s job and is unchanged from P33 — External Customer ID
     * first, then email — because it is also what the Inbox, the panel and the reply path use.
     *
     * @param  array<string, array<string, string>>  $values
     * @param  Collection<int, HelpCenterMetadataMapping>  $mappings
     */
    private function customer(
        HelpCenterRequest $request,
        HelpCenterSpace $space,
        TicketMetadata $metadata,
        array $values,
        Collection $mappings,
    ): ?HelpCenterCustomer {
        $mapped = $values['customer'];

        $customer = $this->customers->match(
            (string) $request->tenant_id,
            $mapped['email'] ?? $request->customer_email,
            $mapped['name'] ?? $request->customer_name,
            $request->last_message_at,
            $mapped['external_id'] ?? null,
        );

        if ($customer === null) {
            return null;
        }

        $changes = new RecordChanges('customer', $customer->wasRecentlyCreated);

        $this->fill($customer, $mapped, ['name', 'phone', 'company', 'external_id'], $changes);

        // Not subject to the learn-don't-overwrite rule: this is when the ticket arrived, which
        // is a fact rather than a claim, and a stale one is worse than none.
        $customer->forceFill(['last_activity_at' => $request->last_message_at ?? now()])->save();

        $this->fillCustomFields($customer->id, HelpCenterCustomFieldValue::KIND_CUSTOMER,
            $space, $mapped, $mappings, $changes);

        $this->logAll($request, $changes);

        return $customer;
    }

    /**
     * Find or create the Company, then fill what it does not already know.
     *
     * The customer's own email DOMAIN is the fallback source of `domain` — the requirement's
     * worked example (§7): `john@acme.com` with a Company holding `acme.com` must link, whether
     * or not anybody authored an Email Domain mapping.
     *
     * @param  array<string, array<string, string>>  $values
     * @param  Collection<int, HelpCenterMetadataMapping>  $mappings
     */
    private function company(
        HelpCenterRequest $request,
        HelpCenterSpace $space,
        TicketMetadata $metadata,
        array $values,
        Collection $mappings,
        ?HelpCenterCustomer $customer,
    ): ?HelpCenterCompany {
        $mapped = $values['company'];
        $mapped['domain'] ??= $customer?->emailDomain() ?? $metadata->get('email_domain');

        $company = $this->companies->match((string) $request->tenant_id, $mapped);

        if ($company === null) {
            return null;
        }

        $changes = new RecordChanges('company', $company->wasRecentlyCreated);

        $this->fill($company, $mapped, ['name', 'domain', 'phone', 'external_id'], $changes);

        $company->forceFill(['last_activity_at' => $request->last_message_at ?? now()])->save();

        $this->fillCustomFields($company->id, HelpCenterCustomFieldValue::KIND_COMPANY,
            $space, $mapped, $mappings, $changes);

        $this->logAll($request, $changes);

        return $company;
    }

    /**
     * Write the mapped attributes a record does not already hold.
     *
     * `forceFill` and a single save, rather than a save per column: this runs inside ingest and
     * one extra round-trip per mapped field is a cost paid on every message.
     *
     * @param  array<string, string>  $mapped
     * @param  array<int, string>  $attributes
     */
    private function fill(HelpCenterCustomer|HelpCenterCompany $record, array $mapped, array $attributes, RecordChanges $changes): void
    {
        $fill = [];

        foreach ($attributes as $attribute) {
            $value = $mapped[$attribute] ?? null;

            if ($value === null || trim((string) $record->{$attribute}) !== '') {
                continue;
            }

            $fill[$attribute] = $value;
            $changes->add($attribute, null, $value);
        }

        if ($fill !== []) {
            $record->forceFill($fill)->save();
        }
    }

    /**
     * The `custom_field` destinations, resolved against the Space's own field lists.
     *
     * A mapping pointing at a field that has since been deleted is SKIPPED rather than fatal:
     * removing a custom field should not stop mail being ingested.
     *
     * @param  array<string, string>  $mapped
     * @param  Collection<int, HelpCenterMetadataMapping>  $mappings
     */
    private function fillCustomFields(
        int $ownerId,
        string $kind,
        HelpCenterSpace $space,
        array $mapped,
        Collection $mappings,
        RecordChanges $changes,
    ): void {
        $switch = $kind === HelpCenterCustomFieldValue::KIND_COMPANY
            ? 'company_custom_fields'
            : 'customer_custom_fields';

        if (! $space->featureEnabled($switch)) {
            return;
        }

        $existing = HelpCenterCustomFieldValue::mapFor($kind, $ownerId);

        foreach ($mappings as $mapping) {
            if ((string) $mapping->record_type !== $kind || ! $mapping->writesCustomField()) {
                continue;
            }

            $value = $mapped['custom:'.$mapping->custom_field_id] ?? null;
            $field = $value === null ? null : $mapping->destinationField();

            // Same learn-don't-overwrite rule as the attributes above, read off the answers
            // already stored rather than off the record's columns.
            if ($field === null || ! $field->is_active || trim((string) ($existing[$field->id] ?? '')) !== '') {
                continue;
            }

            HelpCenterCustomFieldValue::put((string) $space->tenant_id, $kind, (int) $field->id, $ownerId, $value);
            $changes->add($field->name, null, $value);
        }
    }

    private function logAll(HelpCenterRequest $request, RecordChanges $changes): void
    {
        foreach ($changes->all() as $change) {
            $this->log($request, $changes->recordType.' '.$change['label'], $change['old'], $change['new']);
        }
    }

    /**
     * One audit row (§15).
     *
     * `EVENT_CUSTOMER` for both sides, with `via: mapping` in the meta: the feed groups by event
     * and a reader wants "what changed about who wrote in", not two adjacent event types. The
     * meta is what lets the timeline say the change came from a mapping rather than from
     * somebody editing the panel.
     */
    private function log(HelpCenterRequest $request, string $field, ?string $old, ?string $new): void
    {
        $this->activity->record(
            $request,
            HelpCenterRequestActivity::EVENT_CUSTOMER,
            $field,
            $old,
            $new,
            ['via' => 'mapping'],
        );
    }
}
