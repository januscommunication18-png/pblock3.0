/* Views › the spreadsheet grid, on DataTables.
   ------------------------------------------------------------------
   DataTables v3 (MIT, vendored, dependency-free — jQuery was only required before v3) rather
   than RevoGrid or Tabulator. The reason is not features, it is CONTROL: DataTables renders an
   ordinary <table> with ordinary class names, so "make the rows white" is one CSS rule that
   cannot lose. RevoGrid is a Stencil web component themed solely through `--revo-grid-*`
   custom properties, and every visual request against it turned into archaeology in a minified
   bundle — three rounds of it on the row background alone.

   Two structural bugs disappear with the <table>, rather than being fixed again:

     * widths live in a <colgroup> under `table-layout: fixed`, so a width set on a <col> moves
       the header AND the body. "Only the header resizes" is not expressible here.
     * the browser lays the table out, so there is no cached container height to go stale —
       which is what made the grid come up short in Chrome but not Safari under Tabulator.

   What deliberately does NOT change is what a cell looks like. Every chip is still rendered by
   the helpers in work-item-ui.js, the same ones the Work Items list uses, so a status or a
   priority means the same thing wherever you see it. The grid engine is an implementation
   detail; the row vocabulary is not.

   ## Who fetches

   DataTables runs `serverSide: true` against §24's existing paged endpoint, so it draws the
   pager and the "Showing 1 to 100 of 340" line from real server state. It does not own the
   URL: `fetch` is a function prop, and views.js still builds the request and owns the error
   toast. That keeps one place that knows the endpoints.

   Paging REPLACES the previous load-more-on-scroll (§24). A pager is what the library exists
   to give, and an infinite list cannot say how much is behind it.
   ------------------------------------------------------------------ */

/** A cell's rendered HTML, by column type. Every branch escapes; these become innerHTML. */
function vgCell(type, row, column) {
  var v = vgValue(type, row, column);
  var empty = v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);

  // Dates are handled before the empty check, because an empty date is not nothing — it is a
  // date waiting to be set. The Work Items list renders exactly this: a calendar chip carrying
  // the date, or a compact faint calendar when there is none, so the cell stays clickable
  // either way. Same glyph (WI_CAL) and same formatter (wiFmtDate) as the list, so one date
  // reads the same in both places rather than MM/DD/YYYY here and "12 Aug 2026" there.
  if (type === 'date') {
    var name = column.label || 'date';

    return empty
      ? wiChip(WI_CAL, 'text-faint', 'Set a ' + name.toLowerCase(), true)
      : wiChip(WI_CAL + wiFmtDate(v), 'text-ink', name + ': ' + wiFmtDate(v), true);
  }

  if (empty) return '<span class="text-faint">—</span>';

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
    // Created / Updated. Not a chip: these are not settable, and a calendar affordance on
    // something read-only would be a lie. Same formatter, so the app speaks one date language.
    case 'datetime':
      return '<span class="text-sub">' + wiEsc(wiFmtDate(v)) + '</span>';
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

/** The default a column falls back to when the server has no stored width for it. */
var VG_DEFAULT_WIDTH = 160;
var VG_MIN_WIDTH = 60;

/**
 * Columns whose values sit against the right edge.
 *
 * Declared here rather than detected. DataTables sniffs cell content and right-aligns whatever
 * looks numeric, which is why the columns are pinned to `type: 'string'` — that guessing had
 * the ID column's header and its values disagreeing. Alignment is a decision about the column,
 * so it is written down as one.
 */
var VG_RIGHT = ['work_item.identifier'];

/**
 * Columns whose cells are a link to the work item.
 *
 * The same two the Work Items list makes clickable, so "click the ID or the title to open it"
 * means one thing across the app. They are deliberately NOT inline-editable as a result: a
 * cell cannot both open the item and edit it on one click, and the title is editable in the
 * detail panel the link opens — which is a better place to edit a title than a grid cell.
 */
var VG_LINK = ['work_item.identifier', 'work_item.title'];

