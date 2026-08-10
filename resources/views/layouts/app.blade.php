<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>@yield('title', 'Project Block')</title>

  {{-- Project Block app shell — ported from the HTML POC. Plain Tailwind (CDN) + POC tokens. --}}
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">
  @yield('body')
</body>
</html>
