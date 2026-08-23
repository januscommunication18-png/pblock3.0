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

  /* The Echo CONSTRUCTOR, wherever the bundle put it.
     ------------------------------------------------------------------
     `echo.iife.js` exposes a MODULE NAMESPACE — `{Channel, Connector, EventFormatter, default}`
     — so `window.Echo` is an object and `new window.Echo(...)` throws "not a constructor". The
     connect() below is wrapped in a try/catch that turns any failure into "no socket", so this
     did not break the page; it silently disabled every real-time feature in the application,
     including the topbar Inbox badge below, and left them looking merely stale.

     Both spellings are accepted because which one you get depends on how the vendored file was
     built, and a checkout with the other shape must not be a dead socket again. */
  function echoConstructor() {
    var mod = window.Echo;

    if (typeof mod === 'function') return mod;
    if (mod && typeof mod.default === 'function') return mod.default;
    if (mod && typeof mod.Echo === 'function') return mod.Echo;

    return null;
  }

  function connect() {
    if (!cfg || !cfg.key || !cfg.tenantId || !cfg.userId) return null;
    if (typeof window.Pusher === 'undefined') return null;

    var EchoCtor = echoConstructor();

    if (!EchoCtor) return null;

    var echo = new EchoCtor({
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

  /* ---- Help Center: one Space's live ticket stream (help-center.md, P67) ----
     A SPACE channel, not the per-user one above: a new customer reply concerns whoever has
     that Inbox open, which is usually several people and not necessarily the assignee.

     `spaceIds` is a list because the cross-Space queue shows several at once; each is
     subscribed separately so leaving one Space does not silence the rest.

     Returns an unsubscribe function, and returns a NO-OP one when there is no socket — a
     caller must be able to write `var stop = PB.onSpaceTickets(...)` and call `stop()` in
     beforeUnmount without first asking whether Reverb is running. */
  var SPACE_EVENTS = [
    'ticket.created', 'ticket.updated', 'ticket.reply.received',
    'ticket.assigned', 'ticket.status.changed', 'ticket.unread.updated'
  ];

  function onSpaceTickets(spaceIds, handler) {
    var noop = function () {};

    if (!echo || !cfg || typeof handler !== 'function') return noop;

    var names = (spaceIds || []).map(function (id) {
      return 'tenant.' + cfg.tenantId + '.help-center.space.' + id;
    });

    names.forEach(function (name) {
      var ch = echo.private(name);

      SPACE_EVENTS.forEach(function (evt) {
        // The leading dot is what tells Echo this is a broadcastAs() name rather than a PHP
        // class — without it nothing ever fires and the screen looks simply broken.
        ch.listen('.' + evt, function (payload) {
          try { handler(payload); } catch (e) { /* one bad handler must not kill the socket */ }
        });
      });
    });

    return function () {
      names.forEach(function (name) {
        try { echo.leave(name); } catch (e) { /* already gone */ }
      });
    };
  }

  var echo = null;
  try { echo = connect(); } catch (e) { echo = null; }

  window.PB = window.PB || {};
  window.PB.realtime = echo;
  window.PB.onInbox = onInbox;
  window.PB.onSpaceTickets = onSpaceTickets;

  /* This file is DEFERRED and the screens are not, so a screen's mounted() can run before the
     socket exists — which is exactly what happened: the Space channel was never subscribed and
     the Inbox looked simply inert. Screens try immediately and listen for this as the fallback,
     so neither order is the wrong one. */
  try {
    window.dispatchEvent(new CustomEvent('pb:realtime-ready'));
  } catch (e) { /* an old browser without CustomEvent still gets the immediate path */ }
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
