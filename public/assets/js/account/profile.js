/* The account modal — Profile · Preference · Notification · Security (Account §1).
   ------------------------------------------------------------------
   Plain JS, no framework. This lives in the topbar, which sits outside `#settings-root` and so
   has no Vue app around it — the account menu beside it is wired the same way. Booting a
   second Vue root on every page in the app, to render a dialog that is closed almost all of
   the time, would be a lot of machinery for one form.

   State is the server's, not this file's: every write answers with the whole profile, and the
   modal re-renders from that answer. So the avatar in the topbar and the fields in the form
   cannot disagree about what was just saved.
   ------------------------------------------------------------------ */
(function () {
  var modal = document.getElementById('account-modal');
  if (!modal || modal.dataset.wired) return;
  modal.dataset.wired = '1';

  var endpoints = window.PB_ACCOUNT_ENDPOINTS || {};
  var profile = window.PB_ACCOUNT || {};

  var $ = function (sel) { return modal.querySelector(sel); };
  var $$ = function (sel) { return [].slice.call(modal.querySelectorAll(sel)); };

  var file = $('#account-file');
  var footer = $('#account-footer');
  var save = $('#account-save');
  var error = $('#account-error');

  // ---- talking to the server ----------------------------------------------
  // One place that knows about CSRF and about how a Laravel validation error is shaped, so no
  // call site has to remember either.
  function api(url, options) {
    options = options || {};
    var token = document.querySelector('meta[name="csrf-token"]');

    return fetch(url, {
      method: options.method || 'GET',
      headers: Object.assign({
        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }, options.headers || {}),
      body: options.body,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        if (res.ok) return body;

        // 422 carries field errors; anything else carries a message, or nothing useful at all.
        var first = body.errors ? body.errors[Object.keys(body.errors)[0]] : null;
        var error = new Error((first && first[0]) || body.message || 'Something went wrong.');

        // The whole body rides along: the change-password form puts each message under the
        // field it belongs to, and a wrong current password and a too-short new one are not
        // the same field. A single shared message would make the user guess which.
        error.body = body;
        error.status = res.status;

        throw error;
      });
    });
  }

  // ---- rendering ----------------------------------------------------------
  function render() {
    $('#account-first-name').value = profile.first_name || '';
    $('#account-last-name').value = profile.last_name || '';
    $('#account-display-name').value = profile.display_name || '';
    $('#account-email').value = profile.email || '';
    // The RAW text, not rendered HTML — this is a textarea and the column stores what was
    // typed (P74).
    $('#account-signature').value = profile.signature || '';

    // The banner is an uploaded image if there is one, and otherwise the chosen gradient —
    // the same fallback an uncovered project tile uses, so a profile looks deliberate before
    // anyone has uploaded anything.
    var cover = $('#account-cover');
    // `background` shorthand each time, so setting one clears the other — assigning
    // backgroundImage over a previous gradient would leave both in play.
    cover.style.background = profile.cover_url
      ? 'url("' + profile.cover_url + '") center / cover no-repeat'
      : (profile.cover_gradient || '');

    renderSwatches();

    // The avatar falls back to the initial, which is the same placeholder the topbar and every
    // row avatar use — a profile with no photo should look the same here as it does there.
    var avatar = $('#account-avatar');
    avatar.style.backgroundImage = profile.avatar_url ? 'url("' + profile.avatar_url + '")' : '';
    avatar.textContent = profile.avatar_url ? '' : (profile.initial || '?');

    $('[data-account-remove="cover"]').classList.toggle('hidden', !profile.cover_url);
    $('[data-account-remove="avatar"]').classList.toggle('hidden', !profile.avatar_url);
  }

  /**
   * The gradient palette, drawn like the Add Project one: a row of swatches, the active one
   * ringed in white.
   *
   * Built from the server's list rather than a copy here — the same `projects.cover_gradients`
   * the project covers use, so the two palettes cannot drift apart. A swatch is only ever an
   * index into that list; the gradient string itself is validated against it server-side,
   * because this value ends up inside a `style` attribute.
   */
  function renderSwatches() {
    var box = $('#account-swatches');
    var presets = profile.cover_presets || [];

    // Rebuilt only when the palette changes, so clicking a swatch does not discard and recreate
    // the element under the pointer mid-click.
    if (box.childElementCount !== presets.length) {
      box.innerHTML = '';
      presets.forEach(function (gradient, i) {
        var swatch = document.createElement('button');
        swatch.type = 'button';
        swatch.dataset.gradient = String(i);
        swatch.style.background = gradient;
        box.appendChild(swatch);
      });
    }

    [].slice.call(box.children).forEach(function (swatch, i) {
      // Active means "this is what you would see" — so no swatch is ringed while an uploaded
      // image is covering them all.
      var on = !profile.cover_url && presets[i] === profile.cover_gradient;
      swatch.className = 'h-6 w-8 rounded-md ring-2 ring-offset-1 ring-offset-black/10 transition ' +
        (on ? 'ring-white' : 'ring-transparent hover:ring-white/60');
    });
  }

  /**
   * Reflect a save in the chrome outside this dialog.
   *
   * Changing a display name or a photo changes the header chip, and leaving that stale until
   * the next page load makes a successful save look like it did nothing.
   */
  function syncTopbar() {
    // The header chip and the menu's own card show the same three things the dialog edits.
    // Blade renders them correctly on first paint; this is what keeps them right after a save,
    // without a reload.
    [document.getElementById('user-btn'), document.getElementById('user-menu-avatar')]
      .forEach(function (el) {
        if (!el) return;

        if (profile.avatar_url) {
          el.style.backgroundImage = 'url("' + profile.avatar_url + '")';
          el.textContent = '';
        } else {
          el.style.backgroundImage = '';
          el.textContent = profile.initial || '?';
        }
      });

    var cover = document.getElementById('user-menu-cover');
    if (cover) {
      // The shorthand, so setting one clears the other — a gradient assigned over a previous
      // image URL would otherwise leave both in play.
      cover.style.background = profile.cover_url
        ? 'url("' + profile.cover_url + '") center / cover no-repeat'
        : (profile.cover_gradient || '#9ca3af');
    }

    var name = document.getElementById('user-menu-name');
    if (name) name.textContent = profile.name || '';
  }

  function adopt(body) {
    if (body && body.profile) profile = body.profile;
    render();
    syncTopbar();
  }

  // ---- tabs ---------------------------------------------------------------
  function showTab(key) {
    $$('[data-account-tab]').forEach(function (tab) {
      var on = tab.getAttribute('data-account-tab') === key;
      tab.classList.toggle('border-brand', on);
      tab.classList.toggle('text-ink', on);
      tab.classList.toggle('font-semibold', on);
      tab.classList.toggle('border-transparent', !on);
      tab.classList.toggle('text-sub', !on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    $$('[data-account-panel]').forEach(function (panel) {
      panel.classList.toggle('hidden', panel.getAttribute('data-account-panel') !== key);
    });

    // Only Profile has anything to save; the footer would otherwise offer a button that does
    // nothing on three of the four tabs.
    footer.classList.toggle('hidden', key !== 'profile');
    footer.classList.toggle('flex', key === 'profile');
  }

  // ---- open / close -------------------------------------------------------
  function open(tab) {
    error.classList.add('hidden');
    render();
    showTab(tab || 'profile');
    modal.classList.remove('hidden');

    var first = $('#account-first-name');
    if (tab === 'profile' || !tab) setTimeout(function () { first.focus(); }, 0);
  }

  function close() {
    modal.classList.add('hidden');
  }

  // The menu buttons live in the topbar, outside this element — hence a document listener
  // rather than one scoped to the modal.
  document.addEventListener('click', function (e) {
    var opener = e.target.closest && e.target.closest('[data-account-open]');
    if (!opener) return;

    e.preventDefault();
    var menu = document.getElementById('user-menu');
    if (menu) menu.classList.add('hidden');

    open(opener.getAttribute('data-account-open'));
  });

  modal.addEventListener('click', function (e) {
    if (e.target.closest('[data-account-close]')) { close(); return; }

    var tab = e.target.closest('[data-account-tab]');
    if (tab) { showTab(tab.getAttribute('data-account-tab')); return; }

    var upload = e.target.closest('[data-account-upload]');
    if (upload) {
      file.dataset.kind = upload.getAttribute('data-account-upload');
      file.value = '';
      file.click();

      return;
    }

    var remove = e.target.closest('[data-account-remove]');
    if (remove) { removeImage(remove.getAttribute('data-account-remove')); return; }

    var swatch = e.target.closest('[data-gradient]');
    if (swatch) pickGradient((profile.cover_presets || [])[+swatch.dataset.gradient]);
  });

  /**
   * Choose a banner gradient.
   *
   * A gradient and an uploaded image are the SAME slot, not two layers. Picking a gradient
   * therefore removes the uploaded cover, which is what the Add Project modal does too
   * (`coverImage=''` beside the gradient assignment in projects/index.js).
   *
   * The alternative — keeping the file and letting the image win — is worse in both
   * directions: the swatches appear dead while an image is set, and the gradient sitting
   * invisibly underneath comes back the moment the image is removed, from a click the user
   * made long ago. One banner, one source, and the click you make is the thing you see.
   *
   * Saved on the spot rather than on "Save changes": everything on the banner applies
   * immediately, and the button below belongs to the text fields.
   */
  function pickGradient(gradient) {
    if (!gradient) return;

    fail(null);

    // The image goes first. If it is dropped and the gradient then fails, the banner falls
    // back to a gradient anyway; doing it the other way round could leave the image on screen
    // with a different gradient stored behind it.
    var ready = profile.cover_url ? clearImage('cover') : Promise.resolve();

    ready.then(function () {
      var body = new FormData();
      body.append('_method', 'PATCH');
      body.append('cover_gradient', gradient);

      // Sent alongside, because the endpoint saves the whole profile — omitting them would
      // blank the name the user has not touched.
      body.append('first_name', $('#account-first-name').value);
      body.append('last_name', $('#account-last-name').value);
      body.append('display_name', $('#account-display-name').value);
      body.append('signature', $('#account-signature').value);

      return api(endpoints.update, { method: 'POST', body: body });
    }).then(adopt).catch(fail);
  }

  // Escape closes the dialog. The topbar's own handler also closes the MENU on Escape, which
  // is harmless — the menu is already shut by the time this is open.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
  });

  // ---- images -------------------------------------------------------------
  file.addEventListener('change', function () {
    if (!file.files || !file.files[0]) return;

    var chosen = file.files[0];
    var body = new FormData();
    body.append('kind', file.dataset.kind || 'avatar');
    body.append('image', chosen);

    fail(null);
    upload(body, chosen.name).then(adopt).catch(fail);
  });

  /**
   * Upload with a progress bar.
   *
   * XMLHttpRequest rather than fetch, for one reason: `upload.onprogress`. fetch still cannot
   * report request progress, and the Add Project modal fakes it with a timer that counts up on
   * its own — which lies on a slow connection, exactly when the number matters. This one is
   * the real figure.
   */
  function upload(body, name) {
    var box = $('#account-progress');
    var bar = $('#account-progress-bar');
    var swatches = $('#account-swatches');

    function setPct(n) {
      bar.style.width = n + '%';
      $('#account-progress-pct').textContent = n + '%';
    }

    $('#account-progress-name').textContent = name || 'Uploading…';
    setPct(0);
    box.classList.remove('hidden');
    swatches.classList.add('hidden');

    return new Promise(function (resolve, reject) {
      var token = document.querySelector('meta[name="csrf-token"]');
      var xhr = new XMLHttpRequest();

      xhr.open('POST', endpoints.image);
      xhr.setRequestHeader('X-CSRF-TOKEN', token ? token.getAttribute('content') : '');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) setPct(Math.round((e.loaded / e.total) * 100));
      };

      xhr.onload = function () {
        var parsed = {};
        try { parsed = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }

        done();

        if (xhr.status >= 200 && xhr.status < 300) { resolve(parsed); return; }

        var first = parsed.errors ? parsed.errors[Object.keys(parsed.errors)[0]] : null;
        reject(new Error((first && first[0]) || parsed.message || 'That image could not be uploaded.'));
      };

      xhr.onerror = function () { done(); reject(new Error('That image could not be uploaded.')); };

      function done() {
        box.classList.add('hidden');
        swatches.classList.remove('hidden');
      }

      xhr.send(body);
    });
  }

  function removeImage(kind) {
    fail(null);
    clearImage(kind).then(adopt).catch(fail);
  }

  /** Delete one stored image. Shared with the gradient picker, which has to clear the cover. */
  function clearImage(kind) {
    var body = new FormData();
    body.append('kind', kind);
    // Laravel reads DELETE from the method override when the body is multipart.
    body.append('_method', 'DELETE');

    return api(endpoints.imageDestroy, { method: 'POST', body: body });
  }

  // ---- save ---------------------------------------------------------------
  save.addEventListener('click', function () {
    save.disabled = true;
    fail(null);

    var body = new FormData();
    body.append('_method', 'PATCH');
    body.append('first_name', $('#account-first-name').value);
    body.append('last_name', $('#account-last-name').value);
    body.append('display_name', $('#account-display-name').value);
    body.append('signature', $('#account-signature').value);

    api(endpoints.update, { method: 'POST', body: body })
      .then(function (res) { adopt(res); close(); })
      .catch(fail)
      .then(function () { save.disabled = false; });
  });

  // ---- change password (§4) -----------------------------------------------
  var pwForm = $('#account-password-form');

  if (pwForm) {
    // Show/hide, per field. A password you cannot read is a password you mistype, and the
    // three-field form is where that costs the most.
    pwForm.addEventListener('click', function (e) {
      var eye = e.target.closest('[data-account-eye]');
      if (!eye) return;

      var input = document.getElementById(eye.getAttribute('data-account-eye'));
      if (!input) return;

      var shown = input.type === 'text';
      input.type = shown ? 'password' : 'text';
      eye.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
      eye.classList.toggle('text-brand', !shown);
      eye.classList.toggle('text-faint', shown);
    });

    pwForm.addEventListener('submit', function (e) {
      e.preventDefault();
      changePassword();
    });
  }

  function changePassword() {
    var submit = $('#account-password-submit');
    var current = $('#account-current-password');

    submit.disabled = true;
    pwErrors(null);

    var body = new FormData();
    body.append('_method', 'PATCH');
    if (current) body.append('current_password', current.value);
    body.append('password', $('#account-new-password').value);
    body.append('password_confirmation', $('#account-confirm-password').value);

    api(endpoints.password, { method: 'POST', body: body })
      .then(function (res) {
        // The session is already gone by the time this arrives — the server ended it. Say so,
        // then send them to sign in. The pause is so the message is read rather than glimpsed.
        pwForm.innerHTML = '<div class="px-2 py-16 text-center">' +
          '<h3 class="text-[14px] font-semibold text-head">Password changed</h3>' +
          '<p class="mt-1.5 text-[13px] text-sub">' + escapeText(res.message || '') + '</p>' +
          '<p class="mt-4 text-[12px] text-faint">Taking you to sign in…</p></div>';

        setTimeout(function () { window.location.href = res.redirect || '/'; }, 1800);
      })
      .catch(function (err) {
        submit.disabled = false;
        pwErrors(err);
      });
  }

  /**
   * Field errors under the field they belong to.
   *
   * `api()` throws a single Error, so the raw response is carried on it — a wrong current
   * password and a too-short new one are different fields, and one shared message at the
   * bottom would make the user guess which.
   */
  function pwErrors(err) {
    $$('[data-account-pw-error]').forEach(function (slot) {
      var field = slot.getAttribute('data-account-pw-error');
      var messages = err && err.body && err.body.errors ? err.body.errors[field] : null;

      slot.textContent = messages && messages.length ? messages[0] : '';
      slot.classList.toggle('hidden', !(messages && messages.length));
    });

    if (err && !(err.body && err.body.errors)) $pbToast(err.message);
  }

  function escapeText(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);

    return d.innerHTML;
  }

  function $pbToast(message) {
    if (window.PB && PB.toast) PB.toast(message);
  }

  function fail(e) {
    if (!e) { error.classList.add('hidden'); return; }

    error.textContent = e.message || 'Something went wrong.';
    error.classList.remove('hidden');
  }

  syncTopbar();
})();
