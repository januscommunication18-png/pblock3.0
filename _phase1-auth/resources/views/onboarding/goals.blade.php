@extends('layouts.auth')
@section('title', 'Onboarding · Goals — Project Block')

@section('body')
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:60%"></div></div>

  <header class="flex items-center justify-between px-5 sm:px-10 py-5">
    <div class="flex items-center gap-3">
      <a href="{{ route('onboarding.role') }}" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
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
      <h1 class="text-[24px] font-bold text-head">What brings you to Project Block?</h1>
      <p class="text-[15px] text-sub mb-7">Tell us your goals and team size.</p>

      <p class="text-[13px] font-medium text-ink mb-3">Select one or more</p>

      @php $current = old('goals', $user->onboardingProfile->goals ?? []); @endphp

      <form method="POST" action="{{ route('onboarding.goals.store') }}" id="goals-form">
        @csrf
        <div id="goal-list" class="space-y-2.5">
          @foreach ($goals as $key => $label)
            <button type="button" data-goal="{{ $key }}"
              class="option w-full flex items-center gap-3 h-12 px-4 rounded-lg border text-left text-[14px] border-stroke text-ink hover:bg-hover">
              <span class="box h-5 w-5 rounded border border-stroke shrink-0 grid place-items-center">
                <svg class="tick hidden" width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              <span>{{ $label }}</span>
              <input type="checkbox" name="goals[]" value="{{ $key }}" class="hidden" @checked(in_array($key, (array) $current, true))>
            </button>
          @endforeach
        </div>

        <button id="continue" type="submit" disabled class="mt-6 w-full h-11 rounded-lg text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">Continue</button>
      </form>

      <form method="POST" action="{{ route('onboarding.goals.skip') }}">
        @csrf
        <button type="submit" class="block text-center w-full h-9 leading-9 mt-3 text-[14px] font-semibold text-ink hover:underline">Skip</button>
      </form>
    </div>
  </main>

  <script>
    (function () {
      var cont = document.getElementById('continue');
      var buttons = Array.prototype.slice.call(document.querySelectorAll('#goal-list [data-goal]'));

      function paint() {
        var any = false;
        buttons.forEach(function (b) {
          var cb = b.querySelector('input[type=checkbox]');
          var sel = cb.checked; if (sel) any = true;
          b.className = 'option w-full flex items-center gap-3 h-12 px-4 rounded-lg border text-left text-[14px] ' +
            (sel ? 'border-brand ring-1 ring-brand text-brand font-medium' : 'border-stroke text-ink hover:bg-hover');
          var box = b.querySelector('.box');
          box.classList.toggle('bg-brand', sel);
          box.classList.toggle('border-brand', sel);
          box.classList.toggle('border-stroke', !sel);
          b.querySelector('.tick').classList.toggle('hidden', !sel);
        });
        cont.disabled = !any;
        cont.className = 'mt-6 w-full h-11 rounded-lg text-[14px] font-semibold transition-colors ' +
          (any ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
      }
      buttons.forEach(function (b) {
        b.addEventListener('click', function () {
          var cb = b.querySelector('input[type=checkbox]');
          cb.checked = !cb.checked;
          paint();
        });
      });
      paint();
    })();
  </script>
@endsection
