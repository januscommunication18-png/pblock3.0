/* Project Workspace → Work Items (Phase 5) — state-grouped Tabulator list + Create work item
 * modal. Layout, chip style and grid skin follow html/work-items.html; the grid engine is the
 * same Tabulator build the Members listing uses.
 *
 * This slice covers the list and creation. Row property editing and the detail drawer land in
 * the next slice, so every chip below is display-only — no dead controls. */

// State icons, priority icons, chips, avatars and wiEsc live in
// assets/js/projects/work-item-ui.js — shared with the Cycles screen so a cycle's work item
// list reads exactly like this one.

// ---- Event icons for the activity/history feeds (Activity & Audit spec §6.4) ------------
// A feed row's icon says what KIND of change it was before the sentence is read, which is
// what makes a long timeline skimmable. Keyed by the audit row's field, with the event as a
// fallback for rows that carry no field (creation).
function wiEventSvg(body, w) {
  var size = w || 15;
  return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" ' +
    'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
}
var WI_EVENT_ICON = {
  state: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.2"/>',
  priority: '<path d="M5 20v-6M12 20V7M19 20v-9"/>',
  assignees: '<circle cx="12" cy="8" r="3.2"/><path d="M5 20a7 7 0 0114 0"/>',
  date: '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/>',
  labels: '<path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z"/><circle cx="14.5" cy="9.5" r="1.2"/>',
  comment: '<path d="M20 15a2 2 0 01-2 2H8l-4 4V6a2 2 0 012-2h12a2 2 0 012 2z"/>',
  worklog: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  link: '<path d="M9 15l6-6"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1"/>',
  relation: '<path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/>',
  parent: '<rect x="3" y="4" width="7" height="7" rx="1.6"/><rect x="13" y="13" width="8" height="7" rx="1.6"/><path d="M6.5 11v4a2 2 0 002 2H13"/>',
  text: '<path d="M4 20h4l10-10-4-4L4 16v4z"/>',
  update: '<path d="M5 21V5a1 1 0 011-1h9l-1.5 3L15 10H6"/><path d="M5 21h4"/>',
  archive: '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4"/>',
  created: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
  cycle: '<path d="M21 12a9 9 0 11-3.6-7.2"/><path d="M21 4v4h-4"/>'
};
/** Which icon a feed row gets. */
function wiEventKind(entry) {
  var field = entry.field || '';
  if (entry.event === 'created') return 'created';
  if (field === 'state') return 'state';
  if (field === 'priority') return 'priority';
  if (field === 'assignees') return 'assignees';
  if (field === 'labels') return 'labels';
  if (field === 'start_date' || field === 'due_date') return 'date';
  if (field === 'comment' || field === 'comment_reply') return 'comment';
  if (field === 'worklog') return 'worklog';
  if (field === 'update') return 'update';
  if (field === 'archived_at') return 'archive';
  if (field.indexOf('link') === 0) return 'link';
  if (field.indexOf('relation') === 0 || field.indexOf('subtask') === 0) return 'relation';
  if (field === 'parent') return 'parent';
  if (field === 'cycle') return 'cycle';
  return 'text';
}

// The date picker lives in assets/js/projects/date-picker.js — shared with the Cycles
// screen, and registered below as <wi-calendar>. Its helpers (wiParseISO / wiISO /
// wiFmtDate / WI_MONTHS …) come from that file too and are used throughout this one.


// ---------------------------------------------------------------------------------------
// Rich-text editor — Quill (snow theme), wrapped as a Vue component so it drops in like an
// <input>. Used by the description field, the comment composer and the update composer.
//
// The instance is created on mount and destroyed on unmount, which matters because the
// drawer is v-if'd: opening a different work item builds a fresh editor rather than leaving
// a detached one holding the previous item's content.
//
// The model is HTML, not a Quill Delta. Descriptions, comments and updates are stored and
// rendered as HTML everywhere else in this app (and sanitized server-side on the way in), so
// keeping Delta as a second representation would mean two sources of truth for the same text.
//
// Images do NOT go inline as base64: the default paste/insert behaviour would embed whole
// files in the column, so the image handler uploads through the project's media endpoint and
// inserts the URL it returns.
// ---------------------------------------------------------------------------------------
var WI_EDITOR_TOOLBAR = [
  [{ header: [1, 2, 3, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ color: [] }, { background: [] }],
  [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
  [{ align: [] }],
  ['blockquote', 'code-block'],
  ['link', 'image', 'video'],
  ['clean']
];

/**
 * One person's avatar, used everywhere a name appears: their uploaded photo when they have
 * one, their initial when they do not. This exists because the initials markup had been
 * copied to a dozen places, and every copy silently ignored the photo.
 */
var WiAvatar = {
  props: {
    person: { type: Object, default: null },
    size: { type: Number, default: 24 }
  },
  computed: {
    box: function () {
      return {
        width: this.size + 'px',
        height: this.size + 'px',
        fontSize: Math.max(9, Math.round(this.size * 0.42)) + 'px'
      };
    },
    name: function () { return this.person ? (this.person.name || '') : ''; }
  },
  template:
    '<img v-if="person && person.avatar_url" :src="person.avatar_url" :alt="name" :data-tip="name" ' +
    'class="rounded-full object-cover border border-line shrink-0" :style="box" />' +
    '<span v-else class="rounded-full text-white grid place-items-center font-bold shrink-0" ' +
    ':style="{ background: $pb.avatarColor(person) }" ' +
    ':style="box" :data-tip="name" :aria-label="name">{{ person ? (person.initial || \'?\') : \'?\' }}</span>'
};

var WiEditor = {
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Add a description…' },
    minHeight: { type: String, default: '140px' },
    disabled: { type: Boolean, default: false },
    // Media endpoints. Passed in rather than read from a global so the editor stays a
    // component you can drop anywhere, and so it degrades to no-media when they are absent.
    mediaUpload: { type: String, default: '' },
    mediaGallery: { type: String, default: '' },
    mediaMaxBytes: { type: Number, default: 5 * 1024 * 1024 },
    /**
     * A CSS selector for an element to build the toolbar into.
     *
     * Snow puts its toolbar immediately above the editor, which is right for a description
     * field inside a form. A document editor wants one persistent bar at the top of the
     * screen instead, with the title and body scrolling under it — so the Pages screen names
     * an element to move it into. Empty means the default, and every existing caller keeps it.
     *
     * Quill BUILDS the toolbar either way; this only relocates the finished element. Handing
     * Quill a host full of hand-written markup instead looked equivalent and was not: Snow
     * rewrites the toolbar's DOM (every <select> becomes a picker), so a Vue-rendered toolbar
     * put the two in a fight over the same nodes. Every re-render patched Quill's DOM away,
     * and the resulting mutation storm threw inside Quill's own observer —
     *
     *     TypeError: Cannot read properties of null (reading 'offset')
     *       normalizedToRange → getRange → update → handleDOM
     *
     * — after which Quill stopped tracking changes entirely: no text-change, so nothing
     * saved, and paste appeared to do nothing. The host must therefore be an EMPTY element
     * that Vue renders once and never patches.
     */
    toolbarHost: { type: String, default: '' }
  },
  emits: ['update:modelValue', 'blur'],
  data: function () {
    return { quill: null, gallery: { open: false, items: [], loading: false }, uploading: false };
  },
  mounted: function () {
    if (!window.Quill || !this.$refs.area) return;
    var self = this;

    this.quill = new window.Quill(this.$refs.area, {
      theme: 'snow',
      placeholder: this.placeholder,
      // Explicit: a Quill in readOnly mode is not editable and cannot be pasted into, and
      // `disabled` is optional at every call site.
      readOnly: this.disabled === true,
      modules: {
        toolbar: {
          container: WI_EDITOR_TOOLBAR,
          handlers: {
            image: function () { self.pickImage(); }
          }
        },
        // matchVisual re-creates the source's spacing by inserting blank lines, which turns
        // a pasted paragraph into a gappy mess and is the usual cause of paste "not working"
        // the way people expect.
        clipboard: { matchVisual: false }
      }
    });

    // Paste, handled explicitly.
    //
    // Quill's own clipboard drops content whose formats it does not recognise, so pasting
    // from a document or a mail client could put nothing in the editor at all. Taking the
    // event ourselves makes the outcome deterministic: HTML goes through Quill's parser (so
    // it arrives as Quill formats), and anything else lands as plain text rather than
    // vanishing. What is stored is sanitized server-side either way.
    this.quill.root.addEventListener('paste', function (e) {
      var data = e.clipboardData || window.clipboardData;
      if (!data) return;

      var html = data.getData('text/html');
      var text = data.getData('text/plain');
      if (!html && !text) return;

      // Where to put it. safeRange() never throws and never returns null, so a paste is
      // always placed somewhere — see its note for the crash this replaced.
      var range = self.safeRange();
      if (!range) return;

      e.preventDefault();

      if (!self.pasteHtml(html, range) && !self.pasteText(text, range)) {
        // Both routes failed. Better an unformatted paste than a silent one.
        try { self.quill.insertText(range.index, text || '', 'user'); } catch (ignored) {}
      }

      self.emitValue();
    }, true);

    // Keep the model in step with typing, debounced.
    //
    // This used to sync on blur only, on the reasoning that callers save on blur anyway. But
    // clicking a Save/Comment/Add-update button IS the first blur, and the click was handled
    // before the model had caught up — so the submit saw empty content and did nothing, and
    // a button disabled until there is content looked broken. Syncing costs nothing: it is a
    // property assignment, not a request.
    this._syncTimer = null;
    this.quill.on('text-change', function () {
      clearTimeout(self._syncTimer);
      self._syncTimer = setTimeout(function () { self.emitValue(); }, 120);
    });

    // Blur still emits — immediately, ahead of any pending debounce — and tells the caller,
    // which is what triggers save-on-blur for the description.
    this.quill.on('selection-change', function (range, oldRange) {
      if (range === null && oldRange !== null) {
        clearTimeout(self._syncTimer);
        self.emitValue();
        self.$emit('blur');
      }
    });

    // Move the finished toolbar into its host, if one was named. Done here rather than by
    // configuration so Quill owns every node inside it — see the toolbarHost note.
    if (this.toolbarHost) {
      var host = document.querySelector(this.toolbarHost);
      var built = this.quill.getModule('toolbar');
      if (host && built && built.container) host.appendChild(built.container);
    }

    // Initial content LAST, after every listener is attached.
    //
    // It used to be loaded first, immediately after the Quill instance was built. Quill 2 can
    // throw while parsing (`dangerouslyPasteHTML` → `setContents` → a stale native selection
    // that no longer resolves), and a throw here aborted the rest of mounted() — so
    // `text-change` was never wired, typing never reached the model, and the Save button read
    // an empty document and returned without doing anything. The editor looked fine and the
    // button looked dead.
    //
    // Loading last means a parse failure costs at most the pre-filled text; the editor still
    // works. setHtml() no longer throws either — belt and braces, because this is the failure
    // that is invisible from the outside.
    if (this.modelValue) this.setHtml(this.modelValue);
  },
  beforeUnmount: function () {
    clearTimeout(this._syncTimer);
    // Quill has no destroy(); dropping the reference and letting the v-if remove the DOM is
    // the documented way to tear one down.
    this.quill = null;
  },
  watch: {
    // The item was replaced under us (another edit refreshed the row) — take the new value,
    // but never while the user is typing into it.
    modelValue: function (next) {
      if (this.quill && !this.quill.hasFocus() && next !== this.html()) this.setHtml(next || '');
    }
  },
  methods: {
    html: function () {
      if (!this.quill) return '';
      var html = this.quill.getSemanticHTML ? this.quill.getSemanticHTML() : this.quill.root.innerHTML;
      // Quill reports an empty document as a single empty paragraph; normalise so an emptied
      // editor round-trips to "" rather than to scaffolding the server would strip anyway.
      return this.quill.getText().trim() === '' && this.quill.getLength() <= 1 ? '' : html;
    },
    setHtml: function (html) {
      if (!this.quill) return;

      // Replacing the document invalidates any native selection still pointing into the old
      // DOM, and Quill crashes mapping that stale range back to a position. Dropping the
      // selection first avoids it; the try/catch is there because "the editor lost its
      // starting text" is recoverable and "the component died" is not.
      try {
        this.quill.setSelection(null, 'silent');
      } catch (e) { /* no selection to clear */ }

      try {
        // dangerouslyPasteHTML is Quill's own name for "parse this into a document"; the
        // value is server-sanitized markup, and anything Quill cannot represent it drops.
        this.quill.clipboard.dangerouslyPasteHTML(html || '', 'silent');
      } catch (e) {
        try { this.quill.setText(''); } catch (ignored) { /* nothing more to try */ }
      }

      // And clear it again afterwards. Loading a document schedules Quill's own selection
      // update, which runs after this method returns and maps whatever native range the
      // browser still holds — if that points into the DOM we just replaced, it throws where
      // no try/catch of ours can reach it. Those were the uncaught `update()` errors in the
      // browser log on every page load.
      try {
        this.quill.setSelection(null, 'silent');
      } catch (ignored) { /* nothing to clear */ }
    },
    /**
     * Strip Word's scaffolding before Quill parses the paste.
     *
     * A copy from Word is not really HTML — it is HTML wrapped in conditional comments, an
     * <xml> island, a <style> block of `Mso*` classes, and `mso-…` declarations inside every
     * style attribute. Quill's matchers read that literally: the classes carry no meaning they
     * recognise, the style attributes are mostly noise, and paragraphs come through flattened
     * or wrongly promoted to headings. Handing them plain HTML first is the difference between
     * a paste that keeps its bold, headings and lists and one that arrives as a wall of text.
     *
     * This removes the scaffolding ONLY. It does not invent formatting, and it deliberately
     * leaves everything Quill can genuinely represent.
     */
    cleanPastedHtml: function (html) {
      if (!html) return html;

      return html
        // <!--[if gte mso 9]> … <![endif]--> islands, and any other comment.
        .replace(/<!--[\s\S]*?-->/g, '')
        // Word's <xml> island and its <style> block of Mso class definitions.
        .replace(/<xml[\s\S]*?<\/xml>/gi, '')
        .replace(/<style[\s\S]*?<\/style>/gi, '')
        // Namespaced tags: <o:p>, <w:sdt>, <v:shape>.
        .replace(/<\/?[a-z]+:[^>]*>/gi, '')
        // Mso class names, which mean nothing outside Word. Quoted, single-quoted and bare —
        // Word emits `class=WordSection1` without quotes, which a quoted-only pattern misses.
        .replace(/\s(?:class|lang)=(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, function (attr) {
          return /Mso|WordSection|xl\d/i.test(attr) ? '' : attr;
        })
        // mso-* declarations inside style attributes, and the empty attributes left behind.
        .replace(/style="([^"]*)"/gi, function (whole, css) {
          var kept = css.split(';')
            .filter(function (d) { return d.trim() && !/^\s*mso-/i.test(d); })
            .join(';');

          return kept.trim() ? 'style="' + kept + '"' : '';
        });
    },

    /**
     * Paste HTML, keeping its formatting.
     *
     * Through `clipboard.convert()` → `updateContents()`, which is Quill's own pipeline: the
     * source runs through every registered matcher, so headings, lists, bold, links and the
     * rest arrive as Quill formats. `dangerouslyPasteHTML` at an index takes a shorter route
     * and drops formats the matchers would have kept — which is why a paste from a document
     * or a web page landed as flat text.
     *
     * @return bool whether the paste was placed
     */
    pasteHtml: function (html, range) {
      if (!html || !this.quill || !window.Quill) return false;

      var pasted = null;

      try {
        var Delta = window.Quill.import('delta');
        pasted = this.quill.clipboard.convert({ html: this.cleanPastedHtml(html) });

        // Nothing Quill could represent — fall back to the plain-text branch rather than
        // replacing the selection with an empty document.
        if (!pasted || pasted.length() === 0) return false;

        var change = new Delta().retain(range.index);
        if (range.length) change = change.delete(range.length);

        this.quill.updateContents(change.concat(pasted), 'user');
      } catch (e) {
        return false;
      }

      // Placed. Anything that fails from here is cosmetic and must NOT report failure: the
      // caller falls back to a plain-text insert on false, which would append the same text a
      // second time — the duplicated, run-together paragraphs a pasted document ended up with.
      // Moving the caret is not worth a duplicate paste.
      try {
        this.quill.setSelection(range.index + pasted.length(), 0, 'silent');
      } catch (e) { /* the text is in; the caret can stay where it is */ }

      return true;
    },

    /** @return bool whether the paste was placed */
    pasteText: function (text, range) {
      if (!text || !this.quill) return false;

      try {
        if (range.length) this.quill.deleteText(range.index, range.length, 'user');
        this.quill.insertText(range.index, text, 'user');
        this.quill.setSelection(range.index + text.length, 0, 'silent');

        return true;
      } catch (e) {
        return false;
      }
    },

    /**
     * Where the caret is, without letting Quill throw.
     *
     * `getSelection(true)` forces focus and then maps the native selection to a document
     * position. When that selection sits in a node Quill does not own — the toolbar, the
     * title field, anything outside the editor root — the mapping reads `.offset` off a null
     * blot and throws:
     *
     *     TypeError: Cannot read properties of null (reading 'offset')
     *       normalizedToRange → getRange → update → getSelection
     *
     * That killed paste outright: the handler threw before inserting anything, so the paste
     * was cancelled AND dropped. Falling back to the end of the document means the worst case
     * is text arriving in the wrong place rather than not arriving at all.
     */
    safeRange: function () {
      if (!this.quill) return null;

      // Unforced first — it answers without moving focus, so it cannot provoke the mapping
      // in the case where focus is elsewhere entirely.
      try {
        var current = this.quill.getSelection();
        if (current) return current;
      } catch (e) { /* fall through to the forced read */ }

      try {
        var forced = this.quill.getSelection(true);
        if (forced) return forced;
      } catch (e) { /* fall through to the end of the document */ }

      var length = 0;
      try { length = Math.max(0, this.quill.getLength() - 1); } catch (e) { length = 0; }

      return { index: length, length: 0 };
    },
    emitValue: function () {
      var html = this.html();
      if (html !== this.modelValue) this.$emit('update:modelValue', html);
    },
    /**
     * Push the current content to the model NOW.
     *
     * Typing syncs on a debounce, so a Save clicked inside that window read the previous
     * value and concluded nothing had changed — the edit looked discarded. Every submit path
     * calls this first, which closes the window.
     */
    flush: function () {
      if (!this.quill) return;
      clearTimeout(this._syncTimer);
      this.emitValue();
    },
    insert: function (embed, value) {
      var range = this.safeRange() || { index: this.quill.getLength() };
      this.quill.insertEmbed(range.index, embed, value, 'user');
      this.quill.setSelection(range.index + 1, 'silent');
      this.emitValue();
    },

    // ---- Images -----------------------------------------------------------------------
    /** Toolbar image button: choose between uploading a file and this project's gallery. */
    pickImage: function () {
      if (!this.mediaUpload) return;
      if (this.mediaGallery) { this.openGallery(); return; }
      this.$refs.file.click();
    },
    openGallery: async function () {
      this.gallery = { open: true, items: [], loading: true };
      try {
        var resp = await this.$pb.api(this.mediaGallery);
        this.gallery.items = resp.result || [];
      } catch (e) { this.gallery.items = []; }
      this.gallery.loading = false;
    },
    chooseFromGallery: function (item) {
      this.gallery.open = false;
      this.insert('image', item.src);
    },
    uploadImage: async function (e) {
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) return;

      if (file.size > this.mediaMaxBytes) {
        this.$pb.toast('That image is larger than the upload limit.', 'error');
        return;
      }

      this.uploading = true;
      this.gallery.open = false;
      var form = new FormData();
      form.append('file-0', file); // the field name the media endpoint reads
      try {
        var resp = await this.$pb.api(this.mediaUpload, { method: 'POST', body: form });
        var uploaded = (resp.result || [])[0];
        if (uploaded) this.insert('image', uploaded.url);
      } catch (err) {
        this.$pb.toast(this.$pb.firstError(err) || 'Could not upload that image.', 'error');
      }
      this.uploading = false;
    }
  },
  template:
    '<div class="wi-editor" :style="{ \'--wi-editor-min\': minHeight }">' +
    '<div ref="area"></div>' +
    '<input ref="file" type="file" accept="image/*" class="hidden" @change="uploadImage" />' +
    '<div v-if="uploading" class="px-2 py-1 text-[12px] text-sub">Uploading image…</div>' +

    // Gallery picker: this project's uploads, plus a way to add a new one.
    '<div v-if="gallery.open" class="fixed inset-0 z-[120] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, function () { gallery.open = false; })"></div>' +
    '<div class="relative w-full max-w-[560px] bg-white rounded-xl shadow-xl flex flex-col max-h-[70vh]">' +
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '<span class="text-[14px] font-semibold text-head">Insert image</span>' +
    '<button type="button" @click="$refs.file.click()" class="ml-auto h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Upload</button>' +
    '<button type="button" @click="gallery.open = false" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" data-tip="Close" aria-label="Close">' +
    '' + wiIcon('xmark', 16) + '</button>' +
    '</div>' +
    '<div class="p-3 overflow-y-auto">' +
    '<div v-if="gallery.loading" class="py-8 text-center text-[13px] text-sub">Loading…</div>' +
    '<div v-else-if="gallery.items.length" class="grid grid-cols-3 sm:grid-cols-4 gap-2">' +
    '<button v-for="(g, i) in gallery.items" :key="i" type="button" @click="chooseFromGallery(g)" ' +
    'class="aspect-square rounded-md border border-line overflow-hidden hover:border-brand">' +
    '<img :src="g.src" :alt="g.name" class="h-full w-full object-cover" /></button>' +
    '</div>' +
    '<div v-else class="py-8 text-center text-[13px] text-sub">No images uploaded to this project yet.</div>' +
    '</div></div></div>' +

    '</div>'
};

/**
 * A blank create form.
 *
 * `seed` pre-fills the properties belonging to whatever screen this is mounted inside — an
 * epic's Work Items tab seeds `epic_id`, a cycle's seeds `cycle_id`, a module's seeds
 * `module_ids`. One object rather than a parameter per host: the list only grows, and "Add
 * work item" on a cycle page quietly creating an item outside that cycle is the bug this
 * prevents.
 */
function wiBlankForm(stateId, seed) {
  seed = seed || {};
  return {
    title: '', description: '', state_id: stateId || '', priority: 'none',
    start_date: '', due_date: '', parent_id: '',
    // Epic §9: optional, zero or one. Independent of the module and cycle beside it (§11/§12)
    // — picking one here neither sets nor limits either of the others.
    epic_id: seed.epic_id || '',
    cycle_id: seed.cycle_id || '',
    module_ids: (seed.module_ids || []).slice(),
    // Estimation §11: optional, one value, and "No Estimate" is a real choice (§13).
    estimate_value_id: '',
    assignee_ids: [], label_ids: []
  };
}

/**
 * The Work Items screen — grid, chip pickers, create modal and the work item drawer.
 *
 * A named component rather than an inline PB.boot argument, because it is no longer only its
 * own page: an Epic's Work Items tab mounts this SAME component, so a row there is editable,
 * clicking it opens this drawer, and the two can never drift apart. That is the same reason
 * <wi-list> was extracted, one level up.
 *
 * Everything it needs arrives in `bootstrap` (WorkItemScreenPayload), so a host only has to
 * hand it a payload — there is no hidden dependency on being the page.
 */
