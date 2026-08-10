<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $project['name'] }} — {{ $workspace->name }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">
  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 overflow-y-auto">
    {{-- Project context bar --}}
    <div class="flex items-center gap-2 px-5 sm:px-8 h-11 border-b border-line">
      <a href="{{ route('projects.index') }}" class="flex items-center gap-2 text-sub hover:text-ink text-[13px]">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Projects
      </a>
      <span class="text-faint">/</span>
      <span class="font-medium text-[13px]">{{ $project['identifier'] }}</span>
      <div class="ml-auto flex items-center gap-2">
        @if ($canManage)
          <a href="{{ $project['settings_url'] }}" class="h-8 px-3 grid place-items-center rounded-md border border-line text-[13px] text-ink hover:bg-hover">Project settings</a>
        @endif
      </div>
    </div>

    <div class="h-36 w-full" style="background: {{ $project['cover_url'] ? 'center/cover url('.e($project['cover_url']).')' : $project['cover_gradient'] }}"></div>
    <div class="max-w-[900px] mx-auto px-5 sm:px-8">
      <div class="flex items-center gap-3 -mt-6">
        <span class="h-14 w-14 rounded-xl bg-white border border-line grid place-items-center text-[26px] shadow-sm">{{ $project['emoji'] ?: '📁' }}</span>
        <div class="pt-6">
          <h1 class="text-[22px] font-bold text-head">{{ $project['name'] }}</h1>
          <div class="text-[13px] text-sub">{{ $project['identifier'] }} · <span class="capitalize">{{ $project['visibility'] }}</span></div>
        </div>
      </div>

      @if ($project['description'])
        <p class="text-[14px] text-sub mt-4 max-w-2xl">{{ $project['description'] }}</p>
      @endif

      <div class="mt-8 border border-dashed border-stroke rounded-xl px-6 py-16 text-center">
        <div class="text-[15px] font-semibold text-head">Your project is ready</div>
        <p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Work items, cycles, modules, and pages arrive in a later phase. Configure states, labels, members, and features from Project settings.</p>
        @if ($canManage)
          <a href="{{ $project['settings_url'] }}" class="inline-block mt-4 h-9 px-4 leading-9 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Open project settings</a>
        @endif
      </div>
      <div class="h-10"></div>
    </div>
    </main>
  </div>
</body>
</html>
