@extends('backoffice.layout')
@section('title', $workspace->name)

@section('content')
  <div class="px-5 sm:px-6 py-6 max-w-[900px]">
    <a href="{{ route('backoffice.clients.show', [$client, 'tab' => 'tenants']) }}"
       class="text-[12px] text-sub hover:text-ink hover:underline">&larr; {{ $client->displayName() }}</a>

    <div class="mt-3 bg-white border border-line rounded-xl px-5 py-5">
      <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
          <h1 class="text-[18px] font-semibold text-head truncate">{{ $workspace->name }}</h1>
          <p class="mt-1 text-[13px] text-sub">
            {{ $client->displayName() }} &middot; {{ ucfirst((string) $membership->role) }}
          </p>
          <p class="mt-0.5 text-[11px] text-faint font-mono _moretogether-break">{{ $workspace->id }}</p>
        </div>
        <x-backoffice.status-badge :status="$membership->status" />
      </div>

      <dl class="mt-4 pt-4 border-t border-line grid gap-x-8 gap-y-3 sm:grid-cols-3">
        <div>
          <dt class="text-[12px] text-faint">Members</dt>
          <dd class="text-[13px] text-ink mt-0.5 tabular-nums">{{ $members }}</dd>
        </div>
        <div>
          <dt class="text-[12px] text-faint">Help Desk Spaces</dt>
          <dd class="text-[13px] text-ink mt-0.5 tabular-nums">{{ $spaces }}</dd>
        </div>
        <div>
          <dt class="text-[12px] text-faint">Joined</dt>
          <dd class="text-[13px] text-ink mt-0.5">{{ $membership->joined_at?->format('M j, Y') ?? '—' }}</dd>
        </div>
      </dl>
    </div>

    @if (session('status'))
      <p class="mt-4 text-[13px] text-ink bg-white border border-line rounded-lg px-4 py-3">{{ session('status') }}</p>
    @endif
    @foreach ($errors->all() as $error)
      <p class="mt-4 text-[13px] text-danger bg-white border border-danger/30 rounded-lg px-4 py-3">{{ $error }}</p>
    @endforeach

    {{-- Tenant-scoped actions (§ "Client Actions").

         On their own page, under a heading that says so. The requirement's concern is an
         administrator who means to change one workspace reaching the switch that closes all of
         them — so the two sets of actions never appear side by side, and this one names the
         tenant it affects in every label. --}}
    <div class="mt-5 bg-white border border-line rounded-xl px-5 py-5">
      <div class="text-[11px] font-semibold text-faint uppercase tracking-wide">
        Actions for this tenant only
      </div>
      <p class="mt-1 text-[12px] text-sub">
        These change {{ $client->displayName() }}&rsquo;s access to <span class="font-medium text-ink">{{ $workspace->name }}</span>.
        Their other tenants are untouched.
      </p>

      <div class="mt-4 space-y-4">
        <div class="flex flex-wrap items-center gap-3">
          <form method="POST" action="{{ route('backoffice.clients.tenant.role', [$client, $workspace->id]) }}"
                class="flex items-center gap-2">
            @csrf
            <label for="role" class="text-[13px] text-ink">Role</label>
            <select id="role" name="role" class="pb-input !h-9">
              @foreach ($roles as $value => $label)
                <option value="{{ $value }}" @selected($membership->role === $value)>{{ $label }}</option>
              @endforeach
            </select>
            <button type="submit"
                    class="h-9 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">
              Change Role
            </button>
          </form>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-line">
          <form method="POST" action="{{ route('backoffice.clients.tenant.access', [$client, $workspace->id]) }}">
            @csrf
            <input type="hidden" name="enable" value="{{ $membership->status === \App\Models\WorkspaceMembership::STATUS_DISABLED ? 1 : 0 }}" />
            <button type="submit"
                    class="h-9 px-3 rounded-md border border-warning/40 bg-warning/5 text-[13px] font-semibold text-warning hover:bg-warning/10">
              {{ $membership->status === \App\Models\WorkspaceMembership::STATUS_DISABLED ? 'Enable Tenant Access' : 'Disable Tenant Access' }}
            </button>
          </form>

          <form method="POST" action="{{ route('backoffice.clients.tenant.remove', [$client, $workspace->id]) }}"
                onsubmit="return confirm('Remove {{ $client->displayName() }} from {{ $workspace->name }}?\n\nTheir membership is deleted. Their other tenants are untouched.')">
            @csrf
            <button type="submit"
                    class="h-9 px-3 rounded-md border border-danger/40 bg-danger/5 text-[13px] font-semibold text-danger hover:bg-danger/10">
              Remove From Tenant
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
@endsection
