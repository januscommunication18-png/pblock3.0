/* Wiki — collections (docs/features/wiki.md).
   ------------------------------------------------------------------
   The create-collection modal, plus the collection list on Home. Mounted on its own root so
   the Wiki screen can stay a plain Blade page: only this part needs Vue.

   The sidebar's "Collections" row and the "+" beside its heading live OUTSIDE this root — they
   are rendered by the shared sidebar partial, which every screen includes. They are bound by
   id on mount, the same way the global "New work item" button is: the sidebar does not have to
   know which screen it is on, and this component does not have to be rendered inside it.
   ------------------------------------------------------------------ */
PB.boot('wiki', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      collections: b.collections || [],
      section: b.section || 'home',
      heading: b.heading || 'Wiki',
      headingIcon: b.icon || 'folder',
      empty: b.emptyState || {},
      visibilities: b.visibilities || [],
      endpoints: b.endpoints || {},
      endpointTemplates: b.endpointTemplates || {},
      // New page — a page needs a collection, and this is where one is chosen.
      writableCollections: b.writableCollections || [],
      pageForm: { open: false, title: '', collectionId: '', saving: false, errors: {} },
      open: false,
      saving: false,
      errors: {},
      form: { name: '', description: '', visibility: 'public' }
    };
  },
  computed: {
    visibilityOptions: function () {
      return this.visibilities.map(function (v) { return { value: v.value, label: v.label, desc: v.desc }; });
    },
    canSubmit: function () { return !!String(this.form.name || '').trim() && !this.saving; },

    // Both fields, or the button stays down — asked for, and it is also the only way to keep
    // "which collection?" from being answered by accident.
    canCreatePage: function () {
      return !!String(this.pageForm.title || '').trim() && !!this.pageForm.collectionId;
    }
  },
  mounted: function () {
    var self = this;

    this.bindCreateTriggers();

    /* Arrived from a screen where the modal does not exist — a collection, a page — whose "+"
       is a link to here. Open it, then take `create` back out of the address, or a refresh or a
       shared URL reopens a dialog nobody asked for. */
    try {
      var params = new URLSearchParams(window.location.search);

      if (params.get('create') === '1' || params.get('newpage') === '1') {
        if (params.get('newpage') === '1') { this.showPageForm(); } else { this.show(); }
        params.delete('create');
        params.delete('newpage');
        var query = params.toString();
        window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));
      }
    } catch (e) {}

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.open && !self.saving) self.open = false;
    });
  },
  methods: {
    // `wiIcon` is a global from icons.js; Vue template expressions cannot reach globals,
    // so it is exposed as a method rather than called from the markup directly.
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    /* Every way into "create a collection" from outside this root. Re-run after a sidebar
       repaint, because innerHTML destroys the very node the first pass bound to. */
    bindCreateTriggers: function () {
      var self = this;

      ['wiki-collections-nav', 'wiki-new-collection'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el || el.dataset.pbBound) return;
        el.dataset.pbBound = '1';
        el.addEventListener('click', function (e) { e.preventDefault(); self.show(); });
      });

      var newPage = document.getElementById('wiki-new-page');

      if (newPage && !newPage.dataset.pbBound) {
        newPage.dataset.pbBound = '1';
        newPage.addEventListener('click', function (e) { e.preventDefault(); self.showPageForm(); });
      }
    },

    /**
     * Repaint the sidebar's collection list.
     *
     * The sidebar is server-rendered and sits outside this root, so without this it would keep
     * saying "No collections yet" beside the collection somebody just made — the clearest way
     * to make a working feature look broken. A full page reload would also fix it, and was the
     * first attempt; it throws away the toast and re-fetches an entire screen to add one row.
     */
    syncSidebar: function (list) {
      var host = document.querySelector('[data-wiki-collections]');
      if (!host) return;

      /* The list is passed IN rather than read off `this.collections`. The sidebar shows the
         ACTIVE readable collections; the grid on /wiki/archived shows archived ones, and
         repainting from the grid there would put them back into navigation — the one place
         archiving takes them out of. */
      var rows = list || [];

      if (!rows.length) {
        // An empty list used to leave the previous rows on screen forever. Restore the same
        // button the server renders for @empty, and re-bind it: innerHTML just destroyed the
        // node mounted() bound to.
        host.innerHTML = '<a href="' + (this.endpoints.home || '/wiki') + '?create=1" id="wiki-collections-nav" ' +
          'class="flex items-center gap-2 px-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">' +
          wiIcon('plus', 14) + 'Create your first collection</a>';
        this.bindCreateTriggers();

        return;
      }

      host.innerHTML = rows.map(function (c) {
        // The rows are built by concatenation, so everything interpolated is escaped —
        // including the URL, because one unescaped attribute is how this goes wrong later.
        var esc = function (v) {
          return String(v == null ? '' : v).replace(/[&<>"]/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch];
          });
        };

        return '<a href="' + esc(c.url) + '" ' +
          'class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover">' +
          wiIcon(c.visibility === 'private' ? 'lock' : 'folder', 14, 'text-sub shrink-0') +
          '<span class="truncate">' + esc(c.name) + '</span></a>';
      }).join('');

      this.bindCreateTriggers();
    },

    /* `visibility` is an argument so the Private screen's own empty state can open the modal
       already set to Private — offering "create a private collection" and defaulting to Public
       would be the screen contradicting itself. */
    // ---- new page ---------------------------------------------------------------------------
    showPageForm: function () {
      this.pageForm = {
        open: true, title: '',
        // Pre-chosen when there is only one place it could go: making somebody pick from a list
        // of one is a question with no information in it.
        collectionId: this.writableCollections.length === 1 ? this.writableCollections[0].value : '',
        saving: false, errors: {}
      };
    },

    createPage: async function () {
      if (!this.canCreatePage || this.pageForm.saving) return;
      this.pageForm.saving = true;
      this.pageForm.errors = {};

      try {
        var url = this.$pb.withId(this.endpointTemplates.pageStore, this.pageForm.collectionId);
        var resp = await this.$pb.api(url, { method: 'POST', body: {
          title: String(this.pageForm.title).trim()
        } });
        // Straight into the editor: creating a document and then having to find it in a list is
        // a step nobody wants, and it is the flow Project Pages already use.
        window.location.href = resp.url;
      } catch (e) {
        this.pageForm.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.pageForm.saving = false;
      }
    },

    show: function (visibility) {
      this.errors = {};
      this.form = { name: '', description: '', visibility: visibility || 'public' };
      this.open = true;
    },
    save: async function () {
      if (!this.canSubmit) return;
      this.saving = true;
      this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.collections, { method: 'POST', body: this.form });
        this.collections = resp.collections || [];
        this.open = false;
        this.syncSidebar(resp.sidebar || []);
        this.$pb.toast(resp.message || 'Collection created.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    }
  },

  template:
    '<div>' +

    /* ---- toolbar ----
       The same header the Projects index carries: the sidebar-expand control, a divider, the
       screen's own name, and every action pushed to the right. Titles that sit in the body
       and actions that sit beside them made the Wiki read as a different application from the
       one next door in the rail. */
    '<div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">' +
    // Brings the collapsed sidebar back. Hidden by CSS until there is something to expand, and
    // handled by the sidebar's own delegated listener — same control as the Blade headers.
    '<button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" ' +
    'aria-controls="sidebar" aria-expanded="false" ' +
    'class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" ' +
    'v-html="icon(\'sidebar\', 16)"></button>' +
    '<span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>' +

    '<span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">' +
    '<span class="shrink-0 text-sub" v-html="icon(headingIcon, 16)"></span>' +
    '<span class="truncate">{{ heading }}</span>' +
    '</span>' +

    /* Only where creating is the answer. Shared and Archived carry no create button — you
       cannot create your way into being invited, and there is no such thing as making an
       archived collection. Same rule the empty state below uses, read from the same place. */
    '<div class="ml-auto flex items-center gap-2">' +
    '<button v-if="empty.cta" type="button" @click="show(empty.cta.visibility)" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark ' +
    'text-white text-[13px] font-semibold whitespace-nowrap">' +
    '<span v-html="icon(\'plus\', 14)"></span>New collection</button>' +
    '</div></div>' +

    // ---- the collections ----
    '<div v-if="collections.length" class="px-5 sm:px-8 py-6 grid gap-3">' +
    '<a v-for="c in collections" :key="c.id" :href="c.url" ' +
    'class="flex items-start gap-3 p-4 rounded-lg border border-line hover:bg-hover transition-colors">' +
    '<span class="mt-0.5 shrink-0 text-faint" v-html="icon(c.visibility === \'private\' ? \'lock\' : \'folder\', 16)"></span>' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[14px] font-semibold text-head truncate">{{ c.name }}</span>' +
    '<span v-if="c.visibility === \'private\'" class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">Private</span>' +
    '</div>' +
    '<p v-if="c.description" class="text-[12px] text-sub mt-0.5">{{ c.description }}</p>' +
    '</div></a></div>' +

    // ---- empty state ----
    // One block, four screens: the copy comes from the controller, because "nothing shared with
    // you" and "no collections yet" are different sentences about the same absence.
    '<div v-else class="mx-5 sm:mx-8 mt-6 rounded-xl border border-line px-6 py-12 text-center">' +
    '<span class="inline-grid h-11 w-11 place-items-center rounded-full bg-hover text-faint" ' +
    'v-html="icon(empty.icon || \'folder\', 20)"></span>' +
    '<h2 class="text-[15px] font-semibold text-head mt-3">{{ empty.title }}</h2>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[420px] mx-auto">{{ empty.body }}</p>' +
    '<button v-if="empty.cta" type="button" @click="show(empty.cta.visibility)" ' +
    'class="mt-4 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '{{ empty.cta.label }}</button>' +
    // Nowhere to create your way into being invited, so these offer the way back instead.
    '<a v-else :href="endpoints.home" class="mt-4 inline-block text-[13px] font-semibold text-brand hover:underline">' +
    'Browse collections</a>' +
    '</div>' +

    // ---- new page ----
    // Its own dialog, not a variant of the collection one: they ask different questions, and a
    // modal that changes what it wants depending on how it was opened is two modals sharing a
    // frame.
    '<pb-modal :open="pageForm.open" title="New page" width="max-w-[480px]" ' +
    '@close="pageForm.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Page name</label>' +
    '<input v-model="pageForm.title" maxlength="200" class="pb-input" ' +
    ':class="{\'is-error\': pageForm.errors.title}" placeholder="Escalation process" ' +
    '@keyup.enter="createPage" />' +
    '<p v-if="pageForm.errors.title" class="text-[12px] text-danger mt-1">{{ pageForm.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Collection</label>' +
    '<pb-combo v-if="writableCollections.length" v-model="pageForm.collectionId" ' +
    ':options="writableCollections" placeholder="Choose a collection" />' +
    // Not an empty combo: a picker with nothing in it reads as a bug rather than as an answer.
    '<p v-else class="text-[13px] text-sub">' +
    'There is no collection you can add a page to yet. ' +
    '<button type="button" @click="pageForm.open = false; show()" ' +
    'class="text-brand font-semibold hover:underline">Create one first</button>.</p>' +
    '<p class="text-[12px] text-faint mt-2">You can write the content once the page opens.</p>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="pageForm.open = false">Cancel</button>' +
    '<button type="button" :disabled="!canCreatePage || pageForm.saving" @click="createPage" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ pageForm.saving ? \'Creating…\' : \'Create page\' }}</button>' +
    '</template></pb-modal>' +

    // ---- the modal ----
    '<pb-modal :open="open" title="New collection" width="max-w-[520px]" @close="open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input v-model="form.name" maxlength="120" class="pb-input" :class="{\'is-error\': errors.name}" ' +
    'placeholder="Company handbook" @keyup.enter="save" />' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description <span class="text-faint font-normal">(optional)</span></label>' +
    '<textarea v-model="form.description" rows="2" class="pb-textarea" ' +
    'placeholder="What belongs in this collection?"></textarea>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Who can see it</label>' +
    '<div class="grid gap-2">' +
    '<label v-for="v in visibilityOptions" :key="v.value" ' +
    'class="flex items-start gap-2.5 p-3 rounded-lg border cursor-pointer hover:bg-hover" ' +
    ':class="form.visibility === v.value ? \'border-brand/40 bg-sel/40\' : \'border-stroke\'">' +
    '<input type="radio" :value="v.value" v-model="form.visibility" class="mt-0.5 accent-brand" />' +
    '<span class="min-w-0">' +
    '<span class="block text-[13px] font-semibold text-ink">{{ v.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ v.desc }}</span>' +
    '</span></label>' +
    '</div>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="open = false">Cancel</button>' +
    '<button type="button" :disabled="!canSubmit" @click="save" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Creating…\' : \'Create collection\' }}</button>' +
    '</template></pb-modal>' +
    '</div>'
}, { root: 'wiki-root' });
