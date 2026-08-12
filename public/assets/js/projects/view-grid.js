/* Views › the spreadsheet grid, on RevoGrid.
   ------------------------------------------------------------------
   `<revo-grid>` (@revolist/revogrid, MIT, vendored) rather than Tabulator: it is a web
   component built for spreadsheets, so pinned columns, resize, reorder and virtual scrolling
   are properties rather than things to coax out of a list grid — and it measures itself,
   which removes the class of "the grid came up short in Chrome" problems that came from
   Tabulator caching a container height taken before the flex layout had settled.

   What deliberately does NOT change is what a cell looks like. Every chip is still rendered by
   the helpers in work-item-ui.js, the same ones the Work Items list uses, so a status or a
   priority means the same thing wherever you see it. The grid engine is an implementation
   detail; the row vocabulary is not.

   The contract with views.js — props, events, updateRow() — is unchanged from the Tabulator
   version, so the screen around it did not have to move.
   ------------------------------------------------------------------ */

/** A cell's rendered HTML, by column type. Every branch escapes; these become innerHTML. */
function vgCell(type, row, column) {
  var v = vgValue(type, row, column);

  if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) {
    return '<span class="text-faint">—</span>';
  }

  switch (type) {
    case 'title':
      return '<span class="text-ink truncate">' + wiEsc(v) + '</span>';
    case 'state':
      return wiChip(wiStateIcon(v) + '<span class="truncate">' + wiEsc(v.name) + '</span>', 'text-ink', v.name, true);
    case 'priority':
      var p = WI_PRI[v] || WI_PRI.none;
      return wiChip(p.icon + '<span>' + wiEsc(p.label) + '</span>', p.cls, p.label, true);
    case 'assignees':
    case 'member_list':
      return '<span class="inline-flex items-center gap-1">' +
        v.slice(0, 3).map(function (m) { return wiAvatar(m, 22); }).join('') +
        (v.length > 3 ? '<span class="text-[11px] text-sub">+' + (v.length - 3) + '</span>' : '') + '</span>';
    case 'member':
      return '<span class="inline-flex items-center gap-1.5 min-w-0">' + wiAvatar(v, 22) +
        '<span class="truncate">' + wiEsc(v.name) + '</span></span>';
    case 'labels':
      return v.map(function (l) {
        return '<span class="inline-flex items-center gap-1 h-5 px-1.5 mr-1 rounded border border-line bg-white text-[11px] text-ink">' +
          '<span class="h-2 w-2 rounded-full shrink-0" style="background:' + wiEsc(l.color || '#9ca3af') + '"></span>' +
          wiEsc(l.name) + '</span>';
      }).join('');
    case 'modules':
      return v.map(function (m) { return wiChip('<span class="truncate">' + wiEsc(m.title) + '</span>', 'text-ink mr-1', m.title, true); }).join('');
    case 'epic':
    case 'cycle':
      return wiChip('<span class="truncate">' + wiEsc(v.title || v.name) + '</span>', 'text-ink', v.title || v.name, true);
    case 'estimate':
      return wiChip('<span>' + wiEsc(v.label) + '</span>', 'text-ink', v.label, true);
    case 'work_item_ref':
      return '<span class="text-sub truncate">' + wiEsc(v.identifier) + ' · ' + wiEsc(v.title) + '</span>';
    case 'badge_list':
      return v.map(function (s) { return '<span class="inline-flex items-center h-5 px-1.5 mr-1 rounded bg-hover text-[11px] text-sub capitalize">' + wiEsc(s) + '</span>'; }).join('');
    case 'badge':
      return '<span class="inline-flex items-center h-5 px-1.5 rounded bg-hover text-[11px] text-sub capitalize">' + wiEsc(v) + '</span>';
    case 'longtext':
      return '<span class="text-sub truncate">' + wiEsc(wiPlainText(v, 140)) + '</span>';
    case 'datetime':
    case 'date':
      return '<span class="text-sub">' + wiEsc(vgDate(v)) + '</span>';
    default:
      return '<span class="text-sub truncate">' + wiEsc(v) + '</span>';
  }
}

