<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Conversations — Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/help-desk/conversations.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div class="h-12 shrink-0 border-b border-line flex items-center gap-3 px-5 sm:px-8">
        <span class="text-[13px] font-medium text-ink">Conversations</span>
        <span class="text-[12px] text-faint">{{ $paginator->total() }} total</span>
      </div>

      <div class="flex-1 min-h-0 overflow-y-auto">
        <div id="help-desk-conversations-root" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
        </div>

        {{-- Paging is server-side, so it stays outside the mounted component: the list is a
             page of rows from a URL, and a component that re-fetched them would be a second
             way of asking the same question. --}}
        @if ($paginator->hasPages())
          <div class="max-w-[1080px] mx-auto px-5 sm:px-8 pb-8 flex items-center justify-between">
            @if ($paginator->previousPageUrl())
              <a href="{{ $paginator->previousPageUrl() }}"
                 class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Newer</a>
            @else
              <span></span>
            @endif
            @if ($paginator->nextPageUrl())
              <a href="{{ $paginator->nextPageUrl() }}"
                 class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Older</a>
            @endif
          </div>
        @endif
      </div>
    </main>
  </div>

</body>
</html>
