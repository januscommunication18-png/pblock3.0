{{-- The websocket client (inbox §27). Included by every authenticated app shell, because the
     Inbox badge lives in the topbar and so must update wherever you are — not only on /inbox.

     `PB_REALTIME` is the connection's whole configuration, resolved server-side: the workspace
     decides the channel, so a page rendered for one workspace can never listen on another's.
     Nothing here is secret — the app KEY is public by design; the private channel is guarded
     by routes/channels.php, which re-checks membership on every subscribe. --}}
@auth
  @php($__rtWs = optional(auth()->user())->current_workspace_id)
  @if ($__rtWs && config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key'))
    <script>
      window.PB_REALTIME = {
        key: @json(config('broadcasting.connections.reverb.key')),
        host: @json(config('broadcasting.connections.reverb.options.host') ?: request()->getHost()),
        port: @json((int) (config('broadcasting.connections.reverb.options.port') ?: 8080)),
        scheme: @json(config('broadcasting.connections.reverb.options.scheme') ?: 'http'),
        tenantId: @json($__rtWs),
        userId: @json((int) auth()->id())
      };
    </script>
    <script src="{{ pb_asset('assets/vendor/echo/pusher.min.js') }}"></script>
    <script src="{{ pb_asset('assets/vendor/echo/echo.iife.js') }}"></script>
    <script defer src="{{ pb_asset('assets/js/realtime.js') }}"></script>
  @endif
@endauth
