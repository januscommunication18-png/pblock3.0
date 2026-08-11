@extends('layouts.auth')
@section('title', "You've been invited — Project Block")

{{--
  Invitation landing page (invite spec §19–§21). One page, two audiences: a guest gets
  "Accept invitation", which sends a 6-digit code to the invited address and continues into
  the normal signup (D-I2); a signed-in invited user accepts in place, with no signup at all.
--}}
@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <span class="flex items-center gap-2">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </span>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[420px] pt-6 sm:pt-10">
      <h1 class="text-[24px] font-bold text-head leading-tight">You've been invited to join a workspace</h1>
      <p class="text-[15px] text-sub mb-6">
        {{ $inviterName }} has invited you to join <span class="font-semibold text-ink">{{ $workspace->name }}</span> on Project Block.
      </p>

      @include('invitations.partials.error', ['error' => session('invitation_error')])

      @include('invitations.partials.summary')

      @if ($signedIn)
        <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}" class="mt-6">
          @csrf
          <button type="submit" class="w-full h-11 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
            Accept invitation
          </button>
        </form>
      @else
        <form method="POST" action="{{ route('invitations.start', ['token' => $token]) }}" class="mt-6">
          @csrf
          <button type="submit" class="w-full h-11 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
            Accept invitation
          </button>
        </form>
        <p class="mt-3 text-center text-[13px] text-sub">
          We'll email a 6-digit code to <span class="font-semibold text-ink">{{ $invitation->email }}</span> to confirm it's you.
        </p>
      @endif

      <p class="mt-6 text-center text-[12px] text-faint">
        You're joining an existing workspace — there's nothing to set up or pay for.
      </p>
    </div>
  </main>
@endsection
