/* Project Workspace › Pages (Pages §7-§10, §12).
   ------------------------------------------------------------------
   One Vue root for both the listing and a single page's editor, the same way Cycles, Modules
   and Epics handle theirs: `pageId` in the bootstrap decides which renders, so a page URL is
   real and linkable rather than a list with a document floating over it.

   The editor is <wi-editor> — the one work item descriptions already use. §10's formatting
   list is exactly what its toolbar offers, and reusing it means paste handling, image upload
   and server-side sanitizing come along rather than being rebuilt slightly differently.
   ------------------------------------------------------------------ */
PB.boot('project-pages', {
  props: { bootstrap: Object },
  components: { 'wi-editor': WiEditor, 'pg-editor': PgEditor },
  data: function () {
    var b = this.bootstrap || {};
    return {
      project: b.project || {},
      pages: (b.pages || []).slice(),
      pageId: b.pageId || null,
      // The body of the page being edited. Only this one travels — a listing of fifty
      // documents has no use for fifty documents' worth of HTML.
      content: b.content || '',
      canCreate: !!b.canCreate,
      canDelete: !!b.canDelete,
      titleMax: b.titleMax || 200,
      statuses: Array.isArray(b.statuses) ? b.statuses : [],
      // How long a pause counts as "stopped typing", and how often an autosave is allowed to
      // announce itself.
      autosaveAfter: 1200,
      toastEvery: 15000,
      mentionsComingSoon: b.mentionsComingSoon !== false,
      editorLicense: b.editorLicense || '',
      endpoints: b.endpoints || {},
      query: '',
      showArchived: false,
      // Autosave state. `dirty` is what the indicator reads, so a save that failed still
      // shows as unsaved rather than quietly claiming success.
      saving: false,
      dirty: false,
      savedAt: '',
      // When the last "Page saved" toast went out. Autosave fires after every pause in
      // typing, so an untimed toast would be a stream of them down the corner of the screen —
      // see save().
      lastToastAt: 0,
      creating: { open: false, title: '', busy: false, errors: {} },
      menu: { open: false, page: null, style: {} },
      // §8/§9: title and status are edited together, in one dialog reached from the header —
      // so the document itself holds only the document.
      details: { open: false, title: '', status: 'draft', saving: false, errors: {} },
      versions: (b.versions || []).slice(),
      history: { open: false, busy: false, restoring: null },
      confirm: { open: false, page: null }
    };
  },
  computed: {
    page: function () {
      var self = this;
      return this.pageId ? this.pages.filter(function (p) { return p.id === self.pageId; })[0] || null : null;
    },
    /** §9: the listing, searchable by title; archived pages stay out unless asked for. */
    visible: function () {
      var q = (this.query || '').trim().toLowerCase();
      var archived = this.showArchived;
      return this.pages.filter(function (p) {
        if (p.archived !== archived) return false;
        return !q || (p.title || '').toLowerCase().indexOf(q) > -1;
      });
    },
    archivedCount: function () {
      return this.pages.filter(function (p) { return p.archived; }).length;
    },
    canEditThisPage: function () { return this.canCreate && !!this.page && !this.page.archived; },
    /**
     * Jodit is the Pages editor; <wi-editor> is the fallback while the licensed package is
     * not vendored. Both honour the same contract, so only the tag changes — and the toolbar
     * host below is Quill's alone, since Jodit brings its own.
     */
    useJodit: function () { return pgJoditReady(); }
  },
  mounted: function () {
    // Ctrl/Cmd+S saves now rather than waiting for the debounce — the reflex everyone has in
    // a document editor, and it should do what they expect instead of opening the browser's
    // save dialog over an app that autosaves.
    var self = this;
    this._onKey = function (e) {
      if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 's' && self.page) {
        e.preventDefault();
        self.save(true);
      }
    };
    document.addEventListener('keydown', this._onKey);

    // Navigating away mid-edit — clicking the breadcrumb, closing the tab — happens faster
    // than the autosave debounce. A keepalive request survives the page going away, which a
    // normal fetch does not; without it, a word typed and immediately abandoned is lost.
    this._onLeave = function () {
      if (!self.dirty || !self.page || !self.canEditThisPage) return;
      if (self.$refs.editor && self.$refs.editor.flush) self.$refs.editor.flush();

      try {
        fetch(self.$pb.withId(self.endpoints.update, self.page.id), {
          method: 'PATCH',
          keepalive: true,
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name=\"csrf-token\"]') || {}).content || ''
          },
          body: JSON.stringify({ content: self.content })
        });
      } catch (e) { /* the page is going away; there is nothing left to report to */ }
    };
    window.addEventListener('pagehide', this._onLeave);
  },
  beforeUnmount: function () {
    if (this._onKey) document.removeEventListener('keydown', this._onKey);
    if (this._onLeave) window.removeEventListener('pagehide', this._onLeave);
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },
    pageUrl: function (p) { return this.$pb.withId(this.endpoints.page, p.id); },
    open: function (p) { window.location.href = this.pageUrl(p); },
    fmtWhen: function (iso) {
      if (!iso) return '';
      var d = new Date(iso);
      return wiFmtDate(wiISO(d)) + ' · ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },

    statusMeta: function (key) {
      var found = this.statuses.filter(function (s) { return s.key === key; })[0];
      return found || { key: key, label: key, color: '#9ca3af' };
    },
    openDetails: function () {
      if (!this.page) return;
      this.details = {
        open: true,
        title: this.page.title,
        status: this.page.status,
        saving: false,
        errors: {}
      };
    },
    /**
     * Save the title and status together (§8/§9).
     *
     * Its own request, separate from the body's autosave: renaming a page or publishing it is
     * a decision, and it should not depend on whether the document happens to be dirty — nor
     * should it carry a copy of the body the dialog never had in hand.
     */
    saveDetails: async function () {
      var title = (this.details.title || '').trim();
      if (!title || this.details.saving) return;
      this.details.saving = true; this.details.errors = {};

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.update, this.page.id), {
          method: 'PATCH', body: { title: title, status: this.details.status }
        });
        this.mergePage(resp.page);
        this.details.open = false;
        this.$pb.toast('Page updated.');
      } catch (e) {
        this.details.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.details.saving = false;
    },
    // ---------- §8: create ----------
    openCreate: function () { this.creating = { open: true, title: '', busy: false, errors: {} }; },
    create: async function () {
      var title = (this.creating.title || '').trim();
      if (!title || this.creating.busy) return;
      this.creating.busy = true; this.creating.errors = {};
      try {
        // §8/AC-06: no project is sent — the page belongs to the project in the URL.
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: { title: title } });
        // Straight into the editor: creating a document and then having to find it in a list
        // is a step nobody wants.
        window.location.href = this.pageUrl(resp.page);
      } catch (e) {
        this.creating.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.creating.busy = false;
      }
    },

    // ---------- §12: edit ----------
    onEdit: function () { this.dirty = true; this.queueSave(); },
    /**
     * Autosave, debounced.
     *
     * A document editor that only saves on a button is a document editor that loses work. The
     * delay is long enough that typing a sentence is one request rather than twenty.
     */
    queueSave: function () {
      var self = this;
      clearTimeout(this._saveTimer);
      this._saveTimer = setTimeout(function () { self.save(); }, this.autosaveAfter);
    },
    /**
     * Save now.
     *
     * `manual` marks a save the user asked for — ⌘/Ctrl+S, or leaving the title field. Those
     * always confirm with a toast, because someone who pressed save wants to be told it
     * worked. An autosave only toasts when the last one was a while ago: it fires after every
     * pause in typing, and confirming each of those would put a stream of notifications down
     * the corner of the screen while someone is trying to write. The header indicator carries
     * the moment-to-moment state instead — that is what it is for.
     */
    save: async function (manual) {
      if (!this.page || this.saving || !this.canEditThisPage) return;
      clearTimeout(this._saveTimer);

      // Push the editor's current content into the model BEFORE reading it.
      //
      // <wi-editor> syncs on a 120ms debounce, so a save inside that window reads the
      // previous value — and with the dirty check below, a save within 120ms of the first
      // keystroke saw `dirty` still false and returned without doing anything. Typing a word
      // and immediately hitting ⌘S or clicking away lost it. flush() is exactly what
      // work-items.js calls on every submit path, for this reason.
      if (this.$refs.editor && this.$refs.editor.flush) this.$refs.editor.flush();

      // Nothing changed: blur fires on every click away, and a PATCH per click would be a
      // request, a write and a new updated-at for no edit. A deliberate save still confirms,
      // because pressing ⌘S and getting silence reads as "did that work?".
      if (!this.dirty) {
        if (manual) this.$pb.toast('Page saved.');

        return;
      }

      this.saving = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.update, this.page.id), {
          // Content only. The title and status are the dialog's to change, and sending a
          // stale copy of them here would undo an edit made while the body was dirty.
          method: 'PATCH', body: { content: this.content }
        });
        this.mergePage(resp.page);
        this.dirty = false;
        this.savedAt = resp.page.updated_at;

        var now = Date.now();
        if (manual || now - this.lastToastAt > this.toastEvery) {
          this.lastToastAt = now;
          this.$pb.toast('Page saved.');
        }
      } catch (e) {
        // Left dirty on purpose: the indicator must not claim a save that did not happen,
        // and a failure always says so however often it happens.
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    },
    mergePage: function (page) {
      if (!page) return;
      var at = -1;
      for (var i = 0; i < this.pages.length; i++) {
        if (this.pages[i].id === page.id) { at = i; break; }
      }
      if (at > -1) this.pages.splice(at, 1, page);
      else this.pages.unshift(page);
    },

    // ---------- version history ----------
    openHistory: async function () {
      this.closeMenu();
      this.history = { open: true, busy: true, restoring: null };
      try {
        // Refetched rather than trusted: the list shipped with the page is as old as the page
        // load, and someone else may have edited since.
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.versions, this.page.id));
        this.versions = resp.versions || [];
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.history.busy = false;
    },
    /** The newest version is the page as it stands, so it is labelled rather than offered. */
    isCurrent: function (v) { return this.versions.length > 0 && this.versions[0].id === v.id; },
    restoreVersion: async function (v) {
      if (this.history.restoring) return;
      this.history.restoring = v.id;
      try {
        var url = this.$pb.withId(this.endpoints.restoreVersion, this.page.id)
          .replace('__VERSION__', v.id);
        var resp = await this.$pb.api(url, { method: 'POST' });

        this.mergePage(resp.page);
        // The body comes back with the restore — the editor is showing the old one.
        this.content = resp.content || '';
        this.versions = resp.versions || this.versions;
        this.dirty = false;
        this.history.open = false;
        this.$pb.toast(resp.message || 'Version restored.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.history.restoring = null;
    },

    // ---------- ⋯ menu, archive, delete ----------
    openMenu: function (p, btn) {
      var r = btn.getBoundingClientRect();
      this.menu = {
        open: true, page: p,
        style: { position: 'fixed', width: '180px', zIndex: 120, top: (r.bottom + 6) + 'px', left: Math.max(8, r.right - 180) + 'px' }
      };
    },
    closeMenu: function () { this.menu = { open: false, page: null, style: {} }; },
    toggleArchive: async function (p) {
      this.closeMenu();
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.archive, p.id), { method: 'POST' });
        this.mergePage(resp.page);
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    askDelete: function (p) { this.closeMenu(); this.confirm = { open: true, page: p }; },
    remove: async function () {
      var p = this.confirm.page;
      this.confirm = { open: false, page: null };
      if (!p) return;
      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.destroy, p.id), { method: 'DELETE' });
        if (this.pageId === p.id) { window.location.href = this.endpoints.list; return; }
        this.pages = this.pages.filter(function (x) { return x.id !== p.id; });
        this.$pb.toast('Page deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ============ One page (§8/§10/§12) ============
    '<template v-if="page">' +

    // Breadcrumb header. A document says where it lives, not just how to get back.
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.list" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink shrink-0">' +
    '<span v-html="icon(\'file-lines\', 14)"></span>Pages</a>' +
    '<span class="text-faint shrink-0" v-html="icon(\'chevron-right\', 12)"></span>' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ page.title }}</span>' +
    // §8/§9: rename and publish from here — the document below holds only the document.
    '<button v-if="canEditThisPage" type="button" @click="openDetails" ' +
    'data-tip="Edit title and status" aria-label="Edit title and status" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover shrink-0" ' +
    'v-html="icon(\'pen\', 14)"></button>' +
    '<span v-if="page.archived" class="text-[11px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5 shrink-0">Archived</span>' +

    // §9's status, as a plain chip. Changing it lives in the dialog beside the title — two
    // controls for one field is one too many, and the dialog is where the rename already is.
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md text-[12px] font-medium shrink-0" ' +
    ':style="{color: statusMeta(page.status).color, background: statusMeta(page.status).color + \'1a\'}">' +
    '<span class="h-1.5 w-1.5 rounded-full" :style="{background: statusMeta(page.status).color}"></span>' +
    '{{ statusMeta(page.status).label }}</span>' +

    '<div class="ml-auto flex items-center gap-2">' +
    // The save state, stated honestly — a failed save keeps saying "Unsaved changes".
    '<span class="text-[12px] text-faint whitespace-nowrap">' +
    '<template v-if="saving">Saving…</template>' +
    '<template v-else-if="dirty">Unsaved changes</template>' +
    '<template v-else-if="savedAt">Saved {{ fmtWhen(savedAt) }}</template>' +
    '</span>' +
    '<span v-if="page.updated_by" :style="{ background: $pb.avatarColor(page.updated_by) }" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" ' +
    ':data-tip="\'Last updated by \' + page.updated_by.name">' +
    '<img v-if="page.updated_by.avatar_url" :src="page.updated_by.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ page.updated_by.initial }}</span></span>' +
    // AC-10: mentions are Phase 2 and say so. In the header, not the toolbar — the toolbar
    // belongs to Quill.
    '<span v-if="mentionsComingSoon" class="hidden sm:inline-flex items-center gap-1.5 text-[12px] text-faint whitespace-nowrap" ' +
    'data-tip="Mentioning people and work items arrives in a later phase">' +
    '@ Mention <span class="text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(page, $event.currentTarget)" data-tip="More" aria-label="More" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" v-html="icon(\'ellipsis\', 16)"></button>' +
    '</div></div>' +

    // §10's toolbar, as one persistent bar across the screen rather than a strip glued to a
    // field. Quill BUILDS it and <wi-editor> moves it in here — so this element stays empty
    // in the template and Vue never patches anything inside it. Rendering the buttons here
    // instead put Vue and Quill in a fight over the same nodes; see wi-editor's toolbarHost.
    // The toolbar's home, for whichever editor is mounted: both BUILD their toolbar and hand
    // the finished element over, so this stays empty in the template and Vue never patches
    // anything inside it.
    '<div id="pb-page-toolbar" class="pb-page-toolbar shrink-0" v-show="canEditThisPage"></div>' +

    '<div class="pb-page flex-1 min-h-0 overflow-y-auto">' +
    // Only when there is something to say. The title used to live here; leaving the column
    // behind after it moved to the header left an empty block padding the editor down.
    '<div v-if="page.archived" class="pb-page-doc px-6 pt-6">' +
    '<div class="flex items-start gap-2.5 rounded-md bg-amber-50 px-3 py-2.5">' +
    '<span class="text-amber-700 shrink-0 mt-0.5" v-html="icon(\'circle-info\', 15)"></span>' +
    '<p class="text-[12px] text-amber-900">This page is archived and read-only. Restore it from the ⋯ menu to edit it again.</p></div>' +
    '</div>' +

    '<div v-if="!useJodit" class="pb-page-doc px-6 pt-4">' +
    '<wi-editor ref="editor" v-model="content" :disabled="!canEditThisPage" min-height="40vh" ' +
    'toolbar-host="#pb-page-toolbar" placeholder="Write the documentation for this project…" ' +
    ':media-upload="endpoints.mediaUpload" @update:model-value="onEdit" @blur="save(true)" />' +
    '</div>' +

    // Jodit sits OUTSIDE the centred column so its toolbar can span the screen and pin to the
    // top of the scroller. Only the text inside it is held to the document measure — see
    // .pb-page-jodit in pages.css.
    '<pg-editor v-if="useJodit" class="pb-page-jodit" ref="editor" v-model="content" ' +
    'toolbar-host="#pb-page-toolbar" ' +
    ':disabled="!canEditThisPage" min-height="420px" placeholder="Write the documentation for this project…" ' +
    ':license="editorLicense" :media-upload="endpoints.mediaUpload" @update:model-value="onEdit" @blur="save(true)" />' +
    '</div>' +
    '</template>' +

    // ============ Listing (§7/§9) ============
    '<template v-else>' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<span class="inline-flex items-center gap-2 text-[13px] font-medium text-ink">' +
    '<span v-html="icon(\'file-lines\', 15, \'text-sub\')"></span>Pages' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ visible.length }}</span></span>' +
    '<div class="ml-auto flex items-center gap-1.5">' +
    '<input v-model="query" placeholder="Search pages…" ' +
    'class="h-8 w-52 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<button v-if="archivedCount" type="button" @click="showArchived = !showArchived" ' +
    'class="h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">' +
    '{{ showArchived ? \'Active\' : \'Archived\' }}</button>' +
    '<button v-if="canCreate" type="button" @click="openCreate" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap">' +
    '<span v-html="icon(\'plus\', 14)"></span>Create Page</button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto">' +

    // §7's empty state
    '<div v-if="!visible.length" class="max-w-[560px] mx-auto px-6 py-16 text-center">' +
    '<span class="h-12 w-12 rounded-full bg-hover grid place-items-center text-sub mx-auto" v-html="icon(\'file-lines\', 22)"></span>' +
    '<h3 class="text-[15px] font-semibold text-head mt-4">' +
    '{{ showArchived ? \'No archived pages\' : (query ? \'No page matches\' : \'Create your first Page\') }}</h3>' +
    '<p v-if="!showArchived && !query" class="text-[13px] text-sub mt-1">Create project documentation, requirements, ' +
    'meeting notes, specifications, and other shared project knowledge.</p>' +
    '<button v-if="canCreate && !showArchived && !query" type="button" @click="openCreate" ' +
    'class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Create Page</button>' +
    '<p v-else-if="!canCreate" class="text-[12px] text-faint mt-4">You do not have permission to create pages in this project.</p>' +
    '</div>' +

    // §9's rows
    '<div v-else class="divide-y divide-line">' +
    '<div v-for="p in visible" :key="p.id" class="group flex items-center gap-3 px-6 h-[56px] hover:bg-[#f8f9fa]">' +
    '<span class="grid place-items-center shrink-0 text-sub" v-html="icon(\'file-lines\', 15)"></span>' +
    '<a :href="pageUrl(p)" class="text-[14px] text-ink truncate flex-1 hover:text-brand">{{ p.title }}</a>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md text-[11px] font-medium whitespace-nowrap shrink-0" ' +
    ':style="{color: statusMeta(p.status).color, background: statusMeta(p.status).color + \'1a\'}">' +
    '{{ statusMeta(p.status).label }}</span>' +
    '<span v-if="p.parent" class="hidden lg:inline text-[11px] text-faint truncate max-w-[160px]" ' +
    ':data-tip="\'In \' + p.parent.title">{{ p.parent.title }}</span>' +
    '<span class="hidden md:inline text-[12px] text-sub whitespace-nowrap">{{ fmtWhen(p.updated_at) }}</span>' +
    '<span v-if="p.updated_by" :style="{ background: $pb.avatarColor(p.updated_by) }" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" ' +
    ':data-tip="\'Last updated by \' + p.updated_by.name">' +
    '<img v-if="p.updated_by.avatar_url" :src="p.updated_by.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ p.updated_by.initial }}</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(p, $event.currentTarget)" data-tip="More" aria-label="More" ' +
    'class="h-7 w-7 grid place-items-center rounded hover:bg-line text-faint opacity-0 group-hover:opacity-100 shrink-0" v-html="icon(\'ellipsis\', 15)"></button>' +
    '</div></div>' +

    '</div></template>' +

    // ============ ⋯ menu ============
    '<div v-if="menu.open" class="fixed inset-0 z-[110]" @click="closeMenu"></div>' +
    '<div v-if="menu.open" :style="menu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5 py-1 text-[13px]">' +
    '<a :href="pageUrl(menu.page)" class="w-full flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'arrow-up-right-from-square\', 15, \'text-faint\')"></span>Open page</a>' +
    '<button v-if="pageId" type="button" @click="openHistory" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'clock\', 15, \'text-faint\')"></span>Version history</button>' +
    '<button type="button" @click="toggleArchive(menu.page)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'box-archive\', 15, \'text-faint\')"></span>{{ menu.page.archived ? \'Restore page\' : \'Archive page\' }}</button>' +
    '<template v-if="canDelete"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="askDelete(menu.page)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger">' +
    '<span v-html="icon(\'trash\', 15)"></span>Delete page</button></template>' +
    '</div>' +

    // ============ Version history ============
    '<pb-modal :open="history.open" width="max-w-[560px]" title="Version history" @close="history.open = false">' +
    '<p v-if="history.busy" class="text-[13px] text-sub py-6 text-center">Loading…</p>' +
    '<p v-else-if="!versions.length" class="text-[13px] text-sub py-6 text-center">No versions yet.</p>' +
    '<div v-else class="divide-y divide-line -my-1">' +
    '<div v-for="v in versions" :key="v.id" class="flex items-center gap-3 py-2.5">' +
    '<span class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" ' +
    ':style="{ background: $pb.avatarColor(v.edited_by) }">' +
    '<img v-if="v.edited_by && v.edited_by.avatar_url" :src="v.edited_by.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ v.edited_by ? v.edited_by.initial : \'?\' }}</span></span>' +
    '<div class="min-w-0 flex-1">' +
    '<p class="text-[13px] text-ink truncate">{{ v.title }}</p>' +
    '<p class="text-[11px] text-faint">{{ v.edited_by ? v.edited_by.name : \'Someone\' }} · {{ fmtWhen(v.at) }}' +
    '<span v-if="v.status === \'published\'"> · Published</span></p></div>' +
    '<span v-if="isCurrent(v)" class="text-[11px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5 shrink-0">Current</span>' +
    '<button v-else-if="canEditThisPage" type="button" @click="restoreVersion(v)" :disabled="!!history.restoring" ' +
    'class="h-7 px-2.5 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover disabled:opacity-50 shrink-0">' +
    '{{ history.restoring === v.id ? \'Restoring…\' : \'Restore\' }}</button>' +
    '</div></div>' +
    '<p class="text-[12px] text-faint mt-4">Edits made close together are kept as one version, so a ' +
    'sitting is one entry rather than dozens. Publishing, and edits by someone else, always start ' +
    'a new one. Restoring loses nothing — the current version stays in the history.</p>' +
    '</pb-modal>' +

    // ============ §8/§9: title and status ============
    '<pb-modal :open="details.open" title="Page details" @close="details.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title</label>' +
    '<input v-model="details.title" :maxlength="titleMax" placeholder="Untitled" @keyup.enter="saveDetails" ' +
    'class="pb-input w-full" :class="{\'is-error\': details.errors.title}" />' +
    '<p v-if="details.errors.title" class="text-[12px] text-danger mt-1">{{ details.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Status</label>' +
    '<div class="space-y-2">' +
    '<button v-for="st in statuses" :key="st.key" type="button" @click="details.status = st.key" ' +
    'class="w-full text-left rounded-lg border px-4 py-2.5 flex items-center gap-2.5" ' +
    ':class="details.status === st.key ? \'border-brand bg-sel/40\' : \'border-line hover:bg-hover\'">' +
    '<span class="h-2 w-2 rounded-full shrink-0" :style="{background: st.color}"></span>' +
    '<span class="text-[13px] text-ink flex-1">{{ st.label }}</span>' +
    '<span v-if="details.status === st.key" class="text-brand shrink-0" v-html="icon(\'check\', 15)"></span>' +
    '</button></div>' +
    '<p class="text-[12px] text-faint mt-2">A draft is still being written. Publishing says it is ready to read.</p>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="details.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!details.title.trim() || details.saving" @click="saveDetails">{{ details.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ============ §8: create ============
    '<pb-modal :open="creating.open" title="Create Page" @close="creating.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title</label>' +
    '<input v-model="creating.title" :maxlength="titleMax" placeholder="Project Requirements" @keyup.enter="create" ' +
    'class="pb-input w-full" :class="{\'is-error\': creating.errors.title}" />' +
    '<p v-if="creating.errors.title" class="text-[12px] text-danger mt-1">{{ creating.errors.title[0] }}</p>' +
    '<p class="text-[12px] text-faint mt-2">You can write the content once the page opens.</p>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="creating.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!creating.title.trim() || creating.busy" @click="create">{{ creating.busy ? \'Creating…\' : \'Create Page\' }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="confirm.open" title="Delete page?" ' +
    'message="The page and its content will be removed from this project." ' +
    'confirm-label="Delete page" @close="confirm.open = false" @confirm="remove" />' +

    '</div>'
});
