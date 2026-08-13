/* Drafts — work items captured before they have a project (docs/features/drafts.md).
   ------------------------------------------------------------------
   Two panes: the author's drafts on the left, the one being written on the right. Hybrid
   Vue-in-Blade (CLAUDE.md §14), mounted by PB.boot into #settings-root, using the shared
   runtime's <pb-modal>, <pb-confirm>, <pb-empty> and toast.

   The description is <wi-editor> (D-D4) — the Quill editor the work item detail writes its
   description in, with the same toolbar, the same paste handling and the same server-side
   sanitizing. Image upload is deliberately absent: the media endpoints are project-scoped
   (`/projects/{project}/work-items/media`) and a draft has no project, so the editor is given
   no endpoints and degrades to text-only formatting, which is what it does without them.

   Every other control is a shared one too. The pickers are <pb-combo>, the searchable combobox
   from the settings runtime — with search on the project and state lists, which can run long,
   and without on priority, where five fixed options make a search field noise. Dates are
   <wi-calendar>, the anchored popover the work item Start/Due chips open. Neither is
   re-implemented here: a draft's Due date behaves like a work item's because it IS the same
   component, bounds and all.

   A draft holds free text and nothing else — no state, assignee, label, parent, cycle, module
   or estimate. All of those are project-scoped, and a draft has no project to scope them
   against (D-D3); they are chosen when the draft is published, in the project that owns them.
   ------------------------------------------------------------------ */
