@extends('layouts.auth')
@section('title', 'Create workspace — Project Block')

@section('body')
  <!-- Minimal header: back (left) · title · close (right) -->
  <header class="relative h-14 shrink-0 border-b border-line flex items-center px-4">
    <a href="{{ route('welcome') }}" title="Back" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('chevron-left', 18) !!}
    </a>
    <span class="absolute left-1/2 -translate-x-1/2 font-semibold text-[15px] text-head">Create workspace</span>
    <a href="{{ route('welcome') }}" title="Close" class="ml-auto h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('xmark', 18) !!}
    </a>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[460px] py-10 sm:py-14">
      <h1 class="text-[24px] font-bold text-head mb-8">Create your workspace</h1>

      <form method="POST" action="{{ route('workspaces.store') }}" id="ws-form">
        @csrf

        <label class="block text-[13px] font-medium text-ink mb-1.5" for="ws-name">Name your workspace <span class="text-danger">*</span></label>
        <input id="ws-name" name="name" type="text" value="{{ old('name') }}" placeholder="Something familiar and recognizable is always best." class="pb-input" autocomplete="off" />
        @error('name') <p class="text-[12px] text-danger mt-1.5">{{ $message }}</p> @enderror

        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Set your workspace's URL <span class="text-danger">*</span></label>
        <div class="pb-group">
          <span class="pb-group__prefix">app.projectblock.so/</span>
          <input id="ws-slug" name="slug" type="text" value="{{ old('slug') }}" placeholder="Type or paste a URL" class="pb-group__field" autocomplete="off" />
        </div>
        @error('slug') <p class="text-[12px] text-danger mt-1">{{ $message }}</p> @enderror

        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">How many people will use this workspace? <span class="text-danger">*</span></label>
        <input type="hidden" name="team_size" id="team_size" value="{{ old('team_size') }}" />
        <div class="relative" id="range-combo" data-sizes='@json($teamSizes)'>
          <button type="button" id="range-btn" aria-haspopup="listbox" aria-expanded="false" class="pb-input text-left cursor-pointer">
            <span class="flex items-center justify-between h-full">
              <span id="range-value" class="text-faint truncate">Select a range</span>
              {!! pb_icon('chevron-down', 16, 'text-faint shrink-0 ml-2') !!}
            </span>
          </button>
          <ul id="range-list" role="listbox" class="hidden absolute z-30 mt-1 w-full max-h-60 overflow-auto rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5"></ul>
        </div>
        @error('team_size') <p class="text-[12px] text-danger mt-1.5">{{ $message }}</p> @enderror

        {{-- "Choose your view" is a radio group, so it says so.

             It used to be a row of plain <button>s whose selected state existed only as classes
             the script rewrote: nothing announced the group, nothing announced which option was
             chosen, and arrow keys did nothing. The state now lives in `aria-checked` — one
             attribute that the accessibility tree and the stylesheet both read, so the two
             cannot disagree about which view is selected. --}}
        <label id="view-label" class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Choose your view <span class="text-danger">*</span></label>
        <input type="hidden" name="view_type" id="view_type" value="{{ old('view_type') }}" />
        <div id="view-list" role="radiogroup" aria-labelledby="view-label" class="grid sm:grid-cols-2 gap-3">
          @php
            $viewIcons = ['classic' => 'M4 6h16M4 12h16M4 18h10', 'agile' => 'M13 2L4.5 13H11l-1 9 8.5-11H12l1-9z'];
          @endphp
          @foreach ($views as $key => $view)
            @php $available = $view['available'] && ($key !== 'classic' || $classicEnabled); @endphp
            @if ($available)
              <button type="button" data-view="{{ $key }}" role="radio" aria-checked="false"
                class="_moretogether-viewcard view-card w-full flex items-start gap-3 p-4 rounded-lg border border-stroke text-left hover:bg-hover">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="_moretogether-viewcard__icon mt-0.5 shrink-0"><path d="{{ $viewIcons[$key] ?? '' }}" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div class="min-w-0">
                  <div class="_moretogether-viewcard__title text-[14px] font-medium">{{ $view['label'] }}</div>
                  <div class="text-[12px] text-sub mt-0.5">{{ $view['description'] }}</div>
                </div>
                {{-- `text-white`, because under the Font Awesome icon set this tick is a glyph
                     inheriting currentColor — without it, a dark check on a brand-blue circle. --}}
                <span class="_moretogether-viewcard__check ml-auto h-5 w-5 rounded-full bg-brand text-white grid place-items-center shrink-0">{!! pb_icon('check-thin', 11) !!}</span>
              </button>
            @else
              {{-- Classic — Coming Soon: visible but disabled, never selectable (WS-VIEW-002) --}}
              <div class="w-full flex items-start gap-3 p-4 rounded-lg border border-dashed border-stroke bg-hover/40 text-left opacity-70 cursor-not-allowed select-none" aria-disabled="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="mt-0.5 shrink-0 text-faint"><path d="{{ $viewIcons[$key] ?? '' }}" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-[14px] font-medium text-sub">{{ $view['label'] }}</span>
                    <span class="text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 rounded px-1.5 py-0.5">Coming soon</span>
                  </div>
                  <div class="text-[12px] text-faint mt-0.5">{{ $view['description'] }}</div>
                </div>
              </div>
            @endif
          @endforeach
        </div>
        @error('view_type') <p class="text-[12px] text-danger mt-1.5">{{ $message }}</p> @enderror

        {{-- Enable apps (multi-select) — ONE copy, from the shared partial.

             There used to be a second, hard-coded copy of this section below it, left behind
             when the partial was extracted. It was not merely a repeat: it rendered every
             available app as a locked "Default" with a hidden apps[] input, so Wiki and Help
             Desk were submitted as on whatever the real checkboxes above said. --}}
        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Enable apps <span class="text-danger">*</span></label>
        <p class="text-[12px] text-sub mb-3">Choose what this workspace can do. You can turn more on later as they launch.</p>
        @include('partials.workspace-apps')

        {{-- The tenant subdomain (P72), shown only once a customer-facing app is on. --}}
        @include('partials.workspace-subdomain')

        <div class="flex items-center gap-3 mt-8">
          <button id="create" type="submit" disabled class="h-10 px-5 rounded-md text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">Create workspace</button>
          <a href="{{ route('welcome') }}" class="h-10 px-5 grid place-items-center rounded-md border border-stroke text-[14px] font-semibold text-ink hover:bg-hover">Go back</a>
        </div>
        {{-- Why the button is off. It waits for the four required fields, which is right — but a
             disabled control with no reason beside it reads as a broken page rather than as an
             unfinished form. --}}
        <p id="create-hint" class="text-[12px] text-sub mt-2"></p>
      </form>
    </div>
  </main>

  {{-- Loaded DEFERRED and before the inline block below (P72). Deferred scripts run after
       parsing, so the inline listener for `pb:subdomain-state` is already registered by the
       time this dispatches its first state on load — the reveal and the gate agree from the
       first paint rather than one frame later. --}}
  <script defer src="{{ pb_asset('assets/js/workspace-subdomain.js') }}"></script>

  <script>
    (function () {
      var nameEl = document.getElementById('ws-name');
      var slugEl = document.getElementById('ws-slug');
      var sizeEl = document.getElementById('team_size');
      var viewEl = document.getElementById('view_type');
      var create = document.getElementById('create');
      var hint = document.getElementById('create-hint');
      var slugTouched = {{ old('slug') ? 'true' : 'false' }};

      /* The subdomain field reports its own state (P72). Held here rather than read off the
         field, so this screen does not need to know how that partial is built. */
      var subdomain = { required: false, valid: true };
      window.addEventListener('pb:subdomain-state', function (e) {
        subdomain = e.detail || subdomain;
        gate();
      });

      function gate() {
        // Named one by one so the hint can say WHICH one is still missing. "Fill in the
        // required fields" on a form this long is a hint that makes the reader hunt.
        var missing = [];
        if (!nameEl.value.trim()) missing.push('a name');
        if (!slugEl.value.trim()) missing.push('a URL');
        if (!sizeEl.value) missing.push('a team size');
        if (!viewEl.value) missing.push('a view');
        // Named like the rest, so switching Help Center on and missing the new field below
        // does not leave a disabled button with no explanation.
        if (subdomain.required && !subdomain.valid) missing.push('an available subdomain');

        var ok = missing.length === 0;
        create.disabled = !ok;
        create.className = 'h-10 px-5 rounded-md text-[14px] font-semibold transition-colors ' +
          (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
        hint.textContent = ok ? '' : 'Still needed: ' + missing.join(', ') + '.';
      }
      function slugify(v) { return v.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
      nameEl.addEventListener('input', function () { if (!slugTouched) slugEl.value = slugify(nameEl.value); gate(); });
      slugEl.addEventListener('input', function () { slugTouched = true; slugEl.value = slugify(slugEl.value); gate(); });

      /*
       * View selection — a radio group (only available cards are selectable).
       *
       * The whole visual state is one attribute: `aria-checked`. The stylesheet paints from it
       * (see ._moretogether-viewcard in styles.css), so what a screen reader announces and what
       * the eye sees are the same fact rather than two copies of it that can drift.
       *
       * `tabindex` is roving, which is what makes a radio group behave like one: the group is a
       * single tab stop and the arrow keys move within it.
       */
      var viewCards = Array.prototype.slice.call(document.querySelectorAll('#view-list .view-card'));

      function paintViews() {
        var anySelected = false;
        viewCards.forEach(function (card) {
          var sel = card.getAttribute('data-view') === viewEl.value;
          card.setAttribute('aria-checked', sel ? 'true' : 'false');
          card.tabIndex = sel ? 0 : -1;
          if (sel) anySelected = true;
        });
        // Nothing chosen yet: the first option holds the tab stop, or the group would be
        // unreachable by keyboard entirely.
        if (!anySelected && viewCards.length) viewCards[0].tabIndex = 0;
      }

      function selectView(card) {
        viewEl.value = card.getAttribute('data-view');
        paintViews();
        gate();
      }

      viewCards.forEach(function (card, i) {
        card.addEventListener('click', function () { selectView(card); });
        card.addEventListener('keydown', function (e) {
          // Arrows move AND select, which is how a radio group behaves; Space picks the one
          // you are on. Enter is left to the form.
          var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
          if (step) {
            e.preventDefault();
            var next = viewCards[(i + step + viewCards.length) % viewCards.length];
            selectView(next);
            next.focus();
          } else if (e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            selectView(card);
          }
        });
      });

      // Range combobox
      var RANGES = JSON.parse(document.getElementById('range-combo').getAttribute('data-sizes'));
      var rBtn = document.getElementById('range-btn');
      var rList = document.getElementById('range-list');
      var rVal = document.getElementById('range-value');
      function renderRanges() {
        rList.innerHTML = RANGES.map(function (r) {
          var sel = sizeEl.value === r;
          return '<li role="option" data-val="' + r + '" class="group relative flex items-center cursor-pointer select-none py-2 pl-3 pr-9 text-[14px] text-ink hover:bg-brand hover:text-white">' +
            '<span class="block truncate">' + r + '</span>' +
            '<span class="' + (sel ? '' : 'hidden ') + 'absolute inset-y-0 right-0 flex items-center pr-3 text-brand group-hover:text-white">{!! pb_icon('check-thin', 16) !!}</span>' +
            '</li>';
        }).join('');
        rList.querySelectorAll('[data-val]').forEach(function (li) {
          li.onclick = function () {
            sizeEl.value = li.getAttribute('data-val');
            rVal.textContent = sizeEl.value;
            rVal.classList.remove('text-faint'); rVal.classList.add('text-ink');
            closeR(); gate();
          };
        });
      }
      function openR() { renderRanges(); rList.classList.remove('hidden'); rBtn.setAttribute('aria-expanded', 'true'); }
      function closeR() { rList.classList.add('hidden'); rBtn.setAttribute('aria-expanded', 'false'); }
      rBtn.addEventListener('click', function (e) { e.stopPropagation(); if (rList.classList.contains('hidden')) openR(); else closeR(); });
      document.addEventListener('click', function (e) { if (!document.getElementById('range-combo').contains(e.target)) closeR(); });

      // Restore old() selections
      if (sizeEl.value) { rVal.textContent = sizeEl.value; rVal.classList.remove('text-faint'); rVal.classList.add('text-ink'); }
      paintViews();
      gate();
    })();
  </script>
@endsection
