{{-- The Back Office shell (docs/features/backoffice-auth.md).

     Deliberately NOT the customer application's layout. The two are different products with
     different sessions, and a Back Office screen that looks exactly like the app is one an
     administrator can mistake for the app — which is the mistake that ends with platform
     credentials typed into a customer-facing form. The dark header is the difference you notice
     without reading anything. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  {{-- Platform administration should never be indexed, previewed or archived by a crawler. --}}
  <meta name="robots" content="noindex, nofollow, noarchive" />
  <title>@yield('title', 'Back Office') — Project Block</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
</head>
<body class="bg-hover text-ink text-[13px] min-h-screen flex flex-col">

  @auth('backoffice')
    {{-- The Back Office shell (docs/features/backoffice-clients.md, §1).

         Fixed top bar, STICKY sidebar, scrolling main — "the left navigation should remain fixed
         or sticky while the main content area scrolls". Sticky rather than fixed: a fixed rail
         needs the main column to carry a matching left margin, and the two then have to agree
         about a width forever. Sticky keeps them in one flow. --}}
    <div class="min-h-screen flex flex-col">
      <header class="bg-head text-white shrink-0 sticky top-0 z-20">
        <div class="px-5 sm:px-6 h-14 flex items-center gap-3">
          <a href="{{ route('backoffice.dashboard') }}" class="font-semibold tracking-tight">Back Office</a>
          <span class="text-white/30">|</span>
          <span class="text-[12px] text-white/60">Project Block</span>

          <div class="ml-auto flex items-center gap-3">
            <span class="text-[12px] text-white/70">
              {{ auth('backoffice')->user()->name }}
              &middot; {{ \App\Models\BackofficeUser::roleLabel(auth('backoffice')->user()->role) }}
            </span>
          </div>
        </div>
      </header>

      <div class="flex-1 flex min-h-0">
        {{-- Primary navigation (§2). The order is the requirement's own.

             Modules that are not built yet are drawn but NOT linked, with a "Soon" chip — the
             same treatment Help Center Settings gives its Coming Soon page. A nav item that
             navigates to an empty screen is a promise the screen then has to break; one that
             says "soon" is honest and still shows where the product is going. --}}
        <aside class="w-56 shrink-0 border-r border-line bg-white hidden md:flex flex-col">
          <nav class="sticky top-14 px-2 py-4 flex-1">
            @php
                $items = [
                    ['label' => 'Dashboard', 'route' => 'backoffice.dashboard', 'active' => 'backoffice.dashboard'],
                    ['label' => 'Clients', 'route' => 'backoffice.clients.index', 'active' => 'backoffice.clients.*'],
                    ['label' => 'Plans / Packages', 'route' => null, 'active' => null],
                    ['label' => 'Usage', 'route' => null, 'active' => null],
                    ['label' => 'Users', 'route' => 'backoffice.admins.index', 'active' => 'backoffice.admins.*', 'can' => 'manage-backoffice-admins'],
                    ['label' => 'App Settings', 'route' => null, 'active' => null],
                ];
            @endphp

            @foreach ($items as $item)
              @continue(isset($item['can']) && ! auth('backoffice')->user()->can($item['can']))

              @if ($item['route'])
                @php($on = $item['active'] && request()->routeIs($item['active']))
                <a href="{{ route($item['route']) }}"
                   @class(['flex items-center gap-2 px-2 h-9 rounded-md text-[13px]', 'bg-sel text-brand font-medium' => $on, 'text-ink hover:bg-hover' => ! $on])
                   @if ($on) aria-current="page" @endif>
                  {{ $item['label'] }}
                </a>
              @else
                <span class="flex items-center gap-2 px-2 h-9 rounded-md text-[13px] text-faint cursor-not-allowed select-none"
                      title="{{ $item['label'] }} is not built yet">
                  {{ $item['label'] }}
                  <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5">Soon</span>
                </span>
              @endif
            @endforeach

            <div class="mt-4 pt-4 border-t border-line">
              <form method="POST" action="{{ route('backoffice.logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-left flex items-center px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">
                  Logoff
                </button>
              </form>
            </div>
          </nav>
        </aside>

        <main class="flex-1 min-w-0">
          @yield('content')
        </main>
      </div>
    </div>
  @endauth

  {{-- The unauthenticated screens have no shell — they are the gate, not the product. --}}
  @guest('backoffice')
    <main class="flex-1">
      @yield('content')
    </main>
  @endguest

</body>
</html>
