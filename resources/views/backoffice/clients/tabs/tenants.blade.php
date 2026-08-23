{{-- Tenants (§ "Tenants Tab") — every tenant this PERSON belongs to, with the role that
     membership gives them. Three tenants are three rows here, never three clients. --}}
<div class="bg-white border border-line rounded-xl">
  @if (empty($data['tenants']))
    <x-backoffice.empty-state title="No tenants"
      message="This client does not belong to any tenant yet." />
  @else
    <div class="overflow-x-auto">
      <table class="w-full text-[13px]" style="min-width:900px">
        <thead><tr class="text-left text-[12px] text-faint border-b border-line">
          <th class="py-2.5 pl-4 font-medium">Tenant</th>
          <th class="py-2.5 font-medium">Role</th>
          <th class="py-2.5 font-medium">Applications</th>
          <th class="py-2.5 font-medium text-right">Spaces</th>
          <th class="py-2.5 font-medium">Status</th>
          <th class="py-2.5 font-medium">Created</th>
          <th class="py-2.5 pr-4 font-medium text-right">Action</th>
        </tr></thead>
        <tbody>
          @foreach ($data['tenants'] as $t)
            <tr class="border-b border-line last:border-0 hover:bg-hover">
              <td class="py-2.5 pl-4">
                <a href="{{ route('backoffice.clients.tenant', [$client, $t['id']]) }}" class="text-ink font-medium hover:text-brand">
                  {{ $t['name'] }}
                </a>
                <div class="text-[11px] text-faint font-mono">{{ Str::limit($t['id'], 18) }}</div>
              </td>
              <td class="py-2.5 text-sub">{{ $t['role'] }}</td>
              <td class="py-2.5 text-sub">{{ $t['applications'] }}</td>
              <td class="py-2.5 text-right tabular-nums text-sub">{{ $t['spaces'] }}</td>
              <td class="py-2.5"><x-backoffice.status-badge :status="$t['status']" /></td>
              <td class="py-2.5 text-sub whitespace-nowrap">{{ $t['created'] ?? '—' }}</td>
              <td class="py-2.5 pr-4 text-right">
                <a href="{{ route('backoffice.clients.tenant', [$client, $t['id']]) }}"
                   class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">
                  Manage
                </a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
