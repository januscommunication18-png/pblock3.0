<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $label }} — {{ $project->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>
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
          {!! pb_icon('clock', 22) !!}
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
