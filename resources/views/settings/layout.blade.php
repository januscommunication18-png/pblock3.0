<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Workspace settings · {{ ucfirst($section) }} — Project Block</title>

  {{-- Project Block settings shell — hybrid Vue-in-Blade (CLAUDE.md §14, no FlyonUI).
       Same CDN Tailwind + POC design tokens the rest of the app uses. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  {{-- Vue 3 (global build) + shared settings runtime/components. --}}
  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  @stack('section-script')
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  {{-- Topbar (settings mode: minimal, not the full app topbar) --}}
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-nav" class="md:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('bars', 18) !!}
    </button>
    <span class="flex items-center gap-2">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $workspace->initial() }}</span>
      <span class="font-medium text-[13px] max-w-[160px] truncate">{{ $workspace->name }}</span>
      <span class="text-faint">/</span>
      <span class="text-[13px] text-sub">Settings</span>
    </span>
    <div class="ml-auto flex items-center gap-2">
      <span class="h-6 w-px bg-line"></span>
      <a href="{{ route('welcome') }}" title="Close settings" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
        {!! pb_icon('xmark', 18) !!}
      </a>
    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    <div id="nav-backdrop" class="hidden md:hidden fixed inset-0 bg-black/30 z-30"></div>

    {{-- Persistent grouped left nav (spec §13) --}}
    <aside id="settings-nav" class="w-64 shrink-0 border-r border-line bg-white flex flex-col fixed md:relative inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0 transition-transform duration-200">
      <div class="md:hidden flex justify-end px-2 h-12 items-center shrink-0">
        <button id="close-nav" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
          {!! pb_icon('xmark', 16) !!}
        </button>
      </div>
      <nav class="flex-1 overflow-y-auto pt-2 pb-6 px-2">
        @foreach ($nav as $group => $items)
          <div class="px-2 h-8 mt-3 first:mt-1 flex items-center text-[11px] font-semibold text-faint uppercase tracking-wide">{{ $group }}</div>
          @foreach ($items as $item)
            @php $active = $item['key'] === $section; @endphp
            @if ($item['status'] === 'soon')
              <span class="flex items-center gap-2 px-2 h-8 rounded-md text-faint cursor-not-allowed select-none">
                <span class="truncate">{{ $item['label'] }}</span>
                <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5">Soon</span>
              </span>
            @else
              <a href="{{ url('/settings/'.$item['key']) }}"
                 class="flex items-center gap-2 px-2 h-8 rounded-md {{ $active ? 'bg-sel text-brand font-medium' : ($item['status'] === 'placeholder' ? 'text-sub hover:bg-hover' : 'text-ink hover:bg-hover') }}">
                <span class="truncate">{{ $item['label'] }}</span>
              </a>
            @endif
          @endforeach
        @endforeach
      </nav>
    </aside>

    {{-- Section content: the Vue app mounts on #settings-root --}}
    <main class="flex-1 min-w-0 overflow-y-auto">
      <div id="settings-root"
           data-section="{{ $section }}"
           data-bootstrap='@json($bootstrap)'>
        {{-- Vue renders here; this fallback shows only if JS is disabled. --}}
        <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading {{ ucfirst($section) }} settings…</div>
      </div>
    </main>
  </div>

  <script>
    // Mobile settings-nav drawer (parity with the app shell).
    (function () {
      var nav = document.getElementById('settings-nav');
      var bd = document.getElementById('nav-backdrop');
      var open = document.getElementById('open-nav');
      var close = document.getElementById('close-nav');
      function show() { nav.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
      function hide() { nav.classList.add('-translate-x-full'); bd.classList.add('hidden'); }
      open && open.addEventListener('click', show);
      close && close.addEventListener('click', hide);
      bd && bd.addEventListener('click', hide);
    })();
  </script>
</body>
</html>
