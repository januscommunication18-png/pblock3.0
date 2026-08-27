/* Project Block — the work item list, as one component.
   ------------------------------------------------------------------
   <wi-list> is THE work item listing. The project's Work Items screen mounts it, the Cycles
   screen mounts it for a cycle's work items, and anything that lists work items later mounts
   it too — so the grouping, the row heights, the ID/title alignment and the chip cluster are
   the same everywhere by construction rather than by two files agreeing to stay in step.

   They did not stay in step, which is why this exists: the second copy drifted on group
   header colour and column widths within a day of being written.

   The component owns the grid and nothing else. It decides how a work item LOOKS in a list;
   what a click MEANS is the host screen's business, so every interaction leaves as an event:

     @open        a row was clicked          → open the drawer / navigate
     @chip        a row chip was clicked     → open that property's picker  {kind, item, el}
     @group-add   a group's "+" was clicked  → create an item in that state {stateKey}
     @remove      the row's remove was used  → take it out of this list     {item}

   Requires work-item-ui.js (chips, icons, wiEsc) and Tabulator, both loaded first.
   ------------------------------------------------------------------ */

var WiList = {
  props: {
    /** Row payloads, in WorkItemController@card shape. */
    items: { type: Array, default: function () { return []; } },
    /** The project's states, in order — drives grouping and the group headers. */
    states: { type: Array, default: function () { return []; } },
    /** Chips become buttons that open pickers (Work Items §4.2). */
    canEdit: { type: Boolean, default: false },
    /** Show the per-group "+" that creates an item already in that state. */
    canAdd: { type: Boolean, default: false },
    /** Trailing control on each row: the ⋯ actions menu, a remove ×, or nothing. */
    rowAction: { type: String, default: 'menu' },
    /** Tabulator's height. '100%' fills a flex parent; a px value sizes to content. */
    height: { type: String, default: '100%' },
    /**
     * Does this project use labels (Project-Level Labels §3)?
     *
     * Defaults to true so a host that never had labels switched off does not have to say so.
     * When false the label chip leaves the row entirely — including its empty placeholder,
     * which would otherwise offer a field the project has turned off.
     */
    labelsEnabled: { type: Boolean, default: true },
    /**
     * One group holding everything, instead of one per state.
     *
     * For a list that spans projects (Your Work): two projects' "In Progress" are different
     * state rows, so grouping by state would repeat the same heading once per project.
     *
     * This is a real mode rather than "what happens when `states` is empty", because empty
     * states used to mean the grid rendered NOTHING: `groupValues` is Tabulator's whitelist of
     * groups to draw, it was built from `states`, and with none of them every row belonged to
     * a group that was not on the list.
     */
    flat: { type: Boolean, default: false },
    /** The single group's heading in `flat` mode. */
    flatLabel: { type: String, default: 'All work items' }
  },
  emits: ['open', 'chip', 'group-add', 'remove'],
  data: function () {
    return { table: null };
  },
  watch: {
    // Catches a wholesale replacement (`items = resp.items`). A host that mutates the array
    // in place calls refresh() instead — deep-watching 250 rows to save one call is a poor
    // trade.
    items: function () { this.refresh(); },
    states: function () { this.refresh(); }
  },
  computed: {
    /** Group order: the project's own state order, with "no state" last. */
    groupValues: function () {
      if (this.flat) return [WI_FLAT_GROUP];

      return this.states.map(function (s) { return String(s.id); }).concat([WI_NO_STATE]);
    },
    statesById: function () {
      var map = {};
      this.states.forEach(function (s) { map[String(s.id)] = s; });

      return map;
    }
  },
  mounted: function () { this.build(); },
  beforeUnmount: function () {
    if (this.table) { this.table.destroy(); this.table = null; }
  },
  methods: {
    build: function () {
      if (!window.Tabulator || !this.$refs.grid || this.table) return;
      var self = this;
      this._wasEmpty = this.items.length === 0;

      this.table = new Tabulator(this.$refs.grid, {
        data: this.rows(),
        index: 'id',
        layout: 'fitColumns',
        headerVisible: false,
        rowHeight: 44,
        // Let Tabulator own the scroll container (and virtualise long lists) rather than the
        // page; at '100%' it fills whatever flex box the host puts it in.
        height: this.height,
        columnDefaults: { vertAlign: 'middle', headerSort: false },
        groupBy: 'gkey',
        groupToggleElement: 'header',
        groupValues: [this.groupValues],
        groupHeader: function (value, count) { return self.groupHeader(value, count); },
        columns: [
          { title: 'ID', field: 'identifier', width: self.idWidth(), formatter: function (cell) {
            return '<span class="text-[12px] text-sub">' + wiEsc(cell.getValue()) + '</span>';
          } },
          { title: 'Title', field: 'title', minWidth: 160, widthGrow: 1, formatter: function (cell) {
            return wiTitleCell(cell.getRow().getData());
          } },
          { title: '', field: 'meta', width: 620, hozAlign: 'right', formatter: function (cell) {
            return self.metaCell(cell.getRow().getData());
          } }
        ]
      });

      // A row opens the item, except when the click landed on one of the row's own controls —
      // those act in place and must not also open whatever is behind them.
      this.table.on('rowClick', function (e, row) {
        if (e.target.closest('[data-act]')) return;
        self.$emit('open', self.find(row.getData().id));
      });

      // Delegated in the capture phase: Tabulator re-renders rows on every data change, so
      // listeners bound to a row would leak with it, and a group "+" must not also toggle
      // the group it sits in.
      this.$refs.grid.addEventListener('click', function (e) {
        var add = e.target.closest('[data-gadd]');
        if (add) {
          e.stopPropagation();
          self.$emit('group-add', add.getAttribute('data-gadd'));

          return;
        }

        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        e.stopPropagation();

        var item = self.find(btn.getAttribute('data-id'));
        if (!item) return;

        var kind = btn.getAttribute('data-act');
        if (kind === 'remove') self.$emit('remove', item);
        else self.$emit('chip', { kind: kind, item: item, el: btn });
      }, true);
    },

    /** Rebuild the rows from `items`. Hosts that mutate the array in place call this. */
    refresh: function () {
      if (!this.table) { this.$nextTick(this.build); return; }
      var self = this;
      // A grid that was display:none measures zero width, so the first time it appears it
      // needs a re-layout, not just new data.
      var wasHidden = this._wasEmpty === true;
      this._wasEmpty = this.items.length === 0;

      this.table.replaceData(this.rows()).then(function () {
        // Creating the item that adds a digit widens the ID column with it.
        var id = self.table.getColumn('identifier');
        if (id) id.setWidth(self.idWidth());
        if (wasHidden) self.$nextTick(function () { self.table.redraw(true); });
      });
    },

    /**
     * Update ONE row in place, without rebuilding the list.
     *
     * Keeps scroll position and collapsed groups, which a full replaceData throws away — the
     * difference between editing a chip on row 40 and being bounced back to the top.
     * Returns false when the row has to move groups, so the host can fall back to refresh().
     */
    updateRow: function (card) {
      if (!this.table || !card) return false;
      var self = this;

      this.table.updateData([this.row(card)]).then(function () {
        // The chip cluster is drawn by a formatter on a column with no field of its own, so
        // Tabulator sees nothing changed there and leaves the old HTML in place — an added
        // assignee would not appear until a reload. Reformat the row explicitly.
        var row = self.table.getRow(card.id);
        if (row) row.reformat();
      }).catch(function () { self.refresh(); });

      return true;
    },

    find: function (id) {
      var match = this.items.filter(function (i) { return String(i.id) === String(id); });

      return match.length ? match[0] : null;
    },

    /**
     * Width of the ID column, sized to the longest ID actually on screen.
     *
     * A fixed width was left over from the `<PROJECT>-<n>` format; against a plain number it
     * strands ~50px of empty cell between the ID and the title. Measuring instead keeps that
     * gap at the grid's own 8px + 8px cell padding whatever the number grows to, while the
     * column stays a column — so titles still line up down the list.
     * 24 = the first cell's left padding, 8 = its right padding, 7.5px per digit at 12px.
     */
    idWidth: function () {
      var longest = this.items.reduce(function (n, i) {
        return Math.max(n, String(i.identifier || '').length);
      }, 1);

      return 24 + 8 + Math.ceil(longest * 7.5);
    },

    rows: function () {
      var self = this;

      return this.items.map(function (i) { return self.row(i); });
    },

    /** One card as the grid's row shape — the group key is derived, not stored. */
    row: function (i) {
      if (this.flat) return Object.assign({}, i, { gkey: WI_FLAT_GROUP });

      var stateId = i.state_id || (i.state ? i.state.id : null);

      return Object.assign({}, i, { gkey: stateId ? String(stateId) : WI_NO_STATE });
    },

    groupHeader: function (value, count) {
      if (this.flat) {
        return '<span class="wi-chevron grid place-items-center" style="color:#9ca3af">' + wiIcon('chevron-right', 14) + '</span>' +
          '<span class="grid place-items-center">' + wiStateIcon(null) + '</span>' +
          '<span style="color:#23272f;font-weight:600">' + wiEsc(this.flatLabel) + '</span>' +
          '<span style="color:#6b7280;font-weight:600">' + count + '</span>';
      }

      var state = this.statesById[String(value)] || null;
      var add = this.canAdd
        // h-7 w-7 and rounded-md to match wiRowMenuButton exactly: this "+" and a row's ⋯ line
        // up in the same column, and both sit 20px from the right edge (work-items.css).
        ? '<button type="button" data-gadd="' + wiEsc(value) + '" class="ml-auto h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line shrink-0" data-tip="Create Work Item" aria-label="Create Work Item">' + wiIcon('plus', 15) + '</button>'
        : '';

      // Inline colours, not utility classes: Tabulator's base theme styles `.tabulator-group
      // span` with a higher specificity than the skin's reset, and a class here loses to it —
      // which is exactly how the second copy of this grid ended up with red group headings.
      return '<span class="wi-chevron grid place-items-center" style="color:#9ca3af">' + wiIcon('chevron-right', 14) + '</span>' +
        '<span class="grid place-items-center">' + wiStateIcon(state) + '</span>' +
        '<span class="text-[13px] font-semibold" style="color:#0f0f10">' + wiEsc(state ? state.name : 'No state') + '</span>' +
        '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5" style="color:#6b7280;background:#f3f4f6">' + count + '</span>' +
        add;
    },

    metaCell: function (d) {
      var action = '';
      if (this.rowAction === 'menu' && this.canEdit) {
        action = wiRowMenuButton(d.id);
      } else if (this.rowAction === 'remove') {
        action = '<button type="button" data-act="remove" data-id="' + d.id + '" ' +
          'data-tip="Remove from this list" aria-label="Remove from this list" ' +
          'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line hover:text-danger shrink-0">' +
          '' + wiIcon('xmark', 15) + '</button>';
      }

      return wiMetaCell(d, { edit: this.canEdit, action: action, labels: this.labelsEnabled });
    }
  },
  template: '<div ref="grid" class="wi-grid"></div>'
};
