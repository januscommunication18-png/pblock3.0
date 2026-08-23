@extends('backoffice.layout')
@section('title', 'Verify Your Identity')

@section('content')
  @component('backoffice.partials.shell')
    <h1 class="text-[16px] font-semibold text-head">Verify Your Identity</h1>

    {{-- The masked address (§3). Shown only to somebody who already typed it, so it reveals
         nothing they did not supply — its job is to catch a typo before they go looking in the
         wrong inbox. The wording is conditional because this screen renders identically for an
         address that was never authorized. --}}
    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">
      If that address is authorized, we sent a verification code to
      <span class="font-semibold text-ink _moretogether-break">{{ $masked }}</span>.
    </p>

    <form method="POST" action="{{ route('backoffice.verify.submit') }}" class="mt-5">
      @csrf
      <label for="bo-code" class="block text-[12px] font-medium text-ink mb-1.5">Verification Code</label>

      {{-- ONE input, not six boxes.

           Six separate inputs need JavaScript to move focus, break paste, and are read out one
           character at a time by a screen reader. A single field with `inputmode="numeric"` and
           wide letter-spacing looks the same, pastes correctly, and needs no script — and the
           controller strips non-digits, so "482 913" works too. --}}
      <input id="bo-code" name="code" type="text" required autofocus
             inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*"
             class="pb-input !h-12 w-full text-center text-[20px] font-semibold tracking-[0.4em]"
             placeholder="000000" />

      @error('code')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <button type="submit"
              class="mt-5 w-full h-10 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
        Verify Code
      </button>
    </form>

    <div class="mt-5 pt-4 border-t border-line text-center">
      <p class="text-[12px] text-sub">Didn&rsquo;t receive the code?</p>
      <form method="POST" action="{{ route('backoffice.verify.resend') }}" class="mt-2">
        @csrf
        <button type="submit"
                class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">
          Resend Code
        </button>
      </form>
      <a href="{{ route('backoffice.verify.show') }}"
         class="inline-block mt-3 text-[12px] text-sub hover:text-ink hover:underline">Use a different address</a>
    </div>
  @endcomponent
@endsection
