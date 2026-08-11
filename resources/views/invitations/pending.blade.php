@extends('layouts.auth')
@section('title', 'Join workspace — Project Block')

{{--
  The join step (invite spec §28/§33). Onboarding sends an invited user here rather than to
  Create Workspace: they are joining someone else's workspace, so there is nothing to name,
  configure or subscribe to (§29).
--}}
@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <span class="flex items-center gap-2">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </span>
    <form method="POST" action="{{ route('logout') }}">
      @csrf
      <button type="submit" class="text-[13px] text-link font-semibold hover:underline">Sign out</button>
    </form>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[420px] pt-6 sm:pt-10">
      <h1 class="text-[24px] font-bold text-head leading-tight">One last step</h1>
      <p class="text-[15px] text-sub mb-6">
        {{ $inviterName }} invited you to <span class="font-semibold text-ink">{{ $workspace->name }}</span>. Join to finish setting up your account.
      </p>

      @include('invitations.partials.error', ['error' => $error])

      @include('invitations.partials.summary')

      <form method="POST" action="{{ route('invitations.join') }}" class="mt-6">
        @csrf
        <button type="submit" class="w-full h-11 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
          Join {{ $workspace->name }}
        </button>
      </form>
    </div>
  </main>
@endsection