/**
 * The value behind a cell.
 *
 * Most columns read a key off the row, which is the Work Items card. The ones the card does
 * not carry — an epic's lead, a cycle's dates — travel under `view`, keyed by the column key,
 * so nothing there can ever collide with a card key as the card keeps changing.
 */
function vgValue(type, row, column) {
  if (row.view && Object.prototype.hasOwnProperty.call(row.view, column.key)) return row.view[column.key];

  var direct = {
    'work_item.identifier': 'identifier', 'work_item.title': 'title',
    'work_item.state': 'state', 'work_item.priority': 'priority',
    'work_item.start_date': 'start_date', 'work_item.due_date': 'due_date',
    'work_item.description': 'description', 'work_item.parent': 'parent',
    'work_item.created_at': 'created_at', 'work_item.updated_at': 'updated_at',
    'epic.title': 'epic', 'cycle.name': 'cycle', 'module.title': 'modules',
    'estimate.value': 'estimate', 'label.labels': 'labels', 'member.assignees': 'assignees'
  }[column.key];

  return direct ? row[direct] : null;
}

/** 12 Aug 2026 — short enough for a cell, unambiguous about the month. */
function vgDate(value) {
  if (!value) return '';
  var d = new Date(value);
  if (isNaN(d.getTime())) return String(value);

  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

var ViewGrid = {
  props: {
    rows: { type: Array, default: function () { return []; } },
    columns: { type: Array, default: function () { return []; } },
    frozen: { type: Number, default: 0 },
    rowHeight: { type: Number, default: 44 },
    /** Can this user edit the DATA? Separate from being able to edit the view (§14). */
    canEditData: { type: Boolean, default: false },
    hasMore: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    sort: { type: String, default: '' },
    dir: { type: String, default: 'asc' },
    sortable: { type: Array, default: function () { return []; } }
  },
  emits: ['cell', 'open', 'more', 'resize', 'reorder', 'sort'],

  computed: {
    /**
     * RevoGrid's column definitions.
     *
     * Computed rather than built imperatively: RevoGrid takes `columns` and `source` as plain
     * properties and re-renders when they are replaced, so keeping them derived means there is
     * no "now apply the change to the grid" step to forget. The Tabulator version needed
     * setColumns/replaceData calls plus a watcher to decide between them, and getting that
     * wrong is what rebuilt the grid in the middle of a resize drag.
     *
     * §7.2's Fixed columns become `pin: 'colPinStart'`. The server still sends Fixed first and
     * `frozen` still counts them, but pinning is now a property of the column rather than a
     * boundary index the grid has to be told about separately — so a mis-ordered column set
     * can no longer pin the wrong ones.
     */
    definitions: function () {
      var self = this;

      return this.columns.filter(function (c) { return c.visible; }).map(function (column, index) {
        var editable = column.available && column.editable && self.canEditData;
        var head = self.headerHtml(column);

        return {
          prop: 'c' + column.id,
          name: column.label,
          size: column.width || 160,
          minSize: 60,
          pin: index < self.frozen ? 'colPinStart' : undefined,
          // RevoGrid's own cell editing stays off throughout. A cell opens OUR picker, which
          // writes through the work item endpoint with all six of §11.3's checks behind it;
          // an editable grid cell would be a second, weaker way to change the same data.
          readonly: true,
          sortable: false,
          pbId: column.id,
          pbKey: column.key,

          columnTemplate: function (h) {
            return h('span', { class: 'vg-head', innerHTML: head });
          },

          cellTemplate: function (h, props) {
            var row = (props.model && props.model.__row) || {};
            var body = column.available
              ? vgCell(column.type, row, column)
              : '<span class="text-faint italic">—</span>';

            return h('span', {
              // The whole cell is the target, not a control inside it — a spreadsheet cell is
              // clicked anywhere. These attributes are what the delegated listener reads.
              class: 'vg-cell' + (editable ? ' vg-editable' : ''),
              'data-cell': editable ? column.key : null,
              'data-row': row.id,
              innerHTML: body
            });
          }
        };
      });
    },

    /**
     * RevoGrid's data source.
     *
     * The whole card is carried on `__row` and the per-column keys are placeholders: every
     * column's `prop` has to exist on the row or RevoGrid renders nothing for it, but the cell
     * template reads the card, not the cell value. That keeps one row shape — the Work Items
     * card — rather than flattening it per column and having to keep the flattening in step.
     */
    source: function () {
      var defs = this.definitions;

      return this.rows.map(function (row) {
        var out = { id: row.id, __row: row };
        defs.forEach(function (d) { out[d.prop] = ''; });

        return out;
      });
    }
  },

  watch: {
    definitions: function (next) { this.apply('columns', next); },
    source: function (next) { this.apply('source', next); },
    rowHeight: function (next) { this.apply('rowSize', next); }
  },

  mounted: function () {
    this.applyAll();
    this.bind();
  },
  beforeUnmount: function () {
    var el = this.$refs.grid;
    if (!el) return;
    el.removeEventListener('aftercolumnresize', this._onResize);
    el.removeEventListener('aftercolumnsset', this._onColumnsSet);
    el.removeEventListener('scroll', this._onScroll, true);
  },

  methods: {
    /**
     * Set a property on the custom element.
     *
     * Properties, not attributes. `columns` and `source` are arrays of objects carrying
     * functions (the cell templates), and an attribute can only ever be a string — Vue binds
     * `:prop` on an unknown element as an attribute, so these are assigned by hand.
     */
    apply: function (name, value) {
      var el = this.$refs.grid;
      if (!el) return;

      try { el[name] = value; } catch (e) { /* the element is not upgraded yet */ }
    },

    applyAll: function () {
      var el = this.$refs.grid;
      if (!el) return;

      el.resize = true;
      el.canMoveColumns = true;
      // Read-only at the GRID level; editing happens through our own pickers (see the column
      // definitions). This is not the same thing as the user's permission, which the screen
      // resolves per cell.
      el.readonly = true;
      el.range = false;
      el.canFocus = true;
      el.rowHeaders = false;
      // Left visible deliberately: RevoGrid's documentation for this property says to hide the
      // attribution only with a Pro subscription, and this app does not have one.
      el.hideAttribution = false;

      // Row height still comes from the View's density (§12.5) rather than from the theme's
      // default, because that is a per-view setting the user chose — Standard is the work item
      // list's 44px. Everything else about how a row LOOKS is the theme's.
      this.apply('rowSize', this.rowHeight);
      this.apply('columns', this.definitions);
      this.apply('source', this.source);
    },

    bind: function () {
      var el = this.$refs.grid;
      if (!el) return;
      var self = this;

      // ---- resize (§10) ----
      this._onResize = function (e) {
        var changed = e.detail || {};

        Object.keys(changed).forEach(function (index) {
          var col = changed[index];
          if (col && col.pbId) self.$emit('resize', { id: col.pbId, width: Math.round(col.size) });
        });
      };
      el.addEventListener('aftercolumnresize', this._onResize);

      // ---- reorder (§8.3) ----
      // RevoGrid announces the whole column set after a move, which is the shape the layout
      // endpoint wants anyway: both cards, in one request, because one move renumbers the
      // columns around it in both.
      this._onColumnsSet = function (e) {
        if (!self._movedByUser) return;
        self._movedByUser = false;

        var cols = (e.detail && e.detail.columns) || {};
        var ordered = [].concat(cols.colPinStart || [], cols.rgCol || [])
          .map(function (c) { return c && c.pbId; })
          .filter(Boolean);

        if (!ordered.length) return;

        self.$emit('reorder', {
          fixed: ordered.slice(0, self.frozen),
          scroll: ordered.slice(self.frozen)
        });
      };
      el.addEventListener('aftercolumnsset', this._onColumnsSet);

      // A column set is also announced because WE replaced it, and sending that straight back
      // to the server as a user reorder would be a loop. Only a pointer drag on a header arms
      // the handler above.
      el.addEventListener('mousedown', function (e) {
        self._movedByUser = !!(e.target.closest && e.target.closest('.rgHeaderCell'));
      }, true);

      // ---- cells, headers and rows ----
      // Delegated in the capture phase: RevoGrid re-renders cells as it virtualises, so a
      // listener bound to a cell would leak with the cell it was bound to.
      el.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        var head = e.target.closest('[data-sort]');
        if (head) { self.$emit('sort', head.getAttribute('data-sort')); return; }

        var cell = e.target.closest('[data-cell]');
        if (cell && self.canEditData) {
          e.stopPropagation();
          var row = self.find(cell.getAttribute('data-row'));
          if (row) self.$emit('cell', { key: cell.getAttribute('data-cell'), row: row, el: cell });

          return;
        }

        // Anywhere else on a rendered cell opens the work item.
        var body = e.target.closest('.vg-cell');
        if (!body) return;
        var open = self.find(body.getAttribute('data-row'));
        if (open) self.$emit('open', open);
      }, true);

      // §24's progressive load. RevoGrid scrolls an inner viewport rather than the element, so
      // this listens in the capture phase for whichever box actually scrolled.
      this._onScroll = function (e) {
        var box = e.target;
        if (!box || typeof box.scrollTop !== 'number') return;
        if (!self.hasMore || self.loading) return;
        // Half a screen from the bottom, so the next page is usually there before it is needed.
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - (box.clientHeight / 2)) self.$emit('more');
      };
      el.addEventListener('scroll', this._onScroll, true);
    },

    find: function (id) {
      var key = String(id);

      return this.rows.filter(function (r) { return String(r.id) === key; })[0] || null;
    },

    /**
     * A header, with its sort control when the column can be sorted in SQL.
     *
     * Only sortable columns get the control. A sort affordance that did nothing would be worse
     * than none — §24 puts sorting on the server, so a column the server cannot order by simply
     * does not offer one.
     */
    headerHtml: function (column) {
      var sortable = this.sortable.indexOf(column.key) > -1;
      var active = this.sort === column.key;
      var arrow = active ? wiIcon(this.dir === 'desc' ? 'chevron-down' : 'chevron-up', 12, 'text-brand') : '';
      var warn = column.available ? '' :
        '<span data-tip="' + wiEsc(column.reason || '') + '">' + wiIcon('circle-slash', 12, 'text-faint') + '</span>';

      return '<span class="inline-flex items-center gap-1 ' + (column.available ? 'text-head' : 'text-faint') + '"' +
        (sortable ? ' data-sort="' + wiEsc(column.key) + '"' : '') + '>' +
        wiEsc(column.label) + warn + arrow + '</span>';
    },

    /**
     * Swap one row in place, so an inline edit does not cost the scroll position.
     *
     * The source array is REPLACED rather than mutated: RevoGrid re-renders on a new array
     * reference, and a row object changed in place is a change it has no reason to notice.
     */
    updateRow: function (row) {
      var el = this.$refs.grid;
      if (!el || !el.source) return false;

      el.source = el.source.map(function (r) {
        return String(r.id) === String(row.id) ? Object.assign({}, r, { __row: row }) : r;
      });

      return true;
    }
  },

  template:
    '<div class="vg-wrap flex-1 min-h-0 relative flex flex-col">' +
      // RevoGrid's own `default` theme — the one on rv-grid.com/demo. Named rather than left
      // off so it is a decision on the page rather than whatever the package happens to ship
      // as its fallback.
      '<revo-grid ref="grid" theme="default" class="vg-grid flex-1 min-h-0"></revo-grid>' +
      '<div v-if="loading" class="absolute bottom-3 left-1/2 -translate-x-1/2 h-7 px-3 rounded-full bg-white border border-line shadow-sm text-[12px] text-sub inline-flex items-center gap-2">' +
        'Loading…' +
      '</div>' +
    '</div>'
};
