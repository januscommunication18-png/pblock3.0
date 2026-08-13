/* Project Workspace › Views (Views §5–§12).
   ------------------------------------------------------------------
   One Vue root for two screens, chosen by `viewId` in the bootstrap: the listing, or one
   View's grid. Same arrangement as Pages, and for the same reason — they share the create
   dialog, the toasts and the project chrome, and a View has a real URL rather than being a
   mode of the list.

   The grid itself is <view-grid>. Everything here is around it: the listing, creating and
   renaming, the two-card column configuration, the field selector, and the cell pickers.
   ------------------------------------------------------------------ */

var ViewsScreen = {
  components: { 'view-grid': ViewGrid, 'wi-calendar': WiCalendar },

  // PB.boot passes the server payload as a PROP, not on $root.$options — reading it from the
  // wrong place silently yields an empty object, and every default below wins. That presents
  // as a screen with no data and no buttons rather than as an error.
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      project: b.project || {},
      views: b.views || [],
      viewId: b.viewId || null,
      columns: b.columns || [],
      frozen: b.frozen || 0,
      fields: b.fields || [],
      sortable: b.sortable || [],
      densities: b.densities || [],
      visibilities: b.visibilities || [],
      options: b.options || {},
      nameMax: b.nameMax || 120,
      canCreate: !!b.canCreate,
      external: !!b.external,
      externalUrl: b.externalUrl || '',
      canEditView: !!b.canEditView,
      canEditData: !!b.canEditData,
      endpoints: b.endpoints || {},

      // ---- the grid ----
      // The grid holds the current page; this copy exists so a cell edit can put the updated
      // row back into the one on screen without a refetch.
      rows: [],
      pageSize: b.pageSize || 100,
      loading: false,
      search: '',
      sort: '',
      dir: 'asc',

      // ---- dialogs ----
      create: { open: false, name: '', visibility: '', busy: false, error: '' },
      rename: { open: false, id: null, name: '', busy: false, error: '' },
      confirm: { open: false, view: null, busy: false },
      config: { open: false },
      picker: { open: false, group: '', query: '' },
      cell: { open: false, key: '', row: null, style: {}, query: '' },
      menu: { open: false, id: null, style: {} },
      // The work item detail, as a slide-over over the grid (§7.3). It holds an id, not an
      // item: the panel embeds the work item's own page, so the detail is the real drawer.
      panel: { open: false, id: null },
      drag: null
    };
  },

  computed: {
    view: function () {
      var id = this.viewId;

      return id ? this.views.filter(function (v) { return String(v.id) === String(id); })[0] || null : null;
    },
    fixedColumns: function () {
      return this.columns.filter(function (c) { return c.position === 'fixed'; });
    },
    scrollColumns: function () {
      return this.columns.filter(function (c) { return c.position === 'scroll'; });
    },
    /** Where a work item lives, e.g. `/projects/1/work-items`. */
    itemUrl: function () {
      return (this.endpoints.list || '').replace('/views', '/work-items');
    },
    /** The panel's source: the work item's detail page, without the app chrome. */
    panelSrc: function () {
      return this.panel.id ? this.itemUrl + '/' + this.panel.id + '/frame' : '';
    },
    rowHeight: function () {
      var key = (this.view && this.view.density) || 'standard';
      var match = this.densities.filter(function (d) { return d.key === key; })[0];

      // 44 matches the work items list, which is also what `standard` is configured as —
      // the fallback only fires if the density list failed to arrive.
      return match ? match.row_height : 44;
    },
    /** The field selector's groups, narrowed by the search box. */
    fieldGroups: function () {
      var q = this.picker.query.trim().toLowerCase();
      if (!q) return this.fields;

      return this.fields.map(function (g) {
        return Object.assign({}, g, {
          fields: g.fields.filter(function (f) { return f.label.toLowerCase().indexOf(q) > -1; })
        });
      }).filter(function (g) { return g.fields.length; });
    },
    /** The column the open cell picker belongs to. */
    cellColumn: function () {
      var key = this.cell.key;

      return this.columns.filter(function (c) { return c.key === key; })[0] || null;
    },
    /** The choices for the open cell picker, from the shared option lists. */
    cellChoices: function () {
      var col = this.cellColumn;
      if (!col) return [];

      var o = this.options;
      var list = {
        'work_item.state': (o.states || []).map(function (s) { return { id: s.id, label: s.name, color: s.color, group: s.group }; }),
        'work_item.priority': (o.priorities || []).map(function (p) { return { id: p.key, label: p.label }; }),
        'member.assignees': (o.members || []).map(function (m) { return { id: m.id, label: m.name, person: m }; }),
        'label.labels': (o.labels || []).map(function (l) { return { id: l.id, label: l.name, color: l.color }; }),
        'cycle.name': (o.cycles || []).map(function (c) { return { id: c.id, label: c.name }; }),
        'epic.title': (o.epics || []).map(function (e) { return { id: e.id, label: e.title }; }),
        'module.title': (o.modules || []).map(function (m) { return { id: m.id, label: m.title }; }),
        'estimate.value': (o.estimates || []).map(function (e) { return { id: e.id, label: e.label }; })
      }[col.key] || [];

      var q = this.cell.query.trim().toLowerCase();

      return q ? list.filter(function (i) { return String(i.label).toLowerCase().indexOf(q) > -1; }) : list;
    },
    /** Multi-value cells keep the picker open and show ticks; single-value ones close on pick. */
    cellIsMulti: function () {
      return ['label.labels', 'module.title'].indexOf(this.cell.key) > -1;
    },
    cellSelected: function () {
      var row = this.cell.row;
      if (!row) return [];

      var from = {
        'work_item.state': row.state ? [row.state.id] : [],
        'work_item.priority': row.priority ? [row.priority] : [],
        'member.assignees': (row.assignees || []).map(function (a) { return a.id; }),
        'label.labels': (row.labels || []).map(function (l) { return l.id; }),
        'cycle.name': row.cycle ? [row.cycle.id] : [],
        'epic.title': row.epic ? [row.epic.id] : [],
        'module.title': (row.modules || []).map(function (m) { return m.id; }),
        'estimate.value': row.estimate ? [row.estimate.id] : []
      }[this.cell.key] || [];

      return from.map(String);
    }
  },

  mounted: function () {
    // No first load here: <view-grid> asks for page 1 as soon as DataTables builds itself, so
    // kicking one off from this side would be a duplicate request for the same page.
    document.addEventListener('click', this.onGlobalClick, true);
    document.addEventListener('keydown', this.onGlobalKey);
  },
  beforeUnmount: function () {
    document.removeEventListener('click', this.onGlobalClick, true);
    document.removeEventListener('keydown', this.onGlobalKey);
  },

  methods: {
    // ================= listing (§5) =================
    openCreate: function () {
      this.create = {
        open: true, name: '', busy: false, error: '',
        visibility: (this.visibilities[0] || {}).key || 'private'
      };
      var self = this;
      this.$nextTick(function () { if (self.$refs.createName) self.$refs.createName.focus(); });
    },

    submitCreate: async function () {
      if (this.create.busy) return;
      var name = this.create.name.trim();
      if (!name) { this.create.error = 'Give the view a name.'; return; }

      this.create.busy = true;
      this.create.error = '';

      try {
        var resp = await this.$pb.api(this.endpoints.store, {
          method: 'POST',
          body: { name: name, visibility: this.create.visibility }
        });
        // Straight into the new View: the user asked for a view of their data, not for a row
        // in a list. Its columns and rows come from the server on that page.
        window.location.href = resp.view.url;
      } catch (e) {
        this.create.busy = false;
        this.create.error = this.$pb.firstError(e, 'Could not create the view.');
      }
    },

    openMenu: function (view, el) {
      var r = el.getBoundingClientRect();
      this.menu = {
        open: true, id: view.id,
        style: { position: 'fixed', top: (r.bottom + 6) + 'px', left: Math.max(8, r.right - 200) + 'px', width: '200px', zIndex: 120 }
      };
    },

    openRename: function (view) {
      this.menu.open = false;
      this.rename = { open: true, id: view.id, name: view.name, busy: false, error: '' };
      var self = this;
      this.$nextTick(function () { if (self.$refs.renameName) self.$refs.renameName.select(); });
    },

    submitRename: async function () {
      if (this.rename.busy) return;
      var name = this.rename.name.trim();
      if (!name) { this.rename.error = 'Give the view a name.'; return; }

      this.rename.busy = true;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.update, this.rename.id), {
          method: 'PATCH', body: { name: name }
        });
        this.replaceView(resp.view);
        this.rename.open = false;
        this.$pb.toast(resp.message);
      } catch (e) {
        this.rename.busy = false;
        this.rename.error = this.$pb.firstError(e, 'Could not rename the view.');
      }
    },

    duplicate: async function (view) {
      this.menu.open = false;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.duplicate, view.id), { method: 'POST' });
        this.views.unshift(resp.view);
        this.$pb.toast(resp.message);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e, 'Could not duplicate the view.')); }
    },

    toggleFavorite: async function (view) {
      // Optimistic: a star is instant feedback, and rolling one back is reliable.
      var before = view.favorite;
      view.favorite = !before;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.favorite, view.id), { method: 'POST' });
        view.favorite = resp.favorite;
      } catch (e) { view.favorite = before; }
    },

    askDelete: function (view) {
      this.menu.open = false;
      this.confirm = { open: true, view: view, busy: false };
    },

    confirmDelete: async function () {
      if (this.confirm.busy || !this.confirm.view) return;
      this.confirm.busy = true;
      var view = this.confirm.view;

      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.destroy, view.id), { method: 'DELETE' });

        // Deleting the View that is open has nowhere to stay — go back to the listing.
        if (String(this.viewId) === String(view.id)) { window.location.href = this.endpoints.list; return; }

        this.views = this.views.filter(function (v) { return String(v.id) !== String(view.id); });
        this.confirm.open = false;
        this.$pb.toast('View deleted.');
      } catch (e) {
        this.confirm.busy = false;
        this.$pb.toast(this.$pb.firstError(e, 'Could not delete the view.'));
      }
    },

    setDensity: async function (key) {
      if (!this.view) return;
      var before = this.view.density;
      this.view.density = key;

      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.update, this.viewId), {
          method: 'PATCH', body: { density: key }
        });
      } catch (e) {
        this.view.density = before;
        this.$pb.toast(this.$pb.firstError(e, 'Could not change the density.'));
      }
    },

    replaceView: function (card) {
      for (var i = 0; i < this.views.length; i++) {
        if (String(this.views[i].id) === String(card.id)) { this.views.splice(i, 1, card); return; }
      }
      this.views.unshift(card);
    },

    // ================= rows (§7, §24) =================
    /**
     * Fetch one page of rows, for the grid.
     *
     * The grid asks; this answers. It is passed to <view-grid> as a function prop rather than
     * the grid being given the URL, so the endpoints, the CSRF handling and what a failure
     * says to the user all stay in one place — the grid only knows "a page of rows".
     */
    fetchRows: async function (params) {
      this.loading = true;

      var url = this.$pb.withId(this.endpoints.rows, this.viewId) +
        '?page=' + params.page +
        '&q=' + encodeURIComponent(params.search || '') +
        '&sort=' + encodeURIComponent(params.sort || '') +
        '&dir=' + encodeURIComponent(params.dir || 'asc');

      try {
        var resp = await this.$pb.api(url);
        this.rows = resp.rows;

        return { rows: resp.rows, total: resp.total };
      } catch (e) {
        this.rows = [];
        this.$pb.toast(this.$pb.firstError(e, 'Could not load the rows.'));

        throw e;
      } finally {
        this.loading = false;
      }
    },

    /** Ask the grid to fetch again. `reset` goes back to page 1 — a new search or sort. */
    reloadRows: function (reset) {
      if (this.$refs.grid) this.$refs.grid.reload(reset !== false);
    },

    /** §24: debounced, so typing does not fire a query per keystroke. */
    onSearch: function () {
      clearTimeout(this._searchTimer);
      var self = this;
      this._searchTimer = setTimeout(function () { self.reloadRows(true); }, 300);
    },

    /** A sortable header was clicked (§12.3). The grid tells us which column. */
    onSort: function (key) {
      if (!key) return;

      // Same column toggles direction; a different one starts ascending, which is what a
      // spreadsheet does and therefore what people expect.
      this.dir = this.sort === key && this.dir === 'asc' ? 'desc' : 'asc';
      this.sort = key;
      this.reloadRows(true);
    },

    /**
     * Leave the full-page view.
     *
     * Closing a tab the user opened themselves is not something a page may do — `window.close`
     * only works on a window script opened. So it falls back to the View's own URL, which is
     * the honest meaning of "close this full-page thing": you end up back where it came from.
     */
    closeExternal: function () {
      var back = this.endpoints.open.replace('__ID__', this.viewId);

      try {
        window.close();
      } catch (e) { /* not a script-opened window */ }

      // Still here a beat later means the close was refused.
      var self = this;
      setTimeout(function () { window.location.href = back; }, 50);
    },

    /**
     * Open a work item beside the grid (§7.3).
     *
     * The panel embeds the item's own detail page rather than rebuilding it here. The drawer
     * is 700-odd lines of the Work Items screen and drives most of that screen's methods —
     * nine chip pickers, the rich-text editor, sub-items, dependencies, relations, links and
     * five collaboration tabs. A second copy in this file would be a copy that drifts. What
     * the panel shows IS the drawer, in the same `pageMode` the per-item URL already renders.
     */
    openRow: function (row) {
      this.panel = { open: true, id: row.id };
    },

    /**
     * Close it, and re-read the page underneath.
     *
     * Anything could have changed in there — a state, a title, an assignee — and the grid has
     * no way to hear about it across the frame boundary. Re-reading the page the user is on
     * costs one request at the moment they are looking away from the rows.
     */
    closePanel: function () {
      if (!this.panel.open) return;

      this.panel = { open: false, id: null };
      this.reloadRows(false);
    },

    // ================= inline editing (§11) =================
    openCell: function (payload) {
      var r = payload.el.getBoundingClientRect();
      // The calendar needs the room a month grid needs; 260 crushes it to unreadable columns.
      // Same 300 the Work Items list gives its date menus, for the same picker.
      var dated = ['work_item.start_date', 'work_item.due_date'].indexOf(payload.key) > -1;
      var width = dated ? 300 : 260;

      this.cell = {
        open: true, key: payload.key, row: payload.row, query: '',
        style: {
          position: 'fixed', width: width + 'px', zIndex: 130,
          left: Math.max(8, Math.min(r.left, window.innerWidth - width - 8)) + 'px',
          top: (r.bottom + 4) + 'px'
        }
      };
    },

    /**
     * Write one cell (§11.4).
     *
     * The column is named in the payload so the server can check that the submitted field is
     * the one that column writes — the client naming a column is not permission, it is what
     * the server checks the payload against.
     */
    commitCell: async function (choice) {
      var column = this.cellColumn;
      var row = this.cell.row;
      if (!column || !row) return;

      var body = { column_id: column.id };
      var writes = {
        'work_item.state': 'state_id', 'work_item.priority': 'priority',
        'member.assignees': 'assignee_ids', 'label.labels': 'label_ids',
        'cycle.name': 'cycle_id', 'epic.title': 'epic_id',
        'module.title': 'module_ids', 'estimate.value': 'estimate_value_id',
        'work_item.title': 'title', 'work_item.start_date': 'start_date',
        'work_item.due_date': 'due_date'
      }[column.key];

      if (!writes) return;

      if (this.cellIsMulti) {
        var current = this.cellSelected.slice();
        var id = String(choice.id);
        var at = current.indexOf(id);
        if (at > -1) current.splice(at, 1); else current.push(id);
        body[writes] = current;
      } else if (writes === 'assignee_ids') {
        // One owner per work item, so picking replaces rather than adds.
        body[writes] = this.cellSelected.indexOf(String(choice.id)) > -1 ? [] : [choice.id];
      } else {
        // Picking the value already there clears it, which is how the row chips behave.
        body[writes] = this.cellSelected.indexOf(String(choice.id)) > -1 ? null : choice.id;
        if (writes === 'priority' && body[writes] === null) body[writes] = 'none';
      }

      if (!this.cellIsMulti) this.cell.open = false;

      await this.writeCell(row, body);
    },

    commitCellText: async function (value) {
      var column = this.cellColumn;
      var row = this.cell.row;
      if (!column || !row) return;

      var writes = column.key === 'work_item.title' ? 'title'
        : column.key === 'work_item.start_date' ? 'start_date'
          : column.key === 'work_item.due_date' ? 'due_date' : null;

      if (!writes) return;

      this.cell.open = false;
      var body = { column_id: column.id };
      body[writes] = value === '' ? null : value;

      await this.writeCell(row, body);
    },

    writeCell: async function (row, body) {
      try {
        var resp = await this.$pb.api(
          this.$pb.withId(this.endpoints.cell, this.viewId).replace('__ITEM__', row.id),
          { method: 'PATCH', body: body }
        );

        // §11.4: the whole row comes back and replaces the one on screen, so a change that
        // moved something else on the row — a blocked count, a parent chip — shows too.
        this.replaceRow(resp.row);
      } catch (e) {
        // §11.4's revert: the row on screen was never optimistically changed, so re-reading
        // the page is the revert. Re-read the page the user is ON, not page 1 — losing their
        // place is not part of undoing a rejected edit.
        this.$pb.toast(this.$pb.firstError(e, 'That change was not saved.'));
        this.reloadRows(false);
      }
    },

    replaceRow: function (row) {
      for (var i = 0; i < this.rows.length; i++) {
        if (String(this.rows[i].id) !== String(row.id)) continue;
        this.rows.splice(i, 1, row);
        // One row redraw keeps the scroll position; a full replace would jump the grid back
        // to the top after every cell edit.
        if (this.$refs.grid) this.$refs.grid.updateRow(row);

        return;
      }
    },

    // ================= column configuration (§8, §9, §10) =================
    addColumn: async function (field) {
      if (!field.available || field.used) return;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.columns, this.viewId), {
          method: 'POST', body: { key: field.key, position: 'scroll' }
        });
        this.applyColumns(resp);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e, 'Could not add the column.')); }
    },

    removeColumn: async function (column) {
      try {
        var url = this.$pb.withId(this.endpoints.column, this.viewId).replace('__COLUMN__', column.id);
        var resp = await this.$pb.api(url, { method: 'DELETE' });
        this.applyColumns(resp);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e, 'Could not remove the column.')); }
    },

    /**
     * Persist a width the user dragged to (§10).
     *
     * The grid is ALREADY showing it — Tabulator owns the column while the pointer is on its
     * edge. So the response is not applied back: the local column's width is updated in place
     * and nothing else, because handing the whole column set back would rebuild the grid from
     * a value it already had, mid-drag.
     *
     * The width is excluded from the grid's rebuild key for the same reason, so this
     * assignment cannot trigger one.
     */
    setColumnWidth: async function (payload) {
      var column = this.columns.filter(function (c) { return String(c.id) === String(payload.id); })[0];
      var previous = column ? column.width : null;

      if (column) column.width = payload.width;

      try {
        var url = this.$pb.withId(this.endpoints.column, this.viewId).replace('__COLUMN__', payload.id);
        await this.$pb.api(url, { method: 'PATCH', body: { width: payload.width } });
      } catch (e) {
        // Refused — most likely the bounds in UpdateViewColumnRequest. Put the stored width
        // back so the grid is not showing one the server did not accept.
        if (column) column.width = previous;
        this.$pb.toast(this.$pb.firstError(e, 'That column width could not be saved.'));
      }
    },

    /** Drag within or between the two cards (§8.3). */
    onDragStart: function (column) { this.drag = column; },
    onDrop: function (position, target) {
      var moved = this.drag;
      this.drag = null;
      if (!moved) return;

      var fixed = this.fixedColumns.map(function (c) { return c.id; });
      var scroll = this.scrollColumns.map(function (c) { return c.id; });

      fixed = fixed.filter(function (id) { return id !== moved.id; });
      scroll = scroll.filter(function (id) { return id !== moved.id; });

      var into = position === 'fixed' ? fixed : scroll;
      var at = target ? into.indexOf(target.id) : -1;
      if (at > -1) into.splice(at, 0, moved.id); else into.push(moved.id);

      this.saveOrder(fixed, scroll);
    },

    saveOrder: async function (fixed, scroll) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.columnOrder, this.viewId), {
          method: 'PUT', body: { fixed: fixed, scroll: scroll }
        });
        this.applyColumns(resp);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e, 'Could not save the column order.')); }
    },

    applyColumns: function (resp) {
      if (resp.columns) this.columns = resp.columns;
      if (typeof resp.frozen === 'number') this.frozen = resp.frozen;
      if (resp.fields) this.fields = resp.fields;
      if (resp.message) this.$pb.toast(resp.message);
    },

    onGlobalClick: function (e) {
      if (this.menu.open && !e.target.closest('[data-menu]')) this.menu.open = false;
      if (this.cell.open && !e.target.closest('[data-cellmenu]') && !e.target.closest('[data-cell]')) this.cell.open = false;
    },

    /**
     * Escape closes the innermost thing that is open.
     *
     * The panel is last, so a picker opened over it closes first — Escape never pulls the
     * whole detail out from under a menu the user was aiming at.
     *
     * Note that this cannot see an Escape pressed INSIDE the panel: key events do not cross a
     * frame boundary. The panel's own screen handles that case, and the close button and the
     * backdrop are always reachable from this side.
     */
    onGlobalKey: function (e) {
      if (e.key !== 'Escape') return;

      if (this.menu.open) { this.menu.open = false; return; }
      if (this.cell.open) { this.cell.open = false; return; }
      if (this.panel.open) this.closePanel();
    },

    icon: function (name, size, cls) { return wiIcon(name, size || 16, cls || ''); },
    // The shared formatter from date-picker.js, the one the Work Items list uses. There was a
    // second one living in view-grid.js until it started showing the same date two ways.
    when: function (value) { return wiFmtDate(value); }
  },

  template: '' +
  '<div class="flex-1 min-h-0 flex flex-col">' +

    // ---------- LISTING (§5) ----------
    '<template v-if="!viewId">' +
      '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line shrink-0">' +
        '<h1 class="text-[15px] font-semibold text-head">Views</h1>' +
        '<span class="text-[12px] text-sub">{{ views.length }}</span>' +
        '<button v-if="canCreate" type="button" @click="openCreate"' +
          ' class="ml-auto inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
          '<span v-html="icon(\'plus\', 15)"></span>New view</button>' +
      '</div>' +

      '<div v-if="!views.length" class="flex-1 grid place-items-center px-6">' +
        '<div class="max-w-[420px] text-center">' +
          '<div class="mx-auto h-11 w-11 rounded-xl bg-hover grid place-items-center text-sub" v-html="icon(\'table\', 20)"></div>' +
          '<h2 class="mt-3 text-[15px] font-semibold text-head">No views yet</h2>' +
          '<p class="mt-1 text-[13px] text-sub">A view is a spreadsheet of this project\'s work items — you choose the columns, which of them stay pinned while you scroll, and edit the data in place.</p>' +
          '<button v-if="canCreate" type="button" @click="openCreate"' +
            ' class="mt-4 inline-flex items-center gap-1.5 h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
            '<span v-html="icon(\'plus\', 15)"></span>Create view</button>' +
        '</div>' +
      '</div>' +

      '<div v-else class="flex-1 min-h-0 overflow-y-auto">' +
        '<table class="w-full text-[13px]">' +
          '<thead class="sticky top-0 bg-white border-b border-line">' +
            '<tr class="text-left text-[11px] uppercase tracking-wide text-faint">' +
              '<th class="font-semibold px-5 sm:px-6 py-2.5">Name</th>' +
              '<th class="font-semibold px-3 py-2.5 hidden sm:table-cell">Visibility</th>' +
              '<th class="font-semibold px-3 py-2.5 hidden md:table-cell">Owner</th>' +
              '<th class="font-semibold px-3 py-2.5 hidden lg:table-cell">Dataset</th>' +
              '<th class="font-semibold px-3 py-2.5 hidden md:table-cell">Modified</th>' +
              '<th class="w-10"></th>' +
            '</tr>' +
          '</thead>' +
          '<tbody>' +
            '<tr v-for="v in views" :key="v.id" class="border-b border-line hover:bg-hover">' +
              '<td class="px-5 sm:px-6 py-2.5">' +
                '<div class="flex items-center gap-2 min-w-0">' +
                  '<button type="button" @click="toggleFavorite(v)" class="h-6 w-6 grid place-items-center rounded shrink-0"' +
                    ' :class="v.favorite ? \'text-amber-500\' : \'text-faint hover:text-sub\'"' +
                    ' :aria-label="v.favorite ? \'Unfavorite\' : \'Favorite\'"' +
                    ' v-html="icon(\'star\', 15)"></button>' +
                  '<a :href="v.url" class="font-medium text-ink hover:text-brand truncate">{{ v.name }}</a>' +
                '</div>' +
              '</td>' +
              '<td class="px-3 py-2.5 hidden sm:table-cell text-sub">{{ v.visibility_label }}</td>' +
              '<td class="px-3 py-2.5 hidden md:table-cell text-sub">{{ v.owner ? v.owner.name : \'—\' }}</td>' +
              '<td class="px-3 py-2.5 hidden lg:table-cell text-sub">{{ v.dataset_label }}</td>' +
              '<td class="px-3 py-2.5 hidden md:table-cell text-sub">{{ when(v.updated_at) }}</td>' +
              '<td class="px-2 py-2.5 text-right">' +
                '<button type="button" data-menu @click.stop="openMenu(v, $event.currentTarget)"' +
                  ' class="h-7 w-7 grid place-items-center rounded text-faint hover:bg-line hover:text-ink"' +
                  ' aria-label="View actions" v-html="icon(\'ellipsis\', 16)"></button>' +
              '</td>' +
            '</tr>' +
          '</tbody>' +
        '</table>' +
      '</div>' +
    '</template>' +

    // ---------- GRID (§7, §12) ----------
    '<template v-else>' +
      '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line shrink-0">' +
        // External mode keeps only what a page with no navigation needs: a way back, and a
        // way out. Everything else here belongs to the app shell this page does not have.
        '<a :href="external ? endpoints.open.replace(\'__ID__\', viewId) : endpoints.list"' +
          ' class="h-7 w-7 grid place-items-center rounded text-faint hover:bg-hover hover:text-ink shrink-0"' +
          ' :aria-label="external ? \'Back to the view\' : \'Back to views\'" v-html="icon(\'chevron-left\', 16)"></a>' +
        '<h1 class="text-[15px] font-semibold text-head truncate">{{ view ? view.name : \'View\' }}</h1>' +
        '<span v-if="total" class="text-[12px] text-sub shrink-0">{{ total }}</span>' +

        '<div v-if="external" class="ml-auto flex items-center gap-1.5">' +
          '<div class="relative">' +
            '<span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" v-html="icon(\'magnifying-glass\', 14)"></span>' +
            '<input v-model="search" @input="onSearch" type="search" placeholder="Search"' +
              ' class="h-8 w-40 sm:w-56 rounded-md bg-hover pl-8 pr-2 text-[13px] outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
          '</div>' +
          '<button type="button" @click="closeExternal"' +
            ' class="h-8 w-8 grid place-items-center rounded-md border border-line text-sub hover:bg-hover"' +
            ' aria-label="Close" v-html="icon(\'xmark\', 16)"></button>' +
        '</div>' +

        '<div v-else class="ml-auto flex items-center gap-1.5">' +
          '<div class="relative">' +
            '<span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" v-html="icon(\'magnifying-glass\', 14)"></span>' +
            '<input v-model="search" @input="onSearch" type="search" placeholder="Search"' +
              ' class="h-8 w-40 sm:w-56 rounded-md bg-hover pl-8 pr-2 text-[13px] outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
          '</div>' +
          '<select :value="view ? view.density : \'standard\'" @change="setDensity($event.target.value)"' +
            ' class="h-8 rounded-md border border-line bg-white px-2 text-[12px] text-sub" aria-label="Row density">' +
            '<option v-for="d in densities" :key="d.key" :value="d.key">{{ d.label }}</option>' +
          '</select>' +
          '<button type="button" @click="reloadRows(false)" class="h-8 w-8 grid place-items-center rounded-md border border-line text-sub hover:bg-hover"' +
            ' aria-label="Refresh" v-html="icon(\'rotate-right\', 15)"></button>' +
          '<button v-if="canEditView" type="button" @click="config.open = !config.open"' +
            ' class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-line text-[13px] text-ink hover:bg-hover"' +
            ' :class="config.open ? \'bg-sel border-brand text-brand\' : \'\'">' +
            '<span v-html="icon(\'columns\', 15)"></span>Columns</button>' +
          '<a v-if="externalUrl" :href="externalUrl"' +
            ' class="h-8 w-8 grid place-items-center rounded-md border border-line text-sub hover:bg-hover"' +
            ' data-tip="Open as a full page" aria-label="Open as a full page"' +
            ' v-html="icon(\'arrow-up-right-from-square\', 15)"></a>' +
          '<button type="button" data-menu @click.stop="openMenu(view, $event.currentTarget)"' +
            ' class="h-8 w-8 grid place-items-center rounded-md border border-line text-sub hover:bg-hover"' +
            ' aria-label="View actions" v-html="icon(\'ellipsis\', 16)"></button>' +
        '</div>' +
      '</div>' +

      '<div class="flex-1 min-h-0 flex relative">' +
        '<div v-if="loading" class="absolute bottom-14 left-1/2 -translate-x-1/2 z-10 h-7 px-3 rounded-full bg-white border border-line shadow-sm text-[12px] text-sub inline-flex items-center gap-2">' +
          'Loading…' +
        '</div>' +
        '<view-grid ref="grid" class="flex-1 min-w-0"' +
          ' :columns="columns" :frozen="frozen" :row-height="rowHeight" :item-url="itemUrl"' +
          ' :can-edit-data="canEditData" :page-size="pageSize"' +
          ' :sort="sort" :dir="dir" :sortable="sortable" :search="search" :fetch="fetchRows"' +
          ' @cell="openCell" @open="openRow" @resize="setColumnWidth" @sort="onSort"></view-grid>' +

        // ---- the two-card column panel (§8) ----
        '<aside v-if="config.open && canEditView" class="vg-config w-[300px] shrink-0 bg-white flex flex-col overflow-y-auto">' +
          '<div class="flex items-center gap-2 px-4 h-12 border-b border-line shrink-0">' +
            '<h2 class="text-[13px] font-semibold text-head">Columns</h2>' +
            '<button type="button" @click="config.open = false" class="ml-auto h-7 w-7 grid place-items-center rounded text-faint hover:bg-hover"' +
              ' aria-label="Close" v-html="icon(\'xmark\', 15)"></button>' +
          '</div>' +

          '<div v-for="card in [{key:\'fixed\',label:\'Fixed columns\',hint:\'Pinned left while the grid scrolls.\',list:fixedColumns},{key:\'scroll\',label:\'Scroll columns\',hint:\'Scroll horizontally past the fixed ones.\',list:scrollColumns}]"' +
            ' :key="card.key" class="px-4 py-3 border-b border-line"' +
            ' @dragover.prevent @drop.prevent="onDrop(card.key, null)">' +
            '<div class="text-[11px] font-semibold uppercase tracking-wide text-faint">{{ card.label }}</div>' +
            '<p class="text-[12px] text-sub mt-0.5">{{ card.hint }}</p>' +
            '<ul class="mt-2 space-y-1">' +
              '<li v-for="c in card.list" :key="c.id" draggable="true"' +
                ' @dragstart="onDragStart(c)" @dragover.prevent @drop.prevent.stop="onDrop(card.key, c)"' +
                ' class="group flex items-center gap-2 h-8 px-2 rounded-md border border-line bg-white cursor-grab"' +
                ' :class="c.available ? \'\' : \'opacity-60\'">' +
                '<span class="text-faint shrink-0" v-html="icon(\'grip-vertical\', 13)"></span>' +
                '<span class="text-[12px] truncate" :class="c.available ? \'text-ink\' : \'text-faint\'">{{ c.label }}</span>' +
                '<span v-if="!c.available" class="shrink-0 text-faint" :data-tip="c.reason" v-html="icon(\'circle-slash\', 12)"></span>' +
                '<button type="button" @click="removeColumn(c)"' +
                  ' class="ml-auto h-6 w-6 grid place-items-center rounded text-faint opacity-0 group-hover:opacity-100 hover:bg-hover hover:text-danger shrink-0"' +
                  ' :aria-label="\'Remove \' + c.label" v-html="icon(\'xmark\', 13)"></button>' +
              '</li>' +
              '<li v-if="!card.list.length" class="text-[12px] text-faint px-2 py-1.5">Drag a column here.</li>' +
            '</ul>' +
          '</div>' +

          // ---- add column (§9) ----
          '<div class="px-4 py-3">' +
            '<button type="button" @click="picker.open = !picker.open"' +
              ' class="w-full inline-flex items-center justify-center gap-1.5 h-8 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
              '<span v-html="icon(\'plus\', 15)"></span>Add column</button>' +

            '<div v-if="picker.open" class="mt-2 border border-line rounded-md">' +
              '<input v-model="picker.query" type="search" placeholder="Search fields"' +
                ' class="w-full h-8 px-2.5 text-[12px] border-b border-line outline-none" />' +
              '<div class="max-h-[300px] overflow-y-auto py-1">' +
                '<div v-for="g in fieldGroups" :key="g.source" class="px-1 py-1">' +
                  '<div class="flex items-center gap-1 px-1.5 text-[11px] font-semibold uppercase tracking-wide"' +
                    ' :class="g.available ? \'text-faint\' : \'text-faint/70\'">' +
                    '{{ g.label }}' +
                    '<span v-if="!g.available" class="normal-case font-normal tracking-normal">— {{ g.reason }}</span>' +
                  '</div>' +
                  '<button v-for="f in g.fields" :key="f.key" type="button"' +
                    ' :disabled="!f.available || f.used" @click="addColumn(f)"' +
                    ' class="w-full text-left flex items-center gap-2 h-7 px-1.5 rounded text-[12px]"' +
                    ' :class="(!f.available || f.used) ? \'text-faint cursor-not-allowed\' : \'text-ink hover:bg-hover\'">' +
                    '<span class="truncate">{{ f.label }}</span>' +
                    '<span v-if="f.used" class="ml-auto text-[11px] text-faint">Added</span>' +
                  '</button>' +
                '</div>' +
                '<div v-if="!fieldGroups.length" class="px-3 py-3 text-[12px] text-faint">No fields match.</div>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</aside>' +
      '</div>' +

      // ---------- work item detail, as a slide-over (§7.3) ----------
      // What is inside the frame is the Work Items screen in `pageMode` — the real drawer,
      // with every picker, tab, editor and relation live. Not a second copy of it.
      '<div v-if="panel.open" class="fixed inset-0 z-[85]">' +
        '<div class="absolute inset-0 bg-black/20" @click="closePanel"></div>' +
        '<aside class="absolute right-0 top-0 h-full w-full sm:w-[80%] bg-white shadow-2xl flex flex-col">' +
          '<button type="button" @click="closePanel" data-tip="Close" aria-label="Close"' +
            ' class="absolute top-3 left-3 z-10 h-8 w-8 grid place-items-center rounded-md bg-white/90 text-sub hover:bg-hover"' +
            ' v-html="icon(\'arrow-right-long\', 18)"></button>' +
          // `key` on the src: opening a different work item must build a fresh frame rather
          // than leave the previous item's editors and unsaved drafts behind in this one.
          '<iframe :key="panelSrc" :src="panelSrc" class="flex-1 min-h-0 w-full border-0"' +
            ' title="Work item detail"></iframe>' +
        '</aside>' +
      '</div>' +
    '</template>' +

    // ---------- cell picker (§11) ----------
    '<div v-if="cell.open" data-cellmenu :style="cell.style" class="bg-white border border-line rounded-lg shadow-lg overflow-hidden">' +
      '<template v-if="cellColumn && [\'work_item.title\'].indexOf(cellColumn.key) > -1">' +
        '<input :value="cell.row ? cell.row.title : \'\'" ref="cellText" type="text" :maxlength="255"' +
          ' @keydown.enter="commitCellText($event.target.value.trim())" @keydown.esc="cell.open = false"' +
          ' class="w-full h-9 px-2.5 text-[13px] outline-none" />' +
        '<div class="px-2.5 pb-2 text-[11px] text-faint">Enter to save · Esc to cancel</div>' +
      '</template>' +
      // Dates: the same calendar the Work Items list and the create modal use, with the same
      // ordering bounds — a due date cannot land before the start date, and vice versa. A
      // native <input type="date"> was here before; it looked and behaved differently in every
      // browser, and differently again from the picker two screens away.
      '<template v-else-if="cellColumn && [\'work_item.start_date\', \'work_item.due_date\'].indexOf(cellColumn.key) > -1">' +
        '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none"' +
          ' :value="cell.row ? (cellColumn.key === \'work_item.start_date\' ? cell.row.start_date : cell.row.due_date) : null"' +
          ' :after="cellColumn.key === \'work_item.due_date\' && cell.row ? cell.row.start_date : null"' +
          ' :before="cellColumn.key === \'work_item.start_date\' && cell.row ? cell.row.due_date : null"' +
          ' @pick="commitCellText($event)" @clear="commitCellText(\'\')" />' +
      '</template>' +
      '<template v-else>' +
        '<input v-model="cell.query" type="search" placeholder="Search" class="w-full h-8 px-2.5 text-[12px] border-b border-line outline-none" />' +
        '<div class="max-h-[260px] overflow-y-auto py-1">' +
          '<button v-for="c in cellChoices" :key="c.id" type="button" @click="commitCell(c)"' +
            ' class="w-full text-left flex items-center gap-2 h-8 px-2.5 text-[13px] text-ink hover:bg-hover">' +
            '<span v-if="c.color" class="h-2.5 w-2.5 rounded-full shrink-0" :style="{ background: c.color }"></span>' +
            '<span class="truncate">{{ c.label }}</span>' +
            '<span v-if="cellSelected.indexOf(String(c.id)) > -1" class="ml-auto text-brand shrink-0" v-html="icon(\'check\', 14)"></span>' +
          '</button>' +
          '<div v-if="!cellChoices.length" class="px-2.5 py-3 text-[12px] text-faint">Nothing to choose.</div>' +
        '</div>' +
      '</template>' +
    '</div>' +

    // ---------- actions menu ----------
    '<div v-if="menu.open" data-menu :style="menu.style" class="bg-white border border-line rounded-lg shadow-lg p-1">' +
      '<template v-for="v in views" :key="v.id">' +
        '<template v-if="String(v.id) === String(menu.id)">' +
          '<a :href="v.url" class="w-full text-left flex items-center gap-2 h-8 px-2 rounded text-[13px] text-ink hover:bg-hover">' +
            '<span class="text-sub" v-html="icon(\'arrow-right\', 14)"></span>Open</a>' +
          '<button v-if="v.is_owner || canCreate" type="button" @click="openRename(v)" class="w-full text-left flex items-center gap-2 h-8 px-2 rounded text-[13px] text-ink hover:bg-hover">' +
            '<span class="text-sub" v-html="icon(\'pen\', 14)"></span>Rename</button>' +
          '<button v-if="canCreate" type="button" @click="duplicate(v)" class="w-full text-left flex items-center gap-2 h-8 px-2 rounded text-[13px] text-ink hover:bg-hover">' +
            '<span class="text-sub" v-html="icon(\'copy\', 14)"></span>Duplicate</button>' +
          '<button type="button" @click="askDelete(v)" class="w-full text-left flex items-center gap-2 h-8 px-2 rounded text-[13px] text-danger hover:bg-danger/5">' +
            '<span v-html="icon(\'trash\', 14)"></span>Delete</button>' +
        '</template>' +
      '</template>' +
    '</div>' +

    // ---------- create (§6) ----------
    // <pb-modal> rather than hand-rolled markup: it is the app's dialog — same header, same
    // padding, same footer row, Escape and backdrop close, and teleported to <body> so a
    // scrolled grid cannot clip it. Fields use .pb-input for the same reason.
    '<pb-modal :open="create.open" title="New view" @close="create.open = false">' +
      '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
      '<input ref="createName" v-model="create.name" :maxlength="nameMax" placeholder="Sprint planning"' +
        ' @keyup.enter="submitCreate" class="pb-input w-full" :class="{ \'is-error\': create.error }" />' +

      '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Dataset</label>' +
      // Shown rather than implied: §6.2 makes this a real choice with one option today, and
      // hiding it would leave no place for Epics/Modules/Cycles to appear later.
      '<input value="Work items" readonly class="pb-input w-full" />' +
      '<p class="text-[12px] text-faint mt-1.5">Each row is one work item. More datasets are coming.</p>' +

      '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Visibility</label>' +
      '<div v-if="visibilities.length" class="space-y-1.5">' +
        '<label v-for="o in visibilities" :key="o.key"' +
          ' class="flex items-start gap-2.5 px-3 py-2.5 rounded-lg border cursor-pointer"' +
          ' :class="create.visibility === o.key ? \'border-brand bg-sel/40\' : \'border-line hover:bg-hover\'">' +
          '<input type="radio" :value="o.key" v-model="create.visibility" class="mt-0.5 accent-brand" />' +
          '<span class="min-w-0">' +
            '<span class="block text-[13px] font-medium text-ink">{{ o.label }}</span>' +
            '<span class="block text-[12px] text-sub">{{ o.hint }}</span>' +
          '</span>' +
        '</label>' +
      '</div>' +
      // Both visibilities can be switched off in Project Settings, which leaves nothing a view
      // could legally be. Saying so beats an empty group the user cannot act on.
      '<p v-else class="text-[13px] text-sub">Neither private nor project views are allowed on this project. ' +
        'Turn one on in Project Settings → View.</p>' +

      '<p v-if="create.error" class="text-[12px] text-danger mt-3">{{ create.error }}</p>' +

      '<template #footer>' +
        '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover"' +
          ' @click="create.open = false">Cancel</button>' +
        '<button type="button" :disabled="create.busy || !visibilities.length || !create.name.trim()"' +
          ' @click="submitCreate"' +
          ' class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
          '{{ create.busy ? \'Creating…\' : \'Create view\' }}</button>' +
      '</template>' +
    '</pb-modal>' +

    // ---------- rename (§5.3) ----------
    '<pb-modal :open="rename.open" title="Rename view" @close="rename.open = false">' +
      '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
      '<input ref="renameName" v-model="rename.name" :maxlength="nameMax" @keyup.enter="submitRename"' +
        ' class="pb-input w-full" :class="{ \'is-error\': rename.error }" />' +
      '<p v-if="rename.error" class="text-[12px] text-danger mt-2">{{ rename.error }}</p>' +
      '<template #footer>' +
        '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover"' +
          ' @click="rename.open = false">Cancel</button>' +
        '<button type="button" :disabled="rename.busy || !rename.name.trim()" @click="submitRename"' +
          ' class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
          '{{ rename.busy ? \'Saving…\' : \'Save\' }}</button>' +
      '</template>' +
    '</pb-modal>' +

    // ---------- delete (§5.3) ----------
    // The shared destructive confirmation, so deleting a view reads like every other delete
    // in the app rather than like a dialog this screen invented.
    '<pb-confirm :open="confirm.open" title="Delete this view?"' +
      ' message="The view and its column configuration are removed. The work items it shows are not touched."' +
      ' confirm-label="Delete view" @close="confirm.open = false" @confirm="confirmDelete"></pb-confirm>' +

  '</div>'
};

(function () {
  var root = document.getElementById('settings-root');
  if (root && root.getAttribute('data-screen') === 'views') PB.boot('views', ViewsScreen);
})();
