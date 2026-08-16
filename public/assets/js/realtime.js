/* Project Block — the websocket connection (inbox §27).
   ------------------------------------------------------------------
   One Echo instance for the whole app, subscribed to the signed-in user's own private channel
   inside the active workspace. Screens do not connect for themselves; they ask to be told:

       PB.onInbox(function (payload) { ... })

   Everything here degrades. Reverb not running, the vendored client missing, the workspace not
   resolved — each leaves `PB.realtime` null and every screen working exactly as it did before,
   because the Inbox is loaded over HTTP and the socket only ever ADDS to what is on screen. A
   notification system that breaks the page when its transport is down is worse than one that
   is occasionally a refresh behind.
   ------------------------------------------------------------------ */
(function () {
  'use strict';

  var cfg = window.PB_REALTIME || null;
  var listeners = [];

  /** Ask to hear about new Inbox notifications. Safe to call whether or not a socket exists. */
  function onInbox(handler) {
    if (typeof handler === 'function') listeners.push(handler);
  }

  function emit(payload) {
    listeners.forEach(function (fn) {
      try { fn(payload); } catch (e) { /* one bad listener must not stop the others */ }
    });
  }

  function connect() {
    if (!cfg || !cfg.key || !cfg.tenantId || !cfg.userId) return null;
    if (typeof window.Echo === 'undefined' || typeof window.Pusher === 'undefined') return null;

    window.Pusher = window.Pusher;

    var echo = new window.Echo({
      broadcaster: 'reverb',
      key: cfg.key,
      wsHost: cfg.host,
      wsPort: cfg.port,
      wssPort: cfg.port,
      forceTLS: cfg.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      // Laravel's own auth endpoint, which runs routes/channels.php — so who may listen is
      // decided by the same code that decides who may read (§12).
      authEndpoint: '/broadcasting/auth',
      auth: {
        headers: {
          'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || ''
        }
      }
    });

    echo.private('tenant.' + cfg.tenantId + '.user.' + cfg.userId)
      .listen('.inbox.created', function (payload) { emit(payload); });

    return echo;
  }

  var echo = null;
  try { echo = connect(); } catch (e) { echo = null; }

  window.PB = window.PB || {};
  window.PB.realtime = echo;
  window.PB.onInbox = onInbox;
})();

/* The topbar badge, kept current wherever you are (§26/§27).
   Lives here rather than on the Inbox screen because the badge is in the shared chrome: an
   assignment that arrives while you are reading a work item should still show up. */
(function () {
  'use strict';

  if (!window.PB || typeof window.PB.onInbox !== 'function') return;

  window.PB.onInbox(function (payload) {
    var total = payload && payload.counts ? payload.counts.all : null;
    if (total === null || total === undefined) return;

    Array.prototype.forEach.call(document.querySelectorAll('[data-inbox-count]'), function (el) {
      el.textContent = total;
      el.classList.toggle('hidden', !total);
    });
  });
})();
