{{-- The Help Center's page shell (docs/features/help-center.md §13).

     Four screens share it — Overview, Conversations, Inboxes and a Space's views — and they
     differ only in the panel on the right. Copying the document head into each of them, as the
     Wiki's screens do, would be four places to change the day an asset is added.

     The first-run wizard deliberately does NOT extend this: §1 asks for onboarding to be shown
     "instead of displaying an empty Help Desk interface", and a wizard framed by the navigation
     it has not earned yet is exactly that empty interface with a form on top. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>@yield('title', 'Help Center') — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  @stack('scripts')
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  {{-- The rail, then the Help Center's OWN navigation — and nothing else (P4).

       Deliberately `app-rail` rather than `app-sidebar`: the latter carries the Workspace and
       Projects panel, and the Project navigation must never appear inside this module. The
       Help Center's nav replaces it rather than sitting beneath it, so a Help Desk screen is
       Main App Navigation + Help Desk Navigation, full stop. --}}
  <div class="flex flex-1 min-h-0">
    @include('partials.app-rail')
    @include('partials.help-center-nav')

    <main class="flex-1 min-w-0 overflow-y-auto">
      @yield('content')
    </main>
  </div>
</body>
</html>
