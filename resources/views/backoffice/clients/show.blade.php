@extends('backoffice.layout')
@section('title', $client->name)

@section('content')
  <div class="px-5 sm:px-6 py-6">
    <a href="{{ route('backoffice.clients.index') }}" class="text-[12px] text-sub hover:text-ink hover:underline">&larr; All clients</a>

    {{-- Header (§6, §20). The three actions are on the right and are NOT styled alike:
         Reset Password is ordinary, Disable is bordered-warning, Delete is danger. §6 asks for
         exactly that — "do not make destructive actions visually equivalent to standard
         actions". --}}
    <div class="mt-3 bg-white border border-line rounded-xl px-5 py-5">
      <div class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-3">
            <h1 class="text-[18px] font-semibold text-head truncate">{{ $client->displayName() }}</h1>
            <x-backoffice.status-badge :status="$client->status" />
          </div>

          <p class="mt-1.5 text-[13px] text-sub _moretogether-break">
            {{ $client->email() ?? 'No email on file' }}
          </p>
          <p class="mt-0.5 text-[13px] text-sub">
            {{ $client->membershipRows->count() }} {{ Str::plural('tenant', $client->membershipRows->count()) }}
            &middot; Client since {{ $client->created_at?->format('M j, Y') }}
          </p>

          @if ($client->isPendingDeletion())
            <p class="mt-3 text-[12px] text-danger bg-danger/5 border border-danger/25 rounded-lg px-3 py-2">
              Pending deletion. Data is retained until {{ $client->purgeableAt()?->format('M j, Y') }} and can be restored until then.
            </p>
          @endif
        </div>

        <div class="flex flex-col gap-2 shrink-0 w-[220px]">
          {{-- The requirement asks that an action "clearly indicate whether they affect the
               global user or an individual tenant" — so the group is labelled, and the note
               underneath points at where the narrower actions live. --}}
          <div class="text-[11px] font-semibold text-faint uppercase tracking-wide">Global account actions</div>
          <form method="POST" action="{{ route('backoffice.clients.reset-password', $client) }}"
                onsubmit="return confirm('Send a password reset link to {{ $client->email() }}?\n\nThis affects their global account.')">
            @csrf
            <button type="submit" @disabled(! $client->user)
                    class="w-full h-9 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50 disabled:cursor-not-allowed">
              Reset Password
            </button>
          </form>

          @if ($client->isDisabled() || $client->isPendingDeletion())
            <form method="POST" action="{{ route($client->isPendingDeletion() ? 'backoffice.clients.restore' : 'backoffice.clients.enable', $client) }}">
              @csrf
              <button type="submit"
                      class="w-full h-9 rounded-md border border-success/40 bg-success/5 text-[13px] font-semibold text-success hover:bg-success/10">
                {{ $client->isPendingDeletion() ? 'Restore Client' : 'Enable Client' }}
              </button>
            </form>
          @else
            <form method="POST" action="{{ route('backoffice.clients.disable', $client) }}"
                  onsubmit="return confirm('Disable {{ $client->displayName() }}?\n\nThis blocks them from signing in and from EVERY tenant they belong to ({{ $client->membershipRows->count() }}). Their data is retained. To close one tenant only, use the Tenants tab instead.')">
              @csrf
              <button type="submit"
                      class="w-full h-9 rounded-md border border-warning/40 bg-warning/5 text-[13px] font-semibold text-warning hover:bg-warning/10">
                Disable Client
              </button>
            </form>
          @endif

          @unless ($client->isPendingDeletion())
            <button type="button" onclick="document.getElementById('delete-client').showModal()"
                    class="w-full h-9 rounded-md border border-danger/40 bg-danger/5 text-[13px] font-semibold text-danger hover:bg-danger/10">
              Delete Client
            </button>
          @endunless

          <p class="text-[11px] text-faint leading-relaxed mt-1">
            These affect the whole account across every tenant. To change one tenant only, open it
            from the <a href="{{ route('backoffice.clients.show', [$client, 'tab' => 'tenants']) }}"
                        class="text-brand hover:underline">Tenants</a> tab.
          </p>
        </div>
      </div>
    </div>

    @if (session('status'))
      <p class="mt-4 text-[13px] text-ink bg-white border border-line rounded-lg px-4 py-3">{{ session('status') }}</p>
    @endif
    @foreach ($errors->all() as $error)
      <p class="mt-4 text-[13px] text-danger bg-white border border-danger/30 rounded-lg px-4 py-3">{{ $error }}</p>
    @endforeach

    {{-- Tabs (§7). Links, not JavaScript: each one is a URL, so a tab survives a refresh and can
         be sent to somebody. It is also what lets the controller load ONE tab's data. --}}
    <div class="mt-5 border-b border-line overflow-x-auto">
      <div class="flex items-center gap-1 w-max">
        @foreach ($tabs as $key => $label)
          @php($on = $tab === $key)
          <a href="{{ route('backoffice.clients.show', [$client, 'tab' => $key]) }}"
             @class(['h-9 px-3 inline-flex items-center text-[13px] font-medium border-b-2 whitespace-nowrap', 'border-brand text-brand' => $on, 'border-transparent text-sub hover:text-ink' => ! $on])>
            {{ $label }}
          </a>
        @endforeach
      </div>
    </div>

    <div class="mt-5">
      @include('backoffice.clients.tabs.'.$tab)
    </div>
  </div>

  {{-- Delete confirmation (§18). A native <dialog>: modal semantics, focus trapping and Escape
       for free, with no component library on a page that needs no other JavaScript.

       The typed name is checked on the SERVER as well — a confirmation that lives only in the
       browser is one an unlucky script can skip. --}}
  <dialog id="delete-client" class="rounded-xl border border-line p-0 w-[460px] max-w-[92vw] backdrop:bg-black/40">
    <form method="POST" action="{{ route('backoffice.clients.delete', $client) }}" class="p-5">
      @csrf
      <h2 class="text-[15px] font-semibold text-head">Delete {{ $client->displayName() }}?</h2>
      <p class="mt-2 text-[13px] text-sub leading-relaxed">
        This will remove access to its workspaces, users, projects, help desk spaces, files and
        other application data.
      </p>
      <p class="mt-2 text-[13px] text-sub leading-relaxed">
        Nothing is erased immediately — the client moves to <span class="font-semibold text-ink">Pending Deletion</span>
        and its data is kept for {{ \App\Models\Client::RETENTION_DAYS }} days, during which a Super Admin can restore it.
      </p>

      <label for="confirm_name" class="block text-[12px] font-medium text-ink mt-4 mb-1.5">
        Type <span class="font-semibold">{{ $client->displayName() }}</span> to confirm
      </label>
      <input id="confirm_name" name="confirm_name" required autocomplete="off" class="pb-input !h-9 w-full" />

      <div class="flex items-center justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('delete-client').close()"
                class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>
        <button type="submit" class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold hover:opacity-90">Delete Client</button>
      </div>
    </form>
  </dialog>
@endsection
