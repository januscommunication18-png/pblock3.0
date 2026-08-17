/* Wiki — one collection (docs/features/wiki.md).
   ------------------------------------------------------------------
   Its name and visibility, its description, who can open it, and its pages.

   Access is shown ONLY for a private collection. On a public one everybody in the workspace
   can already read it, so a row of avatars would answer a question nobody asked and imply the
   list was the limit.
   ------------------------------------------------------------------ */
/* One row, and its children. Recursive by name — a page can nest to any depth, so the
   component has to be able to render itself. */
var WikiPageRow = {
  name: 'page-row',
  props: { page: Object, depth: Number },
  inject: ['wiki'],
  computed: {
    kids: function () { return this.wiki.tree(this.page.id); },
    collapsed: function () { return this.wiki.isCollapsed(this.page.id); }
  },
  template:
    '<li :data-page="page.id">' +
    '<div class="pb-wiki-row hover:bg-hover/60 transition-colors">' +

    '<span class="flex items-center gap-1.5 min-w-0" :style="{ paddingLeft: (depth * 18) + \'px\' }">' +
    '<span v-if="wiki.canEdit" data-drag title="Drag to reorder or nest" ' +
    'class="shrink-0 cursor-grab text-faint hover:text-sub" v-html="wiki.icon(\'grip-vertical\', 14)"></span>' +
    '<span v-else class="w-[14px] shrink-0"></span>' +

    // The disclosure, only where there is something to disclose. Rows without children keep
    // the same indent from an empty spacer, or every title would sit at its own margin.
    '<button v-if="kids.length" type="button" @click.stop="wiki.toggleCollapsed(page.id)" ' +
    ':aria-expanded="String(!collapsed)" :aria-label="(collapsed ? \'Expand \' : \'Collapse \') + page.title" ' +
    'class="h-5 w-5 grid place-items-center rounded text-faint hover:bg-hover hover:text-sub shrink-0" ' +
    'v-html="wiki.icon(collapsed ? \'chevron-right\' : \'chevron-down\', 13)"></button>' +
    '<span v-else class="w-5 shrink-0"></span>' +

    '<span class="shrink-0 text-faint" v-html="wiki.icon(\'file-lines\', 15)"></span>' +
    '<a :href="page.url" class="truncate text-[13px] font-medium text-ink hover:text-brand">{{ page.title }}</a>' +
    '</span>' +

    '<span>' +
    '<span v-if="page.owner" class="inline-flex items-center gap-2 min-w-0">' +
    '<span class="h-6 w-6 rounded-full grid place-items-center text-[10px] font-semibold text-white bg-cover bg-center shrink-0" ' +
    ':style="page.owner.avatar_url ? { backgroundImage: \'url(\' + page.owner.avatar_url + \')\' } : { background: $pb.avatarColor(page.owner) }">' +
    '<template v-if="!page.owner.avatar_url">{{ page.owner.initial }}</template></span>' +
    '<span class="text-[13px] text-ink truncate">{{ page.owner.name }}</span></span>' +
    '<span v-else class="text-[13px] text-faint">—</span></span>' +

    '<span class="text-[13px]" :class="page.nested ? \'text-ink\' : \'text-faint\'">{{ page.nested || \'—\' }}</span>' +

    '<span>' +
    '<span v-if="page.labels.length" class="flex flex-wrap gap-1">' +
    '<span v-for="l in page.labels" :key="l.id" class="inline-flex items-center gap-1 text-[11px] rounded px-1.5 py-0.5" ' +
    ':style="{ color: l.color, background: l.color + \'1a\' }">' +
    '<span class="h-1.5 w-1.5 rounded-full" :style="{ background: l.color }"></span>{{ l.name }}</span>' +
    '</span><span v-else class="text-[13px] text-faint">—</span></span>' +

    '<span class="whitespace-nowrap">' +
    '<span class="text-[13px] text-sub">{{ page.last_activity }}</span>' +
    '<span v-if="page.updated_by" class="block text-[11px] text-faint">by {{ page.updated_by }}</span></span>' +

    // Both controls in ONE grid cell: the row is a six-column grid, and a seventh child would
    // silently start a new implicit column and knock every row out of alignment.
    '<span class="flex items-center justify-end gap-0.5">' +

    // Adding a page NEXT TO this one, or UNDER it, is a decision about this row — so it
    // belongs on the row rather than only at the top of the table.
    '<span v-if="wiki.canEdit" class="relative">' +
    '<button type="button" @click.stop="wiki.toggleAddMenu(page)" :aria-label="\'Add a page near \' + page.title" ' +
    'data-tip="Add a page" class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover" ' +
    'v-html="wiki.icon(\'plus\', 15)"></button>' +
    '<div v-if="wiki.addMenu === page.id" class="fixed inset-0 z-30" @click="wiki.addMenu = null"></div>' +
    '<div v-if="wiki.addMenu === page.id" ' +
    'class="absolute right-0 top-9 z-40 w-52 rounded-md bg-white p-1 shadow-lg outline outline-1 outline-black/5">' +
    '<button type="button" @click="wiki.openAddPage(null)" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' +
    '<span v-html="wiki.icon(\'file-lines\', 15, \'text-faint\')"></span>Add page</button>' +
    '<button type="button" @click="wiki.openAddPage(page)" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' +
    '<span v-html="wiki.icon(\'diagram-subtask\', 15, \'text-faint\')"></span>Add sub-page</button>' +
    '</div></span>' +

    '<span class="relative">' +
    '<button type="button" @click.stop="wiki.toggleRowMenu(page, $event)" :aria-label="\'Actions for \' + page.title" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover" v-html="wiki.icon(\'ellipsis\', 16)"></button>' +
    '<div v-if="wiki.rowMenu === page.id" class="fixed inset-0 z-30" @click="wiki.rowMenu = null"></div>' +
    '<div v-if="wiki.rowMenu === page.id" ' +
    ':class="[\'absolute right-0 z-40 w-52 rounded-md bg-white p-1 shadow-lg outline outline-1 outline-black/5\', ' +
    'wiki.rowMenuUp ? \'bottom-9\' : \'top-9\']">' +
    '<button type="button" @click="wiki.openEdit(page)" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' +
    '<span v-html="wiki.icon(\'pen\', 15, \'text-faint\')"></span>Edit</button>' +
    '<button type="button" @click="wiki.copyLink(page)" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' +
    '<span v-html="wiki.icon(\'link\', 15, \'text-faint\')"></span>' +
    '{{ wiki.copied === page.id ? \'Copied\' : \'Copy link\' }}</button>' +
    '<button v-if="wiki.canEdit" type="button" @click="wiki.removeFromCollection(page)" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover">' +
    '<span v-html="wiki.icon(\'box-archive\', 15)"></span>Remove from collection</button>' +
    '</div></span>' +
    '</span>' +
    '</div>' +

    // Always rendered, even when empty: an empty list is the drop target that MAKES a page a
    // parent. Without it there is nowhere to drop onto a leaf.
    // v-show, not v-if: the list element has to stay in the document even when hidden, or
    // Sortable loses the drop target that makes this row a parent at all.
    '<ul v-show="!collapsed" class="pb-wiki-children" data-page-list :data-parent="page.id">' +
    '<page-row v-for="k in kids" :key="k.id" :page="k" :depth="depth + 1" />' +
    '</ul>' +
    '</li>'
};

/* The Cover panel's editable copy of the saved cover (docs/features/wiki-cover-page.md).
   Nulls become empty strings: `null` in a text input renders the word "null". */
function coverFormFrom(cover) {
  var c = cover || {};

  return {
    is_enabled: !!c.is_enabled,
    title: c.title || '',
    short_description: c.short_description || '',
    // `!== false` rather than `||`: these three default ON, and an explicit false must survive.
    global_search_enabled: c.global_search_enabled !== false,
    previous_next_enabled: c.previous_next_enabled !== false,
    on_this_page_enabled: c.on_this_page_enabled !== false,
    content_alignment: c.content_alignment || 'center',
    card_layout: c.card_layout || 'auto'
  };
}

