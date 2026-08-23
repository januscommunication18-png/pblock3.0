/* Project Block — Workspace Settings shared Vue runtime + components.
   ------------------------------------------------------------------
   Hybrid Vue-in-Blade (CLAUDE.md §14), no FlyonUI. Loaded on every settings
   page; each section script calls PB.boot('<section>', Component) to mount its
   Vue component on #settings-root, receiving the server `bootstrap` prop.
   Styling uses the shared POC tokens + .pb-* form classes (public/assets).
   Authored with the Options API so components map 1:1 to future .vue SFCs.
   ------------------------------------------------------------------ */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  // ---- fetch helper: JSON in/out, CSRF, throws {status,data} on failure ----
  async function api(url, opts) {
    opts = opts || {};
    var headers = { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' };
    var body = opts.body;
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    var res = await fetch(url, { method: opts.method || 'GET', headers: headers, body: body });
    var data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    if (!res.ok) { var err = new Error('Request failed'); err.status = res.status; err.data = data; throw err; }
    return data;
  }

  // Replace the '__ID__' placeholder in a templated endpoint with a real id.
  function withId(url, id) { return String(url).replace('__ID__', encodeURIComponent(id)); }

  // ---- Avatar fallback colour ----------------------------------------------------------
  /*
   * The background behind someone's initial when they have not uploaded a photo.
   *
   * Everyone used to get the same brand blue (or the same slate, depending which screen you
   * were on), which made a row of five unphotographed people a row of five identical discs —
   * the initial was doing all the work at 10px. A colour per person makes them scannable
   * before you have read anything.
   *
   * Keyed on the user's ID, never their name: the colour has to follow the PERSON, so it is
   * the same in a work item row, a member list and a comment, and renaming yourself does not
   * repaint you. Falls back to hashing the name only when a payload carries no id.
   *
   * Every colour clears 4.5:1 against white text (WCAG AA for small text) — verified, since
   * these are 10px bold and the usual mid-tone palette does not: Tailwind's orange-600,
   * emerald-600 and teal-600 all land near 3.6:1 and were stepped to their 700s.
   */
  var AVATAR_COLORS = [
    '#2563EB', '#DB2777', '#047857', '#7C3AED', '#C2410C', '#0E7490',
    '#C026D3', '#B45309', '#4F46E5', '#E11D48', '#0F766E', '#475569'
  ];

  function avatarColor(person) {
    if (!person) return AVATAR_COLORS[0];

    var key = (person.id !== undefined && person.id !== null && person.id !== '')
      ? String(person.id)
      : String(person.email || person.name || person.label || person.initial || '');

    // A numeric id WALKS the palette instead of being hashed into it.
    //
    // djb2 was the first attempt and it collided badly on short keys: ids 1–12 produced only
    // eight distinct colours, so a team invited one after another — the common case — had
    // people sharing. The remainder is perfect for consecutive integers, which is exactly
    // what user ids are.
    if (/^\d+$/.test(key)) return AVATAR_COLORS[parseInt(key, 10) % AVATAR_COLORS.length];

    // Anything else (a payload with no id) still needs spreading, and djb2 is fine there.
    var hash = 5381;
    for (var i = 0; i < key.length; i++) hash = ((hash * 33) ^ key.charCodeAt(i)) >>> 0;

    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
  }

  // First validation message from a 422 response, else a generic message.
  function firstError(err, fallback) {
    var d = err && err.data;
    if (d && d.errors) { for (var k in d.errors) { return d.errors[k][0]; } }
    if (d && d.message) return d.message;
    return fallback || 'Something went wrong. Please try again.';
  }

  // Field => [messages] map from a 422 response, for inline field validation.
  function fieldErrors(err) {
    return (err && err.data && err.data.errors) ? err.data.errors : {};
  }

  // ---- toast (Tailwind Plus "notifications" style, top-center, stacked) ----
  function toastContainer() {
    var c = document.getElementById('pb-toasts');
    if (!c) {
      c = document.createElement('div');
      c.id = 'pb-toasts';
      c.className = 'fixed top-4 right-4 z-[100] w-full max-w-sm flex flex-col items-end gap-3 pointer-events-none';
      document.body.appendChild(c);
    }
    return c;
  }

  /* toast(message, kind, opts)
     ------------------------------------------------------------------
     `opts` is additive and every field optional, so every existing two-argument call behaves
     exactly as before:

       title   — replaces the fixed "Success"/"Error" heading. Real-time notifications say what
                 happened ("New customer reply received"), which a heading of "Success" does not.
       timeout — milliseconds; the requirement asks for about five seconds where an
                 acknowledgement of your own click only needs three and a half.
       onClick — makes the card itself a control. Used to open the ticket a notification is
                 about, which is the requirement's "clicking the toast should open the ticket". */
  function toast(message, kind, opts) {
    opts = opts || {};
    var isError = kind === 'error';
    var icon = isError
      ? '<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="#ef4444"/><path d="M9 9l6 6M15 9l-6 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>'
      : '<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="#22c55e"/><path d="M8 12l2.5 2.5L16 9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var card = document.createElement('div');
    card.className = 'pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black/5 transition duration-300 ease-out opacity-0 translate-x-2';
    card.innerHTML =
      '<div class="p-4"><div class="flex items-start">' +
      '<div class="shrink-0">' + icon + '</div>' +
      '<div class="ml-3 w-0 flex-1 pt-0.5"><p class="text-[13px] font-semibold text-head" data-t></p><p class="mt-1 text-[13px] text-sub" data-m></p></div>' +
      '<div class="ml-4 flex shrink-0"><button type="button" class="inline-flex rounded-md text-faint hover:text-sub" data-x>' +
      '' + wiIcon('xmark', 18) + '</button></div>' +
      '</div></div>';
    card.querySelector('[data-t]').textContent = opts.title || (isError ? 'Error' : 'Success');
    card.querySelector('[data-m]').textContent = message;
    toastContainer().appendChild(card);
    requestAnimationFrame(function () { card.classList.remove('opacity-0', 'translate-x-2'); });
    var done = false;
    function dismiss() { if (done) return; done = true; card.classList.add('opacity-0', 'translate-x-2'); setTimeout(function () { card.remove(); }, 300); }
    card.querySelector('[data-x]').addEventListener('click', dismiss);

    if (typeof opts.onClick === 'function') {
      card.classList.add('cursor-pointer');
      card.addEventListener('click', function (e) {
        // Not when they meant to dismiss it: the close button lives inside the card, so
        // without this, clicking X would also follow the link.
        if (e.target.closest('[data-x]')) return;
        dismiss();
        opts.onClick();
      });
    }

    setTimeout(dismiss, opts.timeout || 3600);
  }

  // ================= shared components =================
  function registerShared(app) {
    // Feature on/off switch.
    app.component('pb-toggle', {
      props: { modelValue: Boolean, disabled: Boolean },
      emits: ['update:modelValue'],
      template:
        '<button type="button" @click="!disabled && $emit(\'update:modelValue\', !modelValue)"' +
        ' :aria-pressed="String(modelValue)"' +
        ' :class="[\'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0\',' +
        ' modelValue ? \'bg-brand\' : \'bg-stroke\', disabled ? \'opacity-50 cursor-not-allowed\' : \'cursor-pointer\']">' +
        '<span :class="[\'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform\',' +
        ' modelValue ? \'translate-x-[22px]\' : \'translate-x-0.5\']"></span></button>'
    });

    // Preset palette + hex entry (spec §13).
    app.component('pb-color-picker', {
      props: { modelValue: { type: String, default: '#F97316' }, presets: { type: Array, default: function () { return []; } } },
      emits: ['update:modelValue'],
      computed: {
        // A valid #RRGGBB for the native color input (falls back while a hex is mid-typing).
        swatch: function () {
          var v = String(this.modelValue || '').trim();
          return /^#[0-9A-Fa-f]{6}$/.test(v) ? v : '#000000';
        }
      },
      methods: {
        pick: function (c) { this.$emit('update:modelValue', c); },
        onPick: function (e) { this.$emit('update:modelValue', e.target.value.toUpperCase()); },
        onHex: function (e) {
          var v = e.target.value.trim();
          if (v && v[0] !== '#') v = '#' + v;
          this.$emit('update:modelValue', v.toUpperCase());
        },
        same: function (c) { return (this.modelValue || '').toUpperCase() === c.toUpperCase(); }
      },
      template:
        '<div class="flex items-center gap-2 flex-wrap">' +
        '<button v-for="c in presets" :key="c" type="button" @click="pick(c)" :style="{background:c}"' +
        ' :class="[\'h-6 w-6 rounded-full ring-2 ring-offset-1\', same(c) ? \'ring-brand\' : \'ring-transparent hover:ring-line\']"></button>' +
        '<div class="pb-group !h-9" style="width:9.5rem">' +
        '<span class="pb-group__prefix pl-1.5 pr-1 flex items-center">' +
        '<input type="color" class="pb-color-input" :value="swatch" @input="onPick" title="Pick a color" />' +
        '</span>' +
        '<input class="pb-group__field uppercase" :value="modelValue" @input="onHex" maxlength="7" spellcheck="false" /></div></div>'
    });

    // Searchable single-select combobox (Tailwind-style): a trigger button that opens a
    // popover with a search field + filtered option list. Used for long selects (timezone)
    // and any dropdown that benefits from type-ahead.
    app.component('pb-combo', {
      props: {
        // A string in single mode; an array of values when `multiple` is set.
        modelValue: { type: [String, Array], default: '' },
        // options may be plain strings, or {value, label} objects (Base Web style).
        options: { type: Array, default: function () { return []; } },
        placeholder: { type: String, default: 'Select…' },
        invalid: { type: Boolean, default: false },
        dense: { type: Boolean, default: false },
        searchable: { type: Boolean, default: true },
        // Multi-select: picking toggles, the menu stays open, and the button summarises
        // what is chosen. Single-select behaviour is untouched when this is false.
        multiple: { type: Boolean, default: false }
      },
      emits: ['update:modelValue'],
      data: function () { return { open: false, query: '', menuStyle: {} }; },
      computed: {
        norm: function () {
          return this.options.map(function (o) {
            // `avatar` / `initial` are optional: an option that represents a person shows a
            // face, which is how you tell two people with similar names apart.
            return (o && typeof o === 'object')
              ? {
                value: String(o.value),
                label: String(o.label != null ? o.label : o.value),
                desc: o.desc ? String(o.desc) : '',
                avatar: o.avatar || '',
                initial: o.initial || '',
                // Raw markup for options that are a THING rather than a person — a module
                // status, a work item state. Dropped here would silently lose the glyph.
                icon: o.icon || ''
              }
              : { value: String(o), label: String(o), desc: '', avatar: '', initial: '' };
          });
        },
        /** The single-select option currently chosen, so the trigger can show its face. */
        chosenOption: function () {
          if (this.multiple) return null;
          var mv = String(this.modelValue || '');
          return this.norm.find(function (o) { return o.value === mv; }) || null;
        },
        filtered: function () {
          var q = this.query.trim().toLowerCase();
          if (!this.searchable || !q) return this.norm;
          return this.norm.filter(function (o) {
            return o.label.toLowerCase().indexOf(q) >= 0 || o.value.toLowerCase().indexOf(q) >= 0;
          });
        },
        /** Selected values as strings, whichever mode this is in. */
        selected: function () {
          if (!this.multiple) return this.modelValue ? [String(this.modelValue)] : [];
          return (this.modelValue || []).map(String);
        },
        display: function () {
          if (this.multiple) {
            var chosen = this.selected;
            if (!chosen.length) return this.placeholder;
            var labels = this.norm.filter(function (o) { return chosen.indexOf(o.value) > -1; })
              .map(function (o) { return o.label; });
            // Two names read as names; more than that reads as a count.
            return labels.length <= 2 ? labels.join(', ') : labels.length + ' selected';
          }
          var mv = this.modelValue;
          var hit = this.norm.find(function (o) { return o.value === mv; });
          return hit ? hit.label : (mv || this.placeholder);
        }
      },
      methods: {
        // The menu is teleported to <body> with fixed positioning so it is never clipped
        // by a modal's scroll area / footer. Position it under the trigger (or above when
        // there isn't room below).
        position: function () {
          var r = this.$refs.root.getBoundingClientRect();
          var below = window.innerHeight - r.bottom;
          var style = { position: 'fixed', left: r.left + 'px', width: r.width + 'px', zIndex: 120 };
          if (below < 280 && r.top > below) { style.bottom = (window.innerHeight - r.top + 4) + 'px'; }
          else { style.top = (r.bottom + 4) + 'px'; }
          this.menuStyle = style;
        },
        /**
         * Keep the menu under its trigger while the page or a dialog body scrolls.
         *
         * The menu is teleported to <body> and positioned ONCE on open, so scrolling the
         * modal it lives in used to move the field and leave the menu behind — which reads
         * as the list being cut off or detached. `true` for capture, because the scroll
         * happens on the dialog's own overflow container, not on window.
         */
        watchScroll: function () {
          if (this._follow) return;
          var self = this;
          this._follow = function () { if (self.open) self.position(); };
          window.addEventListener('scroll', this._follow, true);
          window.addEventListener('resize', this._follow);
        },
        unwatchScroll: function () {
          if (!this._follow) return;
          window.removeEventListener('scroll', this._follow, true);
          window.removeEventListener('resize', this._follow);
          this._follow = null;
        },
        toggle: function () {
          this.open = !this.open;
          if (!this.open) this.unwatchScroll();
          if (this.open) {
            this.query = ''; this.position(); this.watchScroll();
            var self = this;
            this.$nextTick(function () { if (self.searchable && self.$refs.search) self.$refs.search.focus(); });
          }
        },
        isChosen: function (o) { return this.selected.indexOf(o.value) > -1; },
        choose: function (o) {
          if (!this.multiple) {
            this.$emit('update:modelValue', o.value);
            this.open = false;
            return;
          }
          // Toggle, and stay open: picking several people one at a time should not mean
          // reopening the menu between each.
          var next = this.selected.slice();
          var at = next.indexOf(o.value);
          if (at > -1) next.splice(at, 1); else next.push(o.value);
          this.$emit('update:modelValue', next);
        },
        onDoc: function (e) {
          if (!this.open) return;
          var r = this.$refs.root, m = this.$refs.menu;
          if ((r && r.contains(e.target)) || (m && m.contains(e.target))) return;
          this.open = false;
        },
        onKey: function (e) { if (e.key === 'Escape') this.open = false; },

        /**
         * The page moved under the menu — close it, because a teleported menu positioned once
         * would otherwise be left pointing at nothing.
         *
         * Except when the scroll came from INSIDE the menu. The option list is its own scroll
         * container (`max-h-56 overflow-y-auto`), and its scroll events reach this capture-phase
         * listener on window like any other — so scrolling down a long list of people closed the
         * very list being read, and only the first handful of options could ever be picked.
         */
        onReflow: function (e) {
          if (!this.open) return;

          if (e && e.type === 'scroll') {
            var m = this.$refs.menu;
            if (m && e.target && (m === e.target || (m.contains && m.contains(e.target)))) return;
          }

          this.open = false;
        }
      },
      mounted: function () {
        document.addEventListener('click', this.onDoc);
        document.addEventListener('keydown', this.onKey);
        window.addEventListener('resize', this.onReflow);
        window.addEventListener('scroll', this.onReflow, true);
      },
      beforeUnmount: function () {
        document.removeEventListener('click', this.onDoc);
        document.removeEventListener('keydown', this.onKey);
        window.removeEventListener('resize', this.onReflow);
        window.removeEventListener('scroll', this.onReflow, true);
      },
      template:
        '<div class="relative" ref="root">' +
        '<button type="button" class="pb-input pb-combo-btn" :class="[{\'is-error\': invalid}, dense ? \'!h-9\' : \'\']" @click.stop="toggle">' +
        '<span class="flex items-center gap-2 min-w-0">' +
        '<img v-if="chosenOption && chosenOption.avatar" :src="chosenOption.avatar" alt="" class="h-5 w-5 rounded-full object-cover shrink-0" />' +
        '<span v-else-if="chosenOption && chosenOption.initial" :style="{ background: $pb.avatarColor(chosenOption) }" class="h-5 w-5 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0">{{ chosenOption.initial }}</span>' +
        '<span v-else-if="chosenOption && chosenOption.icon" class="grid place-items-center shrink-0" v-html="chosenOption.icon"></span>' +
        '<span class="truncate" :class="selected.length ? \'text-ink\' : \'text-faint\'">{{ display }}</span></span>' +
        '' + wiIcon('chevron-down', 16, 'text-faint shrink-0 ml-1.5') + '' +
        '</button>' +
        '<teleport to="body">' +
        '<div v-if="open" ref="menu" :style="menuStyle" class="min-w-[9rem] bg-white border border-line rounded-md shadow-lg overflow-hidden">' +
        '<div v-if="searchable" class="p-1.5 border-b border-line">' +
        '<input ref="search" v-model="query" @click.stop placeholder="Search…" class="pb-input !h-9" />' +
        '</div>' +
        '<ul class="max-h-56 overflow-y-auto py-1">' +
        '<li v-for="o in filtered" :key="o.value" @click.stop="choose(o)" :class="[\'px-3 flex gap-2 text-[13px] cursor-pointer hover:bg-hover\', o.desc ? \'py-2 items-start\' : \'h-9 items-center\', isChosen(o) ? \'text-brand\' : \'text-ink\']">' +
        '<img v-if="o.avatar" :src="o.avatar" alt="" class="h-6 w-6 rounded-full object-cover shrink-0" :class="o.desc ? \'mt-0.5\' : \'\'" />' +
        '<span v-else-if="o.initial" :style="{ background: $pb.avatarColor(o) }" class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0" :class="o.desc ? \'mt-0.5\' : \'\'">{{ o.initial }}</span>' +
        // `icon` is raw markup, in the same slot as the avatar: the option's own glyph, the
        // way the work item pickers show a state or priority. Author-supplied, never user
        // input — the option list is always built by the screen, not typed.
        '<span v-else-if="o.icon" class="grid place-items-center shrink-0" :class="o.desc ? \'mt-0.5\' : \'\'" v-html="o.icon"></span>' +
        '<span class="min-w-0 flex-1">' +
        '<span class="block truncate">{{ o.label }}</span>' +
        '<span v-if="o.desc" class="block text-[12px] text-sub whitespace-normal">{{ o.desc }}</span>' +
        '</span>' +
        '<span v-if="isChosen(o)" :class="[\'shrink-0\', o.desc ? \'mt-0.5\' : \'\']">' + wiIcon('check', 15) + '</span>' +
        '</li>' +
        '<li v-if="!filtered.length" class="px-3 h-9 flex items-center text-[13px] text-faint">No matches</li>' +
        '</ul></div></teleport></div>'
    });

    // Centered modal dialog.
    app.component('pb-modal', {
      // `width` is the dialog's max width as a Tailwind class. A default rather than a fixed
      // value: most dialogs are a single column of fields, but a form with side-by-side rows
      // (the module form) needs the room, and cramming it into 520px is what makes those
      // pairs unreadable.
      // `z` is the stacking layer, as a Tailwind class. A default rather than a fixed value for
      // the same reason `width` is one: a dialog opened from a plain page sits above the page,
      // but a dialog opened from INSIDE a drawer has to clear the drawer's own shell — and a
      // modal that renders behind the thing that opened it is a modal nobody can see.
      props: {
        open: Boolean,
        title: String,
        width: { type: String, default: 'max-w-[520px]' },
        z: { type: String, default: 'z-[70]' },
      },
      emits: ['close'],
      template:
        // role/aria-modal are load-bearing beyond a11y: the settings shell reads them to know
        // a dialog is open, so Escape closes the dialog instead of leaving the page.
        '<teleport to="body"><div v-if="open" role="dialog" aria-modal="true" :class="[\'fixed inset-0 flex items-start justify-center p-4 sm:pt-24\', z]">' +
        '<div class="absolute inset-0 bg-black/40" @click="$emit(\'close\')"></div>' +
        '<div :class="[\'relative w-full bg-white rounded-xl shadow-xl flex flex-col max-h-[85vh]\', width]">' +
        '<div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">' +
        '<h2 class="text-[16px] font-semibold text-head">{{ title }}</h2>' +
        '<button @click="$emit(\'close\')" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
        '' + wiIcon('xmark', 18) + '</button></div>' +
        '<div class="px-6 py-5 overflow-y-auto"><slot/></div>' +
        '<div class="px-6 py-4 border-t border-line flex justify-end gap-2 shrink-0"><slot name="footer"/></div>' +
        '</div></div></teleport>'
    });

    // Destructive confirmation.
    app.component('pb-confirm', {
      props: {
        open: Boolean,
        title: String,
        message: String,
        confirmLabel: { type: String, default: 'Delete' },
        // Passed through to `pb-modal`, for the same reason it has one: a confirmation raised
        // from inside a drawer has to clear the drawer. See pb-modal's own note.
        z: { type: String, default: 'z-[70]' },
      },
      emits: ['confirm', 'close'],
      template:
        '<pb-modal :open="open" :title="title" :z="z" @close="$emit(\'close\')">' +
        '<p class="text-[13px] text-sub leading-relaxed">{{ message }}</p>' +
        '<template #footer>' +
        '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="$emit(\'close\')">Cancel</button>' +
        '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold hover:opacity-90" @click="$emit(\'confirm\')">{{ confirmLabel }}</button>' +
        '</template></pb-modal>'
    });

    // Empty state card with a primary action slot (spec §13).
    app.component('pb-empty', {
      props: { title: String, subtitle: String },
      template:
        '<div class="border border-dashed border-stroke rounded-xl px-6 py-10 text-center">' +
        '<div class="text-[14px] font-medium text-head">{{ title }}</div>' +
        '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">{{ subtitle }}</p>' +
        '<div class="mt-4 flex justify-center"><slot/></div></div>'
    });

    /* A free-text multi-value field: type a value, press Enter or comma, get a removable chip.
       ------------------------------------------------------------------
       For fields where the set of valid answers belongs to the reader rather than to us — a
       space's types, for instance. <pb-combo> is the opposite tool: it offers a list somebody
       chose in advance. Both exist because "pick one of these" and "tell us yours" are
       different questions, and a dropdown asked to do the second becomes a list nobody's
       answer is on.

       `suggestions` are offered as one-press chips and nothing more. They keep the common
       spellings consistent across rows without making them the only allowed answers.

       Committing on BLUR as well as on Enter is deliberate: text left in the box when somebody
       reaches for Save is a value they typed and meant, and silently dropping it is the
       classic way a tag field loses work. */
    app.component('pb-tags', {
      props: {
        modelValue: { type: Array, default: function () { return []; } },
        suggestions: { type: Array, default: function () { return []; } },
        placeholder: { type: String, default: 'Type a value and press Enter' },
        max: { type: Number, default: 8 },
        maxLength: { type: Number, default: 40 }
      },
      emits: ['update:modelValue'],
      data: function () { return { draft: '' }; },
      computed: {
        values: function () { return this.modelValue || []; },
        full: function () { return this.values.length >= this.max; },
        // Only the ones not already chosen — offering a chip that does nothing is a dead control.
        offered: function () {
          var lower = this.values.map(function (v) { return String(v).toLowerCase(); });
          return this.suggestions.filter(function (s) {
            return lower.indexOf(String(s).toLowerCase()) === -1;
          });
        }
      },
      methods: {
        add: function (raw) {
          var self = this;
          // A pasted "a, b, c" is three values, not one — splitting here means paste behaves
          // the same way typing does.
          String(raw).split(',').forEach(function (part) {
            var value = part.replace(/\s+/g, ' ').trim().slice(0, self.maxLength);
            if (!value || self.full) return;
            var exists = self.values.some(function (v) {
              return String(v).toLowerCase() === value.toLowerCase();
            });
            if (!exists) self.$emit('update:modelValue', self.values.concat([value]));
          });
          this.draft = '';
        },
        remove: function (index) {
          var next = this.values.slice();
          next.splice(index, 1);
          this.$emit('update:modelValue', next);
        },
        onKey: function (e) {
          if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); this.add(this.draft); return; }
          // Backspace on an empty box takes the last chip back, which is what every tag field
          // does and what fingers expect.
          if (e.key === 'Backspace' && !this.draft && this.values.length) this.remove(this.values.length - 1);
        },
        commit: function () { if (this.draft.trim()) this.add(this.draft); }
      },
      template:
        '<div>' +
        '<div class="pb-input _moretogether-tags" @click="$refs.box.focus()">' +
        '<span v-for="(v, i) in values" :key="v" class="_moretogether-tag">' +
        '<span>{{ v }}</span>' +
        '<button type="button" class="_moretogether-tag__x" :aria-label="\'Remove \' + v" ' +
        'data-tip="Remove" @click.stop="remove(i)">' + wiIcon('xmark', 11) + '</button>' +
        '</span>' +
        '<input ref="box" v-model="draft" class="_moretogether-tags__field" ' +
        ':placeholder="values.length ? \'\' : placeholder" :disabled="full" ' +
        '@keydown="onKey" @blur="commit"/>' +
        '</div>' +
        '<div v-if="offered.length && !full" class="mt-2 flex flex-wrap gap-1.5">' +
        '<button type="button" v-for="s in offered" :key="s" ' +
        'class="h-6 px-2 rounded-full border border-line text-[12px] text-sub hover:bg-hover hover:text-ink" ' +
        '@click="add(s)">+ {{ s }}</button>' +
        '</div>' +
        '<p v-if="full" class="text-[12px] text-faint mt-1.5">That is the most this field holds.</p>' +
        '</div>'
    });

    // Section title + description block.
    app.component('pb-section-head', {
      props: { title: String, desc: String },
      template:
        '<div class="mb-5"><h1 class="text-[20px] font-bold text-head">{{ title }}</h1>' +
        '<p v-if="desc" class="text-[13px] text-sub mt-1 max-w-2xl">{{ desc }}</p></div>'
    });

    // A colored label/tag chip row with edit/delete actions.
    app.component('pb-label-row', {
      props: { name: String, color: String },
      emits: ['edit', 'remove'],
      template:
        '<div class="flex items-center gap-3 px-3 py-2.5 border border-line rounded-lg">' +
        '<span class="h-3.5 w-3.5 rounded-full shrink-0" :style="{background: color || \'#9ca3af\'}"></span>' +
        '<span class="text-[13px] text-ink flex-1 truncate">{{ name }}</span>' +
        '<button class="text-[12px] text-sub hover:text-ink px-1.5" @click="$emit(\'edit\')">Edit</button>' +
        '<button class="text-[12px] text-danger hover:opacity-80 px-1.5" @click="$emit(\'remove\')">Delete</button>' +
        '</div>'
    });

    // Self-contained CRUD manager for a color label / tag list. Reused by Projects, Wiki,
    // Releases (labels + tags) and Initiatives. Talks to the server directly and refreshes
    // from the returned collection.
    app.component('pb-label-manager', {
      props: {
        title: String, description: String,
        items: { type: Array, default: function () { return []; } },
        presets: { type: Array, default: function () { return []; } },
        storeUrl: String, itemUrl: String,
        collectionKey: { type: String, default: 'labels' },
        singular: { type: String, default: 'label' },
        addText: { type: String, default: 'Add label' },
        namePlaceholder: { type: String, default: 'Label name' },
        emptyTitle: { type: String, default: 'No labels yet' },
        emptySubtitle: { type: String, default: 'Create your first label to get started.' },
        withColor: { type: Boolean, default: true },
        disabled: { type: Boolean, default: false },
        lockedTitle: String, lockedSubtitle: String
      },
      data: function () {
        return { list: this.items.slice(), open: false, editing: null, saving: false, errors: {},
          form: { name: '', color: this.presets[0] || '#F97316' }, confirm: { open: false, item: null } };
      },
      methods: {
        openAdd: function () { this.editing = null; this.errors = {}; this.form = { name: '', color: this.presets[0] || '#F97316' }; this.open = true; },
        openEdit: function (it) { this.editing = it; this.errors = {}; this.form = { name: it.name, color: it.color || this.presets[0] || '#F97316' }; this.open = true; },
        close: function () { this.open = false; },
        save: async function () {
          if (!this.form.name.trim() || this.saving) return;
          this.saving = true; this.errors = {};
          var payload = { name: this.form.name.trim() };
          if (this.withColor) payload.color = this.form.color;
          try {
            var url = this.editing ? this.$pb.withId(this.itemUrl, this.editing.id) : this.storeUrl;
            var resp = await this.$pb.api(url, { method: this.editing ? 'PATCH' : 'POST', body: payload });
            this.list = resp[this.collectionKey] || this.list;
            this.open = false;
            this.$pb.toast(this.editing ? 'Updated.' : 'Added.');
          } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
          this.saving = false;
        },
        askRemove: function (it) { this.confirm = { open: true, item: it }; },
        remove: async function () {
          var it = this.confirm.item; if (!it) return;
          try {
            var resp = await this.$pb.api(this.$pb.withId(this.itemUrl, it.id), { method: 'DELETE' });
            this.list = resp[this.collectionKey] || this.list;
            this.$pb.toast('Deleted.');
          } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
          this.confirm = { open: false, item: null };
        }
      },
      template:
        '<div>' +
        '<div class="flex items-start justify-between gap-4 mb-3">' +
        '<div><h2 class="text-[15px] font-semibold text-head">{{ title }}</h2>' +
        '<p v-if="description" class="text-[12px] text-sub mt-0.5 max-w-xl">{{ description }}</p></div>' +
        '<button v-if="!disabled" class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0" @click="openAdd">{{ addText }}</button>' +
        '</div>' +
        '<div v-if="disabled" class="border border-dashed border-stroke rounded-xl px-6 py-10 text-center">' +
        '<div class="text-[14px] font-medium text-head">{{ lockedTitle }}</div>' +
        '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">{{ lockedSubtitle }}</p></div>' +
        '<template v-else>' +
        '<pb-empty v-if="!list.length" :title="emptyTitle" :subtitle="emptySubtitle">' +
        '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="openAdd">{{ addText }}</button></pb-empty>' +
        '<div v-else class="space-y-2">' +
        '<pb-label-row v-for="it in list" :key="it.id" :name="it.name" :color="it.color" @edit="openEdit(it)" @remove="askRemove(it)"/>' +
        '</div></template>' +
        '<pb-modal :open="open" :title="(editing ? \'Edit \' : \'Add \') + singular" @close="close">' +
        '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
        '<input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name" :placeholder="namePlaceholder" @keyup.enter="save" />' +
        '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
        '<div v-if="withColor" class="mt-4"><label class="block text-[13px] font-medium text-ink mb-2">Color</label>' +
        '<pb-color-picker v-model="form.color" :presets="presets" />' +
        '<p v-if="errors.color" class="text-[12px] text-danger mt-1">{{ errors.color[0] }}</p></div>' +
        '<template #footer>' +
        '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="close">Cancel</button>' +
        '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving || !form.name.trim()" @click="save">{{ editing ? \'Save\' : \'Add\' }}</button>' +
        '</template></pb-modal>' +
        '<pb-confirm :open="confirm.open" :title="\'Delete \' + singular + \'?\'" :message="\'This removes it from the workspace. This action cannot be undone.\'" @close="confirm.open=false" @confirm="remove" />' +
        '</div>'
    });
  }

  // ================= tooltips =================
  /**
   * One floating tooltip for every `[data-tip]` on the page.
   *
   * Native `title` waits about a second and cannot be styled, which is no help on a row of
   * icon-only buttons — the whole point of the icon is that its meaning is not written on it.
   * Delegated from the document, so controls rendered later (grid formatters, drawers,
   * dialogs) are covered without re-binding, and shown on focus as well as hover so keyboard
   * users get the same label.
   *
   * Installed once per page by boot(): a second copy would mean two tooltips chasing the
   * cursor. Styled by `.wi-tip` in assets/css/work-items.css.
   */
  function tooltips() {
    if (window.__pbTips) return;

    var el = document.createElement('div');
    el.className = 'wi-tip hidden';
    el.setAttribute('role', 'tooltip');
    document.body.appendChild(el);
    window.__pbTips = el;

    function show(target) {
      var text = target.getAttribute('data-tip');
      if (!text) return;
      el.textContent = text;
      el.classList.remove('hidden');

      var r = target.getBoundingClientRect();
      var left = r.left + r.width / 2 - el.offsetWidth / 2;
      // Keep it on screen when the control sits at either edge.
      el.style.left = Math.max(6, Math.min(left, window.innerWidth - el.offsetWidth - 6)) + 'px';
      // Above by default; below when there is no room above.
      var above = r.top - el.offsetHeight - 8;
      el.style.top = (above < 6 ? r.bottom + 8 : above) + 'px';
    }
    function hide() { el.classList.add('hidden'); }

    function over(e) {
      var t = e.target.closest ? e.target.closest('[data-tip]') : null;
      if (t) show(t); else hide();
    }

    document.addEventListener('mouseover', over);
    document.addEventListener('mouseleave', hide, true);
    document.addEventListener('focusin', over);
    document.addEventListener('focusout', hide);
    // A tooltip left behind while the page moves is worse than none.
    window.addEventListener('scroll', hide, true);
    document.addEventListener('click', hide);
  }

  // ================= boot =================
  /**
   * Mount a screen component, with the shared components and $pb registered on it.
   *
   * `options.root` names the element to mount into, defaulting to the screen root. The account
   * modal uses it: that dialog lives in the topbar, outside #settings-root, and its Preference
   * tab needs <pb-combo> — which is registered here and nowhere else. Reaching it through this
   * function is what stops a second searchable combobox being written for that one panel.
   */
  function boot(name, component, options) {
    /*
     * `options.root` is an ID, but a CSS-selector "#id" is the obvious thing to write and was
     * written — getElementById returned null, boot bailed out silently, and the screen sat on
     * its "Loading…" placeholder forever with nothing in the console to say why. Accept both
     * spellings rather than leave that trap for the next screen.
     */
    var id = String((options && options.root) || 'settings-root').replace(/^#/, '');
    var root = document.getElementById(id);

    if (!root) {
      /*
       * SAY SO. A missing mount point is a programming mistake, not a runtime condition, and
       * the only symptom the user ever sees is a placeholder that never goes away.
       */
      if (window.console && console.error) {
        console.error('PB.boot("' + name + '"): no element with id "' + id + '" — the screen cannot mount.');
      }

      return;
    }
    if (!window.Vue) {
      // The Vue runtime failed to load (blocked CDN, offline, ad-blocker). Surface
      // it instead of silently sitting on the "Loading…" placeholder forever.
      root.innerHTML = '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-10 text-[13px] text-danger">'
        + 'Couldn’t load the app runtime. Please hard-refresh this page (Cmd/Ctrl + Shift + R). '
        + 'If it keeps happening, check your network or any ad/script blocker.</div>';
      return;
    }
    var bootstrap = {};
    try { bootstrap = JSON.parse(root.getAttribute('data-bootstrap') || '{}'); } catch (e) {}
    var app = Vue.createApp(component, { bootstrap: bootstrap });
    app.config.globalProperties.$pb = { api: api, withId: withId, firstError: firstError, fieldErrors: fieldErrors, toast: toast, avatarColor: avatarColor };
    registerShared(app);
    tooltips();
    root.innerHTML = '';
    app.mount(root);
  }

  window.PB = { api: api, withId: withId, firstError: firstError, fieldErrors: fieldErrors, toast: toast, boot: boot, tooltips: tooltips, avatarColor: avatarColor };
})();
