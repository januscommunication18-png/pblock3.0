{{-- Overview — the PERSON (BC-D7). Name and email come from the live user record, so a changed
     address shows here immediately rather than from a copy that could go stale. --}}
<div class="bg-white border border-line rounded-xl">
  <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Client Information</div>
  <dl class="px-4 py-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
    @php
      $rows = [
        ['Client ID', $client->code],
        ['Name', $client->displayName()],
        ['Email', $client->email()],
        ['Phone', $client->phone],
        ['Country', $client->country],
        ['Timezone', $client->timezone ?: $client->user?->timezone],
        ['Tenants', $client->membershipRows->count()],
        ['Client since', $client->created_at?->format('M j, Y')],
        ['Last Active', $client->lastActiveAt()?->format('M j, Y g:i A')],
      ];
    @endphp
    @foreach ($rows as [$label, $value])
      <div>
        <dt class="text-[12px] text-faint">{{ $label }}</dt>
        <dd class="text-[13px] text-ink mt-0.5 _moretogether-break">{{ ($value === null || $value === '') ? '—' : $value }}</dd>
      </div>
    @endforeach
    <div>
      <dt class="text-[12px] text-faint">Status</dt>
      <dd class="mt-1"><x-backoffice.status-badge :status="$client->status" /></dd>
    </div>
  </dl>
</div>