PB.boot('wiki-collection', {
  components: { 'page-row': WikiPageRow },
  provide: function () { return { wiki: this }; },
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      collection: b.collection || {},
      members: b.members || [],
      candidates: b.candidates || [],
      permissions: b.permissions || [],
      canManage: !!b.canManage,
      endpoints: b.endpoints || {},
      pages: b.pages || [],
      canEdit: !!b.canEdit,
      invite: { open: false, userId: '', permission: 'read', saving: false },
      adding: { open: false, title: '', parentId: null, parentTitle: '', groupId: null, groupName: '', busy: false, errors: {} },
      addMenu: null,
      groupMenu: null,
      labels: b.labels || [],
      // Which row's ⋯ menu is open, and the Edit dialog behind it.
      rowMenu: null,
      rowMenuUp: false,
      // Which rows are collapsed. Remembered per collection so a deep tree does not spring
      // back open on every navigation — the tidying is the point.
      collapsed: [],
      visibilities: b.visibilities || [],
      statuses: b.statuses || [],
      publicUrl: b.publicUrl || null,
      statusOpen: false,
      statusBusy: false,
      // The collection's own ⋯ menu, and the one action in it that cannot be undone.
      collectionMenu: false,
      archiveBusy: false,
      deleteConfirm: { open: false, text: '', busy: false },
      previewUrl: b.previewUrl || null,
      groups: b.groups || [],
      // The collection's front door. `cover` is what is saved; `coverForm` is what is being
      // typed, and the two are only the same between saves.
      cover: b.cover || {},
      coverForm: coverFormFrom(b.cover),
      coverAlignments: b.coverAlignments || [],
      coverLayouts: b.coverLayouts || [],
      coverPanel: { open: false, busy: false, dirty: false, errors: {} },
      // Remembered per collection: which way you look at a collection is a preference about
      // that collection, not about the app.
      view: 'list',
      groupsCollapsed: [],
      groupForm: { open: false, id: null, name: '', label: '', short_description: '', long_description: '', busy: false, errors: {} },
      editCollection: { open: false, name: '', description: '', visibility: 'public', busy: false, errors: {} },
      editing: { open: false, id: null, title: '', parentId: '', labels: [], busy: false, errors: {} },
      copied: null,
      sortables: []
    };
  },
  computed: {
    isPrivate: function () { return this.collection.visibility === 'private'; },
    /* The owner is shown beside the invited people, but is not one of them: they cannot be
       removed from their own collection, so they carry no remove control. */
    people: function () {
      var out = [];
      if (this.collection.owner) out.push({ id: null, user: this.collection.owner, permission_label: 'Owner' });

      return out.concat(this.members);
    },
    candidateOptions: function () {
      return this.candidates.map(function (c) {
        return { value: String(c.id), label: c.name, desc: c.email, initial: c.initial, avatar: c.avatar_url };
      });
    },
    permissionOptions: function () {
      return this.permissions.map(function (p) { return { value: p.value, label: p.label, desc: p.desc }; });
    },
    canInvite: function () { return !!this.invite.userId && !this.invite.saving; },

    /* Deleting asks for the collection's name, the same as deleting a project. Case and
       surrounding space are forgiven — the point is that somebody read the name, not that
       they can reproduce its capitals. */
    canDeleteCollection: function () {
      var typed = String(this.deleteConfirm.text || '').trim().toLowerCase();

      return typed !== '' && typed === String(this.collection.name || '').trim().toLowerCase();
    },

    /* The three switches, as data — three near-identical blocks of markup is three places to
       fix the next time the row grows a description. */
    coverFeatures: function () {
      return [
        { key: 'global_search_enabled', label: 'Global search',
          desc: 'A search field on the cover, over this collection.' },
        { key: 'previous_next_enabled', label: 'Previous / Next',
          desc: 'Links to the pages either side, at the foot of a page.' },
        { key: 'on_this_page_enabled', label: 'On this page',
          desc: 'The headings of the page being read, down the right.' }
      ];
    }
  },
  mounted: function () {
    this.collapsed = this.readCollapsed();
    try { this.view = localStorage.getItem('pb.wiki.view.' + (this.collection.id || 0)) || 'list'; } catch (e) {}
    try { this.groupsCollapsed = JSON.parse(localStorage.getItem(this.groupKey()) || '[]'); } catch (e) {}

    // FR-WC-028 — leaving with the cover panel half-edited should cost a confirmation, not the
    // work. Held on the instance so it can be taken off again; a listener that outlives its
    // component blocks navigation on every later screen.
    var self = this;
    this.coverGuard = function (e) {
      if (!self.coverPanel.dirty) return;
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', this.coverGuard);

    this.$nextTick(this.bindSortable);
  },

  unmounted: function () { window.removeEventListener('beforeunload', this.coverGuard); },

  updated: function () { this.$nextTick(this.bindSortable); },

  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    // ---- expand / collapse ----------------------------------------------------------------
    // ---- list / group -----------------------------------------------------------------------
    setView: function (view) {
      this.view = view;
      try { localStorage.setItem('pb.wiki.view.' + (this.collection.id || 0), view); } catch (e) {}
      // Both views are sortable, and each binds a different set of lists.
      this.$nextTick(this.bindSortable);
    },

    // ---- the collection's own actions ------------------------------------------------------
    /* Archiving is reversible, and this same control brings it back. The Archived view is not
       built yet, so without a way back from the collection's own page an archived collection
       would be one nobody could reach again. */
    toggleArchive: async function () {
      this.collectionMenu = false;

      if (this.archiveBusy) return;
      this.archiveBusy = true;

      try {
        var resp = await this.$pb.api(this.endpoints.archive, {
          method: 'PATCH',
          body: { archived: !this.collection.archived }
        });
        this.collection = resp.collection || this.collection;
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }

      this.archiveBusy = false;
    },

    openDeleteCollection: function () {
      this.collectionMenu = false;
      this.deleteConfirm = { open: true, text: '', busy: false };
    },

    deleteCollection: async function () {
      if (!this.canDeleteCollection || this.deleteConfirm.busy) return;
      this.deleteConfirm.busy = true;

      try {
        var resp = await this.$pb.api(this.endpoints.deleteCollection, { method: 'DELETE' });
        // Leaving is the point: this screen describes something that no longer exists.
        window.location.href = resp.redirect || '/wiki';
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.deleteConfirm.busy = false;
      }
    },

    // ---- the cover (docs/features/wiki-cover-page.md) --------------------------------------
    markCoverDirty: function () { this.coverPanel.dirty = true; },

    setCoverField: function (key, value) {
      this.coverForm[key] = value;
      this.coverPanel.dirty = true;
    },

    /* The switch saves on its own — turning the front door off should be one click, not a click
       and then a Save. It posts the WHOLE panel, so "may this be turned on?" is answered
       against the title in front of the person turning it on rather than the last saved one. */
    toggleCoverEnabled: async function (value) {
      var previous = this.coverForm.is_enabled;
      this.coverForm.is_enabled = value;

      // Enabling needs a title. Open the panel first, or the error names a field that is not
      // on the screen.
      if (value && !this.coverForm.title.trim()) this.coverPanel.open = true;

      if (!await this.saveCover()) this.coverForm.is_enabled = previous;
    },

    saveCover: async function () {
      if (this.coverPanel.busy) return false;

      this.coverPanel.busy = true;
      this.coverPanel.errors = {};

      var saved = false;

      try {
        var resp = await this.$pb.api(this.endpoints.cover, { method: 'PATCH', body: this.coverForm });
        this.cover = resp.cover || {};
        this.coverForm = coverFormFrom(this.cover);
        this.coverPanel.dirty = false;
        this.$pb.toast(resp.message || 'Cover saved.');
        saved = true;
      } catch (e) {
        // WCOV-15 — the fields keep what was typed. Reloading them from the server on a failed
        // save throws away the work and answers a problem nobody had.
        this.coverPanel.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }

      this.coverPanel.busy = false;

      return saved;
    },

    groupKey: function () { return 'pb.wiki.groupsCollapsed.' + (this.collection.id || 0); },
    isGroupCollapsed: function (id) { return this.groupsCollapsed.indexOf(id) !== -1; },
    toggleGroupCollapsed: function (id) {
      var i = this.groupsCollapsed.indexOf(id);
      if (i === -1) this.groupsCollapsed.push(id); else this.groupsCollapsed.splice(i, 1);
      try { localStorage.setItem(this.groupKey(), JSON.stringify(this.groupsCollapsed)); } catch (e) {}
      this.$nextTick(this.bindSortable);
    },

    /** The sections drawn at the top level, in the order somebody chose. */
    topGroups: function () {
      return this.groups
        .filter(function (g) { return !g.parent_id; })
        .sort(function (a, b) { return a.position - b.position; });
    },

    /** The sections drawn inside one of them. Nesting stops here — see GroupController. */
    subGroups: function (parentId) {
      return this.groups
        .filter(function (g) { return g.parent_id === parentId; })
        .sort(function (a, b) { return a.position - b.position; });
    },

    /* Options for the "which group" picker. Sub-groups are listed under their parent and
       named with it: two sections called "Overview" under different headings are the same
       word in a flat list, and the picker is the one place the tree is invisible. */
    groupOptions: function () {
      var self = this;
      var options = [{ value: '', label: 'Ungrouped' }];

      this.topGroups().forEach(function (g) {
        options.push({ value: String(g.id), label: g.name, desc: g.label || '' });

        self.subGroups(g.id).forEach(function (sg) {
          options.push({ value: String(sg.id), label: '\u2014 ' + sg.name, desc: g.name });
        });
      });

      return options;
    },

    toggleGroupMenu: function (group) { this.groupMenu = this.groupMenu === group.id ? null : group.id; },

    /** The pages filed under one group, or the ones filed under none. */
    pagesInGroup: function (groupId) {
      return this.pages
        .filter(function (p) { return (p.group_id || null) === groupId; })
        .sort(function (a, b) { return a.position - b.position; });
    },

    /** `parent` is the section the new one goes inside, or null for a top-level section. */
    openGroupForm: function (group, parent) {
      this.groupMenu = null;
      this.groupForm = group
        ? {
          open: true, id: group.id, name: group.name, label: group.label || '',
          short_description: group.short_description || '', long_description: group.long_description || '',
          parentId: group.parent_id || null, parentName: '', busy: false, errors: {}
        }
        : {
          open: true, id: null, name: '', label: '',
          short_description: '', long_description: '',
          parentId: parent ? parent.id : null, parentName: parent ? parent.name : '',
          busy: false, errors: {}
        };
    },

    saveGroup: async function () {
      var name = String(this.groupForm.name || '').trim();
      if (!name || this.groupForm.busy) return;

      this.groupForm.busy = true;
      this.groupForm.errors = {};

      var body = {
        name: name,
        label: this.groupForm.label,
        short_description: this.groupForm.short_description,
        long_description: this.groupForm.long_description
      };

      // Only on create: moving a section between levels is a drag, not a hidden effect of
      // opening the edit form on something that already has a place.
      if (!this.groupForm.id) body.parent_id = this.groupForm.parentId;

      try {
        var resp = this.groupForm.id
          ? await this.$pb.api(this.$pb.withId(this.endpoints.group, this.groupForm.id), { method: 'PATCH', body: body })
          : await this.$pb.api(this.endpoints.groups, { method: 'POST', body: body });

        this.groups = resp.groups || [];
        this.groupForm.open = false;
        this.$pb.toast(resp.message || 'Group saved.');
      } catch (e) {
        this.groupForm.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.groupForm.busy = false;
    },

    removeGroup: async function (group) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.group, group.id), { method: 'DELETE' });
        this.groups = resp.groups || [];
        // The pages survive; they are simply ungrouped now.
        this.pages = this.pages.map(function (p) {
          return p.group_id === group.id ? Object.assign({}, p, { group_id: null }) : p;
        });
        this.$pb.toast(resp.message || 'Group removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    movePageToGroup: async function (page, groupId) {
      var id = groupId === '' ? null : Number(groupId);
      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.pageGroup, page.id), {
          method: 'PATCH', body: { group_id: id }
        });
        this.pages = this.pages.map(function (p) {
          return p.id === page.id ? Object.assign({}, p, { group_id: id }) : p;
        });
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    storageKey: function () {
      return 'pb.wiki.collapsed.' + (this.collection.id || 0);
    },
    readCollapsed: function () {
      try { return JSON.parse(localStorage.getItem(this.storageKey()) || '[]'); } catch (e) { return []; }
    },
    isCollapsed: function (id) { return this.collapsed.indexOf(id) !== -1; },
    toggleCollapsed: function (id) {
      var i = this.collapsed.indexOf(id);
      if (i === -1) this.collapsed.push(id); else this.collapsed.splice(i, 1);
      try { localStorage.setItem(this.storageKey(), JSON.stringify(this.collapsed)); } catch (e) {}

      // Sortable is bound to the lists that just appeared or vanished.
      this.$nextTick(this.bindSortable);
    },

    /* The flat list, as a tree. Rendering nests, and Sortable moves elements between the
       nested <ul>s — so the markup has to BE the shape, not merely describe it. */
    tree: function (parentId) {
      var pid = parentId === undefined ? null : parentId;

      return this.pages
        .filter(function (p) { return (p.parent_id || null) === pid; })
        .sort(function (a, b) { return a.position - b.position; });
    },

    /**
     * Wire every list, and re-wire after each render.
     *
     * Sortable binds to elements, and Vue replaces them whenever `pages` changes — so the
     * instances are destroyed and rebuilt rather than left pointing at nodes that are no
     * longer in the document.
     */
    bindSortable: function () {
      var self = this;
      if (!window.Sortable || !this.canEdit) return;

      this.sortables.forEach(function (s) { try { s.destroy(); } catch (e) {} });
      this.sortables = [];

      // The Group view: pages within and between sections.
      Array.prototype.forEach.call(document.querySelectorAll('[data-group-pages]'), function (list) {
        self.sortables.push(window.Sortable.create(list, {
          group: 'wiki-group-pages',
          animation: 150,
          handle: '[data-drag]',
          draggable: '[data-page]',
          ghostClass: 'pb-drag-ghost',
          onEnd: function () { self.persistGroupPages(); }
        }));
      });

      // The sections themselves.
      var groupList = document.querySelector('[data-group-list]');
      if (groupList) {
        self.sortables.push(window.Sortable.create(groupList, {
          animation: 150,
          handle: '[data-group-drag]',
          // Direct children only: a sub-group card is also [data-group], and without this the
          // top-level list would claim the ones drawn inside its own sections.
          draggable: ':scope > [data-group]',
          ghostClass: 'pb-drag-ghost',
          onEnd: function () { self.persistGroupOrder(groupList, null); }
        }));
      }

      // Each section's own sub-groups, ordered against each other. Deliberately NOT one shared
      // Sortable group: dragging a sub-group into a different section would change which
      // heading it lives under, which is a different edit from reordering.
      Array.prototype.forEach.call(document.querySelectorAll('[data-subgroup-list]'), function (list) {
        self.sortables.push(window.Sortable.create(list, {
          animation: 150,
          handle: '[data-group-drag]',
          draggable: '[data-group]',
          ghostClass: 'pb-drag-ghost',
          onEnd: function () {
            self.persistGroupOrder(list, Number(list.getAttribute('data-subgroup-list')));
          }
        }));
      });

      Array.prototype.forEach.call(document.querySelectorAll('[data-page-list]'), function (list) {
        self.sortables.push(window.Sortable.create(list, {
          // One group across every level is what lets a page be dropped INTO another.
          group: 'wiki-pages',
          animation: 150,
          fallbackOnBody: true,
          // Below 1 the drop zone for "into" is generous; at 1 you can only ever reorder.
          swapThreshold: 0.65,
          handle: '[data-drag]',
          draggable: '[data-page]',
          ghostClass: 'pb-drag-ghost',
          onEnd: function () { self.persistTree(); }
        }));
      });
    },

    /** The Group view's arrangement: which section each page is in, and in what order. */
    persistGroupPages: async function () {
      var nodes = [];

      Array.prototype.forEach.call(document.querySelectorAll('[data-group-pages]'), function (list) {
        var group = list.getAttribute('data-group-pages');
        var position = 0;

        Array.prototype.forEach.call(list.children, function (el) {
          var id = el.getAttribute('data-page');
          if (!id) return;
          nodes.push({
            id: Number(id),
            parent_id: null,
            position: position++,
            group_id: group ? Number(group) : null
          });
        });
      });

      if (!nodes.length) return;

      try {
        var resp = await this.$pb.api(this.endpoints.pagesReorder, { method: 'PATCH', body: { nodes: nodes } });
        this.pages = resp.pages || [];
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
        window.location.reload();
      }
    },

    /** One level at a time: positions are per level, so `parentId` says which. */
    persistGroupOrder: async function (list, parentId) {
      var ids = [];
      if (!list) return;

      Array.prototype.forEach.call(list.children, function (el) {
        var id = el.getAttribute('data-group');
        if (id) ids.push(Number(id));
      });

      try {
        var resp = await this.$pb.api(this.endpoints.groupsReorder, {
          method: 'PATCH',
          body: { ids: ids, parent_id: parentId }
        });
        this.groups = resp.groups || [];
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
        window.location.reload();
      }
    },

    /* Read the tree back OUT of the DOM after a drop: Sortable moved the elements, so the
       document is the authority on where things now are — not the array that produced it. */
    persistTree: async function () {
      var nodes = [];

      Array.prototype.forEach.call(document.querySelectorAll('[data-page-list]'), function (list) {
        var parent = list.getAttribute('data-parent');
        var position = 0;

        Array.prototype.forEach.call(list.children, function (el) {
          var id = el.getAttribute('data-page');
          if (!id) return;
          nodes.push({ id: Number(id), parent_id: parent ? Number(parent) : null, position: position++ });
        });
      });

      if (!nodes.length) return;

      try {
        var resp = await this.$pb.api(this.endpoints.pagesReorder, { method: 'PATCH', body: { nodes: nodes } });
        this.pages = resp.pages || [];
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
        // The DOM now disagrees with the server. Reloading is the honest repair — a tree left
        // showing a move that did not happen is worse than a flicker.
        window.location.reload();
      }
    },


    toggleRowMenu: function (page, event) {
      if (this.rowMenu === page.id) { this.rowMenu = null; return; }

      // Which way it opens is decided from the button's position: near the foot of the window
      // a downward menu is off-screen, which reads exactly like a menu that does not work.
      var room = 260;
      var rect = event && event.currentTarget ? event.currentTarget.getBoundingClientRect() : null;
      this.rowMenuUp = !!rect && (window.innerHeight - rect.bottom) < room && rect.top > room;
      this.rowMenu = page.id;
    },

    /* Pages this one could nest under: everything in the collection except itself. A page
       nested under itself vanishes from every tree that tries to draw it. */
    parentOptions: function (id) {
      return [{ value: '', label: 'Top level' }].concat(
        this.pages.filter(function (p) { return p.id !== id; })
          .map(function (p) { return { value: String(p.id), label: p.title }; })
      );
    },

    openEdit: function (page) {
      this.rowMenu = null;
      this.editing = {
        open: true, id: page.id, title: page.title,
        parentId: page.parent_id ? String(page.parent_id) : '',
        labels: (page.labels || []).map(function (l) { return l.id; }),
        busy: false, errors: {}
      };
    },
    toggleLabel: function (id) {
      var i = this.editing.labels.indexOf(id);
      if (i === -1) this.editing.labels.push(id); else this.editing.labels.splice(i, 1);
    },
    isLabelOn: function (id) { return this.editing.labels.indexOf(id) !== -1; },

    saveEdit: async function () {
      var title = String(this.editing.title || '').trim();
      if (!title || this.editing.busy) return;

      this.editing.busy = true;
      this.editing.errors = {};
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.pageDetails, this.editing.id), {
          method: 'PATCH',
          body: {
            title: title,
            parent_id: this.editing.parentId ? Number(this.editing.parentId) : null,
            labels: this.editing.labels
          }
        });
        this.pages = resp.pages || [];
        this.editing.open = false;
        this.$pb.toast(resp.message || 'Page updated.');
      } catch (e) {
        this.editing.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.editing.busy = false;
    },

    /* Copies the absolute URL — a relative one pasted into chat goes nowhere. */
    copyLink: function (page) {
      this.rowMenu = null;
      var url = location.origin + page.url;
      var self = this;

      function done() { self.copied = page.id; setTimeout(function () { self.copied = null; }, 1600); self.$pb.toast('Link copied.'); }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, function () { self.$pb.toast('Could not copy the link.', 'error'); });

        return;
      }

      // Older browsers, and any page not served over https, where the async API is absent.
      try {
        var el = document.createElement('textarea');
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        el.remove();
        done();
      } catch (e) { this.$pb.toast('Could not copy the link.', 'error'); }
    },

    removeFromCollection: async function (page) {
      this.rowMenu = null;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.pageRemove, page.id), { method: 'DELETE' });
        this.pages = resp.pages || [];
        this.$pb.toast(resp.message || 'Removed from collection.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },


    statusMeta: function (key) {
      var found = this.statuses.filter(function (s) { return s.value === key; })[0];

      return found || { value: key, label: key, desc: '' };
    },
    statusColor: function (key) {
      if (key === 'published') return '#22C55E';

      return key === 'unpublished' ? '#F97316' : '#9CA3AF';
    },

    setStatus: async function (value) {
      this.statusOpen = false;
      if (this.statusBusy || value === this.collection.status) return;

      this.statusBusy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.status, { method: 'PATCH', body: { status: value } });
        this.apply(resp.collection);
        this.$pb.toast(resp.message || 'Status updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.statusBusy = false;
    },

    generatePublicUrl: async function () {
      if (this.statusBusy) return;
      this.statusBusy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.publicUrl, { method: 'POST' });
        this.apply(resp.collection);
        this.$pb.toast(resp.message || 'Public URL generated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.statusBusy = false;
    },

    copyPublicUrl: function () {
      if (!this.publicUrl) return;
      var self = this;
      var done = function () { self.$pb.toast('Link copied.'); };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(this.publicUrl).then(done, function () {
          self.$pb.toast('Could not copy the link.', 'error');
        });

        return;
      }

      try {
        var el = document.createElement('textarea');
        el.value = this.publicUrl;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        el.remove();
        done();
      } catch (e) { this.$pb.toast('Could not copy the link.', 'error'); }
    },

    openEditCollection: function () {
      this.editCollection = {
        open: true,
        name: this.collection.name || '',
        description: this.collection.description || '',
        visibility: this.collection.visibility || 'public',
        busy: false, errors: {}
      };
    },
    saveCollection: async function () {
      var name = String(this.editCollection.name || '').trim();
      if (!name || this.editCollection.busy) return;

      this.editCollection.busy = true;
      this.editCollection.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.collection, {
          method: 'PATCH',
          body: {
            name: name,
            description: this.editCollection.description,
            visibility: this.editCollection.visibility
          }
        });
        this.apply(resp.collection);
        this.editCollection.open = false;
        this.$pb.toast(resp.message || 'Collection updated.');
      } catch (e) {
        this.editCollection.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.editCollection.busy = false;
    },

    /** `parent` is the page the new one goes underneath, or null for the top level. */
    openAddPage: function (parent) {
      this.addMenu = null;
      this.adding = {
        open: true, title: '',
        parentId: parent ? parent.id : null,
        parentTitle: parent ? parent.title : '',
        groupId: null, groupName: '',
        busy: false, errors: {}
      };
    },

    /* Adding INTO a section, from the section's own header. Group view is where somebody is
       thinking in sections, so the page should arrive filed rather than land at the bottom of
       Ungrouped waiting to be dragged back up to where they were already looking. */
    openAddPageInGroup: function (group) {
      this.addMenu = null;
      this.adding = {
        open: true, title: '',
        parentId: null, parentTitle: '',
        groupId: group.id, groupName: group.name,
        busy: false, errors: {}
      };
    },

    toggleAddMenu: function (page) { this.addMenu = this.addMenu === page.id ? null : page.id; },

    /* Name it, then land in it. Creating a document and then having to find it in a list is a
       step nobody wants — the same flow Project Pages already uses. */
    createPage: async function () {
      var title = String(this.adding.title || '').trim();
      if (!title || this.adding.busy) return;

      this.adding.busy = true;
      this.adding.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.pages, {
          method: 'POST',
          body: { title: title, parent_id: this.adding.parentId, group_id: this.adding.groupId }
        });
        window.location.href = resp.url;
      } catch (e) {
        this.adding.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.adding.busy = false;
      }
    },

    openInvite: function () {
      this.invite = { open: true, userId: '', permission: 'read', saving: false };
    },
    addMember: async function () {
      if (!this.canInvite) return;
      this.invite.saving = true;
      try {
        var resp = await this.$pb.api(this.endpoints.members, {
          method: 'POST',
          body: { user_id: Number(this.invite.userId), permission: this.invite.permission }
        });
        this.apply(resp.collection);
        this.invite.open = false;
        this.$pb.toast(resp.message || 'Access granted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.invite.saving = false;
    },
    removeMember: async function (member) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.member, member.id), { method: 'DELETE' });
        this.apply(resp.collection);
        this.$pb.toast(resp.message || 'Access removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    apply: function (payload) {
      if (!payload) return;
      this.collection = payload.collection || this.collection;
      this.members = payload.members || [];
      this.candidates = payload.candidates || [];
      if (payload.pages) this.pages = payload.pages;
      if (payload.publicUrl !== undefined) this.publicUrl = payload.publicUrl;
    }
  },

  template:
    '<div>' +

    /* ---- toolbar ----
       The same header the Projects index carries: the sidebar-expand control, a divider, what
       you are looking at, and every action pushed to the right. The name used to be a 20px
       heading in the body with its controls scattered around it, which made this screen read as
       a different application from the one next door in the rail. */
    '<div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">' +
    '<button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" ' +
    'aria-controls="sidebar" aria-expanded="false" ' +
    'class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" ' +
    'v-html="icon(\'sidebar\', 16)"></button>' +
    '<span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>' +

    '<span class="flex items-center gap-2 min-w-0">' +
    '<span class="shrink-0 text-sub" v-html="icon(isPrivate ? \'lock\' : \'folder\', 16)"></span>' +
    '<span class="text-[14px] font-medium text-ink truncate">{{ collection.name }}</span>' +

    // Archiving takes a collection out of every list, so the one screen that still shows it has
    // to say so — otherwise it looks identical to one that is simply not on the lists.
    '<span v-if="collection.archived" class="shrink-0 text-[10px] uppercase tracking-wide rounded px-1.5 py-0.5 bg-warning/15 text-warning">' +
    'Archived</span>' +
    '<span class="shrink-0 text-[10px] uppercase tracking-wide rounded px-1.5 py-0.5" ' +
    ':class="isPrivate ? \'bg-hover text-sub\' : \'bg-success/15 text-success\'">' +
    '{{ isPrivate ? \'Private\' : \'Public\' }}</span>' +
    '</span>' +

    '<div class="ml-auto flex items-center gap-1.5 sm:gap-2">' +

    // Status. Beside visibility, because the two together are the whole answer to "who can read
    // this" — one inside the workspace, one outside it.
    '<span v-if="canManage" class="relative">' +
    '<button type="button" @click.stop="statusOpen = !statusOpen" :disabled="statusBusy" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-[12px] font-medium hover:opacity-80 disabled:opacity-50" ' +
    ':style="{ color: statusColor(collection.status), background: statusColor(collection.status) + \'1a\' }">' +
    '<span class="h-1.5 w-1.5 rounded-full" :style="{ background: statusColor(collection.status) }"></span>' +
    '{{ statusMeta(collection.status).label }}' +
    '<span v-html="icon(\'chevron-down\', 12)"></span></button>' +

    '<div v-if="statusOpen" class="fixed inset-0 z-30" @click="statusOpen = false"></div>' +
    '<div v-if="statusOpen" class="absolute right-0 top-9 z-40 w-64 rounded-md bg-white p-1 shadow-lg outline outline-1 outline-black/5">' +
    '<button v-for="st in statuses" :key="st.value" type="button" @click="setStatus(st.value)" ' +
    'class="w-full text-left px-2 py-2 rounded-md hover:bg-hover">' +
    '<span class="flex items-center gap-2 text-[13px] text-ink">' +
    '<span class="h-1.5 w-1.5 rounded-full shrink-0" :style="{ background: statusColor(st.value) }"></span>' +
    '{{ st.label }}' +
    '<span v-if="collection.status === st.value" class="ml-auto text-brand" v-html="icon(\'check\', 14)"></span>' +
    '</span>' +
    '<span class="block text-[12px] text-sub mt-0.5 pl-3.5">{{ st.desc }}</span></button>' +
    '</div></span>' +

    '<span v-else class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-[12px] font-medium" ' +
    ':style="{ color: statusColor(collection.status), background: statusColor(collection.status) + \'1a\' }">' +
    '<span class="h-1.5 w-1.5 rounded-full" :style="{ background: statusColor(collection.status) }"></span>' +
    '{{ statusMeta(collection.status).label }}</span>' +

    // A new tab, not this one: previewing is something you look at BESIDE the collection you
    // are editing, and losing the editor to do it defeats the point.
    '<a :href="previewUrl" target="_blank" rel="noopener" ' +
    'class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">' +
    '<span v-html="icon(\'eye\', 14, \'text-faint\')"></span>Preview</a>' +

    '<button type="button" disabled title="Linking a page is being built" ' +
    'class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink cursor-not-allowed opacity-60 whitespace-nowrap">' +
    '<span v-html="icon(\'link\', 14, \'text-faint\')"></span>Link a page</button>' +

    '<button v-if="canEdit" type="button" @click="openAddPage(null)" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap">' +
    '<span v-html="icon(\'plus\', 14)"></span>Add page</button>' +

    // ---- the collection's own ⋯ ----
    '<span v-if="canManage" class="relative shrink-0">' +
    '<button type="button" @click.stop="collectionMenu = !collectionMenu" ' +
    ':aria-expanded="String(collectionMenu)" aria-label="Collection actions" data-tip="Actions" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" ' +
    'v-html="icon(\'ellipsis\', 16)"></button>' +

    '<div v-if="collectionMenu" class="fixed inset-0 z-30" @click="collectionMenu = false"></div>' +
    '<div v-if="collectionMenu" ' +
    'class="absolute right-0 top-9 z-40 w-56 rounded-md bg-white p-1 shadow-lg outline outline-1 outline-black/5">' +

    '<button type="button" @click="collectionMenu = false; openEditCollection()" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' +
    '<span v-html="icon(\'pen\', 15, \'text-faint\')"></span>Edit collection</button>' +

    '<button type="button" @click="toggleArchive" :disabled="archiveBusy" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover disabled:opacity-50">' +
    '<span v-html="icon(collection.archived ? \'rotate-left\' : \'box-archive\', 15, \'text-faint\')"></span>' +
    '{{ collection.archived ? \'Restore collection\' : \'Archive collection\' }}</button>' +

    // Separated, and the only red thing on the screen: it is the one action here that does not
    // come back.
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="openDeleteCollection" ' +
    'class="w-full flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover">' +
    '<span v-html="icon(\'trash\', 15)"></span>Delete collection</button>' +
    '</div></span>' +
    '</div></div>' +

    // The body keeps the reading measure the toolbar above it deliberately does not.
    '<div class="max-w-[1180px] mx-auto px-5 sm:px-8 py-6">' +

    '<p v-if="collection.description" class="text-[13px] text-sub">{{ collection.description }}</p>' +
    '<p v-else class="text-[13px] text-faint">No description.</p>' +

    // ---- who can open it (private only) ----
    //
    // The heading sits ABOVE the faces rather than beside them: a row of initials needs a label
    // it clearly belongs to, and inline it read as one more chip in the same line.
    //
    // `data-tip`, not `title`. A face shows an initial, so its meaning is precisely what is NOT
    // written on it — and the native tooltip waits about a second before saying so.
    // In a card, like Public URL — the two answer the same kind of question ("who can reach
    // this") and looked like different kinds of thing sitting side by side.
    '<div v-if="isPrivate" class="mt-6 rounded-xl border border-line p-5">' +
    '<h2 class="text-[13px] font-semibold text-head">Access</h2>' +
    '<p class="text-[12px] text-sub mt-0.5">Only these people can open this collection.</p>' +

    '<div class="mt-2.5 flex items-center gap-2 flex-wrap">' +
    '<span v-for="p in people" :key="p.user.id" class="relative group">' +
    '<span :data-tip="p.user.name + \' — \' + p.permission_label" tabindex="0" ' +
    'class="h-8 w-8 rounded-full grid place-items-center text-[11px] font-semibold text-white bg-cover bg-center ' +
    'ring-2 ring-white cursor-default" ' +
    ':style="p.user.avatar_url ? { backgroundImage: \'url(\' + p.user.avatar_url + \')\' } : { background: $pb.avatarColor(p.user) }">' +
    '<template v-if="!p.user.avatar_url">{{ p.user.initial }}</template></span>' +
    '<button v-if="canManage && p.id" type="button" @click="removeMember(p)" ' +
    ':aria-label="\'Remove \' + p.user.name" :data-tip="\'Remove \' + p.user.name" ' +
    'class="hidden group-hover:grid absolute -top-1 -right-1 h-4 w-4 place-items-center rounded-full bg-danger text-white text-[9px]">&times;</button>' +
    '</span>' +
    '<button v-if="canManage" type="button" @click="openInvite" data-tip="Add people" aria-label="Add people" ' +
    'class="h-8 w-8 grid place-items-center rounded-full border border-dashed border-stroke text-sub hover:bg-hover" ' +
    'v-html="icon(\'plus\', 14)"></button>' +
    '</div>' +
    '</div>' +

    // ---- pages ----
    '<div class="flex items-center gap-3 mt-6">' +
    '<h2 class="text-[13px] font-semibold text-head">Pages</h2>' +

    // Two ways of looking at the same pages, not two places to keep them.
    '<div class="ml-auto inline-flex rounded-md border border-stroke overflow-hidden">' +
    '<button type="button" @click="setView(\'list\')" ' +
    ':class="[\'h-8 px-3 text-[12px] font-semibold\', view === \'list\' ? \'bg-sel text-brand\' : \'text-ink hover:bg-hover\']">' +
    'List</button>' +
    '<button type="button" @click="setView(\'group\')" ' +
    ':class="[\'h-8 px-3 text-[12px] font-semibold border-l border-stroke\', view === \'group\' ? \'bg-sel text-brand\' : \'text-ink hover:bg-hover\']">' +
    'Group</button>' +
    '</div>' +

    '<button v-if="canEdit && view === \'group\'" type="button" @click="openGroupForm(null, null)" ' +
    'class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">' +
    'New group</button>' +
    '</div>' +

    // Page NAME · Owner · Nested · Label · Last activity · Actions.
    //
    // A list of lists, not a <table>. Sortable nests by moving an element between <ul>s, so
    // the tree has to BE nested markup; the columns are a CSS grid on each row, which keeps it
    // reading as a table while remaining something you can drop a page into.
    // NOT overflow-hidden. It was, to clip the header's fill to the rounded corners — and it
    // clipped the row menus too, so the last row's actions opened into nothing. The header
    // rounds its own top corners instead, which costs one class and hides no menus.
    '<div v-if="view === \'list\' && pages.length" class="mt-2 border border-line rounded-xl">' +
    '<div class="pb-wiki-row rounded-t-xl border-b border-line bg-hover/50 text-[11px] font-semibold text-faint uppercase tracking-wide">' +
    '<span class="pl-9">Page name</span><span>Owner</span><span>Nested pages</span>' +
    '<span>Label</span><span>Last activity</span><span></span>' +
    '</div>' +
    '<ul class="divide-y divide-line" data-page-list :data-parent="null">' +
    '<page-row v-for="p in tree(null)" :key="p.id" :page="p" :depth="0" />' +
    '</ul></div>' +

    '<div v-else-if="view === \'list\'" class="mt-2 rounded-xl border border-line px-6 py-10 text-center">' +
    '<p class="text-[13px] font-semibold text-head">No pages yet</p>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[420px] mx-auto">' +
    'Start with a blank page and build from there.</p>' +
    '<button v-if="canEdit" type="button" @click="openAddPage(null)" ' +
    'class="mt-4 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    'Add page</button>' +
    '</div>' +

    // ---- Group view ----
    '<template v-if="view === \'group\'">' +

    /* ---- the Cover card (docs/features/wiki-cover-page.md) ----
       Pinned above the sections and deliberately NOT one of them: no drag handle, no delete,
       and a quieter fill. A card identical to its neighbours invites somebody to drop it into
       the middle of them, and it is not a section — it is what a reader sees before them. */
    '<div class="mt-3 rounded-xl border border-line bg-hover/40">' +
    '<div class="px-4 py-3 flex items-start gap-2">' +
    '<span class="mt-0.5 h-6 w-6 rounded-md bg-white border border-line grid place-items-center text-faint shrink-0" ' +
    'v-html="icon(\'image\', 13)"></span>' +

    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-semibold text-head">Cover</span>' +
    '<span class="text-[10px] uppercase tracking-wide rounded px-1.5 py-0.5" ' +
    ':class="coverForm.is_enabled ? \'bg-brand/10 text-brand\' : \'bg-white text-faint\'">' +
    '{{ coverForm.is_enabled ? \'On\' : \'Off\' }}</span>' +
    '<span v-if="coverPanel.dirty" class="text-[11px] text-amber-700">Unsaved changes</span>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-0.5">' +
    'The landing page readers see before they choose a page.</p>' +
    '</div>' +

    '<span class="flex items-center gap-2 shrink-0">' +
    '<a v-if="previewUrl" :href="previewUrl" target="_blank" rel="noopener" ' +
    'class="h-7 px-2.5 inline-flex items-center rounded-md text-[12px] font-semibold text-ink hover:bg-white">' +
    'Preview</a>' +
    '<pb-toggle v-if="canEdit" :model-value="coverForm.is_enabled" @update:model-value="toggleCoverEnabled" />' +
    '<button type="button" @click="coverPanel.open = !coverPanel.open" ' +
    ':aria-expanded="String(coverPanel.open)" ' +
    ':aria-label="(coverPanel.open ? \'Hide\' : \'Show\') + \' cover settings\'" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-white" ' +
    'v-html="icon(coverPanel.open ? \'chevron-up\' : \'chevron-down\', 14)"></button>' +
    '</span></div>' +

    // The settings, inline rather than in a modal: half of what they configure is on the
    // screen behind them, and a dialog would cover the thing being described.
    '<div v-show="coverPanel.open" class="border-t border-line bg-white rounded-b-xl px-4 py-4">' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title</label>' +
    '<input v-model="coverForm.title" @input="markCoverDirty" maxlength="100" :disabled="!canEdit" ' +
    'class="pb-input" :class="{\'is-error\': coverPanel.errors.title}" placeholder="Product Knowledge Base" />' +
    '<p v-if="coverPanel.errors.title" class="text-[12px] text-danger mt-1">{{ coverPanel.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Short description ' +
    '<span class="text-faint font-normal">(optional)</span></label>' +
    '<textarea v-model="coverForm.short_description" @input="markCoverDirty" rows="2" maxlength="300" ' +
    ':disabled="!canEdit" class="pb-textarea" ' +
    'placeholder="Everything your team needs to understand our products and processes."></textarea>' +
    '<p v-if="coverPanel.errors.short_description" class="text-[12px] text-danger mt-1">' +
    '{{ coverPanel.errors.short_description[0] }}</p>' +

    '<h3 class="text-[13px] font-semibold text-head mt-5">Page features</h3>' +
    '<div class="mt-2 divide-y divide-line border border-line rounded-lg">' +
    '<div v-for="f in coverFeatures" :key="f.key" class="flex items-start gap-3 px-3 py-2.5">' +
    '<span class="min-w-0 flex-1">' +
    '<span class="block text-[13px] font-medium text-ink">{{ f.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ f.desc }}</span></span>' +
    '<pb-toggle :model-value="coverForm[f.key]" :disabled="!canEdit" ' +
    '@update:model-value="v => setCoverField(f.key, v)" />' +
    '</div></div>' +

    '<h3 class="text-[13px] font-semibold text-head mt-5">Content area layout</h3>' +
    // Said plainly, because the name suggests otherwise: this moves the column, not the text
    // inside a paragraph.
    '<p class="text-[12px] text-sub">Where the document sits in the reading area. It does not ' +
    'change the alignment of the text inside a page.</p>' +
    '<div class="grid gap-2 mt-2 sm:grid-cols-3">' +
    '<label v-for="a in coverAlignments" :key="a.value" ' +
    'class="flex items-start gap-2.5 p-3 rounded-lg border hover:bg-hover" ' +
    ':class="[coverForm.content_alignment === a.value ? \'border-brand/40 bg-sel/40\' : \'border-stroke\', ' +
    'canEdit ? \'cursor-pointer\' : \'opacity-60\']">' +
    '<input type="radio" :value="a.value" v-model="coverForm.content_alignment" @change="markCoverDirty" ' +
    ':disabled="!canEdit" class="mt-0.5 accent-brand" />' +
    '<span class="min-w-0">' +
    '<span class="flex items-center gap-1.5 text-[13px] font-semibold text-ink">' +
    '<span class="text-faint" v-html="icon(a.icon, 13)"></span>{{ a.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ a.desc }}</span></span></label>' +
    '</div>' +

    '<h3 class="text-[13px] font-semibold text-head mt-5">Section card layout</h3>' +
    '<div class="grid gap-2 mt-2 sm:grid-cols-3">' +
    '<label v-for="l in coverLayouts" :key="l.value" ' +
    'class="flex items-start gap-2.5 p-3 rounded-lg border hover:bg-hover" ' +
    ':class="[coverForm.card_layout === l.value ? \'border-brand/40 bg-sel/40\' : \'border-stroke\', ' +
    'canEdit ? \'cursor-pointer\' : \'opacity-60\']">' +
    '<input type="radio" :value="l.value" v-model="coverForm.card_layout" @change="markCoverDirty" ' +
    ':disabled="!canEdit" class="mt-0.5 accent-brand" />' +
    '<span class="min-w-0">' +
    '<span class="flex items-center gap-1.5 text-[13px] font-semibold text-ink">' +
    '<span class="text-faint" v-html="icon(l.icon, 13)"></span>{{ l.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ l.desc }}</span></span></label>' +
    '</div>' +

    '<div v-if="canEdit" class="mt-5 flex items-center justify-end gap-3">' +
    '<span v-if="coverPanel.dirty" class="text-[12px] text-sub">Unsaved changes</span>' +
    '<button type="button" @click="saveCover" :disabled="coverPanel.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ coverPanel.busy ? \'Saving…\' : \'Save changes\' }}</button>' +
    '</div>' +
    '</div></div>' +

    // Sections, draggable by their own handle. Wrapped in one list so Sortable has a
    // container to reorder them within.
    '<div data-group-list>' +
    '<div v-for="g in topGroups()" :key="g.id" :data-group="g.id" class="mt-3 border border-line rounded-xl">' +

    '<div class="px-4 py-3 border-b border-line">' +
    '<div class="flex items-start gap-2">' +

    '<span v-if="canEdit" data-group-drag title="Drag to reorder groups" ' +
    'class="mt-0.5 shrink-0 cursor-grab text-faint hover:text-sub" v-html="icon(\'grip-vertical\', 14)"></span>' +

    '<button type="button" @click="toggleGroupCollapsed(g.id)" ' +
    ':aria-expanded="String(!isGroupCollapsed(g.id))" ' +
    ':aria-label="(isGroupCollapsed(g.id) ? \'Expand \' : \'Collapse \') + g.name" ' +
    'class="mt-0.5 h-5 w-5 grid place-items-center rounded text-faint hover:bg-hover hover:text-sub shrink-0" ' +
    'v-html="icon(isGroupCollapsed(g.id) ? \'chevron-right\' : \'chevron-down\', 13)"></button>' +

    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-semibold text-head truncate">{{ g.name }}</span>' +
    '<span v-if="g.label" class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">{{ g.label }}</span>' +
    '<span class="text-[11px] text-faint">{{ pagesInGroup(g.id).length }} page<span v-if="pagesInGroup(g.id).length !== 1">s</span></span>' +
    '</div>' +
    '<p v-if="g.short_description" class="text-[12px] text-sub mt-0.5">{{ g.short_description }}</p>' +
    '<p v-if="g.long_description && !isGroupCollapsed(g.id)" class="text-[12px] text-faint mt-1.5 whitespace-pre-line">{{ g.long_description }}</p>' +
    '</div>' +

    '<span v-if="canEdit" class="flex items-center gap-1 shrink-0">' +
    // One "+" per section, offering both things a section can contain. Two separate buttons
    // would put "Add sub-group" on every heading of a collection that never uses them.
    '<span class="relative">' +
    '<button type="button" @click.stop="toggleGroupMenu(g)" :aria-label="\'Add to \' + g.name" ' +
    ':aria-expanded="String(groupMenu === g.id)" ' +
    'class="h-7 inline-flex items-center gap-1 px-2 rounded-md text-[12px] font-semibold text-brand hover:bg-hover">' +
    '<span v-html="icon(\'plus\', 13)"></span>Add</button>' +
    '<div v-if="groupMenu === g.id" class="fixed inset-0 z-30" @click="groupMenu = null"></div>' +
    '<div v-if="groupMenu === g.id" ' +
    'class="absolute right-0 top-8 z-40 w-52 rounded-lg border border-line bg-white shadow-lg py-1 text-[13px]">' +
    '<button type="button" @click="openAddPageInGroup(g)" ' +
    'class="w-full flex items-center gap-2 px-3 h-9 text-ink hover:bg-hover text-left">' +
    '<span v-html="icon(\'file-lines\', 15, \'text-faint\')"></span>Add page</button>' +
    '<button type="button" @click="openGroupForm(null, g)" ' +
    'class="w-full flex items-center gap-2 px-3 h-9 text-ink hover:bg-hover text-left">' +
    '<span v-html="icon(\'diagram-subtask\', 15, \'text-faint\')"></span>Add sub-group</button>' +
    '</div></span>' +
    '<button type="button" @click="openGroupForm(g)" data-tip="Edit group" :aria-label="\'Edit \' + g.name" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover" v-html="icon(\'pen\', 14)"></button>' +
    '<button type="button" @click="removeGroup(g)" data-tip="Remove group" :aria-label="\'Remove \' + g.name" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover hover:text-danger" v-html="icon(\'trash\', 14)"></button>' +
    '</span></div></div>' +

    // v-show, never v-if: a collapsed section is still a drop target, which is how you file a
    // page into one you are not currently reading.
    '<ul v-show="!isGroupCollapsed(g.id)" class="divide-y divide-line" :data-group-pages="g.id">' +
    '<li v-for="p in pagesInGroup(g.id)" :key="p.id" :data-page="p.id" class="flex items-center gap-2 px-4 h-12">' +
    '<span v-if="canEdit" data-drag title="Drag to reorder or move" ' +
    'class="shrink-0 cursor-grab text-faint hover:text-sub" v-html="icon(\'grip-vertical\', 14)"></span>' +
    '<span class="shrink-0 text-faint" v-html="icon(\'file-lines\', 15)"></span>' +
    '<a :href="p.url" class="text-[13px] text-ink hover:text-brand truncate flex-1">{{ p.title }}</a>' +
    '<span v-if="canEdit" class="w-44 shrink-0">' +
    '<pb-combo :model-value="p.group_id ? String(p.group_id) : \'\'" :options="groupOptions()" dense ' +
    '@update:model-value="v => movePageToGroup(p, v)" placeholder="Ungrouped" /></span>' +
    '</li>' +
    '<li v-if="!pagesInGroup(g.id).length" class="px-4 h-12 flex items-center text-[12px] text-faint">' +
    // One span, not loose text beside a button: the <li> is a flex row, and each of those
    // would become its own flex item — a sentence broken across the width of the card.
    '<span>No pages in this group yet. Drag one here<template v-if="canEdit">, or ' +
    '<button type="button" @click="openAddPageInGroup(g)" class="text-brand font-semibold hover:underline">add one</button></template>.</span></li>' +
    '</ul>' +

    // Sub-groups: the same card, quieter and indented, inside the section they belong to.
    // Their own list so Sortable reorders them against each other rather than against the
    // top-level sections they are drawn within.
    '<div v-show="!isGroupCollapsed(g.id)" :data-subgroup-list="g.id" class="px-3 pb-3 pt-1 space-y-2">' +
    '<div v-for="sg in subGroups(g.id)" :key="sg.id" :data-group="sg.id" ' +
    'class="ml-6 border border-line rounded-lg bg-hover/40">' +

    '<div class="px-3 py-2 flex items-start gap-2">' +
    '<span v-if="canEdit" data-group-drag title="Drag to reorder sub-groups" ' +
    'class="mt-0.5 shrink-0 cursor-grab text-faint hover:text-sub" v-html="icon(\'grip-vertical\', 13)"></span>' +
    '<button type="button" @click="toggleGroupCollapsed(sg.id)" ' +
    ':aria-expanded="String(!isGroupCollapsed(sg.id))" ' +
    ':aria-label="(isGroupCollapsed(sg.id) ? \'Expand \' : \'Collapse \') + sg.name" ' +
    'class="mt-0.5 h-5 w-5 grid place-items-center rounded text-faint hover:bg-hover hover:text-sub shrink-0" ' +
    'v-html="icon(isGroupCollapsed(sg.id) ? \'chevron-right\' : \'chevron-down\', 12)"></button>' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[13px] font-semibold text-head truncate">{{ sg.name }}</span>' +
    '<span v-if="sg.label" class="text-[10px] uppercase tracking-wide bg-white text-sub rounded px-1.5 py-0.5">{{ sg.label }}</span>' +
    '<span class="text-[11px] text-faint">{{ pagesInGroup(sg.id).length }} page<span v-if="pagesInGroup(sg.id).length !== 1">s</span></span>' +
    '</div>' +
    '<p v-if="sg.short_description" class="text-[12px] text-sub mt-0.5">{{ sg.short_description }}</p>' +
    '<p v-if="sg.long_description && !isGroupCollapsed(sg.id)" class="text-[12px] text-faint mt-1 whitespace-pre-line">{{ sg.long_description }}</p>' +
    '</div>' +
    '<span v-if="canEdit" class="flex items-center gap-1 shrink-0">' +
    '<button type="button" @click="openAddPageInGroup(sg)" :aria-label="\'Add a page to \' + sg.name" ' +
    'class="h-7 inline-flex items-center gap-1 px-2 rounded-md text-[12px] font-semibold text-brand hover:bg-white">' +
    '<span v-html="icon(\'plus\', 12)"></span>Add page</button>' +
    '<button type="button" @click="openGroupForm(sg)" data-tip="Edit sub-group" :aria-label="\'Edit \' + sg.name" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-white" v-html="icon(\'pen\', 13)"></button>' +
    '<button type="button" @click="removeGroup(sg)" data-tip="Remove sub-group" :aria-label="\'Remove \' + sg.name" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-white hover:text-danger" v-html="icon(\'trash\', 13)"></button>' +
    '</span></div>' +

    '<ul v-show="!isGroupCollapsed(sg.id)" class="divide-y divide-line border-t border-line" :data-group-pages="sg.id">' +
    '<li v-for="p in pagesInGroup(sg.id)" :key="p.id" :data-page="p.id" class="flex items-center gap-2 px-3 h-11">' +
    '<span v-if="canEdit" data-drag title="Drag to reorder or move" ' +
    'class="shrink-0 cursor-grab text-faint hover:text-sub" v-html="icon(\'grip-vertical\', 13)"></span>' +
    '<span class="shrink-0 text-faint" v-html="icon(\'file-lines\', 14)"></span>' +
    '<a :href="p.url" class="text-[13px] text-ink hover:text-brand truncate flex-1">{{ p.title }}</a>' +
    '<span v-if="canEdit" class="w-44 shrink-0">' +
    '<pb-combo :model-value="p.group_id ? String(p.group_id) : \'\'" :options="groupOptions()" dense ' +
    '@update:model-value="v => movePageToGroup(p, v)" placeholder="Ungrouped" /></span>' +
    '</li>' +
    '<li v-if="!pagesInGroup(sg.id).length" class="px-3 h-11 flex items-center text-[12px] text-faint">' +
    '<span>Nothing here yet<template v-if="canEdit">, ' +
    '<button type="button" @click="openAddPageInGroup(sg)" class="text-brand font-semibold hover:underline">add a page</button></template>.</span></li>' +
    '</ul></div></div>' +

    '</div></div>' +

    // Everything not filed anywhere. A drop target too, so a page can be taken back out.
    '<div v-if="pagesInGroup(null).length || groups.length" class="mt-3 border border-line rounded-xl">' +
    '<div class="px-4 py-3 border-b border-line flex items-start gap-2">' +
    '<button type="button" @click="toggleGroupCollapsed(0)" ' +
    ':aria-label="(isGroupCollapsed(0) ? \'Expand \' : \'Collapse \') + \'Ungrouped\'" ' +
    'class="mt-0.5 h-5 w-5 grid place-items-center rounded text-faint hover:bg-hover hover:text-sub shrink-0" ' +
    'v-html="icon(isGroupCollapsed(0) ? \'chevron-right\' : \'chevron-down\', 13)"></button>' +
    '<div class="min-w-0">' +
    '<span class="text-[14px] font-semibold text-head">Ungrouped</span>' +
    '<span class="text-[11px] text-faint ml-2">{{ pagesInGroup(null).length }}</span>' +
    '<p class="text-[12px] text-sub mt-0.5">Pages that have not been filed under a group.</p>' +
    '</div></div>' +
    '<ul v-show="!isGroupCollapsed(0)" class="divide-y divide-line" data-group-pages="">' +
    '<li v-for="p in pagesInGroup(null)" :key="p.id" :data-page="p.id" class="flex items-center gap-2 px-4 h-12">' +
    '<span v-if="canEdit" data-drag class="shrink-0 cursor-grab text-faint hover:text-sub" ' +
    'v-html="icon(\'grip-vertical\', 14)"></span>' +
    '<span class="shrink-0 text-faint" v-html="icon(\'file-lines\', 15)"></span>' +
    '<a :href="p.url" class="text-[13px] text-ink hover:text-brand truncate flex-1">{{ p.title }}</a>' +
    '<span v-if="canEdit" class="w-44 shrink-0">' +
    '<pb-combo :model-value="\'\'" :options="groupOptions()" dense ' +
    '@update:model-value="v => movePageToGroup(p, v)" placeholder="Ungrouped" /></span>' +
    '</li>' +
    '<li v-if="!pagesInGroup(null).length" class="px-4 h-12 flex items-center text-[12px] text-faint">' +
    'Everything is filed.</li>' +
    '</ul></div>' +

    '<div v-if="!groups.length" class="mt-3 rounded-xl border border-line px-6 py-10 text-center">' +
    '<p class="text-[13px] font-semibold text-head">No groups yet</p>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[420px] mx-auto">' +
    'Groups are sections inside this collection — "Getting started", "Reference", "Runbooks". ' +
    'The pages stay where they are; a group only says where they sit.</p>' +
    '<button v-if="canEdit" type="button" @click="openGroupForm(null)" ' +
    'class="mt-4 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    'Create a group</button>' +
    '</div>' +
    '</template>' +

    // ---- the group form ----
    '<pb-modal :open="groupForm.open" ' +
    ':title="groupForm.id ? \'Edit group\' : (groupForm.parentId ? \'New sub-group\' : \'New group\')" ' +
    'width="max-w-[520px]" @close="groupForm.open = false">' +
    '<p v-if="!groupForm.id && groupForm.parentId" class="text-[12px] text-sub mb-3">' +
    'This section will sit inside <b class="text-ink">{{ groupForm.parentName }}</b>.</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Group name</label>' +
    '<input v-model="groupForm.name" maxlength="120" class="pb-input" ' +
    ':class="{\'is-error\': groupForm.errors.name}" placeholder="Getting started" @keyup.enter="saveGroup" />' +
    '<p v-if="groupForm.errors.name" class="text-[12px] text-danger mt-1">{{ groupForm.errors.name[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Label <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="groupForm.label" maxlength="60" class="pb-input" placeholder="Internal" />' +
    '<p class="text-[12px] text-faint mt-1">A short tag shown beside the group name.</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Short description <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="groupForm.short_description" maxlength="200" class="pb-input" ' +
    'placeholder="One line, shown under the group name." />' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Long description <span class="text-faint font-normal">(optional)</span></label>' +
    '<textarea v-model="groupForm.long_description" rows="4" maxlength="5000" class="pb-textarea" ' +
    'placeholder="What belongs in this group, and what does not."></textarea>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="groupForm.open = false">Cancel</button>' +
    '<button type="button" :disabled="!groupForm.name.trim() || groupForm.busy" @click="saveGroup" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ groupForm.busy ? \'Saving…\' : \'Save group\' }}</button>' +
    '</template></pb-modal>' +

    // ---- the public address ----
    '<div v-if="canManage" class="mt-8 mb-[150px] rounded-xl border border-line p-5">' +
    '<h2 class="text-[13px] font-semibold text-head">Public URL</h2>' +

    '<template v-if="publicUrl">' +
    '<div class="mt-2 flex items-center gap-2 flex-wrap">' +
    '<code class="text-[12px] text-ink bg-hover border border-line rounded px-2 py-1.5 truncate max-w-full">{{ publicUrl }}</code>' +
    '<button type="button" @click="copyPublicUrl" ' +
    'class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">Copy</button>' +
    '<a v-if="collection.published" :href="publicUrl" target="_blank" rel="noopener" ' +
    'class="h-8 px-3 grid place-items-center rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">Open</a>' +
    '</div>' +
    // Said once, plainly, where the decision was made.
    '<p class="text-[12px] text-faint mt-1.5">This address is permanent and cannot be changed.</p>' +
    '<p v-if="!collection.published" class="text-[12px] text-amber-700 mt-1">' +
    'Not live yet — set the status to Published.</p>' +
    '</template>' +

    '<template v-else>' +
    '<p class="text-[12px] text-sub mt-1 max-w-[560px]">' +
    'Give this collection an address anyone can open, without signing in. ' +
    'It is generated once from the name and cannot be changed afterwards.</p>' +
    '<button type="button" @click="generatePublicUrl" :disabled="statusBusy || isPrivate" ' +
    'class="mt-2 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    'Generate public URL</button>' +
    '<p v-if="isPrivate" class="text-[12px] text-amber-700 mt-1.5">' +
    'A private collection cannot be published. Make it public first.</p>' +
    '</template>' +
    '</div>' +


    // ---- delete the collection ----
    // The name is typed, not a button pressed, and the dialog names what goes with it. The
    // pages inside are documents somebody wrote; a click is too small a gesture for that.
    '<pb-modal :open="deleteConfirm.open" title="Delete collection?" width="max-w-[480px]" ' +
    '@close="deleteConfirm.open = false">' +
    '<p class="text-[13px] text-sub leading-relaxed">' +
    'This permanently deletes <b class="text-ink">{{ collection.name }}</b>, every page in it, ' +
    'its groups and its access list. This cannot be undone.</p>' +
    '<p class="text-[13px] text-sub mt-2">' +
    'To take it out of the lists and keep everything, ' +
    '<button type="button" @click="deleteConfirm.open = false; toggleArchive()" ' +
    'class="text-brand font-semibold hover:underline">archive it instead</button>.</p>' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">' +
    'Type <b>{{ collection.name }}</b> to confirm</label>' +
    '<input v-model="deleteConfirm.text" class="pb-input" @keyup.enter="deleteCollection" />' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="deleteConfirm.open = false">Cancel</button>' +
    '<button type="button" :disabled="!canDeleteCollection || deleteConfirm.busy" @click="deleteCollection" ' +
    'class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50">' +
    '{{ deleteConfirm.busy ? \'Deleting…\' : \'Delete collection\' }}</button>' +
    '</template></pb-modal>' +

    // ---- edit the collection itself ----
    '<pb-modal :open="editCollection.open" title="Edit collection" width="max-w-[520px]" @close="editCollection.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input v-model="editCollection.name" maxlength="120" class="pb-input" ' +
    ':class="{\'is-error\': editCollection.errors.name}" @keyup.enter="saveCollection" />' +
    '<p v-if="editCollection.errors.name" class="text-[12px] text-danger mt-1">{{ editCollection.errors.name[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description <span class="text-faint font-normal">(optional)</span></label>' +
    '<textarea v-model="editCollection.description" rows="2" class="pb-textarea" ' +
    'placeholder="What belongs in this collection?"></textarea>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Who can see it</label>' +
    '<div class="grid gap-2">' +
    '<label v-for="v in visibilities" :key="v.value" ' +
    'class="flex items-start gap-2.5 p-3 rounded-lg border cursor-pointer hover:bg-hover" ' +
    ':class="editCollection.visibility === v.value ? \'border-brand/40 bg-sel/40\' : \'border-stroke\'">' +
    '<input type="radio" :value="v.value" v-model="editCollection.visibility" class="mt-0.5 accent-brand" />' +
    '<span class="min-w-0"><span class="block text-[13px] font-semibold text-ink">{{ v.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ v.desc }}</span></span></label>' +
    '</div>' +
    // Making a public collection private closes it to everyone not named on it — worth saying
    // before the click, not after.
    '<p v-if="editCollection.visibility === \'private\' && collection.visibility === \'public\'" ' +
    'class="text-[12px] text-amber-700 mt-2">' +
    'Making this private will close it to everyone except the people you invite.</p>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="editCollection.open = false">Cancel</button>' +
    '<button type="button" :disabled="!editCollection.name.trim() || editCollection.busy" @click="saveCollection" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ editCollection.busy ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ---- edit one row ----
    '<pb-modal :open="editing.open" title="Edit page" width="max-w-[480px]" @close="editing.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Page name</label>' +
    '<input v-model="editing.title" maxlength="200" class="pb-input" ' +
    ':class="{\'is-error\': editing.errors.title}" @keyup.enter="saveEdit" />' +
    '<p v-if="editing.errors.title" class="text-[12px] text-danger mt-1">{{ editing.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Nested under</label>' +
    // Searchable — a collection can hold a lot of pages, and scrolling a list to find the one
    // to nest under is the part that stops being workable first.
    '<pb-combo v-model="editing.parentId" :options="parentOptions(editing.id)" ' +
    'placeholder="Top level" />' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Labels</label>' +
    '<div v-if="labels.length" class="flex flex-wrap gap-1.5">' +
    '<button v-for="l in labels" :key="l.id" type="button" @click="toggleLabel(l.id)" ' +
    ':aria-pressed="String(isLabelOn(l.id))" ' +
    'class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-md text-[12px] border transition-colors" ' +
    ':style="isLabelOn(l.id) ? { color: l.color, background: l.color + \'1a\', borderColor: l.color } : {}" ' +
    ':class="isLabelOn(l.id) ? \'\' : \'border-stroke text-ink hover:bg-hover\'">' +
    '<span class="h-1.5 w-1.5 rounded-full" :style="{ background: l.color }"></span>{{ l.name }}</button>' +
    '</div>' +
    '<p v-else class="text-[12px] text-sub">No wiki labels yet. They are managed in Settings &rarr; Wiki.</p>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="editing.open = false">Cancel</button>' +
    '<button type="button" :disabled="!editing.title.trim() || editing.busy" @click="saveEdit" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ editing.busy ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ---- name it, then open it ----
    '<pb-modal :open="adding.open" :title="adding.parentId ? \'Add sub-page\' : \'Add page\'" ' +
    'width="max-w-[460px]" @close="adding.open = false">' +
    '<p v-if="adding.parentId" class="text-[12px] text-sub mb-3">' +
    'This page will sit underneath <b class="text-ink">{{ adding.parentTitle }}</b>.</p>' +
    '<p v-if="adding.groupId" class="text-[12px] text-sub -mt-1 mb-3">' +
    'This page will be filed under <b class="text-ink">{{ adding.groupName }}</b>.</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Page name</label>' +
    '<input v-model="adding.title" maxlength="200" placeholder="Escalation process" @keyup.enter="createPage" ' +
    'class="pb-input" :class="{\'is-error\': adding.errors.title}" />' +
    '<p v-if="adding.errors.title" class="text-[12px] text-danger mt-1">{{ adding.errors.title[0] }}</p>' +
    '<p class="text-[12px] text-faint mt-2">You can write the content once the page opens.</p>' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="adding.open = false">Cancel</button>' +
    '<button type="button" :disabled="!adding.title.trim() || adding.busy" @click="createPage" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ adding.busy ? \'Creating…\' : \'Continue\' }}</button>' +
    '</template></pb-modal>' +

    // ---- invite modal ----
    '<pb-modal :open="invite.open" title="Add people" width="max-w-[460px]" @close="invite.open = false">' +
    '<template v-if="candidateOptions.length">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Person</label>' +
    '<pb-combo v-model="invite.userId" :options="candidateOptions" placeholder="Choose someone" />' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">What they can do</label>' +
    '<div class="grid gap-2">' +
    '<label v-for="p in permissionOptions" :key="p.value" ' +
    'class="flex items-start gap-2.5 p-3 rounded-lg border cursor-pointer hover:bg-hover" ' +
    ':class="invite.permission === p.value ? \'border-brand/40 bg-sel/40\' : \'border-stroke\'">' +
    '<input type="radio" :value="p.value" v-model="invite.permission" class="mt-0.5 accent-brand" />' +
    '<span class="min-w-0"><span class="block text-[13px] font-semibold text-ink">{{ p.label }}</span>' +
    '<span class="block text-[12px] text-sub">{{ p.desc }}</span></span></label>' +
    '</div>' +
    '</template>' +
    '<p v-else class="text-[13px] text-sub">Everyone in this workspace already has access.</p>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="invite.open = false">Cancel</button>' +
    '<button v-if="candidateOptions.length" type="button" :disabled="!canInvite" @click="addMember" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ invite.saving ? \'Adding…\' : \'Add\' }}</button>' +
    '</template></pb-modal>' +
    '</div></div>'
}, { root: 'wiki-collection-root' });
