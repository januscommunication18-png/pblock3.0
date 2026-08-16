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

        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Choose your view <span class="text-danger">*</span></label>
        <input type="hidden" name="view_type" id="view_type" value="{{ old('view_type') }}" />
        <div id="view-list" class="grid sm:grid-cols-2 gap-3">
          @php
            $viewIcons = ['classic' => 'M4 6h16M4 12h16M4 18h10', 'agile' => 'M13 2L4.5 13H11l-1 9 8.5-11H12l1-9z'];
          @endphp
          @foreach ($views as $key => $view)
            @php $available = $view['available'] && ($key !== 'classic' || $classicEnabled); @endphp
            @if ($available)
              <button type="button" data-view="{{ $key }}"
                class="view-card w-full flex items-start gap-3 p-4 rounded-lg border text-left border-stroke hover:bg-hover">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="mt-0.5 shrink-0 text-sub view-icon"><path d="{{ $viewIcons[$key] ?? '' }}" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div class="min-w-0">
                  <div class="text-[14px] font-medium text-ink view-title">{{ $view['label'] }}</div>
                  <div class="text-[12px] text-sub mt-0.5">{{ $view['description'] }}</div>
                </div>
                <span class="view-check ml-auto h-5 w-5 rounded-full bg-brand grid place-items-center shrink-0 hidden">{!! pb_icon('check-on-brand', 11) !!}</span>
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

        {{-- Enable apps (multi-select). Projects is on today; the rest are Coming Soon. --}}
        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Enable apps <span class="text-danger">*</span></label>
        <p class="text-[12px] text-sub mb-3">Choose what this workspace can do. You can turn more on later as they launch.</p>
        @include('partials.workspace-apps')
        @error('view_type') <p class="text-[12px] text-danger mt-1.5">{{ $message }}</p> @enderror

        {{-- Enable apps (multi-select). Projects is on today; the rest are Coming Soon. --}}
        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-6">Enable apps <span class="text-danger">*</span></label>
        <p class="text-[12px] text-sub mb-3">Choose what this workspace can do. You can turn more on later as they launch.</p>
        <div class="grid gap-3">
          @foreach (config('workspace.apps') as $appKey => $app)
            @if ($app['available'])
              <div class="w-full flex items-start gap-3 p-4 rounded-lg border border-brand/40 bg-sel/40 text-left cursor-default" title="Projects is the default app and can’t be turned off">
                <span class="mt-0.5 h-5 w-5 rounded-md bg-brand grid place-items-center shrink-0">{!! pb_icon('check-on-brand', 12) !!}</span>
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-[14px] font-semibold text-head">{{ $app['label'] }}</span>
                    <span class="text-[10px] uppercase tracking-wide bg-brand/10 text-brand rounded px-1.5 py-0.5">Default</span>
                  </div>
                  <div class="text-[12px] text-sub mt-0.5">{{ $app['description'] }}</div>
                </div>
                <span role="img" aria-label="Read only" class="ml-auto mt-0.5 shrink-0 text-faint">{!! pb_icon('lock', 14) !!}</span>
                <input type="hidden" name="apps[]" value="{{ $appKey }}" />
              </div>
            @else
              <div class="w-full flex items-start gap-3 p-4 rounded-lg border border-dashed border-stroke bg-hover/40 text-left opacity-70 cursor-not-allowed select-none" aria-disabled="true">
                <span class="mt-0.5 h-5 w-5 rounded-md border border-line shrink-0"></span>
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-[14px] font-medium text-sub">{{ $app['label'] }}</span>
                    <span class="text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 rounded px-1.5 py-0.5">Coming soon</span>
                  </div>
                  <div class="text-[12px] text-faint mt-0.5">{{ $app['description'] }}</div>
                </div>
              </div>
            @endif
          @endforeach
        </div>

        <div class="flex items-center gap-3 mt-8">
          <button id="create" type="submit" disabled class="h-10 px-5 rounded-md text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">Create workspace</button>
          <a href="{{ route('welcome') }}" class="h-10 px-5 grid place-items-center rounded-md border border-stroke text-[14px] font-semibold text-ink hover:bg-hover">Go back</a>
        </div>
      </form>
    </div>
  </main>

  <script>
    (function () {
      var nameEl = document.getElementById('ws-name');
      var slugEl = document.getElementById('ws-slug');
      var sizeEl = document.getElementById('team_size');
      var viewEl = document.getElementById('view_type');
      var create = document.getElementById('create');
      var slugTouched = {{ old('slug') ? 'true' : 'false' }};

      function gate() {
        var ok = nameEl.value.trim().length > 0 && slugEl.value.trim().length > 0 && !!sizeEl.value && !!viewEl.value;
        create.disabled = !ok;
        create.className = 'h-10 px-5 rounded-md text-[14px] font-semibold transition-colors ' +
          (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
      }
      function slugify(v) { return v.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
      nameEl.addEventListener('input', function () { if (!slugTouched) slugEl.value = slugify(nameEl.value); gate(); });
      slugEl.addEventListener('input', function () { slugTouched = true; slugEl.value = slugify(slugEl.value); gate(); });

      // View selection (only available cards are selectable)
      function paintViews() {
        document.querySelectorAll('#view-list .view-card').forEach(function (card) {
          var sel = card.getAttribute('data-view') === viewEl.value;
          card.className = 'view-card w-full flex items-start gap-3 p-4 rounded-lg border text-left ' +
            (sel ? 'border-brand ring-1 ring-brand' : 'border-stroke hover:bg-hover');
          card.querySelector('.view-title').className = 'text-[14px] font-medium view-title ' + (sel ? 'text-brand' : 'text-ink');
          card.querySelector('.view-icon').classList.toggle('text-brand', sel);
          card.querySelector('.view-icon').classList.toggle('text-sub', !sel);
          card.querySelector('.view-check').classList.toggle('hidden', !sel);
        });
      }
      document.querySelectorAll('#view-list .view-card').forEach(function (card) {
        card.addEventListener('click', function () { viewEl.value = card.getAttribute('data-view'); paintViews(); gate(); });
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