var WorkItemsScreen = {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    // On the per-item page the detail is the first thing painted, so its drafts are seeded
    // here rather than in mounted() — otherwise the title field renders empty for a frame.
    var pageItem = b.pageItemId
      ? (b.items || []).find(function (i) { return String(i.id) === String(b.pageItemId); })
      : null;

    return {
      project: b.project || {},
      items: Array.isArray(b.items) ? b.items : [],
      states: Array.isArray(b.states) ? b.states : [],
      labels: Array.isArray(b.labels) ? b.labels : [],
      // Cycles §8.1: the property is only offered when the project has the feature on.
      baseCyclesEnabled: !!b.cyclesEnabled,
      // Labels §3/§15: the picker appears only where the project has labels on. Chips
      // already on a work item stay readable either way (§28.5).
      baseLabelsEnabled: b.labelsEnabled !== false,
      // Estimation §4/§26: the chip appears only where the project has it on and a system is
      // configured — an estimate property with nothing to choose from is not a property.
      baseEstimatesEnabled: !!b.estimatesEnabled,
      estimates: Array.isArray(b.estimates) ? b.estimates : [],
      // Epic §4/§9: the property appears only where the project has the feature on.
      pagesEnabled: !!b.pagesEnabled,
      baseEpicsEnabled: !!b.epicsEnabled,
      epics: Array.isArray(b.epics) ? b.epics : [],
      // Set when this screen is mounted inside an epic, cycle or module: new work items
      // created there belong to it, and that host owns the page-level Add button.
      seed: b.seed || {},
      embedded: !!b.embedded,
      cycles: Array.isArray(b.cycles) ? b.cycles : [],
      // Modules §9.1: the property only appears when the project has the feature on.
      baseModulesEnabled: !!b.modulesEnabled,
      modules: Array.isArray(b.modules) ? b.modules : [],
      members: Array.isArray(b.members) ? b.members : [],
      priorities: Array.isArray(b.priorities) ? b.priorities : [],
      defaultStateId: b.defaultStateId || '',
      canCreate: !!b.canCreate,
      canEdit: !!b.canEdit,
      /*
       * MULTI-PROJECT MODE (Your Work).
       *
       * This screen is project-scoped by design: the rows share a project, so one set of
       * states, labels, members, cycles and endpoints serves every one of them. Your Work
       * hands it rows from SEVERAL projects, where that assumption would offer one project's
       * states for another's item and PATCH it to the wrong URL.
       *
       * `projects` is that vocabulary INDEXED by project id. When it is present, `vocab` and
       * `endpoints` below resolve from the row being acted on instead of from the flat
       * arrays; when it is absent — every existing caller — they return the flat arrays
       * unchanged, so a single-project screen behaves exactly as it did.
       */
      editorLicense: b.editorLicense || '',
      // A field, not a document surface — the tools somebody reaches for while describing a
      // piece of work. The full set belongs on Pages, where the thing being edited IS a
      // document. Image upload is in, because a description often needs a screenshot.
      //
      // `paragraph` is Jodit's format block (Normal / Heading 1-3) and `fontsize` its size
      // menu; both lead, because they act on the block you are in rather than the selection,
      // and the read view styles headings to match (`.pg-editor .jodit-wysiwyg` in
      // work-items.css) so what you type is the size it will be.
      editorButtons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,|,ul,ol,|,link,image,|,eraser',
      projectsById: b.projects || null,
      baseEndpoints: b.endpoints || {},
      // Detail view (§4.4). One component renders it two ways: a right-hand drawer over the
      // list, and — when the per-item URL was opened — the same panel as a full page with the
      // list hidden. `pageItemId` is the server telling us which.
      mediaMaxBytes: (b.mediaMaxKb || 5120) * 1024,
      pageMode: !!b.pageItemId,
      drawer: { open: !!b.pageItemId, id: b.pageItemId || null },
      // Structure sections (§19-§41): sub-tasks, dependencies, relations, links. Loaded with
      // the detail and replaced wholesale by every write, so the panel never has to merge a
      // partial response into what it already had.
      structure: null,
      // Collaboration tabs (§4.1). `All` is the default (§4.2); the tab lives in the URL so
      // a link can point at one (§4.4).
      tab: 'all',
      feed: null,
      feedLoading: false,
      timeTracking: b.timeTracking !== false,
      currentUserId: b.currentUserId || null,
      // The inline composer is for NEW comments only. Replying and editing happen in a
      // modal: both act on a specific comment that may be scrolled well out of view, and
      // typing into a box at the top of the tab gave no sense of what was being answered.
      composer: { content: '', busy: false },
      commentModal: { open: false, mode: 'reply', target: null, content: '', busy: false },
      updateForm: { open: false, id: null, status: 'on_track', content: '', busy: false },
      worklogForm: { open: false, id: null, date: '', hours: '', minutes: '', description: '', busy: false, error: '' },
      // The shared work-item picker behind "add sub-task" and every relation type.
      picker: { open: false, mode: '', type: '', title: '', query: '', allProjects: false, results: [], selected: [], busy: false },
      // Add / edit an external link (§38).
      linkModal: { open: false, id: null, url: '', title: '', busy: false, error: '' },
      // Which "add" dropdown is open — in the action row or in a section header.
      addMenu: '',
      // Sections collapse independently. Dependencies and Relations start CLOSED: they are
      // reference material about other work items, and expanded by default they push the
      // description and the conversation below the fold on every open.
      secOpen: { subtasks: true, dependencies: false, relations: false, links: true, pages: true },
      // The linked-pages picker. Its own dialog rather than the work item picker's: it
      // searches documentation, not work items, and the rows read nothing alike.
      pagePicker: { open: false, query: '', results: [], selected: [], busy: false, loaded: false },
      // Per-row ⋯ menu inside the structure sections.
      structMenu: { open: false, kind: '', row: null, style: {} },
      // The description editor is raised by ⋯ → Edit rather than sitting on the panel: an
      // always-mounted rich-text editor costs its own initialisation on every open, and the
      // detail view is read far more often than it is written.
      editingDescription: false,
      // The title is a heading first and a field second: it turns into an input on a double
      // click, so a stray click on the item's name cannot start an edit nobody intended.
      editingTitle: false,
      // Long descriptions are clamped with a Show more / Show less toggle (prototype
      // behaviour). `overflows` is measured, not guessed, so the control only appears when
      // there is genuinely something hidden.
      desc: { expanded: false, overflows: false },
      // Title/description are free text, so they are edited as drafts and saved on blur
      // rather than PATCHed on every keystroke.
      draft: {
        title: pageItem ? (pageItem.title || '') : '',
        description: pageItem ? (pageItem.description || '') : '',
      },
      // Row editing: which chip picker / action menu is open, and for which item.
      rowMenu: { open: false, kind: '', item: null, style: {} },
      rowQuery: '',
      // Creating a label from inside the picker (§4.3): name + colour, saved without
      // leaving the popover.
      newLabel: { open: false, name: '', color: '', busy: false, error: '' },
      deleteConfirm: { open: false, item: null, busy: false },
      table: null,
      // Create modal
      open: false, saving: false, createMore: false, errors: {},
      // The state the modal was opened with — what "Create more" resets each new item to.
      seedStateId: '',
      // Set when the create modal was opened as "new sub-task" of the open work item.
      seedParentId: '',
      form: wiBlankForm(b.defaultStateId || '', b.seed || {}),
      // Which chip popover is open in the modal ('state' | 'priority' | … | 'due_date').
      menu: '',
      // "Add parent" opens a full search panel rather than a popover (POC ParentSearchModal).
      parentOpen: false,
      memberQuery: '', labelQuery: '', parentQuery: '', epicQuery: ''
    };
  },
  computed: {
    /**
     * The row every picker and every endpoint is currently about.
     *
     * A chip picker wins over the drawer: opening a chip on row A while row B's drawer is
     * open must edit A, which is the order the two are closed in as well.
     */
    activeItem: function () {
      if (this.rowMenu && this.rowMenu.open && this.rowMenu.item) return this.rowMenu.item;

      return this.drawerItem || null;
    },
    /** Jodit where it is vendored, Quill behind it — the same test pages.js makes. */
    useJodit: function () { return pgJoditReady(); },
    /** The signed-in user, for the Subscribe button's face. Null if they are not a member. */
    me: function () {
      var id = String(this.currentUserId || '');
      var members = this.vocab.members || [];

      return members.filter(function (m) { return String(m.id) === id; })[0] || null;
    },
    /** That row's project vocabulary, or null on an ordinary single-project screen. */
    activeProject: function () {
      if (!this.projectsById) return null;
      var it = this.activeItem;

      return it && it.project_id ? (this.projectsById[String(it.project_id)] || null) : null;
    },
    /**
     * The option lists the pickers read.
     *
     * One indirection so the ~14 places that offer a choice do not each have to know whether
     * this screen is showing one project or several. On a single-project screen this is the
     * component's own flat data, which is what it always was.
     */
    vocab: function () {
      return this.activeProject || {
        states: this.states,
        labels: this.labels,
        labelsEnabled: this.baseLabelsEnabled,
        members: this.members,
        cycles: this.cycles,
        cyclesEnabled: this.baseCyclesEnabled,
        modules: this.modules,
        modulesEnabled: this.baseModulesEnabled,
        epics: this.epics,
        epicsEnabled: this.baseEpicsEnabled,
        estimates: this.estimates,
        estimatesEnabled: this.baseEstimatesEnabled
      };
    },
    /**
     * The endpoints every read and write uses.
     *
     * A computed rather than a data field, which is what lets all 26 `this.endpoints.X` call
     * sites become project-aware without one of them changing: each already acts on the open
     * row, and this resolves to that row's project.
     */
    endpoints: function () {
      var p = this.activeProject;

      return (p && p.endpoints) || this.baseEndpoints;
    },
    /*
     * The feature switches, resolved the same way — a project with Cycles off must not offer
     * the chip on its rows just because the project above it in the list has them on. Computed
     * from `vocab`, so the ~20 template references throughout the drawer are unchanged.
     */
    labelsEnabled: function () { return this.vocab.labelsEnabled !== false; },
    cyclesEnabled: function () { return !!this.vocab.cyclesEnabled; },
    modulesEnabled: function () { return !!this.vocab.modulesEnabled; },
    epicsEnabled: function () { return !!this.vocab.epicsEnabled; },
    estimatesEnabled: function () { return !!this.vocab.estimatesEnabled; },
    // Tabulator group order: the project's states in their configured order, then a trailing
    // bucket for items whose state was deleted.
    statesById: function () {
      var map = {};
      this.states.forEach(function (s) { map[String(s.id)] = s; });
      return map;
    },
    formState: function () { return this.statesById[String(this.form.state_id)] || null; },
    formPriority: function () { return WI_PRI[this.form.priority] || WI_PRI.none; },
    filteredMembers: function () {
      var q = (this.memberQuery || '').toLowerCase();
      return this.vocab.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    filteredLabels: function () {
      var q = (this.labelQuery || '').toLowerCase();
      return this.vocab.labels.filter(function (l) { return !q || (l.name || '').toLowerCase().indexOf(q) > -1; });
    },
    // Parent candidates: any existing item in this project (a brand-new item cannot be its
    // own parent, so no self-exclusion is needed on create).
    parentCandidates: function () {
      var q = (this.parentQuery || '').toLowerCase();
      return this.items.filter(function (i) {
        return !q || (i.title || '').toLowerCase().indexOf(q) > -1 || (i.identifier || '').toLowerCase().indexOf(q) > -1;
      }).slice(0, 50);
    },
    parentSelected: function () {
      var id = this.form.parent_id;
      if (!id) return null;
      return this.items.find(function (i) { return String(i.id) === String(id); }) || null;
    },
    selectedAssignees: function () {
      var ids = this.form.assignee_ids.map(String);
      return this.vocab.members.filter(function (m) { return ids.indexOf(String(m.id)) > -1; });
    },
    selectedEstimate: function () {
      var id = String(this.form.estimate_value_id || '');
      return this.vocab.estimates.filter(function (e) { return String(e.id) === id; })[0] || null;
    },
    /** §9: the picker searches epic names, and only this project's epics are in the list. */
    filteredEpics: function () {
      var q = (this.epicQuery || '').toLowerCase();
      return this.vocab.epics.filter(function (e) {
        return !q || (e.title || '').toLowerCase().indexOf(q) > -1 || String(e.identifier) === q;
      });
    },
    selectedEpic: function () {
      var id = String(this.form.epic_id || '');
      return this.vocab.epics.filter(function (e) { return String(e.id) === id; })[0] || null;
    },
    selectedLabels: function () {
      var ids = this.form.label_ids.map(String);
      return this.vocab.labels.filter(function (l) { return ids.indexOf(String(l.id)) > -1; });
    },
    totalCount: function () { return this.items.length; },
    /**
     * The item the detail view is showing, read from `items` rather than copied into the
     * drawer — an inline edit anywhere refreshes that row, and the drawer follows along.
     */
    drawerItem: function () {
      if (!this.drawer.id) return null;
      var id = String(this.drawer.id);
      return this.items.find(function (i) { return String(i.id) === id; }) || null;
    },
    /**
     * The banner above each dependency group. A related work item's ID says nothing about
     * which way the dependency points, so the direction is named and colour-coded: being
     * blocked is the state that needs attention, so it reads as a warning.
     */
    /** Assignee tooltip: the full name, which the label itself may have truncated. */
    assigneeTip: function () {
      var who = this.drawerItem && this.drawerItem.assignees && this.drawerItem.assignees.length
        ? this.drawerItem.assignees[0].name : '';
      if (!who) return this.canEdit ? 'Assign someone' : 'Unassigned';
      return (this.canEdit ? 'Change assignee — ' : 'Assigned to ') + who;
    },
    depGroups: function () {
      return [
        {
          key: 'blocked_by', label: 'Blocked by', cls: 'text-danger bg-danger/5',
          icon: '' + wiIcon('circle-slash', 13) + ''
        },
        {
          key: 'blocking', label: 'Blocking', cls: 'text-amber-700 bg-amber-50',
          icon: '' + wiIcon('triangle-exclamation', 13) + ''
        }
      ];
    },
    /** Same idea for relations, which are informational rather than blocking. */
    relGroups: function () {
      var chain = '' + wiIcon('link', 13) + '';
      var copy = '' + wiIcon('clone', 13) + '';
      return [
        { key: 'related', label: 'relates to', icon: chain },
        { key: 'duplicate_of', label: 'duplicate of', icon: copy },
        { key: 'duplicated_by', label: 'duplicated by', icon: copy }
      ];
    }
  },
  components: { 'wi-calendar': WiCalendar, 'wi-editor': WiEditor, 'wi-avatar': WiAvatar, 'wi-list': WiList, 'pg-editor': PgEditor },
  mounted: function () {
    this.bindGlobalCreate();

    // Escape closes the parent search panel first, then the create modal (POC behaviour).
    var self = this;
    this._onKeydown = function (e) {
      if (e.key !== 'Escape') return;
      // Innermost first: pickers, then the parent panel, then the create modal, then the
      // detail drawer — so Escape never closes the drawer out from under an open picker.
      if (self.editingTitle) { self.cancelEditTitle(); }
      else if (self.rowMenu.open) { self.closeRowMenu(); }
      else if (self.commentModal.open) { self.closeCommentModal(); }
      else if (self.linkModal.open) { self.linkModal.open = false; }
      else if (self.picker.open) { self.closePicker(); }
      else if (self.addMenu) { self.addMenu = ''; }
      else if (self.parentOpen) { self.closeParent(); }
      else if (self.menu) { self.menu = ''; }
      else if (self.open) { self.closeCreate(); }
      else if (self.drawer.open && !self.pageMode) { self.closeDrawer(); }
    };
    document.addEventListener('keydown', this._onKeydown);

    // The action row's dropdowns are plain elements, not <details>, so closing on an outside
    // click is ours to do — otherwise one stays open behind whatever the user clicks next.
    this._onDocClick = function (e) {
      if (self.addMenu && !e.target.closest('[data-add-menu]')) self.addMenu = '';
    };
    document.addEventListener('click', this._onDocClick);

    // The same description is taller in a narrower panel, so whether it overflows is a
    // question of width as well as content — without this the control stayed hidden until
    // the item was reopened.
    this._onResize = function () {
      clearTimeout(self._resizeTimer);
      self._resizeTimer = setTimeout(self.remeasureDescription, 150);
    };
    window.addEventListener('resize', this._onResize);

    // Arrived from another screen's "New work item" action (?create=1) — open the modal.
    try {
      var params = new URLSearchParams(window.location.search);
      if (this.canCreate && params.get('create') === '1') {
        this.openCreate('');
      }

      // The tab belongs to the detail view in both modes, so it is restored for both. It has
      // to be applied BEFORE the drawer opens, or the freshly opened panel shows tab one.
      var wantedTab = params.get('tab');
      if (wantedTab && this.tabList().some(function (t) { return t.key === wantedTab; })) {
        this.tab = wantedTab;
      }

      if (this.pageMode) {
        // The per-item URL: the detail is the page, so open it straight away.
        this.syncDraft();
        this.loadStructure();
        this.loadFeed();
      } else if (params.get('item')) {
        // ?item=<ID> deep link — the shape "Copy link" produced before the item page existed.
        var wanted = String(params.get('item'));
        var match = this.items.find(function (i) {
          return String(i.identifier) === wanted || String(i.id) === wanted;
        });
        if (match) this.openDrawer(match);
      }
    } catch (e) {}
  },
  beforeUnmount: function () {
    if (this._onResize) { window.removeEventListener('resize', this._onResize); this._onResize = null; }
    clearTimeout(this._resizeTimer);
    if (this._globalCreate) {
      this._globalCreate.el.removeEventListener('click', this._globalCreate.handler);
      this._globalCreate = null;
    }
    if (this._onKeydown) {
      document.removeEventListener('keydown', this._onKeydown);
      this._onKeydown = null;
    }
    if (this._onDocClick) {
      document.removeEventListener('click', this._onDocClick);
      this._onDocClick = null;
    }
  },
  methods: {
    /**
     * The sidebar's global "New work item" action (Blade, outside this component's root).
     * Its href already points at this screen with ?create=1 so it works without JS; here we
     * intercept the click and open the modal in place instead of reloading the page.
     */
    bindGlobalCreate: function () {
      var el = document.getElementById('new-work-item-btn');
      if (!el || !this.canCreate) return;
      var self = this;
      var handler = function (e) { e.preventDefault(); self.openCreate(''); };
      el.addEventListener('click', handler);
      this._globalCreate = { el: el, handler: handler };
    },
    // ---------- The list (wi-list) ----------
    /** A row chip was clicked: open that property's picker, anchored to the chip. */
    // ---------- Detail toolbar: vote and subscribe (POC html/work-items.html) ----------
    /**
     * Vote, or take the vote back.
     *
     * Both endpoints toggle server-side and answer with the resulting counts, so nothing here
     * predicts what the click did — a second tab open on the same item would make a different
     * prediction, and one of them would be wrong until the next reload.
     */
    vote: async function (value) {
      await this.react(this.endpoints.vote, { value: value });
    },

    toggleSubscribe: async function () {
      var resp = await this.react(this.endpoints.subscribe, {});
      if (resp) this.$pb.toast(resp.message);
    },

    /** POST, then paint the item from the response — in the list as well as the drawer. */
    react: async function (endpoint, body) {
      var item = this.drawerItem;
      if (!item || !endpoint) return null;

      try {
        var resp = await this.$pb.api(this.$pb.withId(endpoint, item.id), { method: 'POST', body: body });
        // Written onto the row in `items`, not onto the drawer's copy: the drawer reads from
        // that array, and the row underneath has to agree with it when the drawer closes.
        var row = this.items.filter(function (i) { return i.id === item.id; })[0] || item;
        row.votes = resp.votes;
        row.my_vote = resp.my_vote;
        row.subscribed = resp.subscribed;

        return resp;
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'That did not go through.'), 'error');

        return null;
      }
    },

    onChip: function (e) { this.openRowMenu(e.kind, e.item, e.el); },
    /** Ask the list to redraw after the items array was mutated in place. */
    refreshTable: function () { if (this.$refs.list) this.$refs.list.refresh(); },
    // ---------- Inline row editing (§4.2) ----------
    /** Open a chip picker or the action menu, anchored to the clicked control. */
    openRowMenu: function (kind, item, btn) {
      if (!this.canEdit) return;
      var widths = { state: 208, priority: 200, assignees: 256, labels: 256, cycle: 256, epic: 256, estimate: 220, modules: 256, start_date: 300, due_date: 300, menu: 200 };
      var w = widths[kind] || 208;
      var heights = { menu: 220, start_date: 340, due_date: 340 };
      var h = heights[kind] || 260;
      var r = btn.getBoundingClientRect();
      var style = { position: 'fixed', width: w + 'px', zIndex: 120, left: Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px' };
      // Prefer opening downward; flip up when the viewport bottom is close.
      if (r.bottom + h > window.innerHeight - 8 && r.top > h) style.bottom = (window.innerHeight - r.top + 6) + 'px';
      else style.top = (r.bottom + 6) + 'px';

      this.rowQuery = '';
      this.newLabel = { open: false, name: '', color: '', busy: false, error: '' };
      this.rowMenu = { open: true, kind: kind, item: item, style: style };
    },
    closeRowMenu: function () { this.rowMenu = { open: false, kind: '', item: null, style: {} }; },
    rowMembers: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.vocab.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    rowLabels: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.vocab.labels.filter(function (l) { return !q || (l.name || '').toLowerCase().indexOf(q) > -1; });
    },
    /** Palette offered when creating a label inline; the server picks one if none is set. */
    labelColors: function () {
      return ['#6366F1', '#EC4899', '#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#8B5CF6', '#14B8A6'];
    },
    /** Is the typed name already a label? Then "create" would fork the vocabulary. */
    labelExists: function (name) {
      var q = (name || '').trim().toLowerCase();
      return !!q && this.vocab.labels.some(function (l) { return (l.name || '').toLowerCase() === q; });
    },
    startNewLabel: function () {
      this.newLabel = {
        open: true,
        // Whatever was typed into the search is almost always the label being looked for.
        name: (this.rowQuery || '').trim(),
        color: this.labelColors()[this.vocab.labels.length % this.labelColors().length],
        busy: false, error: ''
      };
    },
    cancelNewLabel: function () { this.newLabel.open = false; },
    saveNewLabel: async function () {
      var name = (this.newLabel.name || '').trim();
      var item = this.rowMenu.item;
      if (!name || this.newLabel.busy || !item || !this.endpoints.createLabel) return;

      this.newLabel.busy = true; this.newLabel.error = '';
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.createLabel, item.id), {
          method: 'POST', body: { name: name, color: this.newLabel.color }
        });
        // The project's vocabulary grew, so every picker on this screen should know — into
        // THAT project's list when the screen is showing several, or the label would appear
        // under every project on a Your Work list rather than the one it was created in.
        if (resp.labels) {
          if (this.activeProject) this.activeProject.labels = resp.labels;
          else this.labels = resp.labels;
        }
        this.newLabel.open = false;
        this.rowQuery = '';
        // Applying it goes through the same PATCH as any other label change.
        if (!this.rowHasLabel(resp.label)) this.toggleRowLabel(resp.label);
      } catch (e) { this.newLabel.error = this.$pb.firstError(e); }
      this.newLabel.busy = false;
    },
    rowHasAssignee: function (m) {
      var it = this.rowMenu.item;
      return !!it && (it.assignees || []).some(function (a) { return String(a.id) === String(m.id); });
    },
    rowHasLabel: function (l) {
      var it = this.rowMenu.item;
      return !!it && (it.labels || []).some(function (x) { return String(x.id) === String(l.id); });
    },
    /** PATCH one or more properties and swap the refreshed row back into the grid. */
    /**
     * PATCH one or more properties and swap the refreshed row back into the grid.
     *
     * Confirms on success as well as on failure: a chip edit changes a single word on a row
     * the user may not be looking at, so without a toast there is nothing to distinguish
     * "saved" from "the click missed".
     */
    patchItem: async function (item, body, close, message) {
      if (!item || !this.endpoints.update) return;
      try {
        var url = this.$pb.withId(this.endpoints.update, item.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: body });
        this.replaceItem(resp.item);
        this.$pb.toast(message || 'Work item updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      if (close !== false) this.closeRowMenu();
    },
    /**
     * Swap a refreshed card back into the list.
     *
     * When the row is only carrying different values, Tabulator updates that one row; a full
     * replaceData() rebuilds and regroups the entire grid, which is a lot of work to show a
     * changed priority. The grid is only rebuilt when the row has to MOVE — a state change
     * puts it in a different group — or when it was not in the list to begin with.
     */
    replaceItem: function (card) {
      if (!card) return;
      var previous = null;
      for (var i = 0; i < this.items.length; i++) {
        if (this.items[i].id === card.id) { previous = this.items[i]; this.items.splice(i, 1, card); break; }
      }

      // Same state group: update that one row and keep scroll position and collapsed
      // groups. A state change moves the row between groups, which needs a rebuild.
      var sameGroup = previous && String(previous.state_id || '') === String(card.state_id || '');
      if (sameGroup && this.$refs.list && this.$refs.list.updateRow(card)) return;

      this.refreshTable();
    },
    removeItem: function (item) {
      var i = this.items.indexOf(item);
      if (i > -1) this.items.splice(i, 1);
      // The detail was showing the item that just left the list (archived or deleted) —
      // close it rather than leave an empty panel, or an empty page in page mode.
      if (item && this.drawer.id && String(this.drawer.id) === String(item.id)) this.closeDrawer();
      this.refreshTable();
    },
    setRowState: function (s) { this.patchItem(this.rowMenu.item, { state_id: s ? s.id : '' }, true, 'State updated.'); },
    /** Cycles offered by the picker, filtered by the same search box the others use. */
    rowCycles: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.vocab.cycles.filter(function (c) { return !q || (c.name || '').toLowerCase().indexOf(q) > -1; });
    },
    /**
     * Set or clear a work item's estimate (§14/§15).
     *
     * One value, so picking replaces; picking the current one clears it, which is the same
     * single control §15 describes as "No Estimate".
     */
    setRowEstimate: function (e) {
      var it = this.rowMenu.item;
      if (!it) return;
      var same = e && String(it.estimate_value_id) === String(e.id);
      this.patchItem(it, { estimate_value_id: same || !e ? '' : e.id }, true,
        same || !e ? 'Estimate removed.' : 'Estimate set to ' + e.label + '.');
    },
    /** Epics offered by the picker, filtered by the same search box the others use. */
    rowEpics: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.vocab.epics.filter(function (e) {
        return !q || (e.title || '').toLowerCase().indexOf(q) > -1 || String(e.identifier) === q;
      });
    },
    /**
     * Put the item in an epic, or take it out (§9).
     *
     * ONE epic per work item, so picking replaces — the same shape as the cycle picker above
     * and deliberately not the module one. Picking the epic it is already in clears it, so a
     * single control both assigns and removes.
     *
     * §11/§12: this sends `epic_id` and nothing else. The item's module and cycle are not read
     * here and not written, which is the whole point of keeping the three independent.
     */
    setRowEpic: function (e) {
      var it = this.rowMenu.item;
      if (!it) return;
      var same = e && String(it.epic_id) === String(e.id);
      this.patchItem(it, { epic_id: same || !e ? '' : e.id }, true,
        same || !e ? 'Removed from the epic.' : 'Moved to ' + e.title + '.');
    },
    /** Modules offered by the picker, filtered by the same search box the others use. */
    rowModules: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.vocab.modules.filter(function (m) { return !q || (m.title || '').toLowerCase().indexOf(q) > -1; });
    },
    rowHasModule: function (m) {
      var it = this.rowMenu.item;
      return !!(it && (it.modules || []).some(function (x) { return x.id === m.id; }));
    },
    /**
     * Add or remove one module (§9.3).
     *
     * Multi-select, so the picker stays open and each click toggles one membership — the same
     * behaviour as labels, and unlike the cycle picker where choosing one replaces the other.
     */
    toggleRowModule: function (m) {
      var it = this.rowMenu.item;
      if (!it) return;
      var current = (it.modules || []).map(function (x) { return x.id; });
      var has = current.indexOf(m.id) > -1;
      var ids = has ? current.filter(function (id) { return id !== m.id; }) : current.concat([m.id]);
      this.patchItem(it, { module_ids: ids }, false, has ? 'Removed from ' + m.title + '.' : 'Added to ' + m.title + '.');
    },
    /**
     * Put the item in a cycle, or take it out (§8.3).
     *
     * Picking the cycle it is already in clears it, so the same control both assigns and
     * removes — and picking a DIFFERENT one is a move, which the server does in one update
     * rather than a remove followed by an add.
     */
    setRowCycle: function (c) {
      var it = this.rowMenu.item;
      if (!it) return;
      var same = c && it.cycle_id === c.id;
      this.patchItem(it, { cycle_id: same || !c ? '' : c.id }, true,
        same || !c ? 'Removed from the cycle.' : 'Moved to ' + c.name + '.');
    },
    setRowPriority: function (p) { this.patchItem(this.rowMenu.item, { priority: p.key }, true, 'Priority updated.'); },
    /**
     * A work item has exactly one assignee (§4.3, revised), so picking a member replaces
     * whoever held it — picking the current one clears it. The picker closes on choice,
     * because there is nothing left to multi-select.
     */
    toggleRowAssignee: function (m) {
      var it = this.rowMenu.item;
      var ids = this.rowHasAssignee(m) ? [] : [m.id];
      this.patchItem(it, { assignee_ids: ids }, true, ids.length ? 'Assignee updated.' : 'Assignee cleared.');
    },
    toggleRowLabel: function (l) {
      var it = this.rowMenu.item;
      var ids = (it.labels || []).map(function (x) { return x.id; });
      var at = ids.map(String).indexOf(String(l.id));
      if (at > -1) ids.splice(at, 1); else ids.push(l.id);
      this.patchItem(it, { label_ids: ids }, false, 'Labels updated.');
    },
    setRowDate: function (iso) {
      var body = {}; body[this.rowMenu.kind] = iso;
      var label = this.rowMenu.kind === 'start_date' ? 'Start date' : 'Due date';
      this.patchItem(this.rowMenu.item, body, true, iso ? label + ' updated.' : label + ' cleared.');
    },
    clearRowDate: function () { this.setRowDate(''); },

    // ---------- Detail view: drawer + page mode (§4.4) ----------
    /** Open the detail for an item. Same call from a grid row, a mobile card or a deep link. */
    openDrawer: function (item) {
      if (!item) return;
      this.drawer.id = item.id;
      this.drawer.open = true;
      this.syncDraft();
      this.loadStructure();
      this.loadFeed();
      this.syncUrl();
    },
    closeDrawer: function () {
      // On the per-item page there is nothing behind the panel — closing means going back to
      // the list, not hiding the only content on screen.
      if (this.pageMode) { window.location.href = this.endpoints.list || '/'; return; }
      this.drawer.open = false;
      this.drawer.id = null;
      this.feed = null;
      this.syncUrl();
    },

    // ---------- Collaboration tabs (§4-§11) ----------
    /** The seven tabs, minus Worklogs when the project has time tracking off (§9.2). */
    tabList: function () {
      var tabs = [
        { key: 'all', label: 'All' }, { key: 'activity', label: 'Activity' },
        { key: 'comments', label: 'Comments' }, { key: 'updates', label: 'Updates' },
        { key: 'worklogs', label: 'Worklogs' }, { key: 'transition', label: 'Transition' },
        { key: 'history', label: 'History' }
      ];
      return this.timeTracking ? tabs : tabs.filter(function (t) { return t.key !== 'worklogs'; });
    },
    setTab: function (key) {
      this.tab = key;
      this.syncUrl();
    },
    /**
     * Keep the URL describing what is on screen (§4.4: survives a refresh, can be linked to).
     *
     * BOTH parts have to be written, which is the bug this replaces. `setTab` used to add
     * `?tab=` on its own while nothing recorded WHICH item was open, so switching to Updates
     * in the drawer produced `/work-items?tab=updates` — a URL that reloads to the bare list,
     * no item, and a tab nobody reads. It looked like a deep link and did nothing.
     *
     * `replaceState`, not `pushState`: a tab click is not a navigation, and Back should leave
     * the screen rather than walk the tabs.
     */
    syncUrl: function () {
      // The per-item page already IS the URL for the item; it only carries the tab.
      if (this.pageMode) {
        this.writeParams({ tab: this.tab });

        return;
      }

      this.writeParams(this.drawer.open && this.drawer.id
        ? { item: this.drawer.id, tab: this.tab }
        : { item: null, tab: null });
    },
    /** Set or delete query params on the current URL. */
    writeParams: function (params) {
      try {
        var url = new URL(window.location.href);
        Object.keys(params).forEach(function (key) {
          if (params[key] === null || params[key] === undefined || params[key] === '') url.searchParams.delete(key);
          else url.searchParams.set(key, params[key]);
        });
        window.history.replaceState({}, '', url);
      } catch (e) { /* history is unavailable — the screen still works */ }
    },
    loadFeed: async function () {
      var it = this.drawerItem;
      this.feed = null;
      if (!it || !this.endpoints.feed) return;
      this.feedLoading = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.feed, it.id));
        this.feed = resp.feed;
      } catch (e) { this.feed = null; }
      this.feedLoading = false;
    },
    applyFeed: function (resp) {
      if (resp && resp.feed) this.feed = resp.feed;
      if (resp && resp.message) this.$pb.toast(resp.message);
      this.syncStatusUpdate();
    },
    /**
     * Mirror the item's current status update onto its row (§8).
     *
     * The row's At Risk / Off Track label arrives with the list, but posting, editing or
     * deleting an update returns only the feed — so without this the row keeps the old label
     * until a reload, which is exactly when someone is looking for it to change.
     *
     * Derived from the feed just received rather than re-fetching the row: it is the same
     * data, and the newest update IS the current status (the feed is newest-first).
     */
    syncStatusUpdate: function () {
      var item = this.drawerItem;
      if (!item || !this.feed) return;

      var newest = (this.feed.updates || [])[0];
      var concerning = newest && (newest.status === 'at_risk' || newest.status === 'off_track');

      item.status_update = concerning
        ? { status: newest.status, label: newest.status_label, comment: wiPlainText(newest.content, 240) }
        : null;

      if (this.$refs.list) this.$refs.list.updateRow(item);
    },
    feedUrl: function (name, suffix) {
      var it = this.drawerItem;
      if (!it || !this.endpoints[name]) return '';
      return this.$pb.withId(this.endpoints[name], it.id) + (suffix || '');
    },

    // ---------- Comments (§7) ----------
    /** Reply and edit both open the modal, on top of whatever is on screen. */
    startReply: function (comment) {
      this.commentModal = { open: true, mode: 'reply', target: comment, content: '', busy: false };
    },
    startEditComment: function (comment) {
      this.commentModal = { open: true, mode: 'edit', target: comment, content: comment.content || '', busy: false };
    },
    closeCommentModal: function () { this.commentModal.open = false; },
    /** Strip tags before testing for content: an editor's empty document is still markup. */
    hasText: function (html) {
      return (html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== '';
    },
    submitCommentModal: async function () {
      this.flushEditor('commentModalEditor');
      var m = this.commentModal;
      if (!this.hasText(m.content) || m.busy) return;
      m.busy = true;
      try {
        if (m.mode === 'edit') {
          this.applyFeed(await this.$pb.api(this.feedUrl('comments', '/' + m.target.id), {
            method: 'PATCH', body: { content: m.content }
          }));
        } else {
          this.applyFeed(await this.$pb.api(this.feedUrl('comments'), {
            method: 'POST', body: { content: m.content, parent_comment_id: m.target.id }
          }));
        }
        m.open = false;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      m.busy = false;
    },
    /** The inline composer now only ever posts a new top-level comment (§5.3). */
    postComment: async function () {
      this.flushEditor('commentEditor');
      if (!this.hasText(this.composer.content) || this.composer.busy) return;
      this.composer.busy = true;
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('comments'), {
          method: 'POST', body: { content: this.composer.content }
        }));
        this.composer.content = '';
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.composer.busy = false;
    },
    deleteComment: async function (comment) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('comments', '/' + comment.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    canEditComment: function (comment) {
      return comment.author && String(comment.author.id) === String(this.currentUserId);
    },

    // ---------- Updates (§8) ----------
    openUpdateForm: function (update) {
      this.updateForm = {
        open: true, id: update ? update.id : null,
        status: update ? update.status : 'on_track',
        content: update ? update.content : '', busy: false
      };
    },
    saveUpdate: async function () {
      this.flushEditor('updateEditor');
      var body = (this.updateForm.content || '').replace(/<[^>]*>/g, '').trim();
      if (!body || this.updateForm.busy) return;
      this.updateForm.busy = true;
      var url = this.feedUrl('updates', this.updateForm.id ? '/' + this.updateForm.id : '');
      try {
        this.applyFeed(await this.$pb.api(url, {
          method: this.updateForm.id ? 'PATCH' : 'POST',
          body: { status: this.updateForm.status, content: this.updateForm.content }
        }));
        this.updateForm.open = false;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.updateForm.busy = false;
    },
    deleteUpdate: async function (update) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('updates', '/' + update.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    updateMeta: function (status) {
      // §8.4: icon and label always travel together — never colour on its own.
      var map = {
        on_track: { label: 'On Track', cls: 'text-success border-success/30 bg-success/5', icon: '' + wiIcon('check', 13) + '' },
        at_risk: { label: 'At Risk', cls: 'text-amber-700 border-amber-300 bg-amber-50', icon: '' + wiIcon('triangle-exclamation', 13) + '' },
        off_track: { label: 'Off Track', cls: 'text-danger border-danger/30 bg-danger/5', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.9"/><path d="M12 7.5v5M12 15.5v.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>' }
      };
      return map[status] || map.on_track;
    },

    // ---------- Worklogs (§9) ----------
    openWorklogForm: function (log) {
      var today = new Date();
      var iso = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      this.worklogForm = {
        open: true, id: log ? log.id : null,
        date: log ? log.work_date : iso,
        hours: log ? Math.floor(log.minutes / 60) : '',
        minutes: log ? log.minutes % 60 : '',
        description: log ? (log.description || '') : '',
        busy: false, error: ''
      };
    },
    saveWorklog: async function () {
      if (this.worklogForm.busy) return;
      var total = (parseInt(this.worklogForm.hours || 0, 10) * 60) + parseInt(this.worklogForm.minutes || 0, 10);
      // §9.5, checked here too so the refusal is instant — the server re-checks it.
      if (!total) { this.worklogForm.error = 'Log at least one minute.'; return; }
      this.worklogForm.busy = true; this.worklogForm.error = '';
      var url = this.feedUrl('worklogs', this.worklogForm.id ? '/' + this.worklogForm.id : '');
      try {
        this.applyFeed(await this.$pb.api(url, {
          method: this.worklogForm.id ? 'PATCH' : 'POST',
          body: {
            work_date: this.worklogForm.date,
            hours: parseInt(this.worklogForm.hours || 0, 10),
            minutes: parseInt(this.worklogForm.minutes || 0, 10),
            description: this.worklogForm.description
          }
        }));
        this.worklogForm.open = false;
      } catch (e) { this.worklogForm.error = this.$pb.firstError(e); }
      this.worklogForm.busy = false;
    },
    deleteWorklog: async function (log) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('worklogs', '/' + log.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- Structure: sub-tasks, dependencies, relations, links (§19-§41) ----------
    loadStructure: async function () {
      var it = this.drawerItem;
      this.structure = null;
      if (!it || !this.endpoints.structure) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.structure, it.id));
        this.structure = resp.structure;
      } catch (e) { /* the sections are supporting detail — never block the panel on them */ }
    },
    /** Swap in the structure a write returned, and surface its message. */
    applyStructure: function (resp) {
      if (resp && resp.structure) this.structure = resp.structure;
      this.mergeCards(resp && resp.cards);
      if (resp && resp.message) this.$pb.toast(resp.message);
    },
    /**
     * Fold rows a write returned back into the grid.
     *
     * A relation change moves a chip on a row that is usually NOT the open drawer: adding a
     * blocker puts the "Blocked" chip on the item being blocked. Without this the grid kept
     * the row it was rendered with and the chip only appeared after a reload.
     *
     * Rows not currently in the list are skipped rather than appended — the list is filtered
     * and paged, so an item that is not there was left out deliberately.
     */
    mergeCards: function (cards) {
      if (!cards || !cards.length) return;
      var self = this;

      cards.forEach(function (card) {
        for (var i = 0; i < self.items.length; i++) {
          if (String(self.items[i].id) !== String(card.id)) continue;
          self.items.splice(i, 1, card);
          // The row keeps its group — a relation cannot change state — so one row redraw is
          // enough and the grid keeps its scroll position and collapsed groups.
          if (!self.$refs.list || !self.$refs.list.updateRow(card)) self.refreshTable();
          break;
        }
      });

      // The drawer needs no separate update: `drawerItem` is computed from `items`, so
      // replacing the row IS the drawer refresh.
    },
    structureUrl: function (name, extra) {
      var it = this.drawerItem;
      if (!it || !this.endpoints[name]) return '';
      return this.$pb.withId(this.endpoints[name], it.id) + (extra || '');
    },
    /**
     * Does this item have anything to show in the structure card?
     *
     * Every kind has to be listed. A work item whose ONLY structure was a linked page still
     * answered false here, so the card never rendered and the page it was linked to was
     * invisible — the section inside it was correct and simply never reached.
     */
    hasStructure: function () {
      var s = this.structure;
      if (!s) return false;
      return !!(s.subtasks.items.length || s.dependencies.blocking.length || s.dependencies.blocked_by.length ||
        s.relations.related.length || s.relations.duplicate_of.length || s.relations.duplicated_by.length ||
        s.links.length || (s.pages && s.pages.length));
    },

    /**
     * Did this click start on the backdrop it ended on?
     *
     * Selecting text by dragging routinely ends with the mouse outside the panel — over the
     * backdrop — and a plain `@click` there fired on that mouse-up and closed the dialog
     * mid-selection. Which is what made copying (and then pasting) inside the editor look
     * broken: the selection, and the dialog, were gone before the keystroke.
     */
    backdropDown: function (e) { this._backdropArmed = e.target === e.currentTarget; },
    backdropClick: function (e, close) {
      var armed = this._backdropArmed;
      this._backdropArmed = false;
      if (armed && e.target === e.currentTarget) close();
    },
    /** Flush a named editor before reading its model — see WiEditor.flush(). */
    flushEditor: function (name) {
      var editor = this.$refs[name];
      if (editor && editor.flush) { editor.flush(); return true; }
      // A missing editor means the submit is about to read a stale model; better to know.
      if (window.console) window.console.warn('[work-items] editor ref not found: ' + name);
      return false;
    },
    toggleSection: function (key) { this.secOpen[key] = !this.secOpen[key]; },

    /** Row ⋯ menu: Open · Copy link · Remove (§26/§32/§41). */
    openStructMenu: function (kind, row, ev) {
      var btn = ev.currentTarget;
      var r = btn.getBoundingClientRect();
      var w = 200;
      var style = { position: 'fixed', width: w + 'px', zIndex: 120, left: Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px' };
      if (r.bottom + 150 > window.innerHeight - 8 && r.top > 150) style.bottom = (window.innerHeight - r.top + 6) + 'px';
      else style.top = (r.bottom + 6) + 'px';
      this.structMenu = { open: true, kind: kind, row: row, style: style };
    },
    closeStructMenu: function () { this.structMenu = { open: false, kind: '', row: null, style: {} }; },
    structRemove: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      if (m.kind === 'subtask') this.removeSubtask(m.row);
      else if (m.kind === 'link') this.deleteLink(m.row);
      else if (m.kind === 'page') this.unlinkPage(m.row);
      else this.removeRelation(m.row);
    },
    structOpen: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      if (m.kind === 'link') { window.location.href = m.row.url; return; }
      window.location.href = m.kind === 'page' ? this.pageUrl(m.row) : this.rowUrl(m.row);
    },
    structCopy: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      if (m.kind === 'link') this.copyText(m.row.url);
      else if (m.kind === 'page') this.copyText(window.location.origin + this.pageUrl(m.row));
      else this.copyRowLink(m.row);
    },
    copyText: async function (text) {
      try { await navigator.clipboard.writeText(text); this.$pb.toast('Link copied.'); }
      catch (e) { window.prompt('Copy this link', text); }
    },
    /** "less than a minute ago" / "3 hours ago" — the timestamp a link row shows. */
    relativeTime: function (iso) {
      if (!iso) return '';
      var then = new Date(iso);
      if (isNaN(then)) return '';
      var secs = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
      if (secs < 60) return 'less than a minute ago';
      var units = [['minute', 60], ['hour', 3600], ['day', 86400], ['month', 2592000], ['year', 31536000]];
      for (var i = units.length - 1; i >= 0; i--) {
        var n = Math.floor(secs / units[i][1]);
        if (n >= 1) return n + ' ' + units[i][0] + (n === 1 ? '' : 's') + ' ago';
      }
      return 'just now';
    },

    // ---------- Linked pages ----------
    openPagePicker: function () {
      this.addMenu = '';
      this.pagePicker = { open: true, query: '', results: [], selected: [], busy: false, loaded: false };
      this.searchPages();
    },
    searchPages: async function () {
      var it = this.drawerItem;
      if (!it || !this.endpoints.pageSearch) return;
      this.pagePicker.busy = true;
      try {
        var url = this.$pb.withId(this.endpoints.pageSearch, it.id) +
          '?q=' + encodeURIComponent(this.pagePicker.query || '');
        var resp = await this.$pb.api(url);
        this.pagePicker.results = resp.items || [];
        this.pagePicker.loaded = true;
      } catch (e) { this.pagePicker.results = []; }
      this.pagePicker.busy = false;
    },
    /** Debounced so typing does not fire a request per keystroke. */
    onPageQuery: function () {
      var self = this;
      clearTimeout(this._pageTimer);
      this._pageTimer = setTimeout(function () { self.searchPages(); }, 200);
    },
    togglePagePick: function (row) {
      // Already linked: shown as such rather than offered again.
      if (row.linked) return;
      var ids = this.pagePicker.selected.map(String);
      var at = ids.indexOf(String(row.id));
      if (at > -1) this.pagePicker.selected.splice(at, 1); else this.pagePicker.selected.push(row.id);
    },
    isPagePicked: function (row) {
      return row.linked || this.pagePicker.selected.map(String).indexOf(String(row.id)) > -1;
    },
    confirmPagePicker: async function () {
      if (!this.pagePicker.selected.length || this.pagePicker.busy) return;
      this.pagePicker.busy = true;
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('pages'), {
          method: 'POST', body: { page_ids: this.pagePicker.selected }
        }));
        this.pagePicker.open = false;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.pagePicker.busy = false;
    },
    unlinkPage: async function (page) {
      try {
        // Only the link goes — the page is documentation in its own right.
        this.applyStructure(await this.$pb.api(this.structureUrl('pages') + '/' + page.id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    pageUrl: function (page) {
      return this.endpoints.page ? this.$pb.withId(this.endpoints.page, page.id) : '#';
    },

    // ---------- The shared work-item picker (§22 / §30 / §34) ----------
    /** §20: the sub-task button offers both routes; the choice decides which modal opens. */
    createSubtask: function () {
      this.addMenu = '';
      var it = this.drawerItem;
      if (!it) return;
      // Opens the normal create modal with the parent already set (§21), so a sub-task is
      // created through exactly the same path — and the same validation — as any work item.
      this.openCreate('', it.id);
    },
    /**
     * The parent search (§5). The same modal as sub-tasks and relations rather than a second
     * one that looks almost like it — the question ("which work item?") is identical, only the
     * arity differs, which `mode` carries.
     */
    openParentPicker: function () {
      if (!this.canEdit) return;
      this.openPicker('parent', '', 'Set parent');
    },
    clearParent: async function () {
      var it = this.drawerItem;
      if (!it || !this.canEdit) return;
      await this.patchItem(it, { parent_id: null }, true, 'Parent removed.');
    },
    openPicker: function (mode, type, title) {
      this.addMenu = '';
      this.picker = { open: true, mode: mode, type: type || '', title: title, query: '', allProjects: false, results: [], selected: [], busy: false };
      this.searchItems();
      var self = this;
      this.$nextTick(function () { if (self.$refs.pickerSearch) self.$refs.pickerSearch.focus(); });
    },
    closePicker: function () { this.picker.open = false; },
    searchItems: async function () {
      var it = this.drawerItem;
      if (!it || !this.endpoints.search) return;
      var q = encodeURIComponent(this.picker.query || '');
      var url = this.structureUrl('search', '?q=' + q + (this.picker.allProjects ? '&all_projects=1' : '') +
        (this.picker.mode === 'parent' ? '&for=parent' : '') +
        // What the picker is choosing FOR, so the server can mark what is already taken.
        '&mode=' + encodeURIComponent(this.picker.mode || '') +
        '&type=' + encodeURIComponent(this.picker.type || ''));
      try {
        var resp = await this.$pb.api(url);
        this.picker.results = resp.items || [];
      } catch (e) { this.picker.results = []; }
    },
    /** Debounced so typing does not fire a request per keystroke. */
    onPickerQuery: function () {
      var self = this;
      clearTimeout(this._pickerTimer);
      this._pickerTimer = setTimeout(function () { self.searchItems(); }, 200);
    },
    togglePick: function (row) {
      // Already related, or related the opposite way round. It arrives ticked to say so; the
      // server would refuse it, and an error dialog is a worse way to learn that.
      if (row.blocked) return;

      var ids = this.picker.selected.map(String);
      var at = ids.indexOf(String(row.id));
      // An item has exactly one parent (§5), so here the choice REPLACES — the same shape the
      // cycle picker uses, and the reason the modal's footer says "Set" rather than "Add".
      if (this.picker.mode === 'parent') {
        this.picker.selected = at > -1 ? [] : [row.id];
        return;
      }
      if (at > -1) this.picker.selected.splice(at, 1); else this.picker.selected.push(row.id);
    },
    isPicked: function (row) {
      return !!row.blocked || this.picker.selected.map(String).indexOf(String(row.id)) > -1;
    },
    confirmPicker: async function () {
      if (!this.picker.selected.length || this.picker.busy) return;

      // Parent is a column on the item, not a relation table, so it goes through the ordinary
      // PATCH — the same path, and the same validation, as changing state or a date.
      if (this.picker.mode === 'parent') {
        this.picker.busy = true;
        var ok = await this.patchItem(this.drawerItem, { parent_id: this.picker.selected[0] }, true, 'Parent updated.');
        this.picker.busy = false;
        if (ok !== false) this.closePicker();
        return;
      }

      this.picker.busy = true;
      var body = { work_item_ids: this.picker.selected };
      var url = this.structureUrl(this.picker.mode === 'subtask' ? 'subtasks' : 'relations');
      if (this.picker.mode !== 'subtask') body.relation_type = this.picker.type;
      try {
        this.applyStructure(await this.$pb.api(url, { method: 'POST', body: body }));
        this.closePicker();
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    removeSubtask: async function (row) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('subtasks') + '/' + row.id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    removeRelation: async function (row) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('relations') + '/' + row.relation_id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- External links (§37-§41) ----------
    openLinkModal: function (link) {
      this.addMenu = '';
      this.linkModal = {
        open: true, id: link ? link.id : null,
        url: link ? link.url : '', title: link ? (link.title || '') : '',
        busy: false, error: ''
      };
    },
    saveLink: async function () {
      if (this.linkModal.busy || !this.linkModal.url.trim()) return;
      this.linkModal.busy = true; this.linkModal.error = '';
      var base = this.structureUrl('links');
      var url = this.linkModal.id ? base + '/' + this.linkModal.id : base;
      try {
        this.applyStructure(await this.$pb.api(url, {
          method: this.linkModal.id ? 'PATCH' : 'POST',
          body: { url: this.linkModal.url.trim(), title: this.linkModal.title.trim() }
        }));
        this.linkModal.open = false;
      } catch (e) { this.linkModal.error = this.$pb.firstError(e); }
      this.linkModal.busy = false;
    },
    deleteLink: async function (link) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('links') + '/' + link.id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    /** A related work item's own page, so every row is openable (§26/§32). */
    rowUrl: function (row) {
      return this.endpoints.item ? this.$pb.withId(this.endpoints.item, row.id) : '';
    },
    copyRowLink: async function (row) {
      var url = new URL(this.rowUrl(row), window.location.origin).href;
      try { await navigator.clipboard.writeText(url); this.$pb.toast('Link copied.'); }
      catch (e) { window.prompt('Copy this link', url); }
    },

    /**
     * The parent's ID and title. The row now carries its own `parent` object, so a parent that
     * is filtered out of the grid — or in another project — still names itself; the lookup in
     * the loaded list is only a fallback for payloads written before that.
     */
    parentLabel: function (item) {
      if (!item || !item.parent_id) return 'None';
      if (item.parent) return item.parent.identifier + ' · ' + item.parent.title;
      var id = String(item.parent_id);
      var parent = this.items.find(function (i) { return String(i.id) === id; });
      return parent ? parent.identifier + ' · ' + parent.title : '#' + id;
    },
    drawerCopyLink: async function () {
      var it = this.drawerItem;
      if (!it) return;
      var url = new URL(this.itemUrl(it), window.location.origin).href;
      try {
        await navigator.clipboard.writeText(url);
        this.$pb.toast('Link copied.');
      } catch (e) { window.prompt('Copy this link', url); }
    },
    /** Copy the drafts from the item whenever the detail switches to a different work item. */
    syncDraft: function () {
      var it = this.drawerItem;
      this.draft.title = it ? (it.title || '') : '';
      this.draft.description = it ? (it.description || '') : '';
      // Opening a different work item always starts in read mode, collapsed.
      this.editingDescription = false;
      this.editingTitle = false;
      this.desc = { expanded: false, overflows: false };
      this.measureDescription();
    },
    /**
     * Does the description exceed the clamp? Measured from the DOM after it renders — the
     * alternative, guessing from character count, is wrong the moment someone pastes a table
     * or an image.
     */
    measureDescription: function () {
      var self = this;
      this.$nextTick(function () {
        var el = self.$refs.descriptionBody;
        // scrollHeight vs clientHeight only differs while the clamp is applied, so the
        // measurement is taken in the collapsed state and kept while expanded.
        if (!el) { self.desc.overflows = false; return; }
        if (self.desc.expanded) return;
        self.desc.overflows = el.scrollHeight - el.clientHeight > 4;

        // Measured again once the images inside have loaded.
        //
        // $nextTick fires as soon as Vue has patched the DOM, and an <img> with no bytes yet
        // is zero pixels tall — so a description that is long BECAUSE of a screenshot measured
        // as fitting, and the control never appeared. Cheap: most descriptions have no images,
        // and the ones that do fire this once each.
        Array.prototype.forEach.call(el.querySelectorAll('img'), function (img) {
          if (img.complete) return;
          img.addEventListener('load', self.remeasureDescription, { once: true });
          img.addEventListener('error', self.remeasureDescription, { once: true });
        });
      });
    },
    /** Re-run the measurement without resetting what the reader has already opened. */
    remeasureDescription: function () {
      if (this.desc.expanded) return;
      var el = this.$refs.descriptionBody;
      if (el) this.desc.overflows = el.scrollHeight - el.clientHeight > 4;
    },
    toggleDescription: function () { this.desc.expanded = !this.desc.expanded; },
    /** ⋯ → Edit, and the "add a description" affordance, both land here. */
    editDescription: function () {
      if (!this.canEdit) return;
      this.draft.description = this.drawerItem ? (this.drawerItem.description || '') : '';
      this.editingDescription = true;
    },
    finishEditingDescription: async function () {
      this.flushEditor('descriptionEditor');
      await this.saveDescription();
      this.editingDescription = false;
      // The text just changed; whether it still overflows is a fresh question.
      this.desc.expanded = false;
      this.measureDescription();
    },
    /** Double-click on the heading opens it for editing (and selects what is there). */
    startEditTitle: function () {
      if (!this.canEdit || !this.drawerItem) return;
      this.draft.title = this.drawerItem.title || '';
      this.editingTitle = true;
      var self = this;
      this.$nextTick(function () {
        var el = self.$refs.titleField;
        if (el) { el.focus(); el.select(); }
      });
    },
    cancelEditTitle: function () {
      this.draft.title = this.drawerItem ? (this.drawerItem.title || '') : '';
      this.editingTitle = false;
    },
    /** Save the title if it actually changed; an emptied title is refused, not sent. */
    saveTitle: function () {
      var it = this.drawerItem;
      var next = (this.draft.title || '').trim();
      this.editingTitle = false;
      if (!it || !this.canEdit) return;
      if (!next) { this.draft.title = it.title; return; }
      if (next === it.title) return;
      this.patchItem(it, { title: next }, false, 'Title updated.');
    },
    saveDescription: async function () {
      var it = this.drawerItem;
      if (!it || !this.canEdit) return false;

      var next = this.draft.description || '';
      if (next === (it.description || '')) {
        // Say so rather than close in silence: "I edited it and nothing happened" is
        // indistinguishable from a broken save when the no-op path says nothing.
        this.$pb.toast('No changes to save.');
        return false;
      }

      await this.patchItem(it, { description: next }, false, 'Description updated.');

      // Start the next edit from what the server actually stored — the sanitizer may have
      // adjusted the markup, and comparing against a stale draft would make a real edit look
      // like a no-op.
      var saved = this.drawerItem;
      if (saved) this.draft.description = saved.description || '';

      return true;
    },
    /** One line of the audit feed, phrased from the stored display values (§6). */
    activityLine: function (a) {
      var who = a.actor ? a.actor.name : 'Someone';
      var when = this.fmtDateTime(a.created_at);
      var meta = a.meta || {};
      var text;

      if (a.event === 'created') {
        text = 'created this work item';
      } else if (a.field === 'state') {
        text = 'changed state to ' + (meta.new_label || 'none');
      } else if (a.field === 'parent') {
        text = meta.new_label ? 'set the parent to ' + meta.new_label : 'removed the parent';
      } else if (a.field === 'relation_added' || a.field === 'relation_removed') {
        // "blocking 1" read as a count. Name the relation, then the item it points at.
        var what = this.itemRef(meta);
        var kind = meta.relation || 'related to';
        text = a.field === 'relation_added'
          ? 'marked this as ' + kind + ' ' + what
          : 'removed the "' + kind + '" relation to ' + what;
      } else if (a.field === 'subtask_added') {
        text = 'added ' + this.itemRef(meta) + ' as a sub-task';
      } else if (a.field === 'subtask_removed') {
        text = 'removed ' + this.itemRef(meta) + ' from sub-tasks';
      } else if (a.field === 'comment' || a.field === 'comment_reply') {
        text = a.field === 'comment' ? 'commented' : 'replied to a comment';
      } else if (a.field === 'worklog') {
        text = 'logged ' + (a.new_value || 'time');
      } else if (a.field === 'update') {
        text = 'posted an update' + (a.new_value ? ' — ' + a.new_value : '');
      } else if (a.field === 'assignees' || a.field === 'labels') {
        var names = (meta.new_labels || []).join(', ');
        text = 'set ' + a.field + ' to ' + (names || 'none');
      } else if (a.field === 'description') {
        text = a.new_value ? 'updated the description' : 'cleared the description';
      } else if (a.field === 'archived_at') {
        text = a.new_value ? 'archived this work item' : 'restored this work item';
      } else if (a.field === 'title') {
        text = 'renamed this work item to "' + a.new_value + '"';
      } else if (a.field === 'priority') {
        // The stored value is the key ('medium'); the feed should read like the chip does.
        text = 'changed priority to ' + this.priorityMeta(a.new_value).label;
      } else if (a.field === 'start_date' || a.field === 'due_date') {
        var label = a.field === 'start_date' ? 'start date' : 'due date';
        text = a.new_value ? 'set the ' + label + ' to ' + this.fmtDate(a.new_value) : 'cleared the ' + label;
      } else {
        text = 'changed ' + String(a.field || 'a property').replace(/_/g, ' ') +
          ' to ' + (a.new_value || 'none');
      }

      return { id: a.id, who: who, initial: a.actor ? a.actor.initial : '?', text: text, when: when };
    },
    fmtDateTime: function (iso) {
      if (!iso) return '';
      var d = new Date(iso);
      if (isNaN(d)) return '';
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
        ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    },

    // ---------- Row action menu (§4.4) ----------
    itemUrl: function (item) {
      return this.endpoints.item ? this.$pb.withId(this.endpoints.item, item.id) : '';
    },
    rowEdit: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it) return;
      // From the grid this opens the item first; from the panel's own ⋯ it is already open.
      if (!this.drawer.open || String(this.drawer.id) !== String(it.id)) this.openDrawer(it);
      this.editDescription();
    },
    rowCopy: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it || !this.endpoints.duplicate) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.duplicate, it.id), { method: 'POST' });
        this.items.push(resp.item);
        this.refreshTable();
        this.$pb.toast(resp.message || 'Work item copied.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    rowOpenTab: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (it) window.open(this.itemUrl(it), '_blank', 'noopener');
    },
    rowCopyLink: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it) return;
      var url = new URL(this.itemUrl(it), window.location.origin).href;
      try {
        await navigator.clipboard.writeText(url);
        this.$pb.toast('Link copied.');
      } catch (e) { window.prompt('Copy this link', url); }
    },
    rowArchive: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it || !this.endpoints.archive) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.archive, it.id), { method: 'POST' });
        // Archived items leave the active list but keep their data (§4.4).
        this.removeItem(it);
        this.$pb.toast(resp.message || 'Work item archived.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    rowAskDelete: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (it) this.deleteConfirm = { open: true, item: it, busy: false };
    },
    rowDelete: async function () {
      var it = this.deleteConfirm.item;
      if (!it || this.deleteConfirm.busy) return;
      this.deleteConfirm.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.destroy, it.id), { method: 'DELETE' });
        this.removeItem(it);
        this.deleteConfirm = { open: false, item: null, busy: false };
        this.$pb.toast(resp.message || 'Work item deleted.');
      } catch (e) {
        this.deleteConfirm.busy = false;
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },

    // ---------- Create modal ----------
    openCreate: function (stateKey, parentId) {
      if (!this.canCreate) return;
      // Remember what this modal was opened with so "Create more" can return to it. That
      // includes the parent: creating several sub-tasks in a row should keep creating them
      // under the same work item (§21).
      this.seedStateId = (stateKey && stateKey !== WI_NO_STATE ? stateKey : this.defaultStateId) || '';
      this.seedParentId = parentId || '';
      this.resetForm();
      this.open = true;
      var self = this;
      this.$nextTick(function () { if (self.$refs.titleInput) self.$refs.titleInput.focus(); });
    },
    /** Clear the form and every transient bit of modal state back to the opening seed. */
    resetForm: function () {
      this.form = wiBlankForm(this.seedStateId, this.seed);
      this.form.parent_id = this.seedParentId || '';
      this.errors = {};
      this.menu = ''; this.parentOpen = false;
      this.memberQuery = ''; this.labelQuery = ''; this.parentQuery = ''; this.epicQuery = '';
    },
    closeCreate: function () { this.open = false; this.menu = ''; this.parentOpen = false; },
    toggleMenu: function (name) { this.menu = this.menu === name ? '' : name; },
    // The calendar component owns its own view state, so opening is just a menu toggle.
    onDatePick: function (field, iso) { this.form[field] = iso; this.menu = ''; },
    onDateClear: function (field) { this.form[field] = ''; this.menu = ''; },
    // ---------- Parent search panel ----------
    openParent: function () {
      this.menu = '';
      this.parentQuery = '';
      this.parentOpen = true;
      var self = this;
      this.$nextTick(function () { if (self.$refs.parentSearch) self.$refs.parentSearch.focus(); });
    },
    closeParent: function () { this.parentOpen = false; },
    pickParent: function (item) {
      this.form.parent_id = item ? item.id : '';
      this.closeParent();
    },
    /** Create modal: one assignee, same rule as the row/drawer picker (§4.3, revised). */
    toggleAssignee: function (m) {
      this.form.assignee_ids = this.isAssigned(m) ? [] : [m.id];
      this.menu = '';
    },
    toggleLabel: function (l) {
      var ids = this.form.label_ids.map(String);
      var i = ids.indexOf(String(l.id));
      if (i > -1) this.form.label_ids.splice(i, 1);
      else this.form.label_ids.push(l.id);
    },
    isAssigned: function (m) { return this.form.assignee_ids.map(String).indexOf(String(m.id)) > -1; },
    isLabelled: function (l) { return this.form.label_ids.map(String).indexOf(String(l.id)) > -1; },
    save: async function () {
      if (this.saving || !this.form.title.trim()) return;
      this.saving = true; this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: this.form });
        this.items.push(resp.item);
        this.refreshTable();
        this.$pb.toast('Work item created.');

        // Created as a sub-task of the work item currently open — its Sub-tasks section has
        // to show it without waiting for a reload.
        if (this.drawerItem && resp.item && String(resp.item.parent_id) === String(this.drawerItem.id)) {
          this.loadStructure();
        }
        if (this.createMore) {
          // "Create more" keeps the modal open, but the next work item starts CLEAN: every
          // chip returns to the state the modal was opened in, so nothing carries over from
          // the item just saved. `seedStateId` — not the last-used state — is what we reset
          // to, so a modal opened from a state group's "+" keeps creating in that group
          // while a manual change to State, Priority, dates, assignees, labels or parent is
          // discarded along with the title.
          this.resetForm();
          var self = this;
          this.$nextTick(function () { if (self.$refs.titleInput) self.$refs.titleInput.focus(); });
        } else {
          this.closeCreate();
        }
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    },
    /**
     * Tint the header's state chip from the state's own colour — states are user-defined, so
     * a fixed palette here would drift the moment someone adds one.
     */
    stateChipStyle: function (state) {
      var color = (state && state.color) || '#6b7280';
      return { color: color, background: color + '14', border: '1px solid ' + color + '33' };
    },
    /** "#4 Build API" — an identifier is a bare number now, so it needs its title with it. */
    itemRef: function (meta) {
      var id = meta && meta.target ? '#' + meta.target : 'a work item';
      return meta && meta.target_title ? id + ' ' + meta.target_title : id;
    },
    /** The round event badge shown at the start of a feed row (§6.4). */
    eventIcon: function (entry) {
      return wiEventSvg(WI_EVENT_ICON[wiEventKind(entry)] || WI_EVENT_ICON.text, 15);
    },
    /**
     * What one side of a History row should read.
     *
     * Preference order: the display names frozen into the audit row, then the single label
     * (state, parent), then the stored value. Assignees and labels store IDS in the value
     * column — showing that raw is how "None → 4" reached the screen — so a value that is
     * nothing but ids is suppressed rather than printed.
     */
    historyValue: function (entry, side) {
      var meta = entry.meta || {};
      var many = meta[side + '_labels'];
      if (Array.isArray(many)) return many.length ? many.join(', ') : 'None';

      var one = meta[side + '_label'];
      if (one) return one;

      // Relation rows keep the kind and the target apart; assembled they read as a phrase.
      if (meta.relation && entry[side + '_value']) return meta.relation + ' ' + this.itemRef(meta);

      var raw = entry[side + '_value'];
      if (!raw) return 'None';

      // An id list is a machine value; it means nothing to a reader.
      var idsOnly = ['assignees', 'labels'].indexOf(entry.field) > -1 && /^[0-9,\s]+$/.test(String(raw));
      if (idsOnly) return 'None';

      // Dates are stored ISO; the audit trail should read the way the item does.
      if (entry.field === 'start_date' || entry.field === 'due_date') return this.fmtDate(raw);

      return String(raw);
    },
    /** The small icon that precedes a before/after value in History. */
    valueIcon: function (entry) {
      var kind = wiEventKind(entry);
      var inline = ['state', 'priority', 'assignees', 'date', 'labels'];
      return inline.indexOf(kind) > -1 ? wiEventSvg(WI_EVENT_ICON[kind], 12) : '';
    },
    /** Vue-side counterpart of wiAvatar: templates bind to these two fields. */
    avatarOf: function (person) {
      return person && person.avatar_url ? person.avatar_url : '';
    },
    fmtDate: function (d) { return wiFmtDate(d); },
    stateIcon: function (s) { return wiStateIcon(s); },
    priorityMeta: function (key) { return WI_PRI[key] || WI_PRI.none; }
  },
  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ===== List (hidden on the per-item page, where the detail IS the page) =====
    '<template v-if="!pageMode">' +

    // ===== Toolbar =====
    '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line shrink-0">' +
    '<span class="flex items-center gap-2 text-[13px] font-medium text-ink shrink-0">' +
    '' + wiIcon('bars-thin', 15, 'text-sub') + '' +
    'Work items <span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ totalCount }}</span></span>' +
    '<div class="ml-auto flex items-center gap-1.5">' +
    // Hidden when this screen is embedded in another (an epic's Work Items tab): that host
    // has its own Add button in its header, and two side by side is one too many. Creating
    // new work is still one click away — every group row keeps its "+".
    '<button v-if="canCreate && !embedded" type="button" @click="openCreate(\'\')" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap">' +
    '' + wiIcon('plus', 14) + 'Add work item</button>' +
    '</div></div>' +

    // ===== Grid (desktop) =====
    '<wi-list v-show="items.length" ref="list" class="flex-1 min-h-0 hidden sm:block" ' +
    ':items="items" :states="states" :flat="!!projectsById" :can-edit="canEdit" :can-add="canCreate" row-action="menu" ' +
    ':labels-enabled="baseLabelsEnabled" ' +
    '@open="openDrawer" @chip="onChip" @group-add="openCreate" />' +

    // ===== Cards (mobile) — same data, grouped by state =====
    '<div class="sm:hidden flex-1 overflow-y-auto">' +
    // Across projects there is no shared state order to group by, so the cards are one list —
    // the same choice the grid makes in `flat` mode. Without this the mobile view rendered
    // nothing at all, because it iterates the (empty) state list.
    '<template v-if="projectsById">' +
    '<div v-for="i in items" :key="i.id" @click="openDrawer(i)" class="border-b border-line px-4 py-3">' +
    '<div class="flex items-center gap-2 text-[12px] text-sub">' +
    '<span v-if="i.project" class="shrink-0">{{ (i.project.emoji || \'📁\') + \' \' + i.project.name }}</span>' +
    '<span>{{ i.identifier }}</span></div>' +
    '<div class="text-[14px] text-ink mt-0.5">{{ i.title }}</div>' +
    '<div class="flex flex-wrap items-center gap-1.5 mt-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] text-ink">' +
    '<span class="grid place-items-center" v-html="stateIcon(i.state)"></span>{{ i.state ? i.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px]" :class="priorityMeta(i.priority).cls">' +
    '<span class="grid place-items-center" v-html="priorityMeta(i.priority).icon"></span>{{ priorityMeta(i.priority).label }}</span>' +
    '</div></div>' +
    '</template>' +
    '<template v-else v-for="s in states" :key="s.id">' +
    '<div v-if="items.filter(i => i.state_id === s.id).length" class="flex items-center gap-2 h-9 px-4 border-b border-line" style="background:#f6f7f8">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span>' +
    '<span class="text-[13px] font-semibold text-head">{{ s.name }}</span>' +
    '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5 text-sub bg-hover">{{ items.filter(i => i.state_id === s.id).length }}</span>' +
    '<button v-if="canCreate" type="button" @click="openCreate(String(s.id))" class="ml-auto h-6 w-6 grid place-items-center rounded text-sub hover:bg-line" data-tip="Add work item" aria-label="Add work item">' + wiIcon('plus', 15) + '</button>' +
    '</div>' +
    '<div v-for="i in items.filter(i => i.state_id === s.id)" :key="i.id" @click="openDrawer(i)" class="border-b border-line px-4 py-3">' +
    '<div class="text-[12px] text-sub">{{ i.identifier }}</div>' +
    '<div class="flex items-center gap-2 mt-0.5">' +
    '<span v-if="i.blocked_by_count" class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-danger/30 bg-danger/5 text-[11px] font-semibold text-danger shrink-0">Blocked</span>' +
    '<span class="text-[14px] text-ink">{{ i.title }}</span></div>' +
    '<div class="flex flex-wrap items-center gap-1.5 mt-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] text-ink"><span class="grid place-items-center" v-html="stateIcon(i.state)"></span>{{ i.state ? i.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px]" :class="priorityMeta(i.priority).cls"><span class="grid place-items-center" v-html="priorityMeta(i.priority).icon"></span>{{ priorityMeta(i.priority).label }}</span>' +
    '</div></div>' +
    '</template>' +
    '<div v-if="!items.length" class="px-6 py-14 text-center text-sub text-[13px]">No work items yet.</div>' +
    '</div>' +

    // ===== Empty state (desktop) =====
    '<div v-if="!items.length" class="hidden sm:flex flex-col items-center text-center px-6 py-16">' +
    '<h2 class="text-[16px] font-bold text-head">No work items yet</h2>' +
    '<p class="text-[13px] text-sub mt-1.5 max-w-sm">Work items are the units of work in this project. Add the first one to get started.</p>' +
    '<button v-if="canCreate" type="button" @click="openCreate(\'\')" class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '' + wiIcon('plus', 15) + 'Add work item</button>' +
    '</div>' +

    '</template>' +


    // ===== Detail view (§4.4) — a right-hand drawer over the list, or the whole page when
    // the per-item URL was opened. Same markup either way; `pageMode` swaps the framing. =====
    '<div v-if="drawer.open && drawerItem" :class="pageMode ? \'flex-1 min-h-0 flex flex-col\' : \'fixed inset-0 z-[85]\'">' +
    '<div v-if="!pageMode" class="absolute inset-0 bg-black/20" @mousedown="backdropDown" @click="backdropClick($event, closeDrawer)"></div>' +
    '<aside :class="pageMode ? \'flex-1 min-h-0 flex flex-col bg-white\' : \'absolute right-0 top-0 h-full w-full sm:w-[80%] bg-white shadow-2xl flex flex-col\'">' +

    // ---- Toolbar ----
    '<div class="flex items-center gap-1 px-4 h-14 border-b border-line shrink-0">' +
    '<template v-if="!pageMode">' +
    '<button type="button" @click="closeDrawer" data-tip="Close" aria-label="Close" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '' + wiIcon('arrow-right-long', 18) + '</button>' +
    '<a :href="itemUrl(drawerItem)" data-tip="Open as full page" aria-label="Open as full page" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '' + wiIcon('expand', 16) + '</a>' +
    '</template>' +
    // On the item page the toolbar carries a breadcrumb back to the list instead.
    '<div v-else class="flex items-center gap-1.5">' +
    '<a :href="endpoints.list" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink">' +
    '' + wiIcon('bars-thin', 15) + 'Work items</a>' +
    '' + wiIcon('chevron-right', 13, 'text-faint') + '' +
    '<span class="text-[13px] text-ink font-medium">{{ drawerItem.identifier }}</span>' +
    '</div>' +

    '<div class="ml-auto flex items-center gap-1.5">' +

    // Vote (POC toolbar). Available to anyone who can SEE the item, not only who can edit it:
    // voting on a proposal is not changing it. The chosen side is filled rather than outlined,
    // so "how does the team feel" and "what did I say" are both answerable at a glance.
    '<div class="flex items-center gap-0.5">' +
    '<button type="button" @click="vote(\'up\')" data-tip="Upvote" aria-label="Upvote" ' +
    'class="inline-flex items-center gap-1 h-8 px-2 rounded-md text-[13px] hover:bg-hover" ' +
    ':class="drawerItem.my_vote === \'up\' ? \'bg-sel text-brand\' : \'text-sub\'">' +
    '' + wiIcon('arrow-up', 14) + '<span>{{ drawerItem.votes ? drawerItem.votes.up : 0 }}</span></button>' +
    '<button type="button" @click="vote(\'down\')" data-tip="Downvote" aria-label="Downvote" ' +
    'class="inline-flex items-center gap-1 h-8 px-2 rounded-md text-[13px] hover:bg-hover" ' +
    ':class="drawerItem.my_vote === \'down\' ? \'bg-sel text-brand\' : \'text-sub\'">' +
    '' + wiIcon('arrow-down', 14) + '<span>{{ drawerItem.votes ? drawerItem.votes.down : 0 }}</span></button>' +
    '</div>' +

    // Subscribe. Once following, the button wears your own face — the POC's way of saying
    // "you are on this list", which a filled bell cannot.
    '<button type="button" @click="toggleSubscribe" ' +
    ':data-tip="drawerItem.subscribed ? \'Stop hearing about this work item\' : \'Hear about changes to this work item\'" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span class="grid place-items-center">' +
    '<wi-avatar v-if="drawerItem.subscribed && me" :person="me" :size="20" />' +
    '<span v-else>' + wiIcon('user-thin', 14) + '</span>' +
    '</span>' +
    '<span>{{ drawerItem.subscribed ? \'Unsubscribe\' : \'Subscribe\' }}</span></button>' +

    '<button type="button" @click="drawerCopyLink" data-tip="Copy link" aria-label="Copy link" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '' + wiIcon('link', 16) + '</button>' +
    '<button v-if="canEdit" type="button" @click="openRowMenu(\'menu\', drawerItem, $event.currentTarget)" data-tip="More" aria-label="More" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '' + wiIcon('ellipsis', 16) + '</button>' +
    '</div></div>' +

    // ---- Content: main column + properties ----
    '<div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden">' +

    '<div class="flex-1 min-w-0 px-6 sm:px-8 py-6 lg:overflow-y-auto">' +
    // Header line: the item's current state, then its ID, then anything demanding attention
    // — the same shape as the reference. The state chip is the answer to "where is this?",
    // which is the first thing anyone opening a work item wants.
    '<div class="flex items-center gap-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md text-[12px] font-medium" ' +
    ':style="stateChipStyle(drawerItem.state)" :data-tip="\'State: \' + (drawerItem.state ? drawerItem.state.name : \'No state\')">' +
    '<span class="grid place-items-center" v-html="stateIcon(drawerItem.state)"></span>' +
    '{{ drawerItem.state ? drawerItem.state.name : \'No state\' }}</span>' +
    '<span class="text-[12px] text-sub tracking-wide">{{ drawerItem.identifier }}</span>' +
    '<span v-if="drawerItem.blocked_by_count" class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-danger/30 bg-danger/5 text-[11px] font-semibold text-danger">' +
    '' + wiIcon('circle-slash', 11) + 'Blocked</span>' +
    '</div>' +
    // A heading until it is double-clicked. Enter commits, Escape backs out, blur saves —
    // and it never looks like a form field while you are only reading.
    '<input v-if="editingTitle" ref="titleField" v-model="draft.title" @blur="saveTitle" ' +
    '@keydown.enter.prevent="$event.target.blur()" @keydown.esc.prevent="cancelEditTitle" ' +
    'class="w-full text-[22px] font-semibold text-head mt-2 bg-white outline-none rounded px-1 -ml-1 ring-1 ring-brand/40" />' +
    '<h1 v-else class="text-[22px] font-semibold text-head mt-2 rounded px-1 -ml-1" ' +
    ':class="canEdit ? \'cursor-text\' : \'\'" @dblclick="startEditTitle" ' +
    ':data-tip="canEdit ? \'Double-click to rename\' : null">{{ drawerItem.title }}</h1>' +

    '<div class="mt-4 border-b border-line"></div>' +

    // The editor is mounted only while editing (⋯ → Edit). Reading is the common case, and
    // an editor that is always there pays its start-up cost on every open.
    '<div v-if="editingDescription && canEdit" class="mt-5">' +
    // <pg-editor> (Jodit) in its MINIMUM configuration: no iframe, no page sheet with margins
    // and page breaks — a description is a field, not a document — and a short toolbar rather
    // than the full document set. Same contract as <wi-editor>, which stays as the fallback
    // everywhere here for a checkout without the licensed package.
    '<pg-editor v-if="useJodit" ref="descriptionEditor" v-model="draft.description" min-height="180px" ' +
    ':document-view="false" :buttons="editorButtons" :license="editorLicense" ' +
    ':media-upload="endpoints.mediaUpload" />' +
    '<wi-editor v-else ref="descriptionEditor" v-model="draft.description" min-height="180px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '<div class="flex justify-end gap-2 mt-2">' +
    '<button type="button" @click="editingDescription = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="finishEditingDescription" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Save</button>' +
    '</div></div>' +
    // Read view: the stored markup, which the server sanitized on the way in. Clamped until
    // asked for in full, so a long description does not bury the sections below it.
    '<div v-else-if="drawerItem.description" class="mt-5">' +
    '<div ref="descriptionBody" class="wi-rich text-[14px] text-ink leading-relaxed" ' +
    ':class="desc.expanded ? \'\' : \'wi-desc-clamp\'" v-html="drawerItem.description"></div>' +
    '<button v-if="desc.overflows" type="button" @click="toggleDescription" ' +
    'class="mt-2 text-[13px] font-medium text-link hover:underline">{{ desc.expanded ? \'Show less\' : \'Show more\' }}</button>' +
    '</div>' +
    '<button v-else-if="canEdit" type="button" @click="editDescription" class="mt-5 text-[14px] text-sub hover:text-ink">Add a description…</button>' +
    '<p v-else class="text-[14px] text-sub mt-5">No description.</p>' +


    // ---- Actions + structure sections (§66) --------------------------------------------
    // ---- Action row (§66, styled after html/work-items.html): a labelled sub-task button,
    // then one connected group of icon actions. Each dropdown is a menu, not a direct write,
    // because every one of them needs a choice before anything can happen. ----
    '<div v-if="canEdit" class="flex flex-wrap items-center gap-2 mt-6">' +

    // Add sub-work item — §20's two routes.
    '<div class="relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sub\' ? \'\' : \'sub\'" class="inline-flex items-center gap-1.5 rounded-md shadow-sm h-9 px-3 text-[13px] text-ink ring-1 ring-inset ring-stroke hover:bg-hover">' +
    '' + wiIcon('diagram-subtask', 15) + '' +
    'Add sub-work item</button>' +
    '<div v-if="addMenu === \'sub\'" class="absolute left-0 top-full mt-1 w-52 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="createSubtask" class="w-full text-left flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('plus', 15, 'text-sub') + 'Create new</button>' +
    '<button type="button" @click="openPicker(\'subtask\', \'\', \'Add existing work item\')" class="w-full text-left flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('diagram-subtask', 15, 'text-sub') + 'Add existing</button>' +
    '</div></div>' +

    // Connected icon group: dependency ▾ · relation ▾ · link · attachment · pages
    '<span class="isolate inline-flex rounded-md shadow-sm">' +

    '<span class="relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'dep\' ? \'\' : \'dep\'" data-tip="Add dependency" aria-label="Add dependency" class="relative inline-flex items-center gap-1 rounded-l-md h-9 pl-2.5 pr-1.5 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '' + wiIcon('arrows-up-down', 15) + '' +
    '' + wiIcon('chevron-down', 12) + '</button>' +
    '<div v-if="addMenu === \'dep\'" class="absolute left-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'blocked_by\', \'Blocked by\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocked by</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'blocking\', \'Blocking\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocking</button>' +
    '</div></span>' +

    '<span class="relative -ml-px" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'rel\' ? \'\' : \'rel\'" data-tip="Add relation" aria-label="Add relation" class="relative inline-flex items-center gap-1 h-9 pl-2.5 pr-1.5 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '' + wiIcon('rectangles-pair', 15) + '' +
    '' + wiIcon('chevron-down', 12) + '</button>' +
    '<div v-if="addMenu === \'rel\'" class="absolute left-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'related\', \'Related to\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Related to</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'duplicate_of\', \'Duplicate of\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Duplicate of</button>' +
    '</div></span>' +

    '<button type="button" @click="openLinkModal(null)" data-tip="Add link" aria-label="Add link" class="relative -ml-px inline-flex items-center justify-center h-9 w-9 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '' + wiIcon('link', 15) + '</button>' +

    // Attachments are drawn but inert until they ship (§42) — a dead control that silently
    // does nothing is worse than one that says why.
    '<span data-tip="Attachments — coming soon" aria-label="Attachments — coming soon" class="relative -ml-px inline-flex items-center justify-center h-9 w-9 text-faint ring-1 ring-inset ring-stroke cursor-not-allowed">' +
    '' + wiIcon('paperclip', 15) + '</span>' +

    // Link pages. Live where the project has Pages on, and saying why where it does not.
    '<button v-if="pagesEnabled" type="button" @click="openPagePicker" data-tip="Link pages" aria-label="Link pages" ' +
    'class="relative -ml-px inline-flex items-center justify-center rounded-r-md h-9 w-9 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '' + wiIcon('file-lines', 15) + '</button>' +
    '<span v-else data-tip="Pages are disabled for this project" aria-label="Pages are disabled for this project" ' +
    'class="relative -ml-px inline-flex items-center justify-center rounded-r-md h-9 w-9 text-faint ring-1 ring-inset ring-stroke cursor-not-allowed">' +
    '' + wiIcon('file-lines', 15) + '</span>' +

    '</span></div>' +

    // ---- Structure sections (§23/§27/§33/§40) ------------------------------------------
    // One card, one block per kind. Each block: a collapsible header carrying its own count
    // and "+" action, then — for dependencies and relations — a tinted banner naming the
    // direction, because "TESTI-5" under "Dependencies" is meaningless without knowing which
    // way it points.
    '<div v-if="hasStructure()" class="mt-6 rounded-lg border border-line divide-y divide-line overflow-hidden">' +

    // ===== Sub-work items =====
    '<div v-if="structure.subtasks.items.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'subtasks\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '' + wiIcon('chevron-down', 15, 'transition-transform') + '</button>' +
    '<span class="text-[13px] font-semibold text-head">Sub-work items</span>' +
    '<span class="text-[12px] text-sub">{{ structure.subtasks.items.length }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-5 pl-1 pr-2 rounded-full border border-line text-[11px] text-sub" :data-tip="structure.subtasks.progress.percent + \'% complete\'" :aria-label="structure.subtasks.progress.percent + \'% complete\'">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" :stroke="structure.subtasks.progress.percent === 100 ? \'#22c55e\' : \'#9ca3af\'" stroke-width="2.5"/></svg>' +
    '{{ structure.subtasks.progress.completed }}/{{ structure.subtasks.progress.total }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-sub\' ? \'\' : \'sec-sub\'" data-tip="Add sub-work item" aria-label="Add sub-work item" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '' + wiIcon('plus', 16) + '</button>' +
    '<div v-if="addMenu === \'sec-sub\'" class="absolute right-0 top-full mt-1 w-52 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="createSubtask" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Create new</button>' +
    '<button type="button" @click="openPicker(\'subtask\', \'\', \'Add existing work item\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Add existing</button>' +
    '</div></div></div>' +
    '<ul v-show="secOpen.subtasks" class="mt-1">' +
    '<li v-for="row in structure.subtasks.items" :key="row.id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink" ' +
    ':data-tip="\'State: \' + (row.state ? row.state.name : \'No state\')"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls" ' +
    ':data-tip="\'Priority: \' + priorityMeta(row.priority).label"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<span v-if="row.due_date" class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink">{{ fmtDate(row.due_date) }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" data-tip="Unassigned" aria-label="Unassigned">' +
    '' + wiIcon('user', 12) + '</span>' +
    '<button type="button" @click="openStructMenu(\'subtask\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" data-tip="More" aria-label="More">' +
    '' + wiIcon('ellipsis-small', 15) + '</button>' +
    '</span></li></ul></div>' +

    // ===== Dependencies =====
    '<div v-if="structure.dependencies.blocked_by.length || structure.dependencies.blocking.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'dependencies\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '' + wiIcon('chevron-down', 15, 'transition-transform') + '</button>' +
    '<span class="text-[13px] font-semibold text-head">Dependencies</span>' +
    '<span class="text-[12px] text-sub">{{ structure.dependencies.blocked_by.length + structure.dependencies.blocking.length }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-dep\' ? \'\' : \'sec-dep\'" data-tip="Add dependency" aria-label="Add dependency" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '' + wiIcon('plus', 16) + '</button>' +
    '<div v-if="addMenu === \'sec-dep\'" class="absolute right-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'blocked_by\', \'Blocked by\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocked by</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'blocking\', \'Blocking\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocking</button>' +
    '</div></div></div>' +
    '<div v-show="secOpen.dependencies" class="mt-1 space-y-1">' +
    '<template v-for="g in depGroups" :key="g.key">' +
    '<div v-if="structure.dependencies[g.key].length">' +
    '<div class="flex items-center gap-2 h-8 px-2.5 rounded-md text-[12px]" :class="g.cls">' +
    '<span class="grid place-items-center" v-html="g.icon"></span>{{ g.label }}</div>' +
    '<ul>' +
    '<li v-for="row in structure.dependencies[g.key]" :key="row.relation_id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink" ' +
    ':data-tip="\'State: \' + (row.state ? row.state.name : \'No state\')"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls" ' +
    ':data-tip="\'Priority: \' + priorityMeta(row.priority).label"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" data-tip="Unassigned" aria-label="Unassigned">' +
    '' + wiIcon('user', 12) + '</span>' +
    '<button type="button" @click="openStructMenu(\'relation\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" data-tip="More" aria-label="More">' +
    '' + wiIcon('ellipsis-small', 15) + '</button>' +
    '</span></li></ul></div></template></div></div>' +

    // ===== Relations =====
    '<div v-if="structure.relations.related.length || structure.relations.duplicate_of.length || structure.relations.duplicated_by.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'relations\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '' + wiIcon('chevron-down', 15, 'transition-transform') + '</button>' +
    '<span class="text-[13px] font-semibold text-head">Relations</span>' +
    '<span class="text-[12px] text-sub">{{ structure.relations.related.length + structure.relations.duplicate_of.length + structure.relations.duplicated_by.length }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-rel\' ? \'\' : \'sec-rel\'" data-tip="Add relation" aria-label="Add relation" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '' + wiIcon('plus', 16) + '</button>' +
    '<div v-if="addMenu === \'sec-rel\'" class="absolute right-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'related\', \'Related to\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Related to</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'duplicate_of\', \'Duplicate of\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Duplicate of</button>' +
    '</div></div></div>' +
    '<div v-show="secOpen.relations" class="mt-1 space-y-1">' +
    '<template v-for="g in relGroups" :key="g.key">' +
    '<div v-if="structure.relations[g.key].length">' +
    '<div class="flex items-center gap-2 h-8 px-2.5 rounded-md text-[12px] text-sub bg-hover">' +
    '<span class="grid place-items-center" v-html="g.icon"></span>{{ g.label }}</div>' +
    '<ul>' +
    '<li v-for="row in structure.relations[g.key]" :key="row.relation_id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink" ' +
    ':data-tip="\'State: \' + (row.state ? row.state.name : \'No state\')"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls" ' +
    ':data-tip="\'Priority: \' + priorityMeta(row.priority).label"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" data-tip="Unassigned" aria-label="Unassigned">' +
    '' + wiIcon('user', 12) + '</span>' +
    '<button type="button" @click="openStructMenu(\'relation\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" data-tip="More" aria-label="More">' +
    '' + wiIcon('ellipsis-small', 15) + '</button>' +
    '</span></li></ul></div></template></div></div>' +

    // ===== Links =====
    '<div v-if="structure.links.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'links\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '' + wiIcon('chevron-down', 15, 'transition-transform') + '</button>' +
    '<span class="text-[13px] font-semibold text-head">Links</span>' +
    '<span class="text-[12px] text-sub">{{ structure.links.length }}</span>' +
    '<button v-if="canEdit" type="button" @click="openLinkModal(null)" data-tip="Add link" aria-label="Add link" class="ml-auto h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '' + wiIcon('plus', 16) + '</button>' +
    '</div>' +
    '<ul v-show="secOpen.links" class="mt-1 space-y-1">' +
    '<li v-for="l in structure.links" :key="l.id" class="flex items-center gap-2.5 px-2.5 h-11 rounded-md border border-line">' +
    '' + wiIcon('link', 15, 'text-link shrink-0') + '' +
    '<a :href="l.url" target="_blank" rel="noopener noreferrer" class="text-[13px] text-ink truncate hover:underline">{{ l.label }}</a>' +
    '<span class="ml-auto text-[12px] text-faint shrink-0 hidden sm:inline">{{ relativeTime(l.created_at) }}</span>' +
    '<button type="button" @click="copyText(l.url)" data-tip="Copy link" aria-label="Copy link" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line shrink-0">' +
    '' + wiIcon('clone', 14) + '</button>' +
    '<button v-if="canEdit" type="button" @click="openStructMenu(\'link\', l, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line shrink-0" data-tip="More" aria-label="More">' +
    '' + wiIcon('ellipsis-small', 15) + '</button>' +
    '</li></ul></div>' +

    // ---- Linked pages. The documentation this work item points at. ----
    '<div v-if="pagesEnabled && structure.pages && structure.pages.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'pages\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '' + wiIcon('chevron-down', 15, 'transition-transform') + '</button>' +
    '<span class="text-[13px] font-semibold text-head">Linked pages</span>' +
    '<span class="text-[12px] text-sub">{{ structure.pages.length }}</span>' +
    '<button v-if="canEdit" type="button" @click="openPagePicker" data-tip="Link pages" aria-label="Link pages" ' +
    'class="ml-auto h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '' + wiIcon('plus', 16) + '</button>' +
    '</div>' +
    // Cards in a wrapping grid, per the POC's pageSection(): a page is a document with an
    // author and a date, and a one-line row has nowhere to say so — unlike Links, which really
    // are just a URL.
    '<div v-show="secOpen.pages" class="flex flex-wrap gap-3 mt-1">' +
    '<div v-for="p in structure.pages" :key="p.id" class="group relative w-full sm:w-[320px] rounded-lg border border-line p-3">' +
    '<span v-if="p.project" class="inline-flex items-center gap-1 text-[12px] text-sub">' +
    '<span>{{ p.project.emoji || \'📁\' }}</span>{{ p.project.name }}</span>' +

    '<div class="flex items-center gap-2 mt-2">' +
    '<span class="text-sub shrink-0">' + wiIcon('file-lines', 15) + '</span>' +
    '<a :href="pageUrl(p)" class="text-[14px] font-medium text-ink truncate hover:underline">{{ p.title }}</a>' +
    '<span v-if="p.status === \'draft\'" class="text-[11px] text-faint shrink-0">Draft</span>' +
    '</div>' +

    '<div class="flex items-center gap-2 mt-6 pt-3 border-t border-line">' +
    '<span v-if="p.updated_by" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center bg-brand text-white text-[10px] font-bold shrink-0" ' +
    ':data-tip="\'Last updated by \' + p.updated_by.name">' +
    '<img v-if="p.updated_by.avatar_url" :src="p.updated_by.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ p.updated_by.initial }}</span></span>' +
    '<span class="text-[12px] text-faint truncate">Last updated {{ relativeTime(p.updated_at) }}</span>' +
    '<button v-if="canEdit" type="button" @click="openStructMenu(\'page\', p, $event)" data-tip="More" aria-label="More" ' +
    'class="ml-auto h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover shrink-0">' +
    '' + wiIcon('ellipsis-vertical', 15) + '</button>' +
    '</div></div></div></div>' +

    '</div>' +

    // ---- Structure row ⋯ menu ----
    '<div v-if="structMenu.open" class="fixed inset-0 z-[110]" @click="closeStructMenu"></div>' +
    '<div v-if="structMenu.open" :style="structMenu.style" class="rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">' +
    '<button type="button" @click="structOpen" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">{{ structMenu.kind === \'link\' ? \'Open link\' : \'Open work item\' }}</button>' +
    '<button type="button" @click="structCopy" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Copy link</button>' +
    '<button v-if="structMenu.kind === \'link\'" type="button" @click="openLinkModal(structMenu.row); closeStructMenu();" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Edit</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button v-if="canEdit" type="button" @click="structRemove" class="w-full text-left px-3 h-9 text-[13px] text-danger hover:bg-hover">' +
    '{{ structMenu.kind === \'subtask\' ? \'Remove from parent\' : (structMenu.kind === \'link\' ? \'Delete link\' : (structMenu.kind === \'page\' ? \'Unlink page\' : \'Remove relation\')) }}</button>' +
    '</div>' +

    // ---- Collaboration: All | Activity | Comments | Updates | Worklogs | Transition |
    // History (§4-§11). Seven views of one dataset — the server decides what belongs in
    // each, so the tabs cannot drift apart here. ----
    '<div class="mt-8 border-t border-line pt-4">' +

    // overflow-y-hidden matters: `overflow-x-auto` alone makes the Y axis `auto` too, and the
    // tabs' -mb-px puts content a pixel past the box — enough for a vertical scrollbar to
    // appear down the right-hand edge of the strip. `wi-tabs` hides the horizontal bar's
    // chrome while keeping the strip scrollable on narrow screens (§4.5).
    '<div role="tablist" class="wi-tabs flex items-center gap-4 border-b border-line overflow-x-auto overflow-y-hidden">' +
    '<button v-for="t in tabList()" :key="t.key" role="tab" :aria-selected="tab === t.key" @click="setTab(t.key)" ' +
    'class="shrink-0 h-9 text-[13px] border-b-2 -mb-px transition-colors" ' +
    ':class="tab === t.key ? \'border-brand text-ink font-semibold\' : \'border-transparent text-sub hover:text-ink\'">{{ t.label }}</button>' +
    '<span v-if="tab === \'worklogs\' && feed" class="ml-auto shrink-0 text-[12px] text-sub pb-2">Tracked time: <span class="font-semibold text-ink">{{ feed.worklogs.total_label }}</span></span>' +
    '</div>' +

    '<div v-if="feedLoading" class="py-6 space-y-3">' +
    '<div v-for="n in 3" :key="n" class="h-4 rounded bg-hover animate-pulse" :style="{width: (60 + n * 10) + \'%\'}"></div>' +
    '</div>' +

    '<div v-else-if="feed" class="pt-4">' +

    // ===== Comment composer — on All and Comments (§5.3/§7.3) =====
    '<div v-if="canEdit && (tab === \'all\' || tab === \'comments\')" class="mb-5">' +
    '<pg-editor v-if="useJodit" ref="commentEditor" v-model="composer.content" placeholder="Add comment" ' +
    'min-height="90px" :document-view="false" :buttons="editorButtons" :license="editorLicense" ' +
    ':media-upload="endpoints.mediaUpload" />' +
    '<wi-editor v-else ref="commentEditor" v-model="composer.content" placeholder="Add comment" min-height="90px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '<div class="flex items-center mt-2">' +
    '<button type="button" @click="postComment" :disabled="composer.busy || !hasText(composer.content)" ' +
    'class="ml-auto h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Comment</button>' +
    '</div></div>' +

    // ===== All (§5) =====
    '<ul v-if="tab === \'all\'" class="space-y-4">' +
    '<li v-for="e in feed.all" :key="e.kind + \'-\' + e.id" class="flex items-start gap-2.5">' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(e)"></span>' +
    '<div class="min-w-0 flex-1">' +
    // A comment is somebody talking, so it keeps its card and its author's face; the badge
    // beside it just says "this row is a comment".
    '<template v-if="e.kind === \'comment\'">' +
    '<div class="rounded-lg border border-line bg-white p-3">' +
    '<div class="flex items-center gap-2">' +
    '<wi-avatar :person="e.author" :size="22" />' +
    '<span class="text-[13px] font-medium text-ink">{{ e.author ? e.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint">{{ relativeTime(e.created_at) }}</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="e.content"></div></div>' +
    '</template>' +
    '<template v-else-if="e.kind === \'update\'">' +
    '<div class="text-[13px]"><span class="font-medium text-ink">{{ e.author ? e.author.name : \'Someone\' }}</span> <span class="text-sub">posted an update</span> ' +
    '<span class="inline-flex items-center gap-1 h-5 px-1.5 rounded border text-[11px] font-semibold" :class="updateMeta(e.status).cls"><span v-html="updateMeta(e.status).icon"></span>{{ e.status_label }}</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1" v-html="e.content"></div>' +
    '</template>' +
    '<template v-else-if="e.kind === \'worklog\'">' +
    '<div class="text-[13px]"><span class="font-medium text-ink">{{ e.user ? e.user.name : \'Someone\' }}</span> <span class="text-sub">logged {{ e.duration }}</span></div>' +
    '<div v-if="e.description" class="text-[13px] text-sub mt-0.5">{{ e.description }}</div>' +
    '</template>' +
    '<div v-else class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(e).who }}</span> {{ activityLine(e).text }}</div>' +
    '<div v-if="e.kind !== \'comment\'" class="text-[12px] text-faint mt-0.5">{{ relativeTime(e.created_at) }}</div>' +
    '</div></li>' +
    '<li v-if="!feed.all.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No activity yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Activity and conversations will appear here.</div></li>' +
    '</ul>' +

    // ===== Activity (§6) =====
    '<ul v-else-if="tab === \'activity\'" class="space-y-3">' +
    '<li v-for="a in feed.activity" :key="a.id" class="flex items-start gap-2.5">' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(a)"></span>' +
    '<div class="min-w-0"><div class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(a).who }}</span> {{ activityLine(a).text }}</div>' +
    '<div class="text-[12px] text-faint">{{ relativeTime(a.created_at) }}</div></div></li>' +
    '<li v-if="!feed.activity.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No activity yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Changes to this work item will appear here.</div></li>' +
    '</ul>' +

    // ===== Comments (§7) =====
    // Each comment is a card: it separates one person's contribution from the next, which a
    // flat list of paragraphs does not. Actions are small icon buttons — they repeat on every
    // comment, and three words each turned the header into a wall of links — each carrying a
    // title and aria-label so an icon-only control still has an accessible name (§24).
    '<ul v-else-if="tab === \'comments\'" class="space-y-3">' +
    '<li v-for="c in feed.comments" :key="c.id" class="rounded-lg border border-line bg-white">' +

    '<div class="flex items-start gap-2.5 p-3">' +
    '<wi-avatar :person="c.author" :size="28" />' +
    '<div class="min-w-0 flex-1 overflow-hidden">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ c.author ? c.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint shrink-0">{{ relativeTime(c.created_at) }}</span>' +
    '<span v-if="c.edited" class="text-[11px] text-faint shrink-0">(edited)</span>' +
    '<span class="ml-auto flex items-center gap-0.5 shrink-0">' +
    '<button v-if="canEdit" type="button" @click="startReply(c)" data-tip="Reply" aria-label="Reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '' + wiIcon('reply', 14) + '</button>' +
    '<button v-if="canEditComment(c)" type="button" @click="startEditComment(c)" data-tip="Edit" aria-label="Edit comment" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '' + wiIcon('pen-solid-tip', 14) + '</button>' +
    '<button v-if="canEdit" type="button" @click="deleteComment(c)" data-tip="Delete" aria-label="Delete comment" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-danger hover:bg-hover">' +
    '' + wiIcon('trash-can', 14) + '</button>' +
    '</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="c.content"></div>' +
    '</div></div>' +

    // Replies live inside the parent's card, so a thread reads as one block (§7.8).
    '<ul v-if="c.replies.length" class="border-t border-line bg-[#fafbfc] rounded-b-lg divide-y divide-line">' +
    '<li v-for="r in c.replies" :key="r.id" class="flex items-start gap-2.5 p-3 pl-6">' +
    '<wi-avatar :person="r.author" :size="24" />' +
    '<div class="min-w-0 flex-1 overflow-hidden"><div class="flex items-center gap-2">' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ r.author ? r.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint shrink-0">{{ relativeTime(r.created_at) }}</span>' +
    '<span v-if="r.edited" class="text-[11px] text-faint shrink-0">(edited)</span>' +
    '<span class="ml-auto flex items-center gap-0.5 shrink-0">' +
    '<button v-if="canEditComment(r)" type="button" @click="startEditComment(r)" data-tip="Edit" aria-label="Edit reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '' + wiIcon('pen-solid-tip', 13) + '</button>' +
    '<button v-if="canEdit" type="button" @click="deleteComment(r)" data-tip="Delete" aria-label="Delete reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-danger hover:bg-hover">' +
    '' + wiIcon('trash-can', 13) + '</button>' +
    '</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="r.content"></div></div></li></ul>' +

    '</li>' +
    '<li v-if="!feed.comments.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No comments yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Start the conversation by adding a comment.</div></li>' +
    '</ul>' +

    // ===== Updates (§8) =====
    '<div v-else-if="tab === \'updates\'">' +
    '<div v-if="canEdit" class="flex justify-end mb-3">' +
    '<button type="button" @click="openUpdateForm(null)" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '' + wiIcon('plus', 14) + 'Add update</button></div>' +

    '<div v-if="updateForm.open" class="mb-4 rounded-lg border border-line p-3">' +
    '<div class="flex items-center gap-2 mb-2">' +
    '<button v-for="st in [\'on_track\',\'at_risk\',\'off_track\']" :key="st" type="button" @click="updateForm.status = st" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border text-[12px] font-semibold" ' +
    ':class="updateForm.status === st ? updateMeta(st).cls : \'border-line text-sub\'">' +
    '<span v-html="updateMeta(st).icon"></span>{{ updateMeta(st).label }}</button></div>' +
    '<pg-editor v-if="useJodit" ref="updateEditor" v-model="updateForm.content" placeholder="Add an update…" ' +
    'min-height="90px" :document-view="false" :buttons="editorButtons" :license="editorLicense" />' +
    '<wi-editor v-else ref="updateEditor" v-model="updateForm.content" placeholder="Add an update…" min-height="90px" class="block" />' +
    '<div class="flex justify-end gap-2 mt-2">' +
    '<button type="button" @click="updateForm.open = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveUpdate" :disabled="updateForm.busy" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ updateForm.id ? \'Save\' : \'Add update\' }}</button></div></div>' +

    '<ul class="space-y-3">' +
    '<li v-for="u in feed.updates" :key="u.id" class="rounded-lg border border-line p-3">' +
    '<div class="flex items-center gap-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border text-[12px] font-semibold" :class="updateMeta(u.status).cls">' +
    '<span v-html="updateMeta(u.status).icon"></span>{{ u.status_label }}</span>' +
    '<span class="text-[12px] text-sub">{{ relativeTime(u.created_at) }} · {{ u.author ? u.author.name : \'Someone\' }}</span>' +
    '<span v-if="u.edited" class="text-[11px] text-faint">(edited)</span>' +
    '<span v-if="canEdit" class="ml-auto flex items-center gap-2">' +
    '<button type="button" @click="openUpdateForm(u)" class="text-[12px] text-sub hover:underline">Edit</button>' +
    '<button type="button" @click="deleteUpdate(u)" class="text-[12px] text-danger hover:underline">Delete</button></span>' +
    '</div>' +
    '<div class="wi-rich text-[13px] text-ink mt-2" v-html="u.content"></div>' +
    '<div v-if="u.progress" class="flex items-center gap-2 mt-2 text-[12px] text-sub">' +
    '<span class="h-1.5 w-24 rounded-full bg-line overflow-hidden"><span class="block h-full bg-brand" :style="{width: u.progress.percent + \'%\'}"></span></span>' +
    'Progress {{ u.progress.percent }}% · {{ u.progress.completed }} / {{ u.progress.total }} done</div>' +
    '</li>' +
    '<li v-if="!feed.updates.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No updates yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Share the latest status of this work item.</div></li>' +
    '</ul></div>' +

    // ===== Worklogs (§9) =====
    '<div v-else-if="tab === \'worklogs\'">' +
    '<div v-if="canEdit" class="flex justify-end mb-3">' +
    '<button type="button" @click="openWorklogForm(null)" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '' + wiIcon('plus', 14) + 'Log work</button></div>' +

    '<div v-if="worklogForm.open" class="mb-4 rounded-lg border border-line p-3">' +
    '<div v-if="worklogForm.error" class="mb-2 rounded-md border border-danger/40 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ worklogForm.error }}</div>' +
    '<div class="flex flex-wrap items-end gap-3">' +
    '<div><label class="block text-[12px] text-sub mb-1">Date</label><input v-model="worklogForm.date" type="date" class="pb-input h-9 w-40" /></div>' +
    '<div><label class="block text-[12px] text-sub mb-1">Hours</label><input v-model="worklogForm.hours" type="number" min="0" max="99" class="pb-input h-9 w-20" /></div>' +
    '<div><label class="block text-[12px] text-sub mb-1">Minutes</label><input v-model="worklogForm.minutes" type="number" min="0" max="59" class="pb-input h-9 w-20" /></div>' +
    '<div class="flex-1 min-w-[180px]"><label class="block text-[12px] text-sub mb-1">Description</label>' +
    '<input v-model="worklogForm.description" type="text" placeholder="What did you complete?" class="pb-input h-9" /></div>' +
    '</div>' +
    '<div class="flex justify-end gap-2 mt-3">' +
    '<button type="button" @click="worklogForm.open = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveWorklog" :disabled="worklogForm.busy" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Save</button></div></div>' +

    '<ul class="space-y-2">' +
    '<li v-for="w in feed.worklogs.entries" :key="w.id" class="flex items-start gap-2.5 rounded-lg border border-line p-3">' +
    '<wi-avatar :person="w.user" :size="28" />' +
    '<div class="min-w-0 flex-1"><div class="flex items-center gap-2">' +
    '<span class="text-[13px] text-ink"><span class="font-medium">{{ w.user ? w.user.name : \'Someone\' }}</span> logged <span class="font-semibold">{{ w.duration }}</span></span>' +
    '<span class="text-[12px] text-faint">{{ fmtDate(w.work_date) }}</span>' +
    '<span v-if="canEdit && String(w.user_id) === String(currentUserId)" class="ml-auto flex items-center gap-2">' +
    '<button type="button" @click="openWorklogForm(w)" class="text-[12px] text-sub hover:underline">Edit</button>' +
    '<button type="button" @click="deleteWorklog(w)" class="text-[12px] text-danger hover:underline">Delete</button></span></div>' +
    '<div v-if="w.description" class="text-[13px] text-sub mt-1">{{ w.description }}</div></div></li>' +
    '<li v-if="!feed.worklogs.entries.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No work logged yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Track time spent working on this item.</div></li>' +
    '</ul></div>' +

    // ===== Transition (§10) =====
    '<ul v-else-if="tab === \'transition\'" class="space-y-3">' +
    '<li v-for="t in feed.transition" :key="t.id" class="flex items-start gap-2.5">' +
    '<wi-avatar :person="t.actor" :size="24" />' +
    '<div class="min-w-0"><div class="text-[13px] text-ink">' +
    '<span class="font-medium">{{ t.actor ? t.actor.name : \'Someone\' }}</span> set the state to {{ t.to || \'none\' }}.</div>' +
    '<div class="flex items-center gap-2 mt-1 text-[12px]">' +
    '<span v-if="t.from" class="inline-flex items-center h-5 px-1.5 rounded border border-line text-sub">{{ t.from }}</span>' +
    '<span v-if="t.from" class="text-faint">→</span>' +
    '<span class="inline-flex items-center h-5 px-1.5 rounded border border-line text-ink">{{ t.to || \'none\' }}</span>' +
    '<span class="text-faint">{{ t.is_current ? t.duration + \' so far\' : t.duration }}</span>' +
    '</div>' +
    '<div class="text-[12px] text-faint mt-0.5">{{ relativeTime(t.transitioned_at) }}</div></div></li>' +
    '<li v-if="!feed.transition.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No transitions yet</div>' +
    '<div class="text-[13px] text-sub mt-1">State changes will appear here.</div></li>' +
    '</ul>' +

    // ===== History (§11) =====
    '<ul v-else-if="tab === \'history\'" class="space-y-1">' +
    // A rail down the badges reads as one audit trail rather than a stack of unrelated rows.
    '<li v-for="h in feed.history" :key="h.id" class="relative flex items-start gap-2.5 pb-4 last:pb-0">' +
    '<span class="absolute left-[13px] top-8 bottom-0 w-px bg-line last:hidden"></span>' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(h)"></span>' +
    '<div class="min-w-0 pt-0.5">' +
    '<div class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(h).who }}</span> {{ activityLine(h).text }} ' +
    '<span class="text-[12px] text-faint">· {{ relativeTime(h.created_at) }}</span></div>' +
    '<div class="flex items-center gap-2 mt-1.5 text-[12px]">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line text-sub">' +
    '<span v-if="valueIcon(h)" class="grid place-items-center text-faint" v-html="valueIcon(h)"></span>' +
    '{{ historyValue(h, \'old\') }}</span>' +
    '' + wiIcon('arrow-right-long', 13, 'text-faint shrink-0') + '' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line text-ink">' +
    '<span v-if="valueIcon(h)" class="grid place-items-center text-faint" v-html="valueIcon(h)"></span>' +
    '{{ historyValue(h, \'new\') }}</span>' +
    '</div></div></li>' +
    '<li v-if="!feed.history.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No history yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Changes to work item properties will appear here.</div></li>' +
    '</ul>' +

    '</div>' +

    // §16.3: a failed load must offer a way back, not an empty panel.
    '<div v-else class="py-8 text-center">' +
    '<div class="text-[13px] font-semibold text-head">Unable to load this work item\'s activity</div>' +
    '<button type="button" @click="loadFeed" class="mt-2 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Try again</button>' +
    '</div>' +

    '</div>' +

    '<div class="h-6"></div>' +
    '</div>' +

    // ---- Properties. Each control opens the SAME picker the grid row uses, so there is one
    //      implementation of "change a property" and one PATCH path behind it. ----
    '<aside class="w-full lg:w-[340px] shrink-0 border-t lg:border-t-0 lg:border-l border-line px-5 sm:px-6 py-6 lg:overflow-y-auto">' +
    '<h3 class="text-[15px] font-semibold text-head">Properties</h3>' +
    '<div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-4">' +

    '<div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">State</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'state\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="(canEdit ? \'Change state — \' : \'State: \') + (drawerItem.state ? drawerItem.state.name : \'No state\')" ' +
    'class="flex items-center gap-1.5 max-w-full text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">' +
    '<span class="grid place-items-center shrink-0" v-html="stateIcon(drawerItem.state)"></span>' +
    '<span class="truncate">{{ drawerItem.state ? drawerItem.state.name : \'No state\' }}</span></button></div>' +

    '<div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Priority</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'priority\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change priority\' : \'Priority\'" class="inline-flex items-center gap-1.5 text-[13px] rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="priorityMeta(drawerItem.priority).cls">' +
    '<span class="grid place-items-center" v-html="priorityMeta(drawerItem.priority).icon"></span>{{ priorityMeta(drawerItem.priority).label }}</button></div>' +

    // A long name truncates instead of wrapping: these cells are half a narrow column, and a
    // name breaking across two lines pushes every property below it out of alignment. The
    // full name stays available in the tooltip.
    '<div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Assignee</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'assignees\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="assigneeTip" class="flex items-center gap-1.5 max-w-full text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">' +
    '<template v-if="drawerItem.assignees && drawerItem.assignees.length">' +
    '<wi-avatar :person="drawerItem.assignees[0]" :size="20" />' +
    '<span class="truncate">{{ drawerItem.assignees[0].name }}</span></template>' +
    '<span v-else class="text-sub">Unassigned</span></button></div>' +

    '<div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Start date</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'start_date\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change start date\' : \'Start date\'" class="text-[13px] text-left rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="drawerItem.start_date ? \'text-ink\' : \'text-sub\'">' +
    '{{ drawerItem.start_date ? fmtDate(drawerItem.start_date) : \'None\' }}</button></div>' +

    '<div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Due date</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'due_date\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change due date\' : \'Due date\'" class="text-[13px] text-left rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="drawerItem.due_date ? \'text-ink\' : \'text-sub\'">' +
    '{{ drawerItem.due_date ? fmtDate(drawerItem.due_date) : \'None\' }}</button></div>' +
    '</div>' +

    '<div class="text-[13px] font-medium text-sub mt-6 mb-1">Details</div>' +
    '<div class="divide-y divide-line">' +
    '<div class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Parent</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    '<button v-if="drawerItem.parent_id" type="button" :disabled="!canEdit" @click="openParentPicker" ' +
    ':data-tip="canEdit ? \'Change parent — \' + parentLabel(drawerItem) : parentLabel(drawerItem)" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink min-w-0 hover:bg-hover">' +
    '' + wiIcon('diagram-subtask', 12, 'text-brand shrink-0') + '' +
    '<span class="truncate">{{ parentLabel(drawerItem) }}</span></button>' +
    // One parent per item (§5), so once it is set the "+" would promise a second — the chip
    // changes it, and the x next to it clears it.
    '<button v-if="canEdit && drawerItem.parent_id" type="button" @click="clearParent" ' +
    'data-tip="Remove parent" aria-label="Remove parent" ' +
    'class="h-6 w-6 grid place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0">' +
    '' + wiIcon('xmark', 12) + '</button>' +
    '<button v-if="canEdit && !drawerItem.parent_id" type="button" @click="openParentPicker" ' +
    'data-tip="Add a parent" aria-label="Add a parent" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '<span>Add parent</span></button>' +
    '<span v-if="!canEdit && !drawerItem.parent_id" class="text-[13px] text-sub">None</span>' +
    '</div></div>' +
    // §8.1: "Add cycle" when there is none, the cycle's name as a chip when there is. The
    // whole row disappears with the feature, rather than sitting there refusing to work.
    // §9.1: chips for each module the item belongs to, or "Add module" when it is in none.
    // Estimation §12/§13: the estimate as a chip, or "Estimate +" when there is none. Shown
    // while the feature is on, and also while it is off if this item already carries one —
    // §27 keeps that value readable, just not editable.
    '<div v-if="estimatesEnabled || drawerItem.estimate" class="py-2.5">' +
    '<div class="text-[12px] text-sub mb-1.5">Estimate</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    '<span v-if="drawerItem.estimate && !estimatesEnabled" ' +
    ':data-tip="\'Estimate: \' + drawerItem.estimate.label + \' — estimation is disabled for this project\'" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-hover text-[12px] text-sub max-w-full">' +
    '<span class="truncate">{{ drawerItem.estimate.label }}</span></span>' +
    '<span v-if="drawerItem.estimate && !estimatesEnabled" class="text-[11px] text-faint">Estimation disabled</span>' +

    '<button v-if="drawerItem.estimate && estimatesEnabled" type="button" :disabled="!canEdit" ' +
    '@click="openRowMenu(\'estimate\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change estimate — \' + drawerItem.estimate.label : \'Estimate: \' + drawerItem.estimate.label" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink max-w-full hover:bg-hover">' +
    '<span class="truncate">{{ drawerItem.estimate.label }}</span></button>' +
    // §13: one estimate, so once it is set the "+" would promise a second the picker will
    // not give — the chip itself changes it, and "No Estimate" inside clears it.
    '<button v-if="canEdit && estimatesEnabled && !drawerItem.estimate" type="button" ' +
    '@click="openRowMenu(\'estimate\', drawerItem, $event.currentTarget)" ' +
    'data-tip="Add an estimate" aria-label="Add an estimate" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '<span>Estimate</span></button>' +
    '<span v-if="!canEdit && estimatesEnabled && !drawerItem.estimate" class="text-[13px] text-sub">No Estimate</span>' +
    '</div></div>' +

    // Epic §9: one epic, shown as a chip with the ID beside the title. The whole row is
    // absent when the feature is off (§4) rather than sitting there refusing to work — and
    // it is independent of the Module and Cycle rows below it (§11/§12).
    // Visible while the feature is ON, and also while it is OFF if this item already has an
    // epic — that assignment is historical context and must stay legible. Only when the
    // feature is off AND there is nothing to show does the row disappear entirely.
    '<div v-if="epicsEnabled || drawerItem.epic" class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Epic</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    // Frozen: the chip still reads, but it is not a button and it says why.
    '<span v-if="drawerItem.epic && !epicsEnabled" ' +
    ':data-tip="\'Epic: \' + drawerItem.epic.title + \' — epics are disabled for this project\'" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-hover text-[12px] text-sub max-w-full">' +
    '' + wiIcon('rectangles-pair', 12, 'shrink-0') + '' +
    '<span class="truncate">{{ drawerItem.epic.title }}</span></span>' +
    '<span v-if="drawerItem.epic && !epicsEnabled" class="text-[11px] text-faint">Epic feature disabled</span>' +

    '<button v-if="drawerItem.epic && epicsEnabled" type="button" :disabled="!canEdit" ' +
    '@click="openRowMenu(\'epic\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change epic — \' + drawerItem.epic.title : \'Epic: \' + drawerItem.epic.title" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink max-w-full hover:bg-hover">' +
    '' + wiIcon('rectangles-pair', 12, 'text-brand shrink-0') + '' +
    '<span class="truncate">{{ drawerItem.epic.title }}</span></button>' +
    // §9 gives a work item ONE epic, so once it is set a "+" would promise a second the
    // picker will not give — the chip itself is the way to change it.
    '<button v-if="canEdit && epicsEnabled && !drawerItem.epic" type="button" ' +
    '@click="openRowMenu(\'epic\', drawerItem, $event.currentTarget)" ' +
    'data-tip="Add to an epic" aria-label="Add to an epic" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '<span>Add epic</span></button>' +
    '<span v-if="!canEdit && !drawerItem.epic" class="text-[13px] text-sub">No epic</span>' +
    '</div></div>' +

    // Feature Disable §6: visible while Modules is ON, and also while it is OFF if this item
    // already belongs to one — that assignment is historical context. When the feature is off
    // and there is nothing to show, the row goes entirely rather than sitting there disabled.
    '<div v-if="modulesEnabled || (drawerItem.modules && drawerItem.modules.length)" class="py-2.5">' +
    '<div class="text-[12px] text-sub mb-1.5">Modules</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    // Frozen: still reads, no longer a button, and says why.
    '<template v-if="!modulesEnabled">' +
    '<span v-for="m in drawerItem.modules" :key="m.id" :data-tip="m.title + \' — modules are disabled for this project\'" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-hover text-[12px] text-sub max-w-full">' +
    '' + wiIcon('grid', 12, 'shrink-0') + '' +
    '<span class="truncate">{{ m.title }}</span></span>' +
    '<span class="text-[11px] text-faint">Module feature disabled</span></template>' +
    // v-if on a <template> rather than beside the v-for: Vue 3 evaluates v-if FIRST, so the
    // two on one element means the condition cannot see the loop variable.
    '<template v-if="modulesEnabled">' +
    '<button v-for="m in drawerItem.modules" :key="m.id" type="button" :disabled="!canEdit" ' +
    '@click="openRowMenu(\'modules\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change modules — \' + m.title : m.title" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink max-w-full hover:bg-hover">' +
    '<span class="grid place-items-center shrink-0 text-brand">' + wiIcon('grid', 12) + '</span>' +
    '<span class="truncate">{{ m.title }}</span></button></template>' +
    // §9.3: an item can be in several modules at once, so the "+" stays put after the chips.
    '<button v-if="canEdit && modulesEnabled" type="button" @click="openRowMenu(\'modules\', drawerItem, $event.currentTarget)" ' +
    'data-tip="Add modules" aria-label="Add modules" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '' +
    '<span v-if="!drawerItem.modules || !drawerItem.modules.length">Add modules</span></button>' +
    '<span v-if="!canEdit && modulesEnabled && (!drawerItem.modules || !drawerItem.modules.length)" class="text-[13px] text-sub">No modules</span>' +
    '</div></div>' +

    // Feature Disable §6, as above: shown while Cycles is on, kept read-only while it is off
    // if this item already has one, and hidden when there is neither.
    '<div v-if="cyclesEnabled || drawerItem.cycle" class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Cycle</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    '<span v-if="drawerItem.cycle && !cyclesEnabled" ' +
    ':data-tip="\'Cycle: \' + drawerItem.cycle.name + \' — cycles are disabled for this project\'" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-hover text-[12px] text-sub max-w-full">' +
    '' + wiIcon('rotate', 12, 'shrink-0') + '' +
    '<span class="truncate">{{ drawerItem.cycle.name }}</span></span>' +
    '<span v-if="drawerItem.cycle && !cyclesEnabled" class="text-[11px] text-faint">Cycle feature disabled</span>' +
    '<button v-if="drawerItem.cycle && cyclesEnabled" type="button" :disabled="!canEdit" ' +
    '@click="openRowMenu(\'cycle\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change cycle — \' + drawerItem.cycle.name : \'Cycle: \' + drawerItem.cycle.name" ' +
    'class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink max-w-full hover:bg-hover">' +
    '' + wiIcon('rotate', 12, 'text-brand shrink-0') + '' +
    '<span class="truncate">{{ drawerItem.cycle.name }}</span></button>' +
    // Cycles §8.3.1 is one cycle per item, so once one is set the "+" would promise a second
    // that the picker will not give — the chip itself is the way to change it.
    '<button v-if="canEdit && cyclesEnabled && !drawerItem.cycle" type="button" ' +
    '@click="openRowMenu(\'cycle\', drawerItem, $event.currentTarget)" ' +
    'data-tip="Add to a cycle" aria-label="Add to a cycle" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '<span>Add cycle</span></button>' +
    '<span v-if="!canEdit && cyclesEnabled && !drawerItem.cycle" class="text-[13px] text-sub">No cycle</span>' +
    '</div></div>' +
    // §4.3: labels are a set, so the row carries its own "+" rather than making the chips the
    // only way in — with several labels already applied there was no obvious spot left to
    // click, and an empty row read "None", which says nothing about being able to add one.
    // The chips stay clickable too, since that is where the eye goes first.
    // Labels §3/§18: the editable field is hidden while the feature is off, but chips already
    // on the item stay visible — §28.5 keeps those assignments, so hiding them would make a
    // work item look uncategorized when it is not.
    '<div v-if="labelsEnabled || (drawerItem.labels && drawerItem.labels.length)" class="py-2.5">' +
    '<div class="text-[12px] text-sub mb-1.5">Labels</div>' +
    '<div class="flex flex-wrap items-center gap-1.5">' +
    '<template v-if="!labelsEnabled">' +
    '<span v-for="l in drawerItem.labels" :key="l.id" ' +
    ':data-tip="l.name + \' — labels are disabled for this project\'" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded border border-line bg-hover text-[11px] text-sub max-w-full">' +
    '<span class="h-2 w-2 rounded-full shrink-0 opacity-60" :style="{background: l.color}"></span>' +
    '<span class="truncate">{{ l.name }}</span></span>' +
    '<span class="text-[11px] text-faint">Label feature disabled</span></template>' +

    '<template v-if="labelsEnabled">' +
    '<button v-for="l in drawerItem.labels" :key="l.id" type="button" :disabled="!canEdit" ' +
    '@click="openRowMenu(\'labels\', drawerItem, $event.currentTarget)" ' +
    ':data-tip="canEdit ? \'Change labels — \' + l.name : l.name" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded border border-line text-[11px] text-ink max-w-full hover:bg-hover">' +
    '<span class="h-2 w-2 rounded-full shrink-0" :style="{background: l.color}"></span><span class="truncate">{{ l.name }}</span></button>' +
    '</template>' +
    '<button v-if="canEdit && labelsEnabled" type="button" @click="openRowMenu(\'labels\', drawerItem, $event.currentTarget)" ' +
    'data-tip="Add labels" aria-label="Add labels" ' +
    'class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">' +
    '' + wiIcon('plus', 12) + '' +
    '<span v-if="!drawerItem.labels || !drawerItem.labels.length">Add labels</span></button>' +
    '<span v-if="!canEdit && labelsEnabled && (!drawerItem.labels || !drawerItem.labels.length)" class="text-[13px] text-sub">None</span>' +
    '</div></div>' +
    '</div>' +

    '<div class="mt-6 pt-4 border-t border-line text-[12px] text-sub space-y-1.5">' +
    '<div v-if="drawerItem.created_by">Created by {{ drawerItem.created_by }}</div>' +
    '<div v-if="drawerItem.created_at">Created on {{ fmtDateTime(drawerItem.created_at) }}</div>' +
    '<div v-if="drawerItem.updated_at">Updated on {{ fmtDateTime(drawerItem.updated_at) }}</div>' +
    '</div>' +
    '</aside>' +

    '</div></aside></div>' +



    // ===== Reply / edit a comment (§7.5/§7.8) =====
    // A modal rather than the composer at the top of the tab: both actions are ABOUT a
    // particular comment, which may be scrolled out of sight, so the one being answered is
    // quoted right above the editor.
    '<div v-if="commentModal.open" class="fixed inset-0 z-[102] flex items-start justify-center p-4 sm:pt-20">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, closeCommentModal)"></div>' +
    '<div class="relative w-full max-w-[640px] bg-white rounded-xl shadow-xl flex flex-col max-h-[85vh]">' +

    '<div class="flex items-center gap-3 px-5 py-4 border-b border-line shrink-0">' +
    '<h2 class="text-[15px] font-semibold text-head">{{ commentModal.mode === \'edit\' ? \'Edit comment\' : \'Reply to comment\' }}</h2>' +
    '<button type="button" @click="closeCommentModal" data-tip="Close" aria-label="Close" ' +
    'class="ml-auto h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '' + wiIcon('xmark', 16) + '</button>' +
    '</div>' +

    '<div class="px-5 py-4 overflow-y-auto">' +
    // What is being replied to, so the answer has its question in view.
    '<div v-if="commentModal.mode === \'reply\' && commentModal.target" class="mb-3 rounded-lg border border-line bg-[#fafbfc] p-3">' +
    '<div class="flex items-center gap-2">' +
    '<wi-avatar :person="commentModal.target.author" :size="22" />' +
    '<span class="text-[13px] font-medium text-ink">{{ commentModal.target.author ? commentModal.target.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint">{{ relativeTime(commentModal.target.created_at) }}</span></div>' +
    '<div class="wi-rich text-[13px] text-sub mt-1.5 max-h-24 overflow-y-auto overflow-x-hidden" v-html="commentModal.target.content"></div>' +
    '</div>' +

    '<pg-editor v-if="useJodit" ref="commentModalEditor" v-model="commentModal.content" ' +
    ':placeholder="commentModal.mode === \'edit\' ? \'Edit your comment\' : \'Write a reply\'" ' +
    'min-height="120px" :document-view="false" :buttons="editorButtons" :license="editorLicense" ' +
    ':media-upload="endpoints.mediaUpload" />' +
    '<wi-editor v-else ref="commentModalEditor" v-model="commentModal.content" :placeholder="commentModal.mode === \'edit\' ? \'Edit your comment\' : \'Write a reply\'" ' +
    'min-height="120px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '</div>' +

    '<div class="px-5 py-3 border-t border-line flex justify-end gap-2 shrink-0">' +
    '<button type="button" @click="closeCommentModal" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="submitCommentModal" :disabled="commentModal.busy || !hasText(commentModal.content)" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ commentModal.busy ? \'Saving…\' : (commentModal.mode === \'edit\' ? \'Save\' : \'Reply\') }}</button>' +
    '</div></div></div>' +

    // ===== Work item picker (§22 / §30 / §34) — one dialog for sub-tasks and every relation
    // type; only the title and what happens on Add differ. =====
    '<div v-if="picker.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, closePicker)"></div>' +
    '<div class="relative w-full max-w-[640px] bg-white rounded-xl shadow-xl flex flex-col max-h-[75vh]">' +
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '<span class="text-[14px] font-semibold text-head shrink-0">{{ picker.title }}</span>' +
    '<input ref="pickerSearch" v-model="picker.query" @input="onPickerQuery" type="text" placeholder="Search by ID or title" ' +
    'class="flex-1 h-8 text-[14px] text-ink placeholder:text-faint outline-none bg-transparent" />' +
    '<button type="button" @click="closePicker" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" data-tip="Close" aria-label="Close">' +
    '' + wiIcon('xmark', 16) + '</button>' +
    '</div>' +

    // §57: this project by default, the whole workspace on request.
    '<label class="flex items-center gap-2 px-5 py-2 border-b border-line text-[12px] text-sub shrink-0">' +
    '<input type="checkbox" v-model="picker.allProjects" @change="searchItems" class="h-3.5 w-3.5 rounded border-stroke text-brand" />' +
    'Search all projects in this workspace</label>' +

    '<div class="p-2 overflow-y-auto">' +
    '<button v-for="row in picker.results" :key="row.id" type="button" @click="togglePick(row)" :disabled="!!row.blocked" ' +
    'class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover disabled:opacity-60 disabled:cursor-not-allowed" ' +
    ':class="isPicked(row) ? \'bg-sel/40\' : \'\'">' +
    '<span class="h-[18px] w-[18px] rounded border grid place-items-center shrink-0 transition-colors" ' +
    ':class="isPicked(row) ? \'bg-brand border-brand\' : \'border-stroke bg-white\'">' +
    '<svg v-if="isPicked(row)" width="12" height="12" viewBox="0 0 24 24" fill="none">' +
    '<path d="M5 12l4 4L19 7" stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</span>' +
    '<span class="grid place-items-center shrink-0" v-html="stateIcon(row.state)"></span>' +
    '<span class="text-[12px] text-sub shrink-0">{{ row.identifier }}</span>' +
    '<span class="text-[14px] text-ink truncate">{{ row.title }}</span>' +
    '<span v-if="picker.allProjects" class="text-[11px] text-faint shrink-0">{{ row.project }}</span>' +
    '<span v-if="row.blocked" class="text-[11px] text-faint shrink-0">{{ row.blocked }}</span>' +
    '<span class="ml-auto grid place-items-center shrink-0" v-html="priorityMeta(row.priority).icon"></span>' +
    '</button>' +
    '<div v-if="!picker.results.length" class="px-3 py-8 text-[13px] text-sub text-center">No work items found</div>' +
    '</div>' +

    '<div class="px-5 py-3 border-t border-line flex items-center gap-2 shrink-0">' +
    '<span class="text-[12px] text-sub">{{ picker.selected.length }} selected</span>' +
    '<button type="button" @click="closePicker" class="ml-auto h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="confirmPicker" :disabled="!picker.selected.length || picker.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ picker.busy ? (picker.mode === \'parent\' ? \'Saving…\' : \'Adding…\') : (picker.mode === \'parent\' ? \'Set parent\' : \'Add\') }}</button>' +
    '</div></div></div>' +

    // ===== Link pages (POC: LinkPagesModal) =====
    '<div v-if="pagePicker.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, function(){ pagePicker.open = false; })"></div>' +
    '<div class="relative w-full max-w-[680px] bg-white rounded-xl shadow-xl flex flex-col max-h-[72vh]">' +
    '<div class="flex items-center justify-between px-5 py-4 border-b border-line shrink-0">' +
    '<h2 class="text-[15px] font-semibold text-head">Link pages to ' +
    '<span class="text-brand">{{ drawerItem ? drawerItem.identifier : \'\' }}</span></h2>' +
    '<button type="button" @click="pagePicker.open = false" data-tip="Close" aria-label="Close" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' + wiIcon('xmark', 18) + '</button>' +
    '</div>' +

    '<div class="flex items-center gap-3 px-5 py-2.5 border-b border-line shrink-0">' +
    '' + wiIcon('magnifying-glass', 16, 'text-faint shrink-0') + '' +
    '<input v-model="pagePicker.query" @input="onPageQuery" type="text" placeholder="Search pages" ' +
    'class="flex-1 h-8 text-[14px] text-ink placeholder:text-faint outline-none bg-transparent" />' +
    // The POC pairs this with a "Show Wiki pages" switch. Wiki is a later phase (Pages §14),
    // so it says so rather than offering a control that would do nothing.
    '<span class="h-5 w-px bg-line"></span>' +
    '<span class="text-[12px] text-faint shrink-0 whitespace-nowrap" ' +
    'data-tip="Linking Wiki pages arrives with the Wiki">Wiki pages ' +
    '<span class="text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span></span>' +
    '</div>' +

    '<div class="p-2 overflow-y-auto flex-1">' +
    '<button v-for="row in pagePicker.results" :key="row.id" type="button" @click="togglePagePick(row)" ' +
    ':disabled="row.linked" class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover disabled:opacity-60" ' +
    ':class="isPagePicked(row) ? \'bg-sel/40\' : \'\'">' +
    // The tick is an inline SVG with a hard-coded stroke, not an icon-font glyph: at 11px a
    // webfont glyph sits off-centre in a 16px box and inherits a colour the box has already
    // painted over. This is the same checkbox the module and cycle pickers draw.
    '<span class="h-[18px] w-[18px] rounded border grid place-items-center shrink-0 transition-colors" ' +
    ':class="isPagePicked(row) ? \'bg-brand border-brand\' : \'border-stroke bg-white\'">' +
    '<svg v-if="isPagePicked(row)" width="12" height="12" viewBox="0 0 24 24" fill="none">' +
    '<path d="M5 12l4 4L19 7" stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</span>' +
    '' + wiIcon('file-lines', 15, 'text-sub shrink-0') + '' +
    '<span class="text-[14px] text-ink truncate flex-1 min-w-0">{{ row.title }}</span>' +
    '<span v-if="row.linked" class="text-[11px] text-faint shrink-0">Already linked</span>' +
    '<span v-else class="text-[11px] text-faint shrink-0 hidden sm:inline">{{ relativeTime(row.updated_at) }}</span>' +
    '</button>' +
    '<div v-if="pagePicker.loaded && !pagePicker.results.length" class="px-3 py-8 text-[13px] text-sub text-center">' +
    '{{ pagePicker.query ? \'No page matches\' : \'No pages in this project yet\' }}</div>' +
    '</div>' +

    '<div class="flex items-center gap-2 px-5 py-3 border-t border-line shrink-0">' +
    '<span class="text-[12px] text-sub">{{ pagePicker.selected.length }} selected</span>' +
    '<button type="button" @click="pagePicker.open = false" ' +
    'class="ml-auto h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="confirmPagePicker" :disabled="!pagePicker.selected.length || pagePicker.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ pagePicker.busy ? \'Linking…\' : \'Confirm\' }}</button>' +
    '</div></div></div>' +

    // ===== Add / edit link (§38) =====
    '<div v-if="linkModal.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-28">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, function () { linkModal.open = false; })"></div>' +
    '<div class="relative w-full max-w-[460px] bg-white rounded-xl shadow-xl">' +
    '<div class="px-5 py-4 border-b border-line">' +
    '<h2 class="text-[15px] font-semibold text-head">{{ linkModal.id ? \'Edit link\' : \'Add link\' }}</h2></div>' +
    '<div class="px-5 py-4 space-y-3">' +
    '<div v-if="linkModal.error" class="rounded-lg border border-danger/40 bg-danger/5 px-3 py-2 text-[13px] text-danger">{{ linkModal.error }}</div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">URL</label>' +
    '<input v-model="linkModal.url" type="url" placeholder="https://www.figma.com/…" class="pb-input" @keydown.enter.prevent="saveLink" /></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Title <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="linkModal.title" type="text" placeholder="Checkout UX design" class="pb-input" @keydown.enter.prevent="saveLink" /></div>' +
    '</div>' +
    '<div class="px-5 py-3 border-t border-line flex justify-end gap-2">' +
    '<button type="button" @click="linkModal.open = false" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveLink" :disabled="!linkModal.url.trim() || linkModal.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">{{ linkModal.id ? \'Save\' : \'Add link\' }}</button>' +
    '</div></div></div>' +

    // ===== Row chip pickers + action menu (§4.2 / §4.4) =====
    // One shared, fixed-positioned popover so it escapes the grid's scroll container.
    '<div v-if="rowMenu.open" class="fixed inset-0 z-[110]" @click="closeRowMenu"></div>' +
    '<div v-if="rowMenu.open" :style="rowMenu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5">' +

    // -- State --
    '<template v-if="rowMenu.kind===\'state\'">' +
    '<div class="py-1">' +
    '<button v-for="s in vocab.states" :key="s.id" type="button" @click="setRowState(s)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span><span class="flex-1 truncate">{{ s.name }}</span>' +
    '<span v-if="rowMenu.item && rowMenu.item.state_id===s.id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button></div></template>' +

    // -- Priority --
    '<template v-else-if="rowMenu.kind===\'priority\'">' +
    '<div class="py-1">' +
    '<button v-for="p in priorities" :key="p.key" type="button" @click="setRowPriority(p)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="priorityMeta(p.key).icon"></span><span class="flex-1">{{ p.label }}</span>' +
    '<span v-if="rowMenu.item && rowMenu.item.priority===p.key" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button></div></template>' +

    // -- Assignees (multi-select; stays open) --
    '<template v-else-if="rowMenu.kind===\'assignees\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search members..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="m in rowMembers()" :key="m.id" type="button" @click="toggleRowAssignee(m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<wi-avatar :person="m" :size="24" /><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<span v-if="rowHasAssignee(m)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!rowMembers().length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div></div></template>' +

    // -- Labels (multi-select; stays open) --
    '<template v-else-if="rowMenu.kind===\'labels\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search labels..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="l in rowLabels()" :key="l.id" type="button" @click="toggleRowLabel(l)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: l.color}"></span><span class="flex-1 truncate">{{ l.name }}</span>' +
    '<span v-if="rowHasLabel(l)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!rowLabels().length && !newLabel.open" class="px-2 py-3 text-[13px] text-sub text-center">' +
    '{{ rowQuery ? \'No label matches\' : \'No labels yet\' }}</div>' +
    '</div>' +

    // Create a label without leaving the picker. The row quotes what was typed, so the
    // common case — searched, not found, wanted anyway — is one click.
    '<div v-if="!newLabel.open" class="border-t border-line mt-1 pt-1">' +
    '<button type="button" @click="startNewLabel" :disabled="labelExists(rowQuery)" ' +
    'class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink disabled:opacity-40">' +
    '' + wiIcon('plus', 15, 'text-sub shrink-0') + '' +
    '<span class="truncate">{{ rowQuery.trim() ? \'Create “\' + rowQuery.trim() + \'”\' : \'Create new label\' }}</span></button>' +
    '</div>' +

    '<div v-else class="border-t border-line mt-1 pt-2">' +
    '<div v-if="newLabel.error" class="mb-2 rounded-md border border-danger/40 bg-danger/5 px-2 py-1.5 text-[12px] text-danger">{{ newLabel.error }}</div>' +
    '<input v-model="newLabel.name" placeholder="Label name" maxlength="60" @keydown.enter.prevent="saveNewLabel" ' +
    'class="w-full h-9 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="flex flex-wrap items-center gap-1.5 mt-2 px-0.5">' +
    '<button v-for="c in labelColors()" :key="c" type="button" @click="newLabel.color = c" :data-tip="c" :aria-label="\'Colour \' + c" ' +
    'class="h-5 w-5 rounded-full border-2" :style="{background: c, borderColor: newLabel.color === c ? \'#23272f\' : \'transparent\'}"></button>' +
    '</div>' +
    '<div class="flex justify-end gap-2 mt-2">' +
    '<button type="button" @click="cancelNewLabel" class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveNewLabel" :disabled="newLabel.busy || !newLabel.name.trim()" ' +
    'class="h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[12px] font-semibold disabled:opacity-50">{{ newLabel.busy ? \'Saving…\' : \'Save\' }}</button>' +
    '</div></div>' +
    '</div></template>' +

    // -- Cycle (§8.2): search, current selection ticked, and picking the current one clears
    //    it. Completed cycles are absent by construction — the server only sends assignable
    //    ones, because offering an option it would refuse is worse than not offering it. --
    '<template v-else-if="rowMenu.kind===\'cycle\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search cycles..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="c in rowCycles()" :key="c.id" type="button" @click="setRowCycle(c)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('rotate', 14, 'text-sub shrink-0') + '' +
    '<span class="flex-1 truncate">{{ c.name }}</span>' +
    '<span class="text-[11px] text-faint capitalize shrink-0">{{ c.status }}</span>' +
    '<span v-if="rowMenu.item && rowMenu.item.cycle_id===c.id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!rowCycles().length" class="px-2 py-3 text-[13px] text-sub text-center">' +
    '{{ rowQuery ? \'No cycle matches\' : \'No active or upcoming cycles\' }}</div>' +
    '</div>' +
    '<div v-if="rowMenu.item && rowMenu.item.cycle_id" class="border-t border-line mt-1 pt-1">' +
    '<button type="button" @click="setRowCycle(null)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('xmark', 15, 'text-sub shrink-0') + 'Remove from cycle</button>' +
    '</div></div></template>' +

    // -- Estimate (§11/§13): the configured values, "No Estimate" first. No search box — a
    //    system has at most twenty values and they are meant to be scanned, not hunted. --
    '<template v-else-if="rowMenu.kind===\'estimate\'">' +
    '<div class="p-2"><div class="max-h-60 overflow-y-auto">' +
    '<button type="button" @click="setRowEstimate(null)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-sub">' +
    '<span class="flex-1">No Estimate</span>' +
    '<span v-if="rowMenu.item && !rowMenu.item.estimate_value_id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span></button>' +
    '<button v-for="e in estimates" :key="e.id" type="button" @click="setRowEstimate(e)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="flex-1 truncate">{{ e.label }}</span>' +
    '<span v-if="rowMenu.item && String(rowMenu.item.estimate_value_id)===String(e.id)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!estimates.length" class="px-2 py-3 text-[13px] text-sub text-center">No estimate values configured</div>' +
    '</div></div></template>' +

    // -- Epic (§9): search, current selection ticked, and picking the current one clears it.
    //    Archived epics are absent by construction — the server only sends active ones (§16),
    //    because offering an option it would refuse is worse than not offering it. --
    '<template v-else-if="rowMenu.kind===\'epic\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search epics..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="e in rowEpics()" :key="e.id" type="button" @click="setRowEpic(e)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('rectangles-pair', 14, 'text-sub shrink-0') + '' +
    '<span class="text-[11px] text-faint shrink-0">{{ e.identifier }}</span>' +
    '<span class="flex-1 truncate">{{ e.title }}</span>' +
    '<span v-if="rowMenu.item && String(rowMenu.item.epic_id)===String(e.id)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!rowEpics().length" class="px-2 py-3 text-[13px] text-sub text-center">' +
    '{{ rowQuery ? \'No epic matches\' : \'No epics in this project yet\' }}</div>' +
    '</div>' +
    '<div v-if="rowMenu.item && rowMenu.item.epic_id" class="border-t border-line mt-1 pt-1">' +
    '<button type="button" @click="setRowEpic(null)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('xmark', 15, 'text-sub shrink-0') + 'Remove from epic</button>' +
    '</div></div></template>' +

    // -- Modules (§9.2): multi-select, so it stays open. Archived modules are absent by
    //    construction — the server only sends active ones (§12.2). --
    '<template v-else-if="rowMenu.kind===\'modules\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search modules..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="m in rowModules()" :key="m.id" type="button" @click="toggleRowModule(m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center shrink-0 text-sub">' + wiIcon('grid', 14) + '</span>' +
    '<span class="flex-1 truncate">{{ m.title }}</span>' +
    '<span class="text-[11px] text-faint shrink-0">{{ m.status_label }}</span>' +
    '<span v-if="rowHasModule(m)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!rowModules().length" class="px-2 py-3 text-[13px] text-sub text-center">' +
    '{{ rowQuery ? \'No module matches\' : \'No modules yet\' }}</div>' +
    '</div></div></template>' +

    // -- Dates: the same calendar the create modal uses, with the same ordering bounds --
    '<template v-else-if="rowMenu.kind===\'start_date\' || rowMenu.kind===\'due_date\'">' +
    '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none"' +
    ' :value="rowMenu.kind===\'start_date\' ? rowMenu.item.start_date : rowMenu.item.due_date"' +
    ' :after="rowMenu.kind===\'due_date\' ? rowMenu.item.start_date : null"' +
    ' :before="rowMenu.kind===\'start_date\' ? rowMenu.item.due_date : null"' +
    ' @pick="setRowDate" @clear="clearRowDate" />' +
    '</template>' +

    // -- Action menu (§4.4) --
    '<template v-else-if="rowMenu.kind===\'menu\'">' +
    '<div class="py-1 text-[13px]">' +
    '<button type="button" @click="rowEdit" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' + wiIcon('pen', 15, 'text-faint shrink-0') + 'Edit</button>' +
    '<button type="button" @click="rowCopy" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' + wiIcon('copy', 15, 'text-faint shrink-0') + 'Make a copy</button>' +
    '<button type="button" @click="rowOpenTab" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' + wiIcon('arrow-up-right-from-square', 15, 'text-faint shrink-0') + 'Open in new tab</button>' +
    '<button type="button" @click="rowCopyLink" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' + wiIcon('link', 15, 'text-faint shrink-0') + 'Copy link</button>' +
    '<button type="button" @click="rowArchive" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' + wiIcon('box-archive', 15, 'text-faint shrink-0') + 'Archive</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="rowAskDelete" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger">' + wiIcon('trash', 15, 'shrink-0') + 'Delete</button>' +
    '</div></template>' +

    '</div>' +

    // ===== Delete confirmation (§4.4: delete is permanent) =====
    '<pb-modal :open="deleteConfirm.open" data-tip="Delete work item?" @close="deleteConfirm.open=false">' +
    '<p class="text-[13px] text-sub leading-relaxed">This permanently deletes <span class="font-semibold text-ink">{{ deleteConfirm.item ? deleteConfirm.item.identifier : \'\' }}</span> and everything on it. This cannot be undone.</p>' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="deleteConfirm.open=false">Cancel</button>' +
    '<button type="button" :disabled="deleteConfirm.busy" @click="rowDelete" class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50">{{ deleteConfirm.busy ? \'Deleting…\' : \'Delete\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Create work item modal =====
    // z-index above the detail drawer (z-85): "Create new sub-task" opens this modal from
    // inside the drawer, and at a lower layer it rendered behind the drawer's panel — open,
    // but invisible.
    '<div v-if="open" class="fixed inset-0 z-[100] flex items-start justify-center p-4 sm:pt-20">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, closeCreate)"></div>' +
    '<div class="relative w-full max-w-[720px] bg-white rounded-xl shadow-xl flex flex-col max-h-[86vh]">' +

    // Header
    '<div class="flex items-center gap-2 px-6 pt-5 shrink-0">' +
    // Project context chip — the POC's bordered chip, not a filled one.
    '<span class="inline-flex items-center gap-1.5 h-7 px-2 rounded-md border border-stroke text-[13px] text-ink"><span>{{ project.emoji || \'📁\' }}</span>{{ project.name }}</span>' +
    '' + wiIcon('chevron-right', 13, 'text-faint') + '' +
    '<span class="text-[13px] text-sub">New work item</span>' +
    '<button @click="closeCreate" class="ml-auto h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" data-tip="Close" aria-label="Close">' + wiIcon('xmark', 16) + '</button>' +
    '</div>' +

    // Body. `overflow-visible` (as in the POC) is load-bearing: every chip popover opens
    // upward with `bottom-full`, and a scrolling body would clip the calendar.
    '<div class="px-6 pt-4 overflow-visible">' +
    '<input ref="titleInput" v-model="form.title" type="text" placeholder="Title" class="pb-input h-11 text-[15px]" :class="{\'is-error\': errors.title}" @keyup.enter="save" />' +
    '<p v-if="errors.title" class="text-[12px] text-danger mt-1">{{ errors.title[0] }}</p>' +
    // Plain textarea, not the rich-text editor: creating a work item is a quick capture, and
    // the full editor (with its toolbar, uploads and gallery) belongs on the detail view
    // where the description is actually written. The server treats what is typed here as
    // plain text — see StoreWorkItemRequest.
    '<textarea v-model="form.description" rows="4" placeholder="Click to add description" class="pb-textarea mt-3"></textarea>' +
    '<p v-if="errors.description" class="text-[12px] text-danger mt-1">{{ errors.description[0] }}</p>' +

    // Attribute chips
    '<div class="flex flex-wrap items-center gap-2 mt-4 pb-4">' +

    // State
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'state\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span class="grid place-items-center" v-html="stateIcon(formState)"></span>{{ formState ? formState.name : \'State\' }}</button>' +
    '<div v-if="menu===\'state\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'state\'" class="absolute left-0 bottom-full mb-1 w-48 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<button v-for="s in states" :key="s.id" type="button" @click="form.state_id=s.id; menu=\'\'" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span><span class="flex-1 truncate">{{ s.name }}</span>' +
    '<span v-if="String(form.state_id)===String(s.id)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button></div></div>' +

    // Priority
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'priority\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] hover:bg-hover" :class="formPriority.cls">' +
    '<span class="grid place-items-center" v-html="formPriority.icon"></span>{{ formPriority.label }}</button>' +
    '<div v-if="menu===\'priority\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'priority\'" class="absolute left-0 bottom-full mb-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<button v-for="p in priorities" :key="p.key" type="button" @click="form.priority=p.key; menu=\'\'" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="priorityMeta(p.key).icon"></span><span class="flex-1">{{ p.label }}</span>' +
    '<span v-if="form.priority===p.key" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button></div></div>' +

    // Assignees
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'assignees\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('users-thin', 14) + '' +
    '{{ selectedAssignees.length ? (selectedAssignees.length === 1 ? selectedAssignees[0].name : selectedAssignees.length + \' assignees\') : \'Assignees\' }}</button>' +
    '<div v-if="menu===\'assignees\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'assignees\'" class="absolute left-0 bottom-full mb-1 w-64 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<input v-model="memberQuery" placeholder="Search members..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button v-for="m in filteredMembers" :key="m.id" type="button" @click="toggleAssignee(m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<wi-avatar :person="m" :size="24" /><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<span v-if="isAssigned(m)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!filteredMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div></div></div>' +

    // Estimate (§11). Absent entirely when the feature is off or no system is configured —
    // §4 is explicit that the property must not appear.
    '<div v-if="estimatesEnabled" class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'estimate\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span class="max-w-[120px] truncate">{{ selectedEstimate ? selectedEstimate.label : \'Estimate\' }}</span></button>' +
    '<div v-if="menu===\'estimate\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'estimate\'" class="absolute left-0 bottom-full mb-1 w-52 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button type="button" @click="form.estimate_value_id=\'\'; menu=\'\'" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-sub">' +
    '<span class="flex-1">No Estimate</span>' +
    '<span v-if="!form.estimate_value_id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span></button>' +
    '<button v-for="e in estimates" :key="e.id" type="button" @click="form.estimate_value_id=e.id; menu=\'\'" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="flex-1 truncate">{{ e.label }}</span>' +
    '<span v-if="String(form.estimate_value_id)===String(e.id)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button></div></div></div>' +

    // Epic (§9). Sits between Assignee and Labels, which is the property order §9 asks for.
    // Absent entirely when the feature is off — a control that refuses to work is worse than
    // one that is not there (§4).
    '<div v-if="epicsEnabled" class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'epic\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('rectangles-pair', 14, 'text-sub') + '' +
    '<span class="max-w-[160px] truncate">{{ selectedEpic ? selectedEpic.title : \'Epic\' }}</span></button>' +
    '<div v-if="menu===\'epic\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'epic\'" class="absolute left-0 bottom-full mb-1 w-64 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<input v-model="epicQuery" placeholder="Search epics..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-48 overflow-y-auto">' +
    // §9: clearing the field is a first-class choice, not something you reach by deselecting.
    '<button type="button" @click="form.epic_id=\'\'; menu=\'\'" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-sub">' +
    '<span class="flex-1">No epic</span>' +
    '<span v-if="!form.epic_id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span></button>' +
    '<button v-for="e in filteredEpics" :key="e.id" type="button" @click="form.epic_id=e.id; menu=\'\'" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="text-[11px] text-faint shrink-0">{{ e.identifier }}</span>' +
    '<span class="flex-1 truncate">{{ e.title }}</span>' +
    '<span v-if="String(form.epic_id)===String(e.id)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!filteredEpics.length" class="px-2 py-3 text-[13px] text-sub text-center">' +
    '{{ epicQuery ? \'No epic matches\' : \'No epics in this project yet\' }}</div>' +
    '</div></div></div>' +

    // Labels (§15). Absent when the feature is off — §3 hides the field from Create.
    '<div v-if="labelsEnabled" class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'labels\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('tag-label', 14) + '' +
    '{{ selectedLabels.length ? (selectedLabels.length === 1 ? selectedLabels[0].name : selectedLabels.length + \' labels\') : \'Labels\' }}</button>' +
    '<div v-if="menu===\'labels\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'labels\'" class="absolute left-0 bottom-full mb-1 w-64 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<input v-model="labelQuery" placeholder="Search labels..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button v-for="l in filteredLabels" :key="l.id" type="button" @click="toggleLabel(l)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: l.color}"></span><span class="flex-1 truncate">{{ l.name }}</span>' +
    '<span v-if="isLabelled(l)" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!filteredLabels.length" class="px-2 py-3 text-[13px] text-sub text-center">No labels configured for this project</div>' +
    '</div></div></div>' +

    // Start date — anchored calendar popover (quick options → Custom Date), per the POC
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'start_date\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('calendar', 14, 'text-faint') + '' +
    '{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</button>' +
    '<div v-if="menu===\'start_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'start_date\'" :value="form.start_date" :before="form.due_date" ' +
    '@pick="iso => onDatePick(\'start_date\', iso)" @clear="onDateClear(\'start_date\')" />' +
    '</div>' +

    // Due date
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'due_date\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('calendar', 14, 'text-faint') + '' +
    '{{ form.due_date ? fmtDate(form.due_date) : \'Due date\' }}</button>' +
    '<div v-if="menu===\'due_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'due_date\'" :value="form.due_date" :after="form.start_date" ' +
    '@pick="iso => onDatePick(\'due_date\', iso)" @clear="onDateClear(\'due_date\')" />' +
    '</div>' +

    // Add parent — opens the search panel below (POC: ParentSearchModal)
    '<button type="button" @click.stop="openParent" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '' + wiIcon('plus', 14) + '' +
    '{{ parentSelected ? parentSelected.identifier : \'Add parent\' }}</button>' +

    '</div>' +

    // Chip-field validation messages (these controls have no input of their own)
    '<p v-for="(msgs, field) in errors" :key="field" v-show="field !== \'title\' && field !== \'description\'" class="text-[12px] text-danger mb-2">{{ msgs[0] }}</p>' +
    '</div>' +

    // Footer
    '<div class="flex items-center gap-3 px-6 py-4 border-t border-line shrink-0">' +
    '<button type="button" role="switch" :aria-checked="createMore ? \'true\' : \'false\'" @click="createMore = !createMore" :class="[\'ml-auto shrink-0 relative inline-flex h-5 w-9 items-center rounded-full px-0.5 transition-colors\', createMore ? \'bg-brand\' : \'bg-stroke\']">' +
    '<span :class="[\'h-4 w-4 rounded-full bg-white shadow transition-transform\', createMore ? \'translate-x-4\' : \'translate-x-0\']"></span></button>' +
    '<span class="text-[13px] text-sub">Create more</span>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="closeCreate">Discard</button>' +
    '<button type="button" :disabled="saving || !form.title.trim()" @click="save" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">{{ saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</div>' +

    '</div></div>' +

    // ===== Parent search panel (POC: ParentSearchModal) — sits above the create modal =====
    '<div v-if="parentOpen" class="fixed inset-0 z-[105] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @mousedown="backdropDown" @click="backdropClick($event, closeParent)"></div>' +
    '<div class="relative w-full max-w-[720px] bg-white rounded-xl shadow-xl flex flex-col max-h-[70vh]">' +

    // Search header
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '' + wiIcon('magnifying-glass', 16, 'text-faint shrink-0') + '' +
    '<input ref="parentSearch" v-model="parentQuery" type="text" placeholder="Type to search" class="flex-1 h-8 text-[14px] text-ink placeholder:text-faint outline-none bg-transparent" />' +
    '<span class="h-5 w-px bg-line"></span>' +
    '<span class="text-[13px] text-sub shrink-0">{{ project.identifier }}</span>' +
    '</div>' +

    // Results
    '<div class="p-2 overflow-y-auto">' +
    '<button v-if="parentSelected" type="button" @click="pickParent(null)" class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover">' +
    '' + wiIcon('xmark', 15, 'text-faint shrink-0') + '' +
    '<span class="text-[14px] text-sub">Remove parent</span></button>' +
    '<button v-for="i in parentCandidates" :key="i.id" type="button" @click="pickParent(i)" class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: i.state ? i.state.color : \'#9ca3af\'}"></span>' +
    '<span class="text-[13px] text-sub shrink-0">{{ i.identifier }}</span>' +
    '<span class="text-[14px] text-ink truncate">{{ i.title }}</span>' +
    '<span v-if="parentSelected && parentSelected.id===i.id" class="ml-auto text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!parentCandidates.length" class="px-3 py-6 text-[13px] text-sub text-center">No work items found</div>' +
    '</div>' +

    '</div></div>' +

    '</div>'
};

// Mount as the page only where the page IS the work items screen. Other screens (an Epic's
// Work Items tab) load this file for the component and mount their own root, so an unguarded
// boot here would render the work item list over them.
(function () {
  var root = document.getElementById('settings-root');
  if (root && root.getAttribute('data-screen') === 'work-items') {
    PB.boot('work-items', WorkItemsScreen);
  }
})();
