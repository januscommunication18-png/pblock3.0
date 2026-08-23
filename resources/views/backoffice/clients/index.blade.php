@extends('backoffice.layout')
@section('title', 'Clients')

@section('content')
  <div class="px-5 sm:px-6 py-6">
    <h1 class="text-[18px] font-semibold text-head">Clients</h1>
    <p class="mt-1 text-[13px] text-sub">Every client on the platform, and the workspaces and applications they hold.</p>

    @if (session('status'))
      <p class="mt-4 text-[13px] text-ink bg-white border border-line rounded-lg px-4 py-3">{{ session('status') }}</p>
    @endif

    {{-- Search and filters (§4, §5) as a GET form.

         The whole state lives in the query string, so a filtered list survives a refresh, can be
         bookmarked and can be pasted to a colleague — which a client-side filter would throw
         away. Every control submits the form, so there is no Apply button to forget. --}}
    <form method="GET" class="mt-5 bg-white border border-line rounded-xl px-4 py-3">
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[240px]">
          <label for="q" class="block text-[12px] font-medium text-ink mb-1">Search</label>
          <input id="q" name="q" value="{{ $filters['q'] }}" class="pb-input !h-9 w-full"
                 placeholder="Client, contact, email, workspace or CL-000128" />
        </div>

        <div>
          <label for="status" class="block text-[12px] font-medium text-ink mb-1">Status</label>
          <select id="status" name="status" class="pb-input !h-9" onchange="this.form.submit()">
            <option value="">All</option>
            @foreach ($statuses as $s)
              <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ \App\Models\Client::statusLabel($s) }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label for="application" class="block text-[12px] font-medium text-ink mb-1">Application</label>
          <select id="application" name="application" class="pb-input !h-9" onchange="this.form.submit()">
            <option value="">All</option>
            @foreach ($applications as $a)
              <option value="{{ $a['key'] }}" @selected($filters['application'] === $a['key'])>{{ $a['label'] }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label for="created" class="block text-[12px] font-medium text-ink mb-1">Created</label>
          <select id="created" name="created" class="pb-input !h-9" onchange="this.form.submit()">
            <option value="">Any time</option>
            <option value="today" @selected($filters['created'] === 'today')>Today</option>
            <option value="7d" @selected($filters['created'] === '7d')>Last 7 days</option>
            <option value="30d" @selected($filters['created'] === '30d')>Last 30 days</option>
            <option value="custom" @selected($filters['created'] === 'custom')>Custom range</option>
          </select>
        </div>

        {{-- The two date inputs appear only for a custom range — two empty date fields beside
             "Last 7 days" are two controls that do nothing. --}}
        @if ($filters['created'] === 'custom')
          <div>
            <label for="from" class="block text-[12px] font-medium text-ink mb-1">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="pb-input !h-9" />
          </div>
          <div>
            <label for="to" class="block text-[12px] font-medium text-ink mb-1">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="pb-input !h-9" />
          </div>
        @endif

        <button type="submit" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Search</button>
        @if (array_filter($filters))
          <a href="{{ route('backoffice.clients.index') }}"
             class="h-9 px-3 inline-flex items-center rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Clear</a>
        @endif
      </div>
    </form>

    <div class="mt-5 bg-white border border-line rounded-xl">
      @if ($clients->total() === 0)
        <x-backoffice.empty-state
          title="No clients match those filters"
          message="Try a different search term, or clear the filters to see every client." />
      @else
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]" style="min-width:960px">
            <thead>
              <tr class="text-left text-[12px] text-faint border-b border-line">
                <th class="py-2.5 pl-4 font-medium w-8"></th>
                <th class="py-2.5 font-medium">Client</th>
                <th class="py-2.5 font-medium">Email</th>
                <th class="py-2.5 font-medium text-right">Tenants</th>
                <th class="py-2.5 font-medium">Applications</th>
                <th class="py-2.5 font-medium">Status</th>
                <th class="py-2.5 font-medium">Last Active</th>
                <th class="py-2.5 pr-4 font-medium text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($rows as $row)
                @php($client = $row['model'])
                {{-- ONE row per unique email, whatever the tenant count. The tenants live in the
                     expanded row below rather than as separate clients — which was the whole
                     point of the change. --}}
                <tr class="border-b border-line hover:bg-hover">
                  <td class="py-2.5 pl-4 align-top">
                    @if (count($row['tenants']))
                      {{-- A checkbox as the disclosure control: the sibling `<tr>` shows on
                           `:checked` through CSS alone, so an expandable table needs no
                           JavaScript on a page that otherwise needs none. --}}
                      <input type="checkbox" id="exp-{{ $client->id }}" class="peer sr-only" />
                      <label for="exp-{{ $client->id }}"
                             class="cursor-pointer select-none text-faint hover:text-ink text-[11px]"
                             title="Show tenants">&#9654;</label>
                    @endif
                  </td>
                  <td class="py-2.5 align-top">
                    <a href="{{ route('backoffice.clients.show', $client) }}" class="text-ink font-medium hover:text-brand">
                      {{ $row['name'] }}
                    </a>
                    <div class="text-[11px] text-faint font-mono">{{ $client->code }}</div>
                  </td>
                  <td class="py-2.5 align-top text-sub _moretogether-break">{{ $row['email'] ?? '—' }}</td>
                  <td class="py-2.5 align-top text-right tabular-nums text-ink">{{ count($row['tenants']) }}</td>
                  <td class="py-2.5 align-top text-sub">{{ $row['applications'] ?: '—' }}</td>
                  <td class="py-2.5 align-top"><x-backoffice.status-badge :status="$client->status" /></td>
                  <td class="py-2.5 align-top text-sub whitespace-nowrap">{{ $row['last_active'] ?? '—' }}</td>
                  <td class="py-2.5 pr-4 align-top text-right">
                    <a href="{{ route('backoffice.clients.show', $client) }}"
                       class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">
                      View Client
                    </a>
                  </td>
                </tr>

                @if (count($row['tenants']))
                  <tr class="hidden peer-checked:table-row border-b border-line bg-hover/40">
                    <td></td>
                    <td colspan="7" class="py-3 pr-4">
                      <div class="text-[12px] font-semibold text-faint uppercase tracking-wide">
                        Tenants &middot; {{ count($row['tenants']) }}
                      </div>
                      <table class="mt-2 text-[13px]">
                        <tbody>
                          @foreach ($row['tenants'] as $t)
                            <tr>
                              <td class="py-1 pr-6 text-ink">
                                <a href="{{ route('backoffice.clients.tenant', [$client, $t['id']]) }}"
                                   class="hover:text-brand">{{ $t['name'] }}</a>
                              </td>
                              <td class="py-1 pr-6 text-sub">{{ $t['role'] }}</td>
                              <td class="py-1"><x-backoffice.status-badge :status="$t['status']" /></td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    </td>
                  </tr>
                @endif
              @endforeach
            </tbody>
          </table>
        </div>

        <div class="px-4 py-3 border-t border-line">
          {{ $clients->links() }}
        </div>
      @endif
    </div>
  </div>
@endsection
