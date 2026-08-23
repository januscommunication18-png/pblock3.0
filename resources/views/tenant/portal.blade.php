{{-- A tenant's customer-facing page (docs/features/workspace-subdomain.md, P72).

     Deliberately plain. This is the proof that the hostname resolved to the right workspace and
     that the product is switched on — not a customer portal, which nothing has specified. When
     the real one is built it replaces this view and the routing underneath it does not change,
     which is the point of separating them. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $product }} — {{ $workspace->name }}</title>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}">
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}">
</head>
<body class="bg-[#f9fafb]">
  <main class="min-h-screen grid place-items-center px-6">
    <div class="w-full max-w-md text-center">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-faint">{{ $product }}</div>
      <h1 class="text-[24px] font-semibold text-head mt-2">{{ $workspace->name }}</h1>
      <p class="text-[14px] text-sub mt-2">{{ $blurb }}</p>

      {{-- The host, echoed back. Reading it here is how somebody testing knows the request was
           resolved from the Host header rather than from a session or a guess. --}}
      <p class="text-[12px] text-faint mt-6 _moretogether-break">
        Served from
        <span class="font-medium text-ink">{{ request()->getHost() }}{{ request()->getPort() == 80 || request()->getPort() == 443 ? '' : ':'.request()->getPort() }}</span>
      </p>
    </div>
  </main>
</body>
</html>
