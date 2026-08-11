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

  function toast(message, kind) {
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
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>' +
      '</div></div>';
    card.querySelector('[data-t]').textContent = isError ? 'Error' : 'Success';
    card.querySelector('[data-m]').textContent = message;
    toastContainer().appendChild(card);
    requestAnimationFrame(function () { card.classList.remove('opacity-0', 'translate-x-2'); });
    var done = false;
    function dismiss() { if (done) return; done = true; card.classList.add('opacity-0', 'translate-x-2'); setTimeout(function () { card.remove(); }, 300); }
    card.querySelector('[data-x]').addEventListener('click', dismiss);
    setTimeout(dismiss, 3600);
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
                initial: o.initial || ''
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
        toggle: function () {
          this.open = !this.open;
          if (this.open) {
            this.query = ''; this.position();
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
        onReflow: function () { if (this.open) this.open = false; }
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
        '<button type="button" class="pb-input pb-combo-btn flex items-center justify-between text-left" :class="[{\'is-error\': invalid}, dense ? \'!h-9\' : \'\']" @click.stop="toggle">' +
        '<span class="flex items-center gap-2 min-w-0">' +
        '<img v-if="chosenOption && chosenOption.avatar" :src="chosenOption.avatar" alt="" class="h-5 w-5 rounded-full object-cover shrink-0" />' +
        '<span v-else-if="chosenOption && chosenOption.initial" class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold shrink-0">{{ chosenOption.initial }}</span>' +
        '<span class="truncate" :class="selected.length ? \'text-ink\' : \'text-faint\'">{{ display }}</span></span>' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1.5"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
        '</button>' +
        '<teleport to="body">' +
        '<div v-if="open" ref="menu" :style="menuStyle" class="min-w-[9rem] bg-white border border-line rounded-md shadow-lg overflow-hidden">' +
        '<div v-if="searchable" class="p-1.5 border-b border-line">' +
        '<input ref="search" v-model="query" @click.stop placeholder="Search…" class="pb-input !h-9" />' +
        '</div>' +
        '<ul class="max-h-56 overflow-y-auto py-1">' +
        '<li v-for="o in filtered" :key="o.value" @click.stop="choose(o)" :class="[\'px-3 flex gap-2 text-[13px] cursor-pointer hover:bg-hover\', o.desc ? \'py-2 items-start\' : \'h-9 items-center\', isChosen(o) ? \'text-brand\' : \'text-ink\']">' +
        '<img v-if="o.avatar" :src="o.avatar" alt="" class="h-6 w-6 rounded-full object-cover shrink-0" :class="o.desc ? \'mt-0.5\' : \'\'" />' +
        '<span v-else-if="o.initial" class="h-6 w-6 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold shrink-0" :class="o.desc ? \'mt-0.5\' : \'\'">{{ o.initial }}</span>' +
        '<span class="min-w-0 flex-1">' +
        '<span class="block truncate">{{ o.label }}</span>' +
        '<span v-if="o.desc" class="block text-[12px] text-sub whitespace-normal">{{ o.desc }}</span>' +
        '</span>' +
        '<svg v-if="isChosen(o)" width="15" height="15" viewBox="0 0 24 24" fill="none" :class="[\'shrink-0\', o.desc ? \'mt-0.5\' : \'\']"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
        '</li>' +
        '<li v-if="!filtered.length" class="px-3 h-9 flex items-center text-[13px] text-faint">No matches</li>' +
        '</ul></div></teleport></div>'
    });

    // Centered modal dialog.
    app.component('pb-modal', {
      props: { open: Boolean, title: String },
      emits: ['close'],
      template:
        // role/aria-modal are load-bearing beyond a11y: the settings shell reads them to know
        // a dialog is open, so Escape closes the dialog instead of leaving the page.
        '<teleport to="body"><div v-if="open" role="dialog" aria-modal="true" class="fixed inset-0 z-[70] flex items-start justify-center p-4 sm:pt-24">' +
        '<div class="absolute inset-0 bg-black/40" @click="$emit(\'close\')"></div>' +
        '<div class="relative w-full max-w-[520px] bg-white rounded-xl shadow-xl flex flex-col max-h-[85vh]">' +
        '<div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">' +
        '<h2 class="text-[16px] font-semibold text-head">{{ title }}</h2>' +
        '<button @click="$emit(\'close\')" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>' +
        '<div class="px-6 py-5 overflow-y-auto"><slot/></div>' +
        '<div class="px-6 py-4 border-t border-line flex justify-end gap-2 shrink-0"><slot name="footer"/></div>' +
        '</div></div></teleport>'
    });

    // Destructive confirmation.
    app.component('pb-confirm', {
      props: { open: Boolean, title: String, message: String, confirmLabel: { type: String, default: 'Delete' } },
      emits: ['confirm', 'close'],
      template:
        '<pb-modal :open="open" :title="title" @close="$emit(\'close\')">' +
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
  function boot(name, component) {
    var root = document.getElementById('settings-root');
    if (!root) return;
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
    app.config.globalProperties.$pb = { api: api, withId: withId, firstError: firstError, fieldErrors: fieldErrors, toast: toast };
    registerShared(app);
    tooltips();
    root.innerHTML = '';
    app.mount(root);
  }

  window.PB = { api: api, withId: withId, firstError: firstError, fieldErrors: fieldErrors, toast: toast, boot: boot, tooltips: tooltips };
})();
