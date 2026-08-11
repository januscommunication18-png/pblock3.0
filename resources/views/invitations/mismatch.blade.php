@extends('layouts.auth')
@section('title', 'Wrong account — Project Block')

{{--
  Signed in as somebody other than the invited person (invite spec §54). The invitation is
  bound to one address and can never be accepted by another account, so the only ways forward
  are signing out or leaving.
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
    <div class="w-full max-w-[420px] pt-8 sm:pt-14">
      <h1 class="text-[22px] font-bold text-head leading-tight">You're signed in as a different account</h1>
      <p class="mt-2 text-[15px] text-sub">
        This invitation was sent to <span class="font-semibold text-ink">{{ $invitedEmail }}</span>.
        Please sign in with the account associated with this email address to accept the invitation.
      </p>

      <div class="mt-5 rounded-xl border border-line bg-[#f8f9fa] p-4">
        <div class="text-[13px] text-sub">Currently signed in as</div>
        <div class="text-[14px] font-semibold text-ink truncate">{{ $currentEmail }}</div>
      </div>

      <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="w-full h-11 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
          Sign out and switch account
        </button>
      </form>

      <a href="{{ route('welcome') }}" class="mt-3 block text-center text-[13px] text-link font-semibold hover:underline">Cancel</a>
    </div>
  </main>
@endsection
