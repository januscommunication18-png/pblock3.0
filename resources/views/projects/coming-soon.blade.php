<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $label }} — {{ $project->name }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-y-auto">
      @include('partials.project-tabs')

      {{-- Requirements §12: name the feature and say Coming Soon. Never present an
           unfinished tab as production-ready, and never dead-end the navigation. --}}
      <div class="flex-1 flex flex-col items-center justify-center text-center px-6 py-16">
        <span class="h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <h1 class="text-[18px] font-bold text-head mt-4">{{ $label }}</h1>
        <p class="text-[13px] text-sub mt-1.5 max-w-sm">
          {{ $label }} is coming soon. This phase delivers Work items; the rest of the project
          workspace is on the way.
        </p>
        <a href="{{ route('projects.work-items', $project) }}"
           class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
          Go to Work items
        </a>
      </div>
    </main>
  </div>
</body>
</html>