var ViewGrid = {
  props: {
    columns: { type: Array, default: function () { return []; } },
    frozen: { type: Number, default: 0 },
    rowHeight: { type: Number, default: 44 },
    /** Can this user edit the DATA? Separate from being able to edit the view (§14). */
    canEditData: { type: Boolean, default: false },
    sort: { type: String, default: '' },
    dir: { type: String, default: 'asc' },
    sortable: { type: Array, default: function () { return []; } },
    search: { type: String, default: '' },
    /** Base URL for a work item, e.g. `/projects/1/work-items`. Gives the link cells a real href. */
    itemUrl: { type: String, default: '' },
    /** The server's page size. DataTables must page in the same steps the endpoint does. */
    pageSize: { type: Number, default: 100 },
    /**
     * Fetch one page: ({page, search, sort, dir}) => Promise<{rows, total}>.
     *
     * A prop rather than a URL, so views.js keeps sole ownership of the endpoints and of what
     * a failed request says to the user.
     */
    fetch: { type: Function, default: null }
  },
  emits: ['cell', 'open', 'resize', 'sort'],

  computed: {
    /** The columns actually drawn, in order. Hidden ones never reach the table. */
    visible: function () {
      return this.columns.filter(function (c) { return c.visible; });
    },

    /**
     * A fingerprint of everything that requires the table to be rebuilt from scratch.
     *
     * Width is deliberately absent: a resize changes a <col>, not the table's structure.
     * Including it is what rebuilt the grid mid-drag under Tabulator and lost the pointer.
     */
    layoutKey: function () {
      return this.visible.map(function (c) {
        return c.id + ':' + c.key + ':' + (c.available ? 1 : 0) + ':' + (c.editable ? 1 : 0);
      }).join('|') + '#' + this.frozen;
    },

    /** Widths only. Separate from `layoutKey` so a resize re-sizes without rebuilding. */
    widthKey: function () {
      return this.visible.map(function (c) { return c.width || VG_DEFAULT_WIDTH; }).join(',');
    }
  },

  watch: {
    layoutKey: function () { this.rebuild(); },
    // A drag has already moved the <col> by the time the server answers, so this normally sets
    // the width it is already at. It earns its place when the server REFUSES one: views.js puts
    // the stored width back, and this is what snaps the column to it.
    widthKey: function () { this.sizeTable(); },
    rowHeight: function (next) { this.applyRowHeight(next); },
    // The header carries the sort arrow, so it is redrawn rather than the whole table — a
    // rebuild here would throw away the page the user is on.
    sort: function () { this.paintHeaders(); },
    dir: function () { this.paintHeaders(); }
  },

  mounted: function () {
    this.rebuild();
  },
  beforeUnmount: function () {
    this.destroy();
    window.removeEventListener('mousemove', this._onDrag);
    window.removeEventListener('mouseup', this._endDrag);
  },

  methods: {
    // ================= building =================
    destroy: function () {
      if (!this._dt) return;

      this._dt.destroy();
      this._dt = null;
      if (this.$refs.table) this.$refs.table.innerHTML = '';
    },

    /**
     * Build (or rebuild) the table.
     *
     * DataTables takes its columns once, so a changed column set means a new instance. That is
     * cheap here because the rows come from the server anyway: the rebuild re-asks for page 1,
     * which is the right thing after a column was added, removed or reordered.
     */
    rebuild: function () {
      var self = this;
      var el = this.$refs.table;
      if (!el || typeof DataTable === 'undefined') return;

      this.destroy();
      this._total = 0;
      this._rows = [];

      this._dt = new DataTable(el, {
        serverSide: true,
        // Search and sort stay on OUR toolbar and OUR headers — both already existed, both are
        // styled with the rest of the app, and both already talk to the server. Turning on the
        // library's versions as well would be two controls for one behaviour.
        searching: false,
        ordering: false,
        lengthChange: false,
        autoWidth: false,
        pageLength: this.pageSize,
        // The default styling stripes odd rows via `table.dataTable.stripe`. We never add that
        // class, and this makes sure DataTables does not add alternating classes of its own:
        // grey alternating rows are the exact defect this grid was rebuilt to end.
        stripeClasses: [],
        language: { emptyTable: 'No work items match this view.', info: 'Showing _START_ to _END_ of _TOTAL_', infoEmpty: '', infoFiltered: '' },
        layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
        columns: this.definitions(),
        createdRow: function (tr, data) {
          tr.setAttribute('data-row', data.id);
        },
        ajax: function (data, callback) { self.load(data, callback); },
        drawCallback: function () { self.afterDraw(); }
      });

      this.sizeTable();
      this.applyRowHeight(this.rowHeight);
      this.bind();
    },

    /**
     * DataTables' column definitions.
     *
     * `data: null` because a cell reads the whole Work Items card rather than one field of it —
     * one row shape, the same one the Work Items list uses, instead of a per-column flattening
     * that would have to be kept in step with the card.
     */
    definitions: function () {
      var self = this;

      return this.visible.map(function (column, index) {
        return {
          data: null,
          orderable: false,
          title: self.headerHtml(column, index),
          className: 'vg-td',
          // Type detection is declared, not sniffed. Left to itself DataTables inspects the
          // rendered content, decides a column like the ID is numeric, and tags it
          // `dt-type-numeric` — which its stylesheet right-aligns. The VALUES do not move
          // (`.vg-cell` is a flex box, which text-align does not reach) so only the header
          // shifts, and the column reads as misaligned against its own data.
          //
          // Ordering is server-side (§24), so a detected type buys nothing here anyway.
          type: 'string',
          render: function (row) { return self.cellHtml(column, row); }
        };
      });
    },

    /**
     * One cell's HTML.
     *
     * The single place a cell is built. Both the first render and the in-place row update go
     * through here — they used to carry a copy each, which is two chances for a link, an
     * `editable` rule or a data attribute to exist in one and not the other.
     */
    cellHtml: function (column, row) {
      var link = VG_LINK.indexOf(column.key) > -1 && this.itemUrl;
      // A link cell is never also an edit target: one click cannot mean both.
      var editable = !link && column.available && column.editable && this.canEditData;

      var body = column.available
        ? vgCell(column.type, row, column)
        : '<span class="text-faint italic">—</span>';

      if (link) {
        // A REAL href, not a click handler on a span. That is what makes ⌘-click, middle-click
        // and "Open in new tab" work, and what puts the target in the status bar on hover. The
        // delegated listener below intercepts only the plain left click, to open the panel.
        body = '<a class="vg-link" href="' + wiEsc(this.itemUrl + '/' + row.id) + '"' +
          ' data-open="' + wiEsc(row.id) + '">' + body + '</a>';
      }

      // The whole cell is the target, not a control inside it — a spreadsheet cell is
      // clicked anywhere. These attributes are what the delegated listener reads.
      return '<span class="vg-cell' + (editable ? ' vg-editable' : '') + '"' +
        (editable ? ' data-cell="' + wiEsc(column.key) + '"' : '') +
        ' data-row="' + wiEsc(row.id) + '">' + body + '</span>';
    },

    /**
     * One page, from views.js.
     *
     * The server counts only on page 1 — the COUNT over a large table is the expensive half of
     * what paging exists to avoid — so the total is remembered here and reused for pages 2+.
     * Anything that changes the count (a new search) resets paging, which asks for page 1 and
     * therefore brings a fresh one.
     */
    load: function (data, callback) {
      var self = this;
      var length = data.length || this.pageSize;
      var page = Math.floor((data.start || 0) / length) + 1;

      var empty = function () {
        callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
      };

      if (typeof this.fetch !== 'function') { empty(); return; }

      this.fetch({ page: page, search: this.search, sort: this.sort, dir: this.dir })
        .then(function (res) {
          self._rows = (res && res.rows) || [];
          if (page === 1 || !self._total) self._total = (res && res.total) || self._rows.length;

          callback({
            draw: data.draw,
            recordsTotal: self._total,
            recordsFiltered: self._total,
            data: self._rows
          });
        })
        .catch(empty);
    },

    /** Ask for rows again. `reset` returns to page 1 — a new search or a new sort. */
    reload: function (reset) {
      if (!this._dt) return;
      if (reset) this._total = 0;

      this._dt.ajax.reload(null, !!reset);
    },

    // ================= sizing =================
    widthAt: function (index) {
      var column = this.visible[index];

      return Math.max(VG_MIN_WIDTH, (column && column.width) || VG_DEFAULT_WIDTH);
    },

    /**
     * Widths, through a <colgroup> under `table-layout: fixed`.
     *
     * This is the whole reason the engine changed. A <col> width applies to the column, header
     * and body alike, so a resize cannot move one without the other — the defect that took two
     * attempts to fix on Tabulator is not expressible in this markup.
     */
    sizeTable: function () {
      var table = this.$refs.table;
      if (!table) return;

      var group = table.querySelector('colgroup.vg-cols');

      if (!group) {
        group = document.createElement('colgroup');
        group.className = 'vg-cols';
        table.insertBefore(group, table.firstChild);
      }

      var total = 0;
      group.innerHTML = '';

      for (var i = 0; i < this.visible.length; i++) {
        var col = document.createElement('col');
        var w = this.widthAt(i);

        col.style.width = w + 'px';
        group.appendChild(col);
        total += w;
      }

      // An explicit total lets the table be wider than its box, which is what makes the
      // horizontal scroll — and therefore the pinned columns — mean anything.
      table.style.width = total + 'px';
      this.pin();
    },

    applyRowHeight: function (height) {
      if (this.$refs.wrap) this.$refs.wrap.style.setProperty('--vg-row-h', (height || 44) + 'px');
    },

    /**
     * §7.2's Fixed columns, as sticky cells.
     *
     * `position: sticky` rather than a second cloned table (which is how grid libraries do it):
     * one table means one source of row heights, so a pinned cell cannot drift out of line with
     * the row it belongs to.
     */
    pin: function () {
      var table = this.$refs.table;
      if (!table) return;

      var count = Math.min(this.frozen, this.visible.length);
      var lefts = [];
      var acc = 0;

      for (var i = 0; i < count; i++) { lefts.push(acc); acc += this.widthAt(i); }

      var right = this.visible.map(function (c) { return VG_RIGHT.indexOf(c.key) > -1; });
      var rows = [].slice.call(table.querySelectorAll('thead tr, tbody tr'));

      rows.forEach(function (tr) {
        for (var c = 0; c < tr.children.length; c++) {
          var cell = tr.children[c];
          cell.classList.toggle('vg-pin', c < count);
          cell.classList.toggle('vg-pin-last', c === count - 1);
          // Alignment rides along in the same pass. Both are per-column decorations applied to
          // header and body alike, and walking every cell twice to keep them in separate
          // methods would buy nothing but a second chance for the two to disagree.
          cell.classList.toggle('vg-right', !!right[c]);
          cell.style.left = c < count ? lefts[c] + 'px' : '';
        }
      });
    },

    /** Rows are replaced on every draw, so the pinning has to be re-applied to the new ones. */
    afterDraw: function () {
      this.pin();
      this.markScrolled();
    },

    /**
     * Tell the stylesheet whether anything has scrolled under the pinned columns.
     *
     * The edge line is always drawn; the shadow is not. A shadow with nothing behind it reads
     * as an edge that has come loose, so it appears only when it is saying something — that
     * the rows continue underneath the frozen block.
     */
    markScrolled: function () {
      var wrap = this.$refs.wrap;
      if (!wrap) return;

      var box = wrap.querySelector('.dt-layout-table .dt-layout-cell');
      wrap.classList.toggle('vg-scrolled', !!box && box.scrollLeft > 0);
    },

    // ================= headers =================
    /**
     * A header, with its sort control when the column can be sorted in SQL.
     *
     * Only sortable columns get the control. A sort affordance that did nothing would be worse
     * than none — §24 puts sorting on the server, so a column the server cannot order by simply
     * does not offer one.
     */
    headerHtml: function (column, index) {
      var sortable = this.sortable.indexOf(column.key) > -1;
      var active = this.sort === column.key;
      var arrow = active ? wiIcon(this.dir === 'desc' ? 'chevron-down' : 'chevron-up', 12, 'text-brand') : '';
      var warn = column.available ? '' :
        '<span data-tip="' + wiEsc(column.reason || '') + '">' + wiIcon('circle-slash', 12, 'text-faint') + '</span>';

      return '<span class="vg-head inline-flex items-center gap-1 ' + (column.available ? 'text-head' : 'text-faint') + '"' +
        (sortable ? ' data-sort="' + wiEsc(column.key) + '"' : '') + '>' +
        wiEsc(column.label) + warn + arrow + '</span>' +
        '<span class="vg-grip" data-grip="' + index + '"></span>';
    },

    /** Redraw the headers in place — for a sort change, which must not cost the current page. */
    paintHeaders: function () {
      var table = this.$refs.table;
      if (!table) return;

      var cells = table.querySelectorAll('thead th');

      for (var i = 0; i < cells.length && i < this.visible.length; i++) {
        cells[i].innerHTML = this.headerHtml(this.visible[i], i);
      }
    },

    // ================= events =================
    bind: function () {
      var self = this;
      var table = this.$refs.table;
      if (!table) return;

      // Delegated: DataTables replaces every <tr> on each draw, so a listener bound to a row
      // would be thrown away with it.
      if (!this._bound) {
        this._bound = true;

        table.addEventListener('click', function (e) {
          if (!e.target.closest) return;

          var head = e.target.closest('[data-sort]');
          if (head) { self.$emit('sort', head.getAttribute('data-sort')); return; }

          // The ID and title links. Only a PLAIN left click is taken: ⌘/Ctrl/Shift-click and
          // anything that is not the primary button belong to the browser, so opening the item
          // in a new tab or window keeps working exactly as the href promises.
          var link = e.target.closest('[data-open]');
          if (link) {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

            e.preventDefault();
            e.stopPropagation();
            var item = self.find(link.getAttribute('data-open'));
            if (item) self.$emit('open', item);

            return;
          }

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
        });

        table.addEventListener('mousedown', function (e) {
          var grip = e.target.closest && e.target.closest('[data-grip]');
          if (grip) self.startDrag(e, parseInt(grip.getAttribute('data-grip'), 10));
        });

        // On the WRAP, in the capture phase, because scroll does not bubble — and because the
        // box that actually scrolls is created by DataTables and replaced on every rebuild,
        // while the wrap is ours and outlives all of them.
        if (this.$refs.wrap) {
          this.$refs.wrap.addEventListener('scroll', function () { self.markScrolled(); }, true);
        }
      }
    },

    /**
     * §10's column resize.
     *
     * Hand-rolled because DataTables has no resize of its own, and because with a <colgroup>
     * there is barely anything to roll: the drag writes one <col> width and the browser moves
     * the header and every row together. The server is told once, on release, rather than on
     * every mouse move.
     */
    startDrag: function (e, index) {
      if (isNaN(index)) return;
      e.preventDefault();

      var self = this;
      var startX = e.clientX;
      var startW = this.widthAt(index);
      var group = this.$refs.table.querySelector('colgroup.vg-cols');
      var col = group ? group.children[index] : null;
      if (!col) return;

      var width = startW;

      this._onDrag = function (move) {
        width = Math.max(VG_MIN_WIDTH, Math.round(startW + (move.clientX - startX)));
        col.style.width = width + 'px';
        self.$refs.table.style.width = (self.totalWidth() - startW + width) + 'px';
        self.pin();
      };

      this._endDrag = function () {
        window.removeEventListener('mousemove', self._onDrag);
        window.removeEventListener('mouseup', self._endDrag);
        document.body.classList.remove('vg-resizing');

        var column = self.visible[index];
        if (column && width !== startW) self.$emit('resize', { id: column.id, width: width });
      };

      document.body.classList.add('vg-resizing');
      window.addEventListener('mousemove', this._onDrag);
      window.addEventListener('mouseup', this._endDrag);
    },

    totalWidth: function () {
      var total = 0;
      for (var i = 0; i < this.visible.length; i++) total += this.widthAt(i);

      return total;
    },

    find: function (id) {
      var key = String(id);

      return (this._rows || []).filter(function (r) { return String(r.id) === key; })[0] || null;
    },

    /**
     * Swap one row in place, so an inline edit does not cost the page or the scroll position.
     *
     * The cells are rewritten directly rather than through the DataTables API: under
     * `serverSide` every draw is a fresh request, so `row().data().draw()` would re-fetch the
     * page to show a row we already have.
     */
    updateRow: function (row) {
      var table = this.$refs.table;
      if (!table) return false;

      var tr = table.querySelector('tbody tr[data-row="' + String(row.id).replace(/"/g, '') + '"]');
      if (!tr) return false;

      for (var i = 0; i < (this._rows || []).length; i++) {
        if (String(this._rows[i].id) === String(row.id)) this._rows[i] = row;
      }

      var self = this;
      this.visible.forEach(function (column, index) {
        var td = tr.children[index];
        if (td) td.innerHTML = self.cellHtml(column, row);
      });

      return true;
    }
  },

  // The <table> is EMPTY on purpose. DataTables builds the head and body from `columns` and
  // the ajax response, and wraps the table in its own layout container (.dt-container) which
  // carries the info line and the pager — so anything written here would be replaced anyway.
  template:
    '<div ref="wrap" class="vg-wrap flex-1 min-h-0 flex flex-col">' +
      '<table ref="table" class="vg-grid"></table>' +
    '</div>'
};
