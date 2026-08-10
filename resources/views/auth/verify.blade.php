@extends('layouts.auth')
@section('title', 'Enter your code — Project Block')

@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <a class="flex items-center gap-2" href="{{ route('signup') }}">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </a>
    <a href="{{ route('signup') }}" class="text-[13px] text-link font-semibold hover:underline">Start over</a>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[380px] pt-6 sm:pt-10">
      <h1 class="text-[24px] font-bold text-head leading-tight">Check your email.</h1>
      <p class="text-[15px] text-sub mb-7">
        We sent a 6-digit code to <span class="font-semibold text-ink">{{ $email }}</span>. Enter it below to continue.
      </p>

      @if (session('status'))
        <div class="mb-4 rounded-lg border border-brand/30 bg-brand/5 px-3.5 py-2.5 text-[13px] text-brand">{{ session('status') }}</div>
      @endif
      @error('code')
        <div class="mb-4 rounded-lg border border-danger/40 bg-danger/5 px-3.5 py-2.5 text-[13px] text-danger">{{ $message }}</div>
      @enderror

      <form method="POST" action="{{ route('auth.verify') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}" />
        <label class="block text-[13px] font-medium text-ink mb-1.5" for="code">Verification code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
          placeholder="000000"
          class="pb-input tracking-[0.5em] text-center text-[18px] font-semibold {{ $errors->has('code') ? 'is-error' : '' }}"
          required autofocus />

        <button type="submit" class="mt-5 w-full h-11 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
          Verify &amp; continue
        </button>
      </form>

      <form method="POST" action="{{ route('auth.verify.resend') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-[13px] text-link font-semibold hover:underline">Didn't get it? Resend code</button>
      </form>

      @if(config('app.env') === 'local')
        <p class="mt-6 text-center text-[12px] text-faint">
          Dev tip: view the sent email at <a href="{{ route('dev.emaillog') }}" class="text-link underline">/emaillog</a>.
        </p>
      @endif
    </div>
  </main>
@endsection
