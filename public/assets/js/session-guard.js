/* Session idle-timeout guard (docs/features/session-timeout.md).
   ------------------------------------------------------------------
   Injected on every authenticated page by App\Http\Middleware\InjectSessionGuard. Four jobs:

     1. Count down to the deadline the server stated, and warn before it arrives.
     2. Treat real activity as activity — throttled, because the timer needs to know somebody
        is present, not how fast they type.
     3. Turn a 401 from ANY request into "your session has expired", never a raw status line.
     4. Keep what was being typed, so an hour of writing does not evaporate with the session.

   NO DEPENDENCIES ON PURPOSE. Not Vue, not the PB runtime, not a stylesheet. This runs on
   every page in the application including ones that load neither, and its whole value is
   being there at the moment everything else has stopped working. Styling is inline for the
   same reason — a Tailwind class that was never compiled fails silently, which for a modal
   nobody can see is indistinguishable from having no modal at all.
   ------------------------------------------------------------------ */
(function () {
  'use strict';

  var CFG = window.PB_SESSION;
  if (!CFG || !CFG.statusUrl) return;

  // Shared across tabs. A session belongs to the browser, not to one page of it: without this,
  // working in one tab lets an idle second tab count down to a warning that is simply wrong.
  var DEADLINE_KEY = 'pb.session.deadline';
  var DRAFT_PREFIX = 'pb.draft.';

  var deadline = Date.now() + (CFG.remaining || 0) * 1000;
  var warnAt = (CFG.warn_at || 300) * 1000;
  var pingEvery = (CFG.ping_seconds || 60) * 1000;
  var lastPing = Date.now();
  var state = 'active'; // active | warning | expired
  var ticker = null;

  function store(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }
  function read(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
  function drop(key) { try { localStorage.removeItem(key); } catch (e) {} }

  function setDeadline(ms) {
    deadline = ms;
    store(DEADLINE_KEY, String(ms));
  }

  function remaining() { return Math.max(0, deadline - Date.now()); }

  function csrf() {
    var m = document.querySelector('meta[name=csrf-token]');
    return m ? m.content : '';
  }

  // ---- unsaved work ---------------------------------------------------------------------
  /*
   * Snapshot what is on screen at the moment the session dies (SES-009).
   *
   * Best-effort by contract: every part of it is wrapped, because the one thing worse than
   * losing a draft is a draft-saver that throws and takes the "sign in again" button with it.
   *
   * Passwords are never captured. Neither is anything empty — a page of blank fields restored
   * over a fresh form would overwrite real values with nothing.
   */
  function snapshot() {
    try {
      var fields = [];

      Array.prototype.forEach.call(
        document.querySelectorAll('input, textarea'),
        function (el, i) {
          var type = (el.type || 'text').toLowerCase();
          if (type === 'password' || type === 'hidden' || type === 'file') return;
          if (type === 'checkbox' || type === 'radio') return;
          if (!el.value || !String(el.value).trim()) return;
          if (el.name === '_token') return;

          fields.push({ k: el.name || el.id || ('idx:' + i), v: el.value });
        }
      );

      // Rich text lives in Jodit's contenteditable, not in the textarea it replaced, so the
      // loop above cannot see it — and a long description is exactly what is worth keeping.
      var editors = [];
      Array.prototype.forEach.call(document.querySelectorAll('.jodit-wysiwyg'), function (el) {
        var html = el.innerHTML || '';
        if (html.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '').trim() !== '') editors.push(html);
      });

      if (!fields.length && !editors.length) return;

      store(DRAFT_PREFIX + location.pathname, JSON.stringify({
        at: Date.now(), fields: fields, editors: editors
      }));
    } catch (e) { /* a lost draft must never cost the sign-in path */ }
  }

  function readDraft() {
    try {
      var raw = read(DRAFT_PREFIX + location.pathname);
      if (!raw) return null;

      var d = JSON.parse(raw);
      var ttl = (CFG.draft_ttl_hours || 24) * 3600 * 1000;

      // Stale drafts are worse than none: offering week-old text back next to a form somebody
      // has since filled in correctly invites them to overwrite the good version.
      if (!d || !d.at || (Date.now() - d.at) > ttl) { drop(DRAFT_PREFIX + location.pathname); return null; }

      return d;
    } catch (e) { return null; }
  }

  function restoreDraft(d) {
    try {
      var inputs = document.querySelectorAll('input, textarea');

      d.fields.forEach(function (f) {
        var el = null;

        if (f.k.indexOf('idx:') === 0) {
          el = inputs[parseInt(f.k.slice(4), 10)];
        } else {
          el = document.querySelector('[name="' + f.k + '"]') || document.getElementById(f.k);
        }
        if (!el) return;

        el.value = f.v;
        // Both events, because half this application is Vue (which listens for `input`) and
        // half is plain forms with `change` handlers.
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });

      var areas = document.querySelectorAll('.jodit-wysiwyg');
      (d.editors || []).forEach(function (html, i) {
        var area = areas[i];
        if (!area) return;

        // Prefer Jodit's own setter — it parses the value and fires `change`, which is what
        // syncs the Vue model behind the editor. Writing innerHTML only updates the pixels.
        var container = area.closest ? area.closest('.jodit-container') : null;
        if (container && container.component) {
          try { container.component.value = html; return; } catch (e) { /* fall through */ }
        }

        area.innerHTML = html;
        area.dispatchEvent(new Event('input', { bubbles: true }));
      });
    } catch (e) { /* partial restore beats none */ }
  }

  // ---- modal ----------------------------------------------------------------------------
  var S = {
    overlay: 'position:fixed;inset:0;z-index:2147483000;background:rgba(15,15,16,.55);' +
      'display:flex;align-items:center;justify-content:center;padding:20px;' +
      'font-family:Inter,system-ui,-apple-system,sans-serif;',
    card: 'background:#fff;border-radius:14px;max-width:440px;width:100%;' +
      'box-shadow:0 20px 50px rgba(0,0,0,.25);padding:26px 28px;',
    title: 'font-size:17px;font-weight:700;color:#0f0f10;margin:0 0 8px;',
    body: 'font-size:14px;line-height:1.55;color:#4b5563;margin:0 0 22px;',
    row: 'display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;',
    primary: 'height:38px;padding:0 18px;border-radius:8px;border:0;cursor:pointer;' +
      'background:#1b5f8a;color:#fff;font-size:13px;font-weight:600;',
    ghost: 'height:38px;padding:0 18px;border-radius:8px;cursor:pointer;background:#fff;' +
      'border:1px solid #d1d5db;color:#23272f;font-size:13px;font-weight:600;'
  };

  var host = null;

  function closeModal() { if (host) { host.remove(); host = null; } }

  function showModal(opts) {
    closeModal();

    host = document.createElement('div');
    host.setAttribute('style', S.overlay);
    host.setAttribute('role', 'alertdialog');
    host.setAttribute('aria-modal', 'true');

    var card = document.createElement('div');
    card.setAttribute('style', S.card);

    var h = document.createElement('p');
    h.setAttribute('style', S.title);
    h.textContent = opts.title;

    var p = document.createElement('p');
    p.setAttribute('style', S.body);
    p.textContent = opts.body;

    var row = document.createElement('div');
    row.setAttribute('style', S.row);

    (opts.buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('style', b.primary ? S.primary : S.ghost);
      btn.textContent = b.label;
      btn.addEventListener('click', b.onClick);
      row.appendChild(btn);
    });

    card.appendChild(h); card.appendChild(p); card.appendChild(row);
    host.appendChild(card);
    document.body.appendChild(host);

    // Focus the action, so the keyboard and a screen reader both land on the way out.
    var first = row.querySelector('button');
    if (first) first.focus();

    return { body: p };
  }

  // ---- the three states -----------------------------------------------------------------
  function phrase(ms) {
    var total = Math.ceil(ms / 1000);
    var m = Math.floor(total / 60);
    var s = total % 60;

    if (m <= 0) return s === 1 ? '1 second' : s + ' seconds';
    if (s === 0) return m === 1 ? '1 minute' : m + ' minutes';

    return m + ' min ' + s + ' sec';
  }

  var warningBody = null;

  function showWarning() {
    if (state === 'warning' || state === 'expired') return;
    state = 'warning';

    var ui = showModal({
      title: 'Your session is about to expire',
      body: 'Your session will expire in ' + phrase(remaining()) + ' due to inactivity.',
      buttons: [
        { label: 'Sign Out', onClick: signOut },
        { label: 'Stay Signed In', primary: true, onClick: staySignedIn }
      ]
    });

    warningBody = ui.body;
  }

  function expire(fromServer) {
    if (state === 'expired') return;
    state = 'expired';
    warningBody = null;

    snapshot();
    setDeadline(Date.now());
    if (!fromServer) store('pb.session.expired', String(Date.now()));

    showModal({
      title: 'Your session has expired',
      body: 'For your security, you were signed out because your session was inactive. ' +
        'Sign in again to continue.',
      // One button. There is nothing else to do here, and an escape hatch back to a page whose
      // every action will now fail is a worse outcome than no escape hatch.
      buttons: [{ label: 'Sign In Again', primary: true, onClick: toSignIn }]
    });
  }

  function toSignIn() {
    // Through /session/expired, NOT straight to /signin.
    //
    // The browser can be certain the session is over while the server still holds it — a slept
    // laptop, a dropped ping, a skewed clock. /signin is a guest route, so in exactly that case
    // a still-authenticated visitor is bounced off it and never sees a login screen. The
    // endpoint ends the session first, so this works from either side of the timeout.
    //
    // The page being left is the page to come back to (SES-008), sent as a parameter because by
    // now there may be no session left to remember it in.
    var next = location.pathname + location.search;
    location.href = CFG.expiredUrl + '?next=' + encodeURIComponent(next);
  }

  function signOut() {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = CFG.logoutUrl;
    form.style.display = 'none';

    var t = document.createElement('input');
    t.type = 'hidden'; t.name = '_token'; t.value = csrf();

    form.appendChild(t);
    document.body.appendChild(form);
    form.submit();
  }

  async function staySignedIn() {
    closeModal();
    state = 'active';
    warningBody = null;

    var ok = await ping(true);

    // The click itself is not proof the session survived — it may already have lapsed on the
    // server while the modal sat there. Only the server's answer decides.
    if (!ok) expire(true);
  }

  /** Tell the server somebody is here. Returns false only when the session is already gone. */
  async function ping(force) {
    if (!force && Date.now() - lastPing < pingEvery) return true;
    lastPing = Date.now();

    try {
      var res = await window.__pbFetch(CFG.extendUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
      });

      if (res.status === 401 || res.status === 419) return false;
      if (!res.ok) return true; // A blip is not an expiry — keep the local clock running.

      var data = await res.json();
      setDeadline(Date.now() + (data.remaining || 0) * 1000);

      return true;
    } catch (e) {
      // Offline. The local countdown carries on, and the next real request settles it.
      return true;
    }
  }

  function tick() {
    var left = remaining();

    if (left <= 0) { expire(false); return; }

    if (left <= warnAt) {
      showWarning();
      if (warningBody) {
        warningBody.textContent = 'Your session will expire in ' + phrase(left) + ' due to inactivity.';
      }
    }
  }

  // ---- activity -------------------------------------------------------------------------
  function onActivity() {
    // NOT while the warning is up. Reaching for the mouse to click "Stay Signed In" would
    // otherwise silently extend the session and dismiss nothing — leaving a modal on screen
    // that no longer means anything, and teaching people the warning is noise.
    if (state !== 'active') return;

    ping(false);
  }

  ['mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(function (evt) {
    document.addEventListener(evt, onActivity, { passive: true, capture: true });
  });

  // Coming back to a backgrounded tab is the moment the local clock is least trustworthy —
  // timers are throttled while hidden, and the machine may have been asleep. Re-ask.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible' || state === 'expired') return;
    resync();
  });

  async function resync() {
    try {
      var res = await window.__pbFetch(CFG.statusUrl, { headers: { Accept: 'application/json' } });
      if (res.status === 401 || res.status === 419) { expire(true); return; }
      if (!res.ok) return;

      var data = await res.json();
      setDeadline(Date.now() + (data.remaining || 0) * 1000);

      // The window may have been widened or narrowed in Settings since this page loaded.
      warnAt = (data.warn_at || warnAt / 1000) * 1000;

      if (remaining() > warnAt && state === 'warning') { closeModal(); state = 'active'; }
    } catch (e) { /* offline; the countdown continues */ }
  }

  // Another tab moved the deadline, or died.
  window.addEventListener('storage', function (e) {
    if (e.key === DEADLINE_KEY && e.newValue) {
      var ms = parseInt(e.newValue, 10);
      if (!isNaN(ms) && ms > deadline) {
        deadline = ms;
        if (state === 'warning' && remaining() > warnAt) { closeModal(); state = 'active'; }
      }
    }
    if (e.key === 'pb.session.expired' && e.newValue) expire(true);
  });

  // ---- 401 interception -----------------------------------------------------------------
  /*
   * Every request in the application, not just the ones that remembered to ask.
   *
   * Wrapping fetch is the only way to cover PB.api, the work-item screens, drafts, the inbox
   * and whatever is written next from ONE place. The alternative — a check in each caller —
   * is a rule that holds until the first person who does not know about it, and the symptom
   * is the raw "401 Unauthorized" this feature exists to remove.
   *
   * The original is kept on window.__pbFetch so this guard's own calls cannot recurse.
   */
  window.__pbFetch = window.fetch.bind(window);

  window.fetch = function () {
    var url = String((arguments[0] && arguments[0].url) || arguments[0] || '');

    return window.__pbFetch.apply(window, arguments).then(function (res) {
      var ours = url.indexOf(CFG.extendUrl) !== -1 || url.indexOf(CFG.statusUrl) !== -1 ||
        url.indexOf(CFG.expiredUrl) !== -1;

      // 419 is CSRF, which after a timeout means the same thing to a person: the session they
      // had is not the session they have.
      if (!ours && (res.status === 401 || res.status === 419)) expire(true);

      return res;
    });
  };

  // ---- recovered content ----------------------------------------------------------------
  function offerDraft() {
    var d = readDraft();
    if (!d) return;

    var bar = document.createElement('div');
    bar.setAttribute('style',
      'position:fixed;left:50%;transform:translateX(-50%);bottom:20px;z-index:2147482000;' +
      'background:#fff;border:1px solid #d1d5db;border-radius:12px;padding:12px 14px;' +
      'box-shadow:0 12px 30px rgba(0,0,0,.16);display:flex;align-items:center;gap:12px;' +
      'font-family:Inter,system-ui,sans-serif;font-size:13px;color:#23272f;max-width:min(560px,92vw);');

    var text = document.createElement('span');
    text.style.flex = '1';
    text.textContent = 'We saved what you were typing before your session expired.';

    var restore = document.createElement('button');
    restore.type = 'button';
    restore.setAttribute('style', S.primary + 'height:32px;padding:0 14px;');
    restore.textContent = 'Restore';
    restore.addEventListener('click', function () {
      restoreDraft(d);
      drop(DRAFT_PREFIX + location.pathname);
      bar.remove();
    });

    var discard = document.createElement('button');
    discard.type = 'button';
    discard.setAttribute('style', S.ghost + 'height:32px;padding:0 14px;');
    discard.textContent = 'Discard';
    discard.addEventListener('click', function () {
      drop(DRAFT_PREFIX + location.pathname);
      bar.remove();
    });

    bar.appendChild(text); bar.appendChild(discard); bar.appendChild(restore);
    document.body.appendChild(bar);
  }

  // ---- start ----------------------------------------------------------------------------
  setDeadline(deadline);
  drop('pb.session.expired');
  ticker = setInterval(tick, 1000);
  window.addEventListener('beforeunload', function () { clearInterval(ticker); });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', offerDraft);
  } else {
    offerDraft();
  }
})();
