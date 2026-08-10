<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Projects — {{ $workspace->name }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/index.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 overflow-y-auto">
      <div id="settings-root" data-bootstrap='@json($bootstrap)'>
        <div class="max-w-[1100px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading projects…</div>
      </div>
    </main>
  </div>
</body>
</html>
