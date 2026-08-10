@extends('layouts.auth')
@section('title', 'Onboarding · Role — Project Block')

@php
    // Per-role icons ported from the HTML POC (onboarding-role.html).
    $roleIcons = [
        'pm'  => 'M12 3l8 4-8 4-8-4 8-4zM4 12l8 4 8-4M4 16l8 4 8-4',
        'em'  => 'M12 3l8 4-8 4-8-4 8-4zM4 12l8 4 8-4',
        'des' => 'M4 7l5-3 6 3 5-2v13l-5 2-6-3-5 3V7z',
        'dev' => 'M4 5h16v11H4zM2 20h20',
        'fnd' => 'M5 19l3-9 4 3 4-7 3 13',
        'ops' => 'M4 12a8 8 0 018-8M20 12a8 8 0 01-8 8M8 4L5 7M16 20l3-3',
        'oth' => 'M12 3l8 4-8 4-8-4 8-4z',
    ];
@endphp

@section('body')
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:40%"></div></div>

  <header class="flex items-center justify-between px-5 sm:px-10 py-5">
    <div class="flex items-center gap-3">
      <a href="{{ route('onboarding.profile') }}" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <a class="flex items-center gap-2" href="#">
        <svg width="24" height="24" viewBox="0 0 32 32" fill="#0f0f10"><path d="M5 21 L15 4 L20.5 4 L10.5 21 Z"/><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z"/></svg>
        <span class="text-[18px] font-bold tracking-tight text-head">Project Block</span>
      </a>
    </div>
    <div class="flex items-center gap-2">
      <div class="flex items-center gap-2 border border-line rounded-full pl-1 pr-3 py-1 text-[13px] text-ink">
        <span class="h-5 w-5 rounded-full bg-brand grid place-items-center text-white text-[9px] font-bold">{{ $user->initial() }}</span>
        <span>{{ $user->displayName() }}</span>
      </div>
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" title="Log out" aria-label="Log out" class="flex items-center gap-1.5 h-8 px-3 rounded-full border border-line text-[13px] text-sub hover:bg-hover hover:text-ink transition-colors">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="hidden sm:inline">Log out</span>
        </button>
      </form>
    </div>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[430px] py-6 sm:py-12">
      <h1 class="text-[24px] font-bold text-head">What's your role?</h1>
      <p class="text-[15px] text-sub mb-7">Let's set up Project Block for how you work.</p>

      <p class="text-[13px] font-medium text-ink mb-3">Select one</p>

      <form method="POST" action="{{ route('onboarding.role.store') }}" id="role-form">
        @csrf
        <input type="hidden" name="role" id="role-input" value="{{ old('role', $user->onboardingProfile->role_selection ?? '') }}" />
        <div id="role-list" class="space-y-2.5">
          @foreach ($roles as $key => $label)
            <button type="button" data-role="{{ $key }}"
              class="option w-full flex items-center gap-3 h-12 px-4 rounded-lg border text-left text-[14px] border-stroke text-ink hover:bg-hover">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="{{ $roleIcons[$key] ?? $roleIcons['oth'] }}" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span>{{ $label }}</span>
              <span class="check ml-auto h-5 w-5 rounded bg-brand grid place-items-center hidden"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </button>
          @endforeach
        </div>

        <button id="continue" type="submit" disabled class="mt-6 w-full h-11 rounded-lg text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">Continue</button>
      </form>

      <form method="POST" action="{{ route('onboarding.role.skip') }}">
        @csrf
        <button type="submit" class="block text-center w-full h-9 leading-9 mt-3 text-[14px] font-semibold text-ink hover:underline">Skip</button>
      </form>
    </div>
  </main>

  <script>
    (function () {
      var input = document.getElementById('role-input');
      var cont = document.getElementById('continue');
      var buttons = Array.prototype.slice.call(document.querySelectorAll('#role-list [data-role]'));

      function paint() {
        buttons.forEach(function (b) {
          var sel = b.getAttribute('data-role') === input.value;
          b.className = 'option w-full flex items-center gap-3 h-12 px-4 rounded-lg border text-left text-[14px] ' +
            (sel ? 'border-brand ring-1 ring-brand text-brand font-medium' : 'border-stroke text-ink hover:bg-hover');
          b.querySelector('.check').classList.toggle('hidden', !sel);
        });
        var ok = !!input.value;
        cont.disabled = !ok;
        cont.className = 'mt-6 w-full h-11 rounded-lg text-[14px] font-semibold transition-colors ' +
          (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
      }
      buttons.forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-role'); paint(); });
      });
      paint();
    })();
  </script>
@endsection
