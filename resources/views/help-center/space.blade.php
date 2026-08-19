@extends('help-center.layout')

@section('title', $space->name.' — '.$panelLabel)

@section('content')
  {{-- One Space, one section (P4).

       Every panel below reads through `$space`, never through the models' own tables — so a
       Space's screen structurally cannot show another Space's Inbox, workflow or members
       (P3 §15). The relations are eager-loaded once by the controller. --}}
  {{-- Toolbar — the same h-12 bordered bar the Spaces listing and the Projects index use, so
       every screen in the module carries one heading style: 14px medium, leading icon,
       right-aligned actions. It is flush to the edges, which is why the page's own padding
       starts below it rather than wrapping it. --}}
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">
    @include('partials.sidebar-expand')
    <span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">
      {!! pb_icon('rectangles-pair', 16, 'text-sub shrink-0') !!}
      <span class="truncate">{{ $space->name }}</span>
    </span>
    @if ($space->archived_at)
      <span class="_moretogether-badge _moretogether-badge--off shrink-0">Archived</span>
    @endif
    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
      @if ($spaceEdit['can'])
        {{-- Sibling of the dialog's Vue root, bound by id — the same pattern the sidebar's
             create actions use, so the header does not have to know the dialog exists. --}}
        <button type="button" id="help-center-edit-space"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
          {!! pb_icon('pen', 13) !!} Edit Space
        </button>
      @endif
      <a href="{{ route('help-center.spaces.index') }}"
         class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
        All Spaces
      </a>
    </div>

    {{-- The dialog's root. Teleports to <body>, so it only has to exist. --}}
    @if ($spaceEdit['can'])
      <div id="help-center-space-edit" data-bootstrap="{{ json_encode($spaceEdit) }}"></div>
      @push('scripts')
        <script defer src="{{ pb_asset('assets/js/help-center/space-edit.js') }}"></script>
      @endpush
    @endif
  </div>

  {{-- The same six sections as the sidebar, as tabs — so a Space can be moved through without
       going back to the nav. Both are built from `$sections`, which is why "active" means the
       same in both. Its own bar under the toolbar, matching the toolbar's flush edges. --}}
  <div class="flex flex-wrap items-center gap-1 px-5 sm:px-8 py-2 border-b border-line">
    @foreach ($sections as $s)
      <a href="{{ $s['url'] }}"
         @class(['inline-flex items-center gap-1.5 h-7 px-3 rounded-md text-[12px] border', 'border-stroke bg-sel text-brand font-semibold' => $s['active'], 'border-transparent text-ink hover:bg-hover' => ! $s['active']])>
        {!! pb_icon($s['icon'], 13) !!}
        {{ $s['label'] }}
      </a>
    @endforeach
  </div>

  <div class="px-5 sm:px-8 py-6">

    {{-- The Space's own subtitle line: types and description, under the tabs rather than in
         the 48px toolbar, which has no room for them. --}}
    @if ($space->typeList() || $space->description)
      <div class="mb-5">
        @if ($space->typeList())
          <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($space->typeList() as $type)
              <span class="_moretogether-tag _moretogether-tag--static">{{ $type }}</span>
            @endforeach
          </div>
        @endif
        @if ($space->description)
          <p class="mt-1.5 text-[13px] text-sub max-w-[640px]">{{ $space->description }}</p>
        @endif
      </div>
    @endif

    {{-- ============ Overview ============ --}}
    @if ($panel === 'overview')
      @php($inbox = $space->inboxes->first())
      <dl class="mt-6 max-w-[720px] rounded-lg border border-line divide-y divide-line">
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Space Lead</dt>
          <dd class="text-[13px] text-ink">{{ $space->lead?->displayName() ?? '—' }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Space Type</dt>
          <dd class="text-[13px] text-ink">{{ $space->typeLabel() }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Department Groups</dt>
          <dd class="text-[13px] text-ink">{{ $space->groupList() ? implode(', ', $space->groupList()) : '—' }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Support members</dt>
          <dd class="text-[13px] text-ink">{{ $space->members->count() }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Inbox</dt>
          <dd class="text-[13px] text-ink _moretogether-break">{{ $inbox?->name ?? '—' }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Inbound address</dt>
          <dd class="text-[13px] text-ink _moretogether-break">{{ $inbox?->inboundAddress() ?? '—' }}</dd></div>
        <div class="flex gap-4 px-4 py-3"><dt class="w-44 shrink-0 text-[12px] text-sub">Workflow</dt>
          <dd class="text-[13px] text-ink">{{ $space->statuses->pluck('name')->implode(' → ') ?: '—' }}</dd></div>
      </dl>

      {{-- The gap that actually stops the inbound test working, called out where it is noticed
           rather than left as a silent zero. --}}
      @if ($inbox && $inbox->emailAddresses->isEmpty())
        <div class="mt-6 max-w-[720px] _moretogether-notice px-4 py-3">
          <div class="text-[13px] font-semibold text-ink">No customer-facing email address</div>
          <p class="mt-1 text-[12px] text-sub">
            This Inbox has no address for customers to write to, so there is nothing to forward
            from and the inbound test cannot run. Add the address your forwarding rule sits on.
          </p>
          <a href="{{ route('help-center.inboxes') }}"
             class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">
            Add email address
          </a>
        </div>
      @endif

      {{-- Inbound email testing (P7). Only where there is an Inbox to test through. --}}
      @if ($inbox)
        <div id="help-center-inbound-test" data-bootstrap="{{ json_encode($inboundTest) }}">
          <div class="mt-6 max-w-[720px] rounded-lg border border-line px-4 py-6 text-[13px] text-sub">Loading…</div>
        </div>
        @push('scripts')
          <script defer src="{{ pb_asset('assets/js/help-center/inbound-test.js') }}"></script>
        @endpush
      @endif

    {{-- ============ Conversations ============ --}}
    @elseif ($panel === 'conversations')
      {{-- §16's six views live HERE, as filters. Inert while there is nothing to filter, and
           marked so — a control that looks live and does nothing is worse than one that says
           it is waiting. --}}
      <div class="mt-5 flex flex-wrap items-center gap-1" aria-disabled="true">
        <span class="inline-flex items-center h-7 px-3 rounded-md text-[12px] border border-stroke bg-sel text-brand font-semibold">All</span>
        @foreach ($conversationViews as $v)
          <span class="inline-flex items-center h-7 px-3 rounded-md text-[12px] border border-line text-faint">{{ $v['label'] }}</span>
        @endforeach
      </div>
      <div class="mt-6 rounded-lg border border-line px-6 py-14 text-center max-w-[720px]">
        <div class="mx-auto h-10 w-10 rounded-lg bg-hover grid place-items-center text-sub">{!! pb_icon('inbox', 18) !!}</div>
        <h2 class="mt-3 text-[14px] font-semibold text-head">No conversations yet</h2>
        <p class="mt-1 text-[13px] text-sub max-w-[420px] mx-auto">Once forwarding is live, customer email arriving at this Space's addresses will appear here.</p>
      </div>

    {{-- ============ Inbox ============ --}}
    @elseif ($panel === 'inbox')
      @forelse ($space->inboxes as $inbox)
        <div class="mt-6 max-w-[720px] rounded-lg border border-line">
          <div class="px-4 py-3 border-b border-line text-[14px] font-semibold text-head">{{ $inbox->name }}</div>
          <div class="px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-[12px] text-sub shrink-0">Inbound address</span>
              <code class="_moretogether-break flex-1 min-w-[220px] rounded-md border border-line bg-[#f9fafb] px-2 py-1 text-[12px] text-ink">{{ $inbox->inboundAddress() }}</code>
            </div>
            @if ($inbox->emailAddresses->isNotEmpty())
              <table class="mt-3 w-full text-[13px]">
                <thead><tr class="text-left text-[12px] text-faint border-b border-line">
                  <th class="py-2 font-medium">Name</th><th class="py-2 font-medium">Email Address</th><th class="py-2 font-medium">Status</th>
                </tr></thead>
                <tbody>
                  @foreach ($inbox->emailAddresses as $a)
                    @php($meta = $a->statusMeta())
                    <tr class="border-b border-line last:border-0">
                      <td class="py-2 text-ink">{{ $a->name ?: '—' }}</td>
                      <td class="py-2 text-ink _moretogether-break">{{ $a->email }}</td>
                      <td class="py-2"><span class="_moretogether-badge _moretogether-badge--{{ $meta['tone'] }}">{{ $meta['label'] }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            @else
              <p class="mt-3 text-[12px] text-faint">No customer-facing addresses connected yet.</p>
            @endif
          </div>
        </div>
      @empty
        <p class="mt-6 text-[13px] text-sub">This Space has no Inbox.</p>
      @endforelse
      <p class="mt-3 text-[12px] text-sub">
        Addresses are added on the
        <a href="{{ route('help-center.inboxes') }}" class="text-brand font-semibold hover:underline">Inboxes screen</a>.
      </p>

    {{-- ============ Workflow ============ --}}
    @elseif ($panel === 'workflow')
      <div class="mt-6 flex flex-wrap items-center gap-2">
        @foreach ($space->statuses as $i => $status)
          <span class="inline-flex items-center h-6 px-2 rounded-full text-[11px] font-semibold text-white" style="background: {{ $status->color }}">{{ $status->name }}</span>
          @if (! $loop->last)<span class="text-faint">&rarr;</span>@endif
        @endforeach
      </div>
      <table class="mt-4 w-full max-w-[720px] text-[13px]">
        <thead><tr class="text-left text-[12px] text-faint border-b border-line">
          <th class="py-2 font-medium">Status</th><th class="py-2 font-medium">Responsibility</th>
          <th class="py-2 font-medium">State</th><th class="py-2 font-medium">Default assignees</th>
        </tr></thead>
        <tbody>
          @forelse ($space->statuses as $status)
            <tr class="border-b border-line last:border-0">
              <td class="py-2 text-ink">
                {{ $status->name }}
                @if ($status->isSystem()) <span class="text-faint">{!! pb_icon('lock', 11) !!}</span> @endif
              </td>
              <td class="py-2 text-sub">{{ $status->responsibility === 'creator' ? 'Creator' : 'Assignee' }}</td>
              <td class="py-2 text-sub">{{ $status->is_active ? 'Active' : 'Inactive' }}</td>
              <td class="py-2 text-sub">
                @php($names = \App\Models\User::whereIn('id', $status->default_assignees ?: [])->pluck('full_name'))
                {{ $names->isNotEmpty() ? $names->implode(', ') : '—' }}
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="py-3 text-[12px] text-faint">No workflow configured for this Space.</td></tr>
          @endforelse
        </tbody>
      </table>

    {{-- ============ Members ============ --}}
    @elseif ($panel === 'members')
      <table class="mt-6 w-full max-w-[720px] text-[13px]">
        <thead><tr class="text-left text-[12px] text-faint border-b border-line">
          <th class="py-2 font-medium">Coworker</th><th class="py-2 font-medium">Department Groups</th><th class="py-2 font-medium">Status</th>
        </tr></thead>
        <tbody>
          @forelse ($space->members as $member)
            <tr class="border-b border-line last:border-0">
              <td class="py-2 text-ink _moretogether-break">{{ $member->user?->displayName() ?? $member->email }}</td>
              <td class="py-2">
                @forelse ($member->groupList() as $g)
                  <span class="_moretogether-tag _moretogether-tag--static mr-1">{{ $g }}</span>
                @empty
                  <span class="text-faint">—</span>
                @endforelse
              </td>
              <td class="py-2">
                @if ($member->isPending())
                  <span class="_moretogether-badge _moretogether-badge--wait">Invited</span>
                @else
                  <span class="_moretogether-badge _moretogether-badge--ok">Active</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="3" class="py-3 text-[12px] text-faint">Nobody has been added to this Space yet.</td></tr>
          @endforelse
        </tbody>
      </table>

    {{-- ============ Settings ============ --}}
    @else
      @php($cfg = $space->settings)
      @if ($cfg)
        <h2 class="mt-6 text-[14px] font-semibold text-head">Metadata</h2>
        <dl class="mt-2 max-w-[720px] rounded-lg border border-line divide-y divide-line">
          @foreach (config('help-center.metadata') as $key => $meta)
            <div class="flex gap-4 px-4 py-2.5">
              <dt class="w-44 shrink-0 text-[12px] text-sub">{{ $meta['label'] }}</dt>
              <dd class="text-[13px] text-ink">
                {{ ! ($meta['available'] ?? false) ? 'Coming Soon' : ((($cfg->metadata[$key] ?? false)) ? 'On' : 'Off') }}
              </dd>
            </div>
          @endforeach
        </dl>

        <h2 class="mt-6 text-[14px] font-semibold text-head">Automation</h2>
        @php($t = $cfg->threshold())
        <dl class="mt-2 max-w-[720px] rounded-lg border border-line divide-y divide-line">
          <div class="flex gap-4 px-4 py-2.5"><dt class="w-44 shrink-0 text-[12px] text-sub">Auto BCC</dt>
            <dd class="text-[13px] text-ink _moretogether-break">{{ $cfg->auto_bcc_enabled ? $cfg->auto_bcc_email : 'Off' }}</dd></div>
          <div class="flex gap-4 px-4 py-2.5"><dt class="w-44 shrink-0 text-[12px] text-sub">Reassignment</dt>
            <dd class="text-[13px] text-ink">{{ $cfg->reassign_enabled ? $t['hours'].'h '.$t['minutes'].'m' : 'Off' }}</dd></div>
          @if ($cfg->reassign_enabled)
            <div class="flex gap-4 px-4 py-2.5"><dt class="w-44 shrink-0 text-[12px] text-sub">Then</dt>
              <dd class="text-[13px] text-ink">{{ config('help-center.reassign_destinations')[$cfg->reassign_destination] ?? $cfg->reassign_destination }}</dd></div>
          @endif
          <div class="flex gap-4 px-4 py-2.5"><dt class="w-44 shrink-0 text-[12px] text-sub">Auto-follow on mention</dt>
            <dd class="text-[13px] text-ink">{{ $cfg->auto_follow_mentions ? 'On' : 'Off' }}</dd></div>
        </dl>
        {{-- Honest about what is stored versus what runs (HC-D17). --}}
        <p class="mt-3 text-[12px] text-sub max-w-[720px]">Reassignment and auto-follow are saved with this Space and take effect once conversations arrive.</p>
      @else
        <p class="mt-6 text-[13px] text-sub">This Space has no settings recorded. Spaces created before the settings step have none.</p>
      @endif
    @endif

  </div>
@endsection
