{{-- Users — the people this client shares tenants with. A tab on somebody's own record that
     listed only them would be a one-row table. --}}
<div class="bg-white border border-line rounded-xl">
  @if (empty($data['users']))
    <x-backoffice.empty-state title="No other users"
      message="Nobody else belongs to this client's tenants yet." />
  @else
    <div class="overflow-x-auto">
      <table class="w-full text-[13px]" style="min-width:820px">
        <thead><tr class="text-left text-[12px] text-faint border-b border-line">
          <th class="py-2.5 pl-4 font-medium">Name</th>
          <th class="py-2.5 font-medium">Email</th>
          <th class="py-2.5 font-medium">Tenant</th>
          <th class="py-2.5 font-medium">Role</th>
          <th class="py-2.5 font-medium">Status</th>
          <th class="py-2.5 pr-4 font-medium">Joined</th>
        </tr></thead>
        <tbody>
          @foreach ($data['users'] as $u)
            <tr class="border-b border-line last:border-0">
              <td class="py-2.5 pl-4 text-ink font-medium">{{ $u['name'] }}</td>
              <td class="py-2.5 text-sub _moretogether-break">{{ $u['email'] ?? '—' }}</td>
              <td class="py-2.5 text-sub">{{ $u['workspace'] ?? '—' }}</td>
              <td class="py-2.5 text-sub">{{ $u['role'] }}</td>
              <td class="py-2.5"><x-backoffice.status-badge :status="$u['status']" /></td>
              <td class="py-2.5 pr-4 text-sub whitespace-nowrap">{{ $u['last_active'] ?? '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
