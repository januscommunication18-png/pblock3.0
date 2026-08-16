{{-- Session idle-timeout guard (docs/features/session-timeout.md).

     Appended to every authenticated HTML page by App\Http\Middleware\InjectSessionGuard —
     never `@include`d, because this application has ~16 separate full-page templates and a
     page that quietly forgets to warn people looks identical to one that does.

     PB_SESSION carries the server's arithmetic so the browser never has to guess: nothing here
     is a secret, it is the same window the signed-in person is already living under. --}}
@auth
  @php
    $__session = app(\App\Services\SessionTimeout::class)->payload(request()) + [
        'statusUrl' => route('session.status'),
        'extendUrl' => route('session.extend'),
        'signinUrl' => route('signin'),
        'expiredUrl' => route('session.expired'),
        'logoutUrl' => route('logout'),
    ];
  @endphp
  <script>
    window.PB_SESSION = @json($__session);
  </script>
  <script defer src="{{ pb_asset('assets/js/session-guard.js') }}"></script>
@endauth
