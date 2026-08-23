<?php

namespace App\Services\HelpCenter\Metadata;

/**
 * What the mapping engine changed on one Customer or Company (P75 §15).
 *
 * Collected rather than written as it goes, because the audit entry the requirement asks for
 * names the RECORD and the mapping — "Customer company changed from None to Acme Corporation
 * using Company & Customer mapping" — and that sentence cannot be composed until the run is
 * over and it is known whether the record was created or merely updated.
 */
class RecordChanges
{
    /** @var array<int, array{label: string, old: ?string, new: ?string}> */
    private array $changes = [];

    public function __construct(
        public readonly string $recordType,
        public readonly bool $created,
    ) {}

    public function add(string $label, ?string $old, ?string $new): void
    {
        if ((string) $old === (string) $new) {
            return;
        }

        $this->changes[] = ['label' => $label, 'old' => $old, 'new' => $new];
    }

    public function any(): bool
    {
        return $this->changes !== [];
    }

    /** @return array<int, array{label: string, old: ?string, new: ?string}> */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * The sentences for the activity feed.
     *
     * "None" rather than an empty gap for a field that had no value: the requirement's own
     * example reads "changed from None to Acme Corporation", and a blank on one side of an
     * arrow reads as a rendering fault.
     *
     * @return array<int, string>
     */
    public function sentences(): array
    {
        $noun = ucfirst($this->recordType);

        return array_map(
            fn (array $c) => sprintf(
                '%s %s changed from %s to %s using Company & Customer mapping.',
                $noun,
                mb_strtolower($c['label']),
                trim((string) $c['old']) === '' ? 'None' : $c['old'],
                trim((string) $c['new']) === '' ? 'None' : $c['new'],
            ),
            $this->changes,
        );
    }
}