PB.boot('drafts', {
  props: { bootstrap: Object },
  components: { 'wi-calendar': WiCalendar, 'wi-editor': WiEditor, 'pg-editor': PgEditor },
  data: function () {
    var b = this.bootstrap || {};
    return {
      drafts: (b.drafts || []).slice(),
      projects: (b.projects || []).slice(),
      priorities: b.priorities || {},
      urls: b.urls || {},
      editorLicense: b.editorLicense || '',
      mediaMaxBytes: b.mediaMaxBytes || 5 * 1024 * 1024,
      // A field, not a document surface: the tools somebody actually reaches for while writing
      // down a thought they have not filed yet. The full set belongs on Pages, where the thing
      // being edited IS a document.
      editorButtons: 'bold,italic,underline,strikethrough,|,ul,ol,|,link,image,|,eraser',
      // Which anchored date popover is open, if any: 'start_date', 'due_date' or ''.
      menu: '',
      /*
       * What <wi-editor> is SEEDED with, and the key that remounts it — deliberately not
       * `current.description`.
       *
       * Binding the editor to the value it emits is a feedback loop: every keystroke emitted a
       * new description, which changed the prop, which re-rendered the component, which had
       * Vue patch the DOM Quill was in the middle of mutating. Quill's observer then threw
       *
       *     TypeError: Cannot read properties of null (reading 'offset')
       *       normalizedToRange → getRange → update
       *
       * and stopped tracking changes altogether — after which typing still appeared to work
       * (the browser was editing a contenteditable) but nothing reached the model, so paste
       * did nothing and saves wrote the pre-crash text. The same failure WiEditor's own
       * `toolbarHost` note describes, reached from the other direction.
       *
       * So the flow is one-way: this seeds the editor when the OPEN DRAFT CHANGES, and content
       * comes back only through @update:model-value. Typing never touches it, so the editor's
       * props are stable while it is being written in and Vue leaves its subtree alone.
       */
      editorSeed: '',
      editorKey: 0,
      // The draft open in the right pane. `null` is the empty state; a row with `id: null` is
      // a new one that has not reached the server yet — it is created by the first save, not
      // by the click that opened it, so an abandoned "New draft" leaves nothing behind.
      current: null,
      saving: false,
      dirty: false,
      savedAt: '',
      // How long a pause counts as "stopped typing", and how often an autosave is allowed to
      // announce itself. Both taken from the Pages editor, which solved this first.
      autosaveAfter: 1200,
      toastEvery: 15000,
      lastToastAt: 0,
      // <pb-combo> models are strings, so the ids it round-trips are held as strings and
      // converted at the API boundary rather than compared loosely all over the screen.
      publishing: { open: false, projectId: '', stateId: '', busy: false, errors: {} },
      confirm: { open: false }
    };
  },
  computed: {
    /** The project chosen in the publish modal, if the picker has settled on one. */
    publishProject: function () {
      var id = Number(this.publishing.projectId);
      return this.projects.filter(function (p) { return p.id === id; })[0] || null;
    },
    publishStates: function () {
      return this.publishProject ? this.publishProject.states : [];
    },
    /**
     * Which editor mounts.
     *
     * <pg-editor> whenever Jodit is loaded, and <wi-editor> only as the fallback for a
     * checkout without the licensed package — the same choice pages.js makes, on the same
     * test. Both honour one contract (modelValue / update:modelValue / blur / flush), so
     * nothing below cares which one answered.
     */
    useJodit: function () { return pgJoditReady(); },

    canPublish: function () {
      return !!(this.current && this.current.id && this.publishing.projectId && !this.publishing.busy);
    },

    // ---- <pb-combo> option lists --------------------------------------------------------
    // `icon` is raw markup the combo renders in the option's avatar slot, which is how the
    // work item pickers show a state or a priority. Author-built from config and the project's
    // own records, never from user input.

    priorityOptions: function () {
      var self = this;
      return Object.keys(this.priorities).map(function (key) {
        return { value: key, label: self.priorities[key], icon: self.priorityIcon(key) };
      });
    },
    projectOptions: function () {
      return this.projects.map(function (p) {
        return { value: String(p.id), label: p.name, icon: '<span>' + (p.emoji || '📁') + '</span>' };
      });
    },
    stateOptions: function () {
      return this.publishStates.map(function (s) {
        // wiStateIcon keys off the state's `group`, so Backlog looks like a backlog here and
        // in the Work Items list — the glyph survives the state being renamed.
        return { value: String(s.id), label: s.name, icon: wiStateIcon(s) };
      });
    }
  },
  mounted: function () {
    var self = this;
    // Non-reactive on purpose: an edit counter is bookkeeping, and nothing renders from it.
    this._rev = 0;

    // ⌘/Ctrl+S saves now rather than waiting for the debounce — the reflex everyone has in an
    // editor, and it should do what they expect instead of opening the browser's save dialog
    // over an app that autosaves. On the document, not the pane: Quill handles its own
    // keydowns and a listener further out is the one that always hears this.
    this._onKey = function (e) {
      if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 's' && self.current) {
        e.preventDefault();
        self.save(true);
      }
    };
    document.addEventListener('keydown', this._onKey);

    // Leaving mid-edit — clicking a project in the sidebar, closing the tab — happens faster
    // than the autosave debounce. A keepalive request survives the page going away, which a
    // normal fetch does not; without it a sentence typed and immediately abandoned is lost.
    this._onLeave = function () {
      if (!self.dirty || !self.current) return;
      self.flushEditor();

      var draft = self.current;
      if (!String(draft.title || '').trim()) return;

      try {
        fetch(draft.id ? self.url(self.urls.draft, draft.id) : self.urls.store, {
          method: draft.id ? 'PATCH' : 'POST',
          keepalive: true,
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
          },
          body: JSON.stringify(self.payload(draft))
        });
      } catch (e) { /* the page is going away; there is nothing left to report to */ }
    };
    window.addEventListener('pagehide', this._onLeave);
  },
  beforeUnmount: function () {
    clearTimeout(this._saveTimer);
    if (this._onKey) document.removeEventListener('keydown', this._onKey);
    if (this._onLeave) window.removeEventListener('pagehide', this._onLeave);
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    /** '/drafts/__ID__' and '/drafts/__ID__/publish' resolved against a real id. */
    url: function (template, id) { return this.$pb.withId(template, id); },

    priorityLabel: function (key) { return this.priorities[key] || 'None'; },

    /** The work item priority glyph, from the shared vocabulary in work-item-ui.js. */
    priorityIcon: function (key) { return (WI_PRI[key] || WI_PRI.none).icon; },

    /** First line of the description, tags stripped — the list shows a hint, not the body. */
    excerpt: function (draft) {
      var text = String(draft.description || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      return text.length > 90 ? text.slice(0, 90) + '…' : text;
    },

    ago: function (iso) {
      if (!iso) return '';
      var mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + 'm ago';
      if (mins < 1440) return Math.floor(mins / 60) + 'h ago';
      return Math.floor(mins / 1440) + 'd ago';
    },

    // ---- opening and editing ----------------------------------------------------------

    open: function (draft) {
      // Whatever was being typed is committed before the pane changes underneath it — the
      // same reason the editor saves on blur. Not awaited: the request carries its own copy of
      // the outgoing draft, and save() re-points the pane only if it is still showing it.
      if (this.dirty) this.save();
      clearTimeout(this._saveTimer);
      this.current = Object.assign({}, draft);
      this.dirty = false;
      this.savedAt = draft.updated_at || '';
      this.seedEditor(draft.description);
      this._savedPayload = JSON.stringify(this.payload(this.current));
    },

    startNew: function () {
      if (this.dirty) this.save();
      clearTimeout(this._saveTimer);
      this.current = { id: null, title: '', description: '', priority: 'none', start_date: null, due_date: null };
      this.dirty = false;
      this.savedAt = '';
      this.seedEditor('');
      this._savedPayload = null;
      this.$nextTick(function () {
        var field = this.$refs.titleField;
        if (field) field.focus();
      }.bind(this));
    },

    touch: function () {
      this.dirty = true;
      // Bumped on every edit; save() captures it before its request and compares afterwards,
      // so a response never clears the dirty flag for a change it did not carry.
      this._rev = (this._rev || 0) + 1;
      this.queueSave();
    },

    /**
     * Autosave, debounced.
     *
     * An editor that only saves on blur is an editor that loses work — and Quill's blur is
     * worse than an input's: it fires when the editor loses SELECTION, so clicking a plain
     * area of the page leaves the caret where it is and nothing is committed. Waiting for a
     * pause in typing does not depend on where the next click lands. The delay is long enough
     * that a sentence is one request rather than twenty.
     */
    queueSave: function () {
      var self = this;
      clearTimeout(this._saveTimer);
      this._saveTimer = setTimeout(function () { self.save(); }, this.autosaveAfter);
    },

    /**
     * Load a document into the editor — the only thing that ever writes to it.
     *
     * The key bump remounts <wi-editor> rather than relying on its modelValue watcher, which
     * refuses to swap the document while the editor has focus. That guard is right for its
     * usual job (an item refreshed underneath someone mid-sentence) and wrong for this one:
     * switching drafts must replace the document, focused or not.
     */
    seedEditor: function (html) {
      this.editorSeed = html || '';
      this.editorKey++;
      // The remount hands the document straight back in the editor's own normalised form.
      // That echo has to be stored — it is what the next save posts — but calling it an edit
      // would mark every draft dirty and autosave it the moment it was opened.
      //
      // Armed only when there is something to hand back: both editors skip loading an empty
      // document, so an editor seeded blank never echoes, and a guard left armed would eat
      // the first thing typed into a new draft.
      this._echoKey = this.editorSeed ? this.editorKey : null;
    },

    /**
     * The editor's model, guarded against no-op writes.
     *
     * <wi-editor> emits on a 120ms debounce AND on every flush, so an unguarded handler would
     * mark the draft dirty each time save() flushed it — and a save that marks the thing dirty
     * is a save that never settles.
     */
    setDescription: function (html) {
      if (String(html || '') === String(this.current.description || '')) return;

      var echo = this._echoKey === this.editorKey;
      this._echoKey = null;

      this.current.description = html;
      if (!echo) this.touch();
    },

    /**
     * Push the editor's content into the model before reading it.
     *
     * Typing syncs on a debounce, so a save inside that window reads the previous value and
     * concludes nothing changed — the last word typed is silently dropped. Every submit path
     * in work-items.js calls flush() for exactly this; so does this one.
     */
    flushEditor: function () {
      var editor = this.$refs.descriptionEditor;
      if (!editor || !editor.flush) return;

      // ONLY while the editor is the thing being typed into.
      //
      // save() runs here from the title's blur, from the date and priority menus, from a timer
      // and from pagehide — none of which leave focus in the editor, and asking an editor to
      // reconcile against a selection somewhere else is what broke Quill on this screen (see
      // drafts.md). Nothing is lost by skipping: content typed and then left behind was
      // already emitted by the editor's own blur, which fires ahead of its debounce for
      // exactly this reason.
      //
      // Both editors answer "is the caret in me", by different names.
      var focused = typeof editor.isFocused === 'function'
        ? editor.isFocused()
        : !(editor.quill && !editor.quill.hasFocus());

      if (!focused) return;

      editor.flush();
    },

    /** Priority comes from <pb-combo>, which hands back the option's value. */
    setPriority: function (value) {
      this.current.priority = value;
      this.touch();
      this.save();
    },

    // ---- dates: the shared <wi-calendar> popover ----------------------------------------

    fmtDate: function (iso) { return wiFmtDate(iso); },

    toggleMenu: function (name) { this.menu = this.menu === name ? '' : name; },

    /**
     * A picked or cleared date saves immediately.
     *
     * The text fields save on blur, but a calendar cell is clicked and gone — there is no
     * blur to wait for, and leaving the choice unsaved until the next keystroke is how a
     * date silently fails to stick.
     */
    onDatePick: function (field, iso) {
      this.current[field] = iso;
      this.menu = '';
      this.touch();
      this.save();
    },

    onDateClear: function (field) {
      this.current[field] = null;
      this.menu = '';
      this.touch();
      this.save();
    },

    /**
     * Save now.
     *
     * `manual` marks a save the user asked for — the Save as draft button, ⌘/Ctrl+S, leaving
     * the title. Those always confirm, because someone who pressed save wants to be told it
     * worked, and they say why when nothing was saved. An autosave fires after every pause in
     * typing, so it only toasts occasionally; the header indicator carries the rest.
     */
    save: async function (manual) {
      clearTimeout(this._saveTimer);
      // BEFORE the dirty check: the flush is what makes the last word typed count as a change.
      this.flushEditor();

      var draft = this.current;
      if (!draft || this.saving) return;

      // A draft is filed under its title, so there is nothing to store it as yet. Silent for
      // an autosave — somebody mid-sentence in the description does not need telling — but a
      // deliberate save that does nothing has to say why, or it reads as broken.
      if (!String(draft.title || '').trim()) {
        if (manual) this.$pb.toast('Give the draft a title first.', 'error');
        return;
      }

      var body = this.payload(draft);

      // Nothing changed: blur fires on every click away, and a PATCH per click would be a
      // request, a write and a new edited-at for no edit.
      //
      // Compared against what was last PERSISTED rather than trusting the dirty flag. The flag
      // drives the indicator and the autosave, but it is set by heuristics — the echo guard in
      // setDescription deliberately skips it once per remount — and a Save button that says
      // "Draft saved" for an edit it never sent is the one failure this screen must not have.
      if (draft.id && JSON.stringify(body) === this._savedPayload) {
        this.dirty = false;
        if (manual) this.$pb.toast('Draft saved.');
        return;
      }

      this.saving = true;
      // What the request is about to send. Typing during it bumps this, which is how the
      // response below knows whether it is still describing the current state — an autosave
      // fires mid-sentence by design, so this window is the normal case, not a rare race.
      var rev = this._rev;

      try {
        var res = draft.id
          ? await this.$pb.api(this.url(this.urls.draft, draft.id), { method: 'PATCH', body: body })
          : await this.$pb.api(this.urls.store, { method: 'POST', body: body });

        this.merge(res.draft);
        this.savedAt = res.draft.updated_at;
        this._savedPayload = JSON.stringify(body);

        // The id is the one field that MUST come back: without it the next save posts a second
        // draft instead of updating this one. Assigned onto the open object rather than
        // replacing it, so nothing typed while the request was in flight is overwritten — and
        // so the server's sanitized description never lands back in the editor mid-sentence,
        // which <wi-editor>'s modelValue watcher would treat as a document reload.
        if (this.current === draft) {
          draft.id = res.draft.id;
          if (this._rev === rev) {
            draft.updated_at = res.draft.updated_at;
            this.dirty = false;
          }
        }

        // Still dirty after a SUCCESSFUL save means the user typed while it was in flight.
        // save() cleared the pause detector when it started, so without this those edits wait
        // for a blur that may never come. Only on success — re-queueing after a failure would
        // retry a rejected payload every 1.2 seconds for as long as the screen is open.
        if (this.dirty) this.queueSave();

        var now = Date.now();
        if (manual || now - this.lastToastAt > this.toastEvery) {
          this.lastToastAt = now;
          this.$pb.toast('Draft saved.');
        }
      } catch (e) {
        // Left dirty on purpose: the indicator must not claim a save that did not happen, and
        // a failure always says so however often it happens.
        this.$pb.toast(this.$pb.firstError(e, 'Could not save the draft.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    /**
     * What a draft posts. One shape, used by the normal save and by the leave-the-page
     * keepalive — two spellings of the same body is how the two quietly drift apart.
     */
    payload: function (draft) {
      return {
        title: draft.title,
        description: draft.description || '',
        priority: draft.priority || 'none',
        start_date: draft.start_date || '',
        due_date: draft.due_date || ''
      };
    },

    /** Put a saved draft at the head of the list, replacing any earlier copy of it. */
    merge: function (draft) {
      this.drafts = [draft].concat(this.drafts.filter(function (d) { return d.id !== draft.id; }));
    },

    // ---- publishing --------------------------------------------------------------------

    askPublish: async function () {
      // AWAITED, unlike the save when switching drafts. Publishing only flips the flag and
      // allocates the ID — it does not carry the title or description — so anything still
      // unsaved would be published as it was before this session's edits.
      await this.save();
      if (this.dirty) {
        this.$pb.toast('Could not save this draft, so it was not published.', 'error');
        return;
      }

      var first = this.projects[0];
      this.publishing = {
        open: true,
        projectId: first ? String(first.id) : '',
        stateId: this.defaultState(first),
        busy: false,
        errors: {}
      };
    },

    /** A project's default state, so publishing without touching the picker still starts right. */
    defaultState: function (project) {
      if (!project || !project.states.length) return '';
      var preferred = project.states.filter(function (s) { return s.is_default; })[0];
      return String((preferred || project.states[0]).id);
    },

    /**
     * States belong to the project, so changing the project has to re-seed the state — the
     * previous project's state id would be refused by the API, and rightly so.
     */
    onProjectPick: function (value) {
      this.publishing.projectId = value;
      this.publishing.stateId = this.defaultState(this.publishProject);
    },

    publish: async function () {
      if (!this.canPublish) return;
      this.publishing.busy = true;

      try {
        var res = await this.$pb.api(this.url(this.urls.publish, this.current.id), {
          method: 'POST',
          body: {
            project_id: Number(this.publishing.projectId),
            state_id: this.publishing.stateId ? Number(this.publishing.stateId) : ''
          }
        });

        // The draft is gone — it is a work item now, in a project this screen does not show.
        var published = this.current.id;
        this.drafts = this.drafts.filter(function (d) { return d.id !== published; });
        this.current = null;
        this.publishing.open = false;
        this.$pb.toast(res.message);
        window.location.href = res.work_item.url;
      } catch (e) {
        this.publishing.busy = false;
        this.publishing.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not publish this draft.'), 'error');
      }
    },

    // ---- discarding --------------------------------------------------------------------

    discard: async function () {
      var draft = this.current;
      this.confirm.open = false;
      // Before anything else: a queued autosave firing after the delete would recreate what
      // was just discarded.
      clearTimeout(this._saveTimer);
      this.dirty = false;
      if (!draft) return;

      // Never saved: there is nothing on the server to delete.
      if (!draft.id) { this.current = null; return; }

      try {
        await this.$pb.api(this.url(this.urls.draft, draft.id), { method: 'DELETE' });
        this.drafts = this.drafts.filter(function (d) { return d.id !== draft.id; });
        this.current = null;
        this.savedAt = '';
        this.$pb.toast('Draft discarded.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not discard the draft.'), 'error');
      }
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex">' +

    // ============ list ============
    '<section class="w-[320px] shrink-0 border-r border-line flex flex-col min-h-0">' +
    '<div class="h-12 shrink-0 px-4 flex items-center gap-2 border-b border-line">' +
    '<button data-sidebar-expand class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'title="Expand sidebar" v-html="icon(\'sidebar\', 15)"></button>' +
    '<h1 class="text-[14px] font-semibold text-head">Drafts</h1>' +
    '<span class="text-[12px] text-faint">{{ drafts.length }}</span>' +
    '<button class="ml-auto h-7 px-2.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[12px] font-semibold" ' +
    '@click="startNew">New draft</button>' +
    '</div>' +

    '<div v-if="!drafts.length && !current" class="p-4">' +
    '<pb-empty title="No drafts yet" ' +
    'subtitle="A draft is a work item you have thought of but not filed yet — no project, no state, nobody assigned. Write it now, choose where it belongs when you publish it.">' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold" @click="startNew">Write a draft</button>' +
    '</pb-empty></div>' +

    '<div v-else class="flex-1 min-h-0 overflow-y-auto">' +
    '<button v-if="current && !current.id" ' +
    'class="w-full text-left px-4 py-3 border-b border-line bg-sel">' +
    '<div class="text-[13px] font-medium text-brand truncate">{{ current.title || \'Untitled draft\' }}</div>' +
    '<div class="text-[12px] text-faint mt-0.5">Not saved yet</div>' +
    '</button>' +
    '<button v-for="d in drafts" :key="d.id" @click="open(d)" ' +
    'class="w-full text-left px-4 py-3 border-b border-line hover:bg-hover" ' +
    ':class="{\'bg-sel\': current && current.id === d.id}">' +
    '<div class="flex items-center gap-2">' +
    // The glyph is the only thing carrying priority in the list, so it says what it means on
    // hover and focus through the shared tooltip rather than being a silent icon.
    '<span class="shrink-0 grid place-items-center" :data-tip="priorityLabel(d.priority)" ' +
    'v-html="priorityIcon(d.priority)"></span>' +
    '<span class="text-[13px] font-medium text-ink truncate flex-1">{{ d.title }}</span>' +
    '</div>' +
    '<div v-if="excerpt(d)" class="text-[12px] text-sub mt-0.5 truncate">{{ excerpt(d) }}</div>' +
    '<div class="text-[11px] text-faint mt-1">Edited {{ ago(d.updated_at) }}</div>' +
    '</button>' +
    '</div></section>' +

    // ============ editor ============
    '<section class="flex-1 min-w-0 flex flex-col min-h-0">' +
    '<div v-if="!current" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-sm">' +
    '<div class="text-[14px] font-medium text-head">Nothing open</div>' +
    '<p class="text-[13px] text-sub mt-1">Pick a draft on the left, or start a new one.</p>' +
    '</div></div>' +

    '<template v-else>' +
    // The indicator carries the moment-to-moment state so the autosave does not have to toast
    // after every pause in typing — which is what makes an autosaving editor unusable.
    '<div class="h-12 shrink-0 px-6 flex items-center gap-2 border-b border-line">' +
    '<span class="text-[12px] text-faint">' +
    '<template v-if="saving">Saving…</template>' +
    '<template v-else-if="dirty">Unsaved changes</template>' +
    '<template v-else-if="savedAt">Saved {{ ago(savedAt) }}</template>' +
    '</span>' +
    '<button class="ml-auto h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover" ' +
    '@click="confirm.open = true">Discard</button>' +
    // Explicit save, beside the autosave rather than instead of it: the work is already safe,
    // but "did that save?" is a question people should be able to answer by pressing something.
    '<button class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50" ' +
    ':disabled="saving" @click="save(true)">{{ saving ? \'Saving…\' : \'Save as draft\' }}</button>' +
    '<button class="h-8 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!current.id || !projects.length" @click="askPublish">Publish</button>' +
    '</div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto px-6 py-6">' +
    '<div class="max-w-[720px] mx-auto">' +
    // Leaving the title is a deliberate move on to the next field, so it confirms.
    '<input ref="titleField" v-model="current.title" @input="touch" @blur="save(true)" maxlength="255" ' +
    'placeholder="What needs doing?" ' +
    'class="w-full text-[20px] font-semibold text-head placeholder:text-faint border-0 outline-none bg-transparent" />' +

    // The work item detail's description editor. `@blur` is what saves it — <wi-editor> emits
    // that ahead of its own debounce on losing selection, so leaving the editor commits.
    // No :media-upload / :media-gallery: those routes are project-scoped and a draft has no
    // project, so the toolbar's image button is left off rather than pointed at a 404.
    '<div class="mt-4">' +
    // One-way either way: `editorSeed` loads a document, @update:model-value carries content
    // back. Every other prop is a literal, so nothing here changes while someone is typing.
    // See the editorSeed note in data().
    //
    // `document-view="false"` is what makes this the minimum version of the Pages editor: no
    // iframe, no page sheet with margins and page breaks — a description is a field, not a
    // document — and a short toolbar rather than the full document set.
    // The media endpoints are workspace-level (a draft has no project) and are plain strings
    // from the bootstrap, so they never change while the editor is mounted.
    '<pg-editor v-if="useJodit" :key="editorKey" ref="descriptionEditor" :model-value="editorSeed" ' +
    '@update:model-value="setDescription" @blur="save()" min-height="220px" ' +
    ':document-view="false" :buttons="editorButtons" :license="editorLicense" ' +
    ':media-upload="urls.mediaUpload" ' +
    'placeholder="Any detail worth keeping — this is the description the work item starts with." />' +
    // Fallback when the licensed package is not vendored, on the same terms Pages falls back.
    '<wi-editor v-else :key="editorKey" ref="descriptionEditor" :model-value="editorSeed" ' +
    '@update:model-value="setDescription" @blur="save()" min-height="220px" class="block" ' +
    ':media-upload="urls.mediaUpload" :media-gallery="urls.mediaGallery" :media-max-bytes="mediaMaxBytes" ' +
    'placeholder="Any detail worth keeping — this is the description the work item starts with." />' +
    '</div>' +

    '<div class="mt-6 pt-5 border-t border-line grid grid-cols-1 sm:grid-cols-3 gap-4">' +
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Priority</label>' +
    // Five fixed options, so no search field — it would be a box to ignore above a list
    // shorter than itself.
    '<pb-combo :model-value="current.priority" @update:model-value="setPriority" ' +
    ':options="priorityOptions" :searchable="false" placeholder="None" /></div>' +

    // The same anchored popover the work item Start/Due chips open — quick options, then a
    // month grid — with the same EXCLUSIVE bounds, so the pair can never invert and the API's
    // `after:start_date` never has to reject what the picker offered.
    // `pb-input pb-combo-btn` is the combobox trigger's own shape — same height, same outline,
    // label left and glyph right — so the three controls in this row line up instead of the
    // dates sitting a few pixels short of the priority beside them.
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Start date</label>' +
    '<div class="relative">' +
    '<button type="button" class="pb-input pb-combo-btn" @click.stop="toggleMenu(\'start_date\')">' +
    '<span class="truncate" :class="current.start_date ? \'text-ink\' : \'text-faint\'">' +
    '{{ current.start_date ? fmtDate(current.start_date) : \'Start date\' }}</span>' +
    '' + wiIcon('calendar', 15, 'text-faint shrink-0') + '</button>' +
    '<div v-if="menu===\'start_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'start_date\'" :value="current.start_date" :before="current.due_date" ' +
    '@pick="iso => onDatePick(\'start_date\', iso)" @clear="onDateClear(\'start_date\')" />' +
    '</div></div>' +

    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Due date</label>' +
    '<div class="relative">' +
    '<button type="button" class="pb-input pb-combo-btn" @click.stop="toggleMenu(\'due_date\')">' +
    '<span class="truncate" :class="current.due_date ? \'text-ink\' : \'text-faint\'">' +
    '{{ current.due_date ? fmtDate(current.due_date) : \'Due date\' }}</span>' +
    '' + wiIcon('calendar', 15, 'text-faint shrink-0') + '</button>' +
    '<div v-if="menu===\'due_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'due_date\'" :value="current.due_date" :after="current.start_date" ' +
    '@pick="iso => onDatePick(\'due_date\', iso)" @clear="onDateClear(\'due_date\')" />' +
    '</div></div>' +
    '</div>' +

    '<p class="text-[12px] text-faint mt-5">' +
    'State, assignee and labels belong to a project, so they are chosen when you publish this draft.' +
    '</p>' +
    '</div></div>' +
    '</template>' +
    '</section>' +

    // ============ publish ============
    '<pb-modal :open="publishing.open" title="Publish draft" @close="publishing.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Project</label>' +
    // Searchable: a workspace can hold fifty projects, and the one you want is the one you
    // can name. Changing it re-seeds the state below, which belongs to the project.
    '<pb-combo :model-value="publishing.projectId" @update:model-value="onProjectPick" ' +
    ':options="projectOptions" :invalid="!!publishing.errors.project_id" placeholder="Choose a project" />' +
    '<p v-if="publishing.errors.project_id" class="text-[12px] text-danger mt-1">{{ publishing.errors.project_id[0] }}</p>' +

    '<div v-if="stateOptions.length" class="mt-4">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Starting state</label>' +
    '<pb-combo v-model="publishing.stateId" :options="stateOptions" ' +
    ':invalid="!!publishing.errors.state_id" placeholder="Choose a state" />' +
    '<p v-if="publishing.errors.state_id" class="text-[12px] text-danger mt-1">{{ publishing.errors.state_id[0] }}</p>' +
    '</div>' +

    '<p class="text-[12px] text-faint mt-3">' +
    'The draft becomes a work item in that project and gets its ID. It leaves this list.' +
    '</p>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="publishing.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!canPublish" @click="publish">{{ publishing.busy ? \'Publishing…\' : \'Publish\' }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="confirm.open" title="Discard draft?" ' +
    'message="The draft is deleted. Drafts are not archived — there is nothing to restore afterwards." ' +
    'confirm-label="Discard draft" @close="confirm.open = false" @confirm="discard" />' +

    '</div>'
});
