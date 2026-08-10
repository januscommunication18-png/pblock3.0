@extends('layouts.auth')
@section('title', 'Sign up — Project Block')

@section('body')
  {{-- AuthHeader --}}
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <a class="flex items-center gap-2" href="{{ route('signup') }}">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" />
        <path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </a>
    <p class="text-[13px] text-sub">
      Already have an account?
      <a href="{{ route('signin') }}" class="text-link font-semibold hover:underline">Sign in</a>
    </p>
  </header>

  {{-- AuthCard --}}
  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[380px] pt-6 sm:pt-10">
      <h1 class="text-[24px] font-bold text-head leading-tight">Work in all dimensions.</h1>
      <p class="text-[18px] text-sub mb-7">Create your Project Block account.</p>

      @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger/40 bg-danger/5 px-3.5 py-2.5 text-[13px] text-danger">
          {{ $errors->first() }}
        </div>
      @endif
      @if (session('status'))
        <div class="mb-4 rounded-lg border border-brand/30 bg-brand/5 px-3.5 py-2.5 text-[13px] text-brand">
          {{ session('status') }}
        </div>
      @endif

      {{-- OAuth buttons --}}
      <div class="space-y-3">
        <a href="{{ route('social.redirect', 'google') }}" class="relative w-full h-11 rounded-lg border border-stroke bg-white hover:bg-hover transition-colors text-[14px] font-semibold text-ink flex items-center justify-center">
          <span class="absolute left-4 top-1/2 -translate-y-1/2">
            <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.4 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.2 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.1 5.3-4.6 7l7.1 5.5c4.1-3.8 6.4-9.4 6.4-16z"/><path fill="#FBBC05" d="M10.4 28.3c-.5-1.4-.8-2.9-.8-4.3s.3-3 .8-4.3l-7.8-6.1C.9 16.6 0 20.2 0 24s.9 7.4 2.6 10.4l7.8-6.1z"/><path fill="#34A853" d="M24 48c6.2 0 11.5-2 15.3-5.5l-7.1-5.5c-2 1.3-4.6 2.1-8.2 2.1-6.3 0-11.7-3.7-13.6-9l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
          </span>
          Sign up with Google
        </a>

        <a href="{{ route('social.redirect', 'github') }}" class="relative w-full h-11 rounded-lg border border-stroke bg-white hover:bg-hover transition-colors text-[14px] font-semibold text-ink flex items-center justify-center">
          <span class="absolute left-4 top-1/2 -translate-y-1/2">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#0f0f10"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.3.8-.6v-2c-3.2.7-3.9-1.5-3.9-1.5-.5-1.3-1.3-1.7-1.3-1.7-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.7 1.3 3.4 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.5 11.5 0 016 0C17.3 4.6 18.3 5 18.3 5c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5z"/></svg>
          </span>
          Sign up with GitHub
        </a>

        <form method="POST" action="{{ route('sso.start') }}" id="sso-form">
          @csrf
          <input type="hidden" name="email" id="sso-email" />
          <button type="submit" class="relative w-full h-11 rounded-lg border border-stroke bg-white hover:bg-hover transition-colors text-[14px] font-semibold text-ink">
            <span class="absolute left-4 top-1/2 -translate-y-1/2">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f0f10" stroke-width="2"><circle cx="8" cy="8" r="4"/><path d="M11 11l8 8M16 16l2-2M19 19l2-2" stroke-linecap="round"/></svg>
            </span>
            Sign up with Single Sign-On
          </button>
        </form>
      </div>

      {{-- OrDivider --}}
      <div class="flex items-center gap-3 my-6">
        <div class="h-px flex-1 bg-line"></div>
        <span class="text-[13px] text-faint">or</span>
        <div class="h-px flex-1 bg-line"></div>
      </div>

      {{-- Email-first form --}}
      <form method="POST" action="{{ route('signup.email') }}">
        @csrf
        <label class="block text-[13px] font-medium text-ink mb-1.5" for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" class="pb-input {{ $errors->has('email') ? 'is-error' : '' }}" required />

        <button id="continue-btn" type="submit" disabled
          class="mt-5 w-full h-11 rounded-lg bg-hover text-faint text-[14px] font-semibold cursor-not-allowed transition-colors">
          Continue
        </button>
      </form>

      {{-- Legal (AUTH-008) --}}
      <p class="mt-5 text-center text-[12px] text-sub leading-relaxed">
        By creating an account, you understand and agree to
        our <a href="#" class="text-ink font-medium underline">Terms of Service</a>
        and <a href="#" class="text-ink font-medium underline">Privacy Policy</a>.
      </p>
    </div>
  </main>

  {{-- TrustLogos --}}
  <footer class="px-5 pb-12 pt-6">
    <p class="text-center text-[13px] text-sub mb-6">Join 50,000+ teams building with Project Block</p>
    <div class="flex flex-wrap items-center justify-center gap-x-10 gap-y-4 text-faint">
      <span class="text-[15px] font-bold tracking-tight">ZERODHA</span>
      <span class="text-[16px] font-extrabold tracking-widest">SONY</span>
      <span class="text-[15px] font-bold">▶◀ Dolby</span>
      <span class="text-[15px] font-semibold">accenture</span>
    </div>
  </footer>

  <script>
    // Enable Continue only for a plausible email (server still validates authoritatively).
    (function () {
      var email = document.getElementById('email');
      var btn = document.getElementById('continue-btn');
      var ssoEmail = document.getElementById('sso-email');
      if (!email || !btn) return;
      function sync() {
        var ok = /\S+@\S+\.\S+/.test(email.value);
        btn.disabled = !ok;
        if (ssoEmail) ssoEmail.value = email.value;
        btn.classList.toggle('bg-hover', !ok);
        btn.classList.toggle('text-faint', !ok);
        btn.classList.toggle('cursor-not-allowed', !ok);
        btn.classList.toggle('bg-brand', ok);
        btn.classList.toggle('hover:bg-brand-dark', ok);
        btn.classList.toggle('text-white', ok);
      }
      email.addEventListener('input', sync);
      sync();
    })();
  </script>
@endsection
