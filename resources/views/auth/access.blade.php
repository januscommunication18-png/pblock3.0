@extends('layouts.auth')
@section('title', 'Secure page — Project Block')

@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <span class="flex items-center gap-2">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </span>
    {{-- Naming the environment is the honest answer to "why am I being asked for a code?" —
         and it is only ever rendered on a non-production site. --}}
    <span class="text-[11px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-2 py-1">
      {{ $environment }}
    </span>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[380px] pt-10 sm:pt-16">
      <span class="h-11 w-11 rounded-xl bg-hover grid place-items-center text-sub mb-5">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <rect x="4" y="10" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/>
          <path d="M8 10V7a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
          <circle cx="12" cy="15" r="1.4" fill="currentColor"/>
        </svg>
      </span>

      <h1 class="text-[24px] font-bold text-head leading-tight">Secure page</h1>
      <p class="text-[15px] text-sub mt-1.5 mb-7">
        This environment is not open to the public. Enter your access code to reach sign up and sign in.
      </p>

      @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger/40 bg-danger/5 px-3.5 py-2.5 text-[13px] text-danger">
          {{ $errors->first() }}
        </div>
      @endif

      <form method="POST" action="{{ route('access.store') }}" class="space-y-4">
        @csrf
        <div>
          <label for="code" class="block text-[13px] font-semibold text-ink mb-1.5">Access code</label>
          {{-- inputmode numeric brings up the digit keypad on a phone; autocomplete off so a
               browser never offers a code saved on a shared machine. --}}
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="off"
                 autofocus maxlength="64" placeholder="Enter code"
                 class="w-full h-11 px-3.5 rounded-lg border bg-white text-[15px] text-ink placeholder:text-faint tracking-[0.2em]
                        outline-none focus:border-brand {{ $errors->any() ? 'border-danger' : 'border-stroke' }}" />
        </div>

        <button type="submit"
                class="w-full h-11 rounded-lg bg-brand hover:bg-brand-dark transition-colors text-white text-[14px] font-semibold">
          Continue
        </button>
      </form>

      <p class="text-[12px] text-faint mt-6 leading-relaxed">
        Do not share this code outside the team. It keeps this test site out of public view;
        it is not your account password.
      </p>
    </div>
  </main>
@endsection
