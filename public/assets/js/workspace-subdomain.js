/* The tenant subdomain field (docs/features/workspace-subdomain.md, P72).
   ------------------------------------------------------------------
   Shared by both workspace-creation screens. Three jobs:

     1. show the field only when a customer-facing app is on;
     2. check availability as the user types, and say which of the three states they are in;
     3. tell the screen whether the form may be submitted, because both screens gate their own
        Continue button and neither knows this field exists.

   Job 3 is done by dispatching an event rather than by reaching for a button id: the two
   screens disable their button differently, and a script that knew both would be a third
   implementation waiting to disagree with them.
   ------------------------------------------------------------------ */
(function () {
  'use strict';

  var field = document.querySelector('[data-subdomain-field]');

  if (!field) return;

  var input = document.getElementById('ws-subdomain');
  var status = document.getElementById('ws-subdomain-status');
  var checkUrl = field.getAttribute('data-check-url');
  var ROOT = field.getAttribute('data-root') || '';
  var requiredBy = [];

  try { requiredBy = JSON.parse(field.getAttribute('data-required-by') || '[]'); } catch (e) { requiredBy = []; }

  var state = { required: false, value: '', available: null, checking: false };

  /* Whether the form as a whole may go. Broadcast so each screen can fold it into its own
     gate — "required, and not yet known to be available" is the only blocking combination. */
  function announce() {
    var ok = !state.required || state.available === true;

    window.dispatchEvent(new CustomEvent('pb:subdomain-state', {
      detail: { required: state.required, valid: ok, value: state.value }
    }));
  }

  function paint(html, tone) {
    if (!status) return;

    status.innerHTML = html;
    status.className = 'text-[12px] mt-1.5 ' +
      (tone === 'ok' ? 'text-success' : tone === 'bad' ? 'text-danger' : 'text-sub');
  }

  /* ---- which apps are on ----
     Read from the checkboxes the apps partial renders, so this stays true whichever of them
     the screen offers and whatever their order. The locked default app posts a hidden input
     rather than a checkbox, which is why both are collected. */
  function chosenApps() {
    var out = [];

    document.querySelectorAll('input[name="apps[]"]').forEach(function (el) {
      if (el.type === 'hidden' || el.checked) out.push(el.value);
    });

    return out;
  }

  function syncVisibility() {
    var apps = chosenApps();

    state.required = requiredBy.some(function (key) { return apps.indexOf(key) !== -1; });
    field.classList.toggle('hidden', !state.required);

    /* The value is KEPT when the field hides. Somebody who switches Help Center off to read
       the description and back on again should not have to type their subdomain a second
       time — and a hidden empty field is not what the server refuses on, the apps are. */
    if (state.required) check();
    else announce();
  }

  // ---- availability ----
  var timer = null;
  var seq = 0;

  function check() {
    var raw = (input && input.value) || '';

    if (timer) clearTimeout(timer);

    if (raw.trim() === '') {
      state.value = '';
      state.available = null;
      paint('Your customer-facing pages will live at <span class="font-medium text-ink">yourname.' +
        esc(ROOT) + '</span>', 'neutral');
      announce();

      return;
    }

    state.available = null;
    announce();
    paint('Checking availability…', 'neutral');

    /* Debounced, and every answer carries its sequence number.
       Without the sequence a slow response for `acm` can arrive after a fast one for `acme`
       and repaint the field with a verdict about a name the user has already finished typing. */
    var mine = ++seq;

    timer = setTimeout(function () {
      fetch(checkUrl + '?subdomain=' + encodeURIComponent(raw), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (mine !== seq || !data) return;

          state.value = data.subdomain || '';
          state.available = !!data.available;

          if (data.available) {
            paint('&#10003; <span class="font-medium">' + esc(data.subdomain) + '</span> is available &middot; ' +
              '<span class="text-sub">' + esc(data.url || '') + '/help</span>', 'ok');
          } else if (data.reason) {
            paint('&#10005; ' + esc(data.reason), 'bad');
          } else {
            paint('&#10005; <span class="font-medium">' + esc(data.subdomain) + '</span> is already taken', 'bad');
          }

          announce();
        })
        .catch(function () {
          /* The network, not the name. Left UNKNOWN rather than shown as available: the form
             still submits and the server still checks, and claiming a name is free because we
             could not ask is the one wrong answer here. */
          if (mine !== seq) return;

          state.available = null;
          paint('Could not check availability — we will confirm when you continue.', 'neutral');
          announce();
        });
    }, 300);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = String(s);

    return d.innerHTML;
  }

  if (input) {
    input.addEventListener('input', check);
    // Normalised on blur so what they see is what will be stored — the server does the same.
    input.addEventListener('blur', function () {
      if (state.value) input.value = state.value;
    });
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'apps[]') syncVisibility();
  });

  syncVisibility();
})();
