/* Project Workspace › Cycles (Cycles §4-§10).
   ------------------------------------------------------------------
   One Vue root for both the landing page and a single cycle's detail, the same way the Work
   Items screen handles its list and per-item page: `pageCycleId` in the bootstrap decides
   which one renders, so a cycle URL is real and linkable.

   Status is never sent as an editable value — it arrives derived from the dates (§9) and this
   file only groups by it.
   ------------------------------------------------------------------ */

// ---- Status vocabulary (§5). Colours follow the POC in html/cycles.html. ----
var CY_STATUS = {
  active: { label: 'In Progress', color: '#d97706', bg: '#fef3c7' },
  upcoming: { label: 'Upcoming', color: '#2563eb', bg: '#eff6ff' },
  completed: { label: 'Completed', color: '#15803d', bg: '#f0fdf4' }
};

// ---- Breakdown buckets (§5.1), in the order the card reads them. ----
var CY_GROUPS = [
  { key: 'completed', label: 'Completed', color: '#22c55e' },
  { key: 'started', label: 'Started', color: '#d97706' },
  { key: 'unstarted', label: 'Unstarted', color: '#6366f1' },
  { key: 'backlog', label: 'Backlog', color: '#9ca3af' },
  { key: 'cancelled', label: 'Cancelled', color: '#dc2626' }
];

var CY_MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** 'YYYY-MM-DD' -> local Date. Parsed by hand: new Date('2026-08-09') is UTC and can slip a day. */
function cyParse(iso) {
  if (!iso) return null;
  var p = String(iso).slice(0, 10).split('-');
  return new Date(+p[0], +p[1] - 1, +p[2]);
}

// ================= progress ring (§5.1/§5.2) =================
var CyRing = {
  props: { percent: { type: Number, default: 0 }, size: { type: Number, default: 92 }, stroke: { type: Number, default: 9 } },
  computed: {
    r: function () { return (this.size - this.stroke) / 2 - 1; },
    circumference: function () { return 2 * Math.PI * this.r; },
    offset: function () { return this.circumference * (1 - Math.max(0, Math.min(100, this.percent)) / 100); },
    mid: function () { return this.size / 2; }
  },
  template:
    '<span class="relative inline-grid place-items-center shrink-0" :style="{width: size + \'px\', height: size + \'px\'}">' +
    '<svg :width="size" :height="size" :viewBox="\'0 0 \' + size + \' \' + size">' +
    '<circle :cx="mid" :cy="mid" :r="r" fill="none" stroke="#e5e7eb" :stroke-width="stroke"/>' +
    '<circle :cx="mid" :cy="mid" :r="r" fill="none" stroke="#22c55e" :stroke-width="stroke" stroke-linecap="round"' +
    ' :stroke-dasharray="circumference" :stroke-dashoffset="offset" :transform="\'rotate(-90 \' + mid + \' \' + mid + \')\'"/>' +
    '</svg>' +
    '<span class="absolute flex flex-col items-center"><slot><span class="text-[20px] font-semibold text-head leading-none">{{ percent }}%</span>' +
    '<span class="text-[10px] text-faint mt-0.5">Progress</span></slot></span></span>'
};

PB.boot('project-cycles', {
  props: { bootstrap: Object },
  components: { 'cy-ring': CyRing, 'wi-calendar': WiCalendar, 'wi-list': WiList, 'work-items-screen': WorkItemsScreen },
  data: function () {
    var b = this.bootstrap || {};
    return {
      project: b.project || {},
      cycles: (b.cycles || []).slice(),
      items: (b.items || []).slice(),
      states: Array.isArray(b.states) ? b.states : [],
      pageCycleId: b.pageCycleId || null,
      canCreate: !!b.canCreate,
      labelsEnabled: b.labelsEnabled !== false,
      // The full work items payload for this record, or null on the landing page.
      workItems: b.workItems || null,
      // Feature Disable §4: the page still loads when Cycles is off so existing cycles stay
      // readable; these render the read-only state and say why.
      featureEnabled: b.featureEnabled !== false,
      disabledNotice: b.disabledNotice || '',
      settingsUrl: b.settingsUrl || '',
      canDelete: !!b.canDelete,
      parallel: !!b.parallel,
      nameMax: b.nameMax || 120,
      descriptionMax: b.descriptionMax || 2000,
      endpoints: b.endpoints || {},
      tab: 'active',
      query: '',
      searchOpen: false,
      // Create / edit cycle
      form: { open: false, id: null, name: '', description: '', start_date: null, end_date: null, saving: false, errors: {} },
      // Which date field's calendar is open, and where to put it. Fixed coordinates and a
      // teleport, because the dialog body scrolls and would otherwise clip the popover.
      dateMenu: { open: '', style: {} },
      confirm: { open: false, cycle: null },
      menu: { open: false, cycle: null, style: {} },
      // Add work items (§7.3)
      picker: { open: false, query: '', results: [], selected: [], busy: false, loaded: false },
      // Transfer incomplete work (§10)
      transfer: { open: false, to: null, selected: [], busy: false }
    };
  },
  computed: {
    pageCycle: function () {
      var id = this.pageCycleId; var self = this;
      return id ? this.cycles.filter(function (c) { return c.id === self.pageCycleId; })[0] || null : null;
    },
    filtered: function () {
      var q = (this.query || '').trim().toLowerCase();
      return this.cycles.filter(function (c) { return !q || (c.name || '').toLowerCase().indexOf(q) > -1; });
    },
    active: function () { return this.filtered.filter(function (c) { return c.status === 'active'; }); },
    upcoming: function () { return this.filtered.filter(function (c) { return c.status === 'upcoming'; }); },
    completed: function () { return this.filtered.filter(function (c) { return c.status === 'completed'; }); },
    shown: function () { return this[this.tab] || []; },
    groups: function () { return CY_GROUPS; },
    /** Cycles a transfer may land in (§10): this project's Active or Upcoming, not this one. */
    destinations: function () {
      var self = this;
      return this.cycles.filter(function (c) { return c.status !== 'completed' && c.id !== self.pageCycleId; });
    },
    incompleteItems: function () {
      return this.items.filter(function (i) { return i.group !== 'completed' && i.group !== 'cancelled'; });
    },
    formValid: function () { return !!this.form.name.trim() && !!this.form.start_date && !!this.form.end_date; },
    /**
     * The grid sizes itself to its rows, up to a point.
     *
     * Tabulator needs a fixed height to virtualise, but this grid sits inside a page that
     * already scrolls — so it grows with the list and only becomes its own scroll area once
     * a cycle holds more work than fits comfortably on screen.
     */
    gridHeight: function () {
      // Every state gets a group header, occupied or not — the same as the Work Items list —
      // so the height has to allow for all of them plus "No state", or the grid scrolls when
      // it did not need to. 44 per row, 40 per header, matching the skin.
      var headers = (this.states.length + 1) * 40;

      return Math.min(640, this.items.length * 44 + headers + 8) + 'px';
    }
  },
  methods: {
    // ---------- the cycle's work item grid (§7.2) ----------
    // The grid IS the project's work item list — <wi-list>, the same component the Work
    // Items screen mounts. Read-only chips: planning a cycle is about WHICH items are in it,
    // and editing one is the work item screen's job, a row-click away.
    openItem: function (item) { window.location.href = this.itemUrl(item); },

    // ---------- formatting ----------
    statusMeta: function (key) { return CY_STATUS[key] || CY_STATUS.upcoming; },
    /** 'Aug 08 – 22, 2026' when one month, 'Aug 23 – Sep 06, 2026' across two. */
    fmtRange: function (c) {
      var s = cyParse(c.start_date), e = cyParse(c.end_date);
      if (!s || !e) return '';
      var head = CY_MON[s.getMonth()] + ' ' + ('0' + s.getDate()).slice(-2);
      var tail = (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear())
        ? ('0' + e.getDate()).slice(-2)
        : CY_MON[e.getMonth()] + ' ' + ('0' + e.getDate()).slice(-2);
      var sy = s.getFullYear() === e.getFullYear() ? '' : ', ' + s.getFullYear();
      return head + sy + ' – ' + tail + ', ' + e.getFullYear();
    },
    daysLabel: function (c) {
      if (c.status === 'completed') return 'Ended';
      if (c.status === 'upcoming') return 'Not started';
      var n = c.days_remaining || 0;
      return n === 0 ? 'Last day' : (n === 1 ? '1 day left' : n + ' days left');
    },
    count: function (c, key) { return (c.breakdown && c.breakdown[key]) || 0; },
    percent: function (c) { return (c.breakdown && c.breakdown.progress) || 0; },
    barWidth: function (c, key) {
      var scope = this.count(c, 'scope');
      return scope ? (this.count(c, key) / scope * 100) + '%' : '0%';
    },
    cycleUrl: function (c) { return this.$pb.withId(this.endpoints.cycle, c.id); },
    itemUrl: function (i) { return this.$pb.withId(this.endpoints.workItem, i.id); },
    goto: function (c) { window.location.href = this.cycleUrl(c); },

    // ---------- create / edit (§6, §9.1) ----------
    openCreate: function () {
      this.form = { open: true, id: null, name: '', description: '', start_date: null, end_date: null, saving: false, errors: {} };
    },
    openEdit: function (c) {
      this.closeMenu();
      this.form = { open: true, id: c.id, name: c.name, description: c.description || '', start_date: c.start_date, end_date: c.end_date, saving: false, errors: {} };
    },
    fmtDate: function (iso) { return wiFmtDate(iso); },
    /**
     * Open one field's calendar, anchored to its own button.
     *
     * The same <wi-calendar> the work item Start/Due chips use, so a date is picked the same
     * way everywhere — quick options, then a month grid with month and year dropdowns.
     */
    openDate: function (kind, btn) {
      var w = 300, h = 380;
      var r = btn.getBoundingClientRect();
      var style = { position: 'fixed', width: w + 'px', zIndex: 130 };
      style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
      if (r.bottom + h > window.innerHeight - 8 && r.top > h) style.bottom = (window.innerHeight - r.top + 6) + 'px';
      else style.top = (r.bottom + 6) + 'px';

      this.dateMenu = { open: kind, style: style };
    },
    closeDate: function () { this.dateMenu = { open: '', style: {} }; },
    onDatePick: function (iso) {
      this.form[this.dateMenu.open] = iso;
      this.closeDate();
    },
    onDateClear: function () {
      this.form[this.dateMenu.open] = null;
      this.closeDate();
    },
    /**
     * The other end of the range, moved one day out.
     *
     * wi-calendar's bounds are EXCLUSIVE — a work item's due date must fall strictly after
     * its start date. A cycle is different: §6.2 only says the end date cannot be *earlier*
     * than the start, so a one-day cycle is legal. Shifting the bound by a day makes the
     * shared picker express that without giving it a second meaning for work items.
     */
    dateBound: function (iso, days) {
      var d = wiParseISO(iso);
      if (!d) return null;

      return wiISO(new Date(d.getFullYear(), d.getMonth(), d.getDate() + days));
    },
    saveCycle: async function () {
      if (!this.formValid || this.form.saving) return;
      this.form.saving = true; this.form.errors = {};
      var body = {
        name: this.form.name.trim(), description: this.form.description || null,
        start_date: this.form.start_date, end_date: this.form.end_date
      };
      try {
        var editing = !!this.form.id;
        var url = editing ? this.$pb.withId(this.endpoints.update, this.form.id) : this.endpoints.store;
        var resp = await this.$pb.api(url, { method: editing ? 'PATCH' : 'POST', body: body });
        this.mergeCycle(resp.cycle);
        this.form.open = false;
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) {
        this.form.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.form.saving = false;
    },
    /** Replace a cycle in place, or append it — the server's copy is always the truth. */
    mergeCycle: function (cycle) {
      if (!cycle) return;
      var at = -1;
      for (var i = 0; i < this.cycles.length; i++) { if (this.cycles[i].id === cycle.id) { at = i; break; } }
      if (at > -1) this.cycles.splice(at, 1, cycle); else this.cycles.push(cycle);
      this.cycles.sort(function (a, b) { return (a.start_date || '') < (b.start_date || '') ? -1 : 1; });
      // A new cycle's own tab is where the user will look for it.
      if (!this.pageCycleId) this.tab = cycle.status;
    },

    // ---------- ⋯ menu ----------
    openMenu: function (c, btn) {
      var r = btn.getBoundingClientRect();
      this.menu = {
        open: true, cycle: c,
        style: { position: 'fixed', width: '184px', zIndex: 120, top: (r.bottom + 6) + 'px', left: Math.max(8, r.right - 184) + 'px' }
      };
    },
    closeMenu: function () { this.menu = { open: false, cycle: null, style: {} }; },
    askDelete: function (c) { this.closeMenu(); this.confirm = { open: true, cycle: c }; },
    removeCycle: async function () {
      var c = this.confirm.cycle; if (!c) return;
      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.destroy, c.id), { method: 'DELETE' });
        // Deleting the cycle currently on screen leaves nothing to show, so go back to the list.
        if (this.pageCycleId === c.id) { window.location.href = this.endpoints.list; return; }
        this.cycles = this.cycles.filter(function (x) { return x.id !== c.id; });
        this.$pb.toast('Cycle deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, cycle: null };
    },

    // ---------- work items (§7.2/§7.3) ----------
    openPicker: function () {
      this.picker = { open: true, query: '', results: [], selected: [], busy: false, loaded: false };
      this.searchItems();
    },
    searchItems: async function () {
      if (!this.pageCycleId) return;
      this.picker.busy = true;
      try {
        var url = this.$pb.withId(this.endpoints.search, this.pageCycleId) + '?q=' + encodeURIComponent(this.picker.query || '');
        var resp = await this.$pb.api(url);
        this.picker.results = resp.items || [];
        this.picker.loaded = true;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    togglePicked: function (item) {
      var at = this.picker.selected.indexOf(item.id);
      if (at > -1) this.picker.selected.splice(at, 1); else this.picker.selected.push(item.id);
    },
    isPicked: function (item) { return this.picker.selected.indexOf(item.id) > -1; },
    addItems: async function () {
      if (!this.picker.selected.length || this.picker.busy) return;
      this.picker.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.addItems, this.pageCycleId), {
          method: 'POST', body: { work_item_ids: this.picker.selected }
        });
        this.items = resp.items || this.items;
        this.mergeCycle(resp.cycle);
        this.picker.open = false;
        this.$pb.toast(resp.message || 'Added.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    removeItem: async function (item) {
      try {
        var url = this.$pb.withId(this.endpoints.removeItem, this.pageCycleId).replace('__ITEM__', item.id);
        var resp = await this.$pb.api(url, { method: 'DELETE' });
        this.items = resp.items || this.items;
        this.$pb.toast(resp.message || 'Removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- transfer incomplete work (§10) ----------
    openTransfer: function () {
      this.closeMenu();
      this.transfer = {
        open: true, to: null, busy: false,
        // Everything unfinished is pre-selected: moving all of it is the common case, and
        // deselecting a few is less work than picking a dozen.
        selected: this.incompleteItems.map(function (i) { return i.id; })
      };
    },
    toggleTransfer: function (item) {
      var at = this.transfer.selected.indexOf(item.id);
      if (at > -1) this.transfer.selected.splice(at, 1); else this.transfer.selected.push(item.id);
    },
    isTransferring: function (item) { return this.transfer.selected.indexOf(item.id) > -1; },
    runTransfer: async function () {
      if (!this.transfer.to || !this.transfer.selected.length || this.transfer.busy) return;
      this.transfer.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.transfer, this.pageCycleId), {
          method: 'POST', body: { to_cycle_id: this.transfer.to, work_item_ids: this.transfer.selected }
        });
        this.items = resp.items || this.items;
        this.transfer.open = false;
        this.$pb.toast(resp.message || 'Transferred.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.transfer.busy = false;
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // Feature Disable §4: read-only notice, shown on the list and the detail alike.
    '<div v-if="!featureEnabled" class="flex items-start gap-2.5 px-6 py-2.5 border-b border-line bg-amber-50 shrink-0">' +
    '' + wiIcon('circle-info', 15, 'text-amber-700 shrink-0 mt-0.5') + '' +
    '<p class="text-[12px] text-amber-900">{{ disabledNotice }}' +
    '<a v-if="settingsUrl" :href="settingsUrl" class="ml-1 font-semibold underline">Project settings</a></p></div>' +

    // ============ Detail view (§7) ============
    '<template v-if="pageCycle">' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.list" data-tip="Back to cycles" class="text-sub hover:text-ink shrink-0">' +
    '' + wiIcon('arrow-left', 16) + '</a>' +
    '<span class="text-[14px] font-medium text-ink truncate">{{ pageCycle.name }}</span>' +
    '<span class="inline-flex items-center h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap"' +
    ' :style="{color: statusMeta(pageCycle.status).color, background: statusMeta(pageCycle.status).bg}">{{ statusMeta(pageCycle.status).label }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-md border border-stroke bg-white text-[12px] text-sub whitespace-nowrap">' +
    '' + wiIcon('calendar', 13) + '' +
    '{{ fmtRange(pageCycle) }}</span>' +
    '<span class="text-[12px] text-faint">{{ daysLabel(pageCycle) }}</span>' +
    '<div class="ml-auto flex items-center gap-2">' +
    '<button v-if="canCreate && pageCycle.status !== \'completed\'" type="button" @click="openPicker" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '' + wiIcon('plus', 14) + 'Add work items</button>' +
    '<button v-if="canCreate" type="button" @click="openMenu(pageCycle, $event.currentTarget)" data-tip="More" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover border border-stroke">' +
    '' + wiIcon('ellipsis-thin', 16) + '</button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto"><div class="p-4 sm:p-6 max-w-[1200px] mx-auto w-full">' +
    '<p v-if="pageCycle.description" class="text-[13px] text-sub leading-relaxed mb-4 whitespace-pre-line">{{ pageCycle.description }}</p>' +

    // Breakdown card
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<div class="flex items-center gap-4">' +
    '<cy-ring :percent="percent(pageCycle)" />' +
    '<div class="min-w-0">' +
    '<h3 class="text-[13px] font-semibold text-head">Breakdown of this cycle\'s work items</h3>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ count(pageCycle, \'completed\') }} of {{ count(pageCycle, \'scope\') }} work items completed</p>' +
    '<div class="mt-3 flex h-2.5 w-full max-w-[280px] rounded-full overflow-hidden bg-line">' +
    '<span v-for="g in groups" :key="g.key" :style="{width: barWidth(pageCycle, g.key), background: g.color}" :data-tip="g.label"></span>' +
    '</div></div></div>' +
    '<div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2.5">' +
    '<div v-for="g in groups" :key="g.key" class="flex items-center justify-between">' +
    '<span class="inline-flex items-center gap-2 text-[12px] text-ink"><span class="h-2.5 w-2.5 rounded-full" :style="{background: g.color}"></span>{{ g.label }}</span>' +
    '<span class="text-[12px] text-sub font-medium">{{ count(pageCycle, g.key) }}</span></div>' +
    '<div class="flex items-center justify-between">' +
    '<span class="inline-flex items-center gap-2 text-[12px] text-ink">' +
    '' + wiIcon('bars-staggered', 13, 'text-sub') + 'Scope</span>' +
    '<span class="text-[12px] text-sub font-medium">{{ count(pageCycle, \'scope\') }}</span></div>' +
    '</div></div>' +

    '<div class="h-6"></div></div>' +

    // Cycle work items (§7.2) — the project's Work Items grid, full width, so a row here has
    // the same structure, the same chips and the same reading order as a row there. Outside
    // the centred card column on purpose: boxing the grid narrower than the list it mirrors
    // is exactly what made the two look like different things.
    '<div>' +
    // Just a heading and a count. "Add work items" lives once, in the page header above —
    // the same action twice on one screen is two things to keep in sync and one decision the
    // reader has to make for no reason.
    '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line">' +
    '<span class="flex items-center gap-2 text-[13px] font-medium text-ink shrink-0">' +
    '' + wiIcon('bars-thin', 15, 'text-sub') + '' +
    'Cycle work items <span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ items.length }}</span></span>' +
    '</div>' +
    '<div v-show="!items.length" class="px-6 py-12 text-center">' +
    '<p class="text-[13px] text-sub">No work items in this cycle yet.</p>' +
    '<button v-if="canCreate && pageCycle.status !== \'completed\'" type="button" @click="openPicker" ' +
    'class="mt-3 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Add work items</button></div>' +
    // The work items SCREEN, given this cycle's rows — editable chips, the real drawer, the
    // same everything, because it is the same component the project's list uses. v-if rather
    // than v-show: the grid inside measures itself on mount and a display:none container
    // gives it a height of zero.
    '<work-items-screen v-if="workItems && items.length" :bootstrap="workItems" />' +
    '</div>' +

    '<div class="h-8"></div></div>' +
    '</template>' +

    // ============ Landing page (§5) ============
    '<template v-else>' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<div class="flex items-center gap-1 h-12">' +
    '<button v-for="t in [\'active\', \'upcoming\', \'completed\']" :key="t" type="button" @click="tab = t" ' +
    ':class="[\'px-1 h-12 flex items-center text-[13px] font-medium border-b-2\', tab === t ? \'text-ink border-brand\' : \'text-sub hover:text-ink border-transparent\']">' +
    '<span class="px-2 py-1 rounded-md hover:bg-hover capitalize">{{ t }}</span>' +
    '<span class="text-[11px] text-faint ml-0.5">{{ (t === \'active\' ? active : (t === \'upcoming\' ? upcoming : completed)).length }}</span></button>' +
    '</div>' +
    '<div class="ml-auto flex items-center gap-1.5 text-sub">' +
    '<input v-if="searchOpen" v-model="query" placeholder="Search cycles…" ref="search" ' +
    'class="h-8 w-52 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<button type="button" @click="searchOpen = !searchOpen; query = \'\'" data-tip="Search" aria-label="Search" class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover">' +
    '' + wiIcon('magnifying-glass', 16) + '</button>' +
    '<button v-if="canCreate" type="button" @click="openCreate" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0">' +
    '' + wiIcon('plus', 14) + '<span class="hidden sm:inline">Add cycle</span></button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto">' +

    // Empty state (§5.4)
    '<div v-if="!shown.length" class="h-full min-h-[360px] flex flex-col items-center justify-center text-center px-6 py-16">' +
    '<span class="h-16 w-16 rounded-2xl bg-hover grid place-items-center text-faint mb-4">' +
    '' + wiIcon('rotate', 30) + '</span>' +
    '<h3 class="text-[15px] font-semibold text-head">No {{ tab }} cycles</h3>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[420px]">' +
    'Cycles are time boxes — a start date, an end date, and the work your team plans to finish between them. ' +
    'Work items you add to a cycle keep everything else about them.</p>' +
    '<button v-if="canCreate" type="button" @click="openCreate" class="mt-5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Create cycle</button>' +
    '</div>' +

    // Active tab: the full card for each running cycle (§5.1)
    '<template v-else-if="tab === \'active\'">' +
    '<div v-for="c in shown" :key="c.id" class="p-4 sm:p-6 max-w-[1200px] mx-auto w-full">' +
    '<div class="flex flex-wrap items-center gap-3 mb-4">' +
    '<a :href="cycleUrl(c)" class="inline-flex items-center gap-2 text-[16px] font-semibold text-head hover:text-brand">' +
    '' + wiIcon('rotate', 18, 'text-brand') + '{{ c.name }}</a>' +
    '<span class="inline-flex items-center h-6 px-2.5 rounded-md text-[12px] font-medium" :style="{color: statusMeta(c.status).color, background: statusMeta(c.status).bg}">{{ statusMeta(c.status).label }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-md border border-stroke bg-white text-[12px] text-sub">' +
    '' + wiIcon('calendar', 13) + '{{ fmtRange(c) }}</span>' +
    '<span class="text-[12px] text-faint">{{ daysLabel(c) }}</span>' +
    '<div class="ml-auto flex items-center gap-2">' +
    '<span v-if="c.created_by" :style="{ background: $pb.avatarColor(c.created_by) }" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold" :data-tip="\'Created by \' + c.created_by.name">' +
    '<img v-if="c.created_by.avatar_url" :src="c.created_by.avatar_url" alt="" class="h-full w-full object-cover" /><span v-else>{{ c.created_by.initial }}</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(c, $event.currentTarget)" data-tip="More" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover border border-stroke">' +
    '' + wiIcon('ellipsis-thin', 16) + '</button>' +
    '</div></div>' +
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<div class="flex items-center gap-4">' +
    '<cy-ring :percent="percent(c)" />' +
    '<div class="min-w-0">' +
    '<h3 class="text-[13px] font-semibold text-head">Breakdown of this cycle\'s work items</h3>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ count(c, \'completed\') }} of {{ count(c, \'scope\') }} work items completed</p>' +
    '<div class="mt-3 flex h-2.5 w-full max-w-[280px] rounded-full overflow-hidden bg-line">' +
    '<span v-for="g in groups" :key="g.key" :style="{width: barWidth(c, g.key), background: g.color}" :data-tip="g.label"></span>' +
    '</div></div></div>' +
    '<div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2.5">' +
    '<div v-for="g in groups" :key="g.key" class="flex items-center justify-between">' +
    '<span class="inline-flex items-center gap-2 text-[12px] text-ink"><span class="h-2.5 w-2.5 rounded-full" :style="{background: g.color}"></span>{{ g.label }}</span>' +
    '<span class="text-[12px] text-sub font-medium">{{ count(c, g.key) }}</span></div>' +
    '<div class="flex items-center justify-between">' +
    '<span class="inline-flex items-center gap-2 text-[12px] text-ink">' +
    '' + wiIcon('bars-staggered', 13, 'text-sub') + 'Scope</span>' +
    '<span class="text-[12px] text-sub font-medium">{{ count(c, \'scope\') }}</span></div>' +
    '</div>' +
    '<div class="mt-5 pt-4 border-t border-line">' +
    '<a :href="cycleUrl(c)" class="text-[13px] font-semibold text-brand hover:underline">Open cycle →</a></div>' +
    '</div></div></template>' +

    // Upcoming / Completed: one row each (§5.2/§5.3)
    '<div v-else class="divide-y divide-line">' +
    '<div v-for="c in shown" :key="c.id" class="group flex items-center gap-3 px-6 h-[52px] hover:bg-[#f8f9fa]">' +
    '<cy-ring :percent="percent(c)" :size="30" :stroke="2.4">' +
    '<span class="text-[8px] font-semibold text-sub">{{ percent(c) }}%</span></cy-ring>' +
    '<a :href="cycleUrl(c)" class="text-[14px] text-ink truncate flex-1 hover:text-brand">{{ c.name }}</a>' +
    '<span class="text-[12px] text-sub whitespace-nowrap hidden sm:inline">{{ fmtRange(c) }}</span>' +
    '<span class="inline-flex items-center h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap" :style="{color: statusMeta(c.status).color, background: statusMeta(c.status).bg}">{{ statusMeta(c.status).label }}</span>' +
    '<span v-if="c.created_by" :style="{ background: $pb.avatarColor(c.created_by) }" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" :data-tip="\'Created by \' + c.created_by.name">' +
    '<img v-if="c.created_by.avatar_url" :src="c.created_by.avatar_url" alt="" class="h-full w-full object-cover" /><span v-else>{{ c.created_by.initial }}</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(c, $event.currentTarget)" data-tip="More" aria-label="More" ' +
    'class="h-7 w-7 grid place-items-center rounded hover:bg-line text-faint opacity-0 group-hover:opacity-100 shrink-0">' +
    '' + wiIcon('ellipsis-thin', 15) + '</button>' +
    '</div></div>' +

    '</div></template>' +

    // ============ ⋯ menu ============
    '<div v-if="menu.open" class="fixed inset-0 z-[110]" @click="closeMenu"></div>' +
    '<div v-if="menu.open" :style="menu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5 py-1 text-[13px]">' +
    '<button type="button" @click="openEdit(menu.cycle)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '' + wiIcon('pen', 15, 'text-faint shrink-0') + 'Edit cycle</button>' +
    '<a :href="cycleUrl(menu.cycle)" class="w-full flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '' + wiIcon('arrow-up-right-from-square', 15, 'text-faint shrink-0') + 'Open cycle</a>' +
    // §10: only offered on a finished cycle, which is the only time there is unfinished work
    // left behind to move on.
    '<button v-if="menu.cycle && menu.cycle.status === \'completed\' && pageCycleId === menu.cycle.id" type="button" @click="openTransfer" ' +
    'class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '' + wiIcon('arrow-right-arrow-left', 15, 'text-faint shrink-0') + 'Transfer work items</button>' +
    '<template v-if="canDelete"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="askDelete(menu.cycle)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger">' +
    '' + wiIcon('trash', 15, 'shrink-0') + 'Delete cycle</button></template>' +
    '</div>' +

    // ============ Create / edit cycle (§6.1) ============
    '<pb-modal :open="form.open" :title="form.id ? \'Edit cycle\' : \'Create cycle\'" @close="form.open = false">' +
    '<input v-model="form.name" :maxlength="nameMax" placeholder="Title" @keyup.enter="saveCycle" ' +
    'class="w-full h-11 px-3 rounded-md text-[15px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 focus:outline-brand" ' +
    ':class="form.errors.name ? \'outline-danger\' : \'outline-stroke\'" />' +
    '<p v-if="form.errors.name" class="text-[12px] text-danger mt-1">{{ form.errors.name[0] }}</p>' +
    '<textarea v-model="form.description" rows="4" :maxlength="descriptionMax" placeholder="Description" ' +
    'class="w-full mt-3 px-3 py-2.5 rounded-md text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-stroke focus:outline-brand resize-none"></textarea>' +
    // Two fields rather than one range control, styled like the work item list's Start and
    // Due date chips — same button, same icon, same MM/DD/YYYY label.
    '<div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">' +
    '<div class="min-w-0">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Start date</label>' +
    '<button type="button" @click="openDate(\'start_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border text-[13px] hover:bg-hover" ' +
    ':class="[form.errors.start_date ? \'border-danger\' : \'border-stroke\', form.start_date ? \'text-ink\' : \'text-sub\']">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</button></div>' +

    '<div class="min-w-0">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">End date</label>' +
    '<button type="button" @click="openDate(\'end_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border text-[13px] hover:bg-hover" ' +
    ':class="[form.errors.end_date ? \'border-danger\' : \'border-stroke\', form.end_date ? \'text-ink\' : \'text-sub\']">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '{{ form.end_date ? fmtDate(form.end_date) : \'End date\' }}</button></div>' +
    '</div>' +
    '<p v-if="form.errors.start_date" class="text-[12px] text-danger mt-1.5">{{ form.errors.start_date[0] }}</p>' +
    '<p v-if="form.errors.end_date" class="text-[12px] text-danger mt-1.5">{{ form.errors.end_date[0] }}</p>' +
    '<p v-if="!parallel" class="text-[12px] text-sub mt-2">' +
    'Parallel cycles are off, so these dates cannot overlap another active or upcoming cycle.</p>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="form.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!formValid || form.saving" @click="saveCycle">{{ form.saving ? \'Saving…\' : (form.id ? \'Save\' : \'Create cycle\') }}</button>' +
    '</template></pb-modal>' +

    // ============ Add work items (§7.3) ============
    '<pb-modal :open="picker.open" title="Add work items" @close="picker.open = false">' +
    '<input v-model="picker.query" @input="searchItems" placeholder="Search work items…" ' +
    'class="w-full h-9 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="mt-2 max-h-[320px] overflow-y-auto">' +
    '<button v-for="r in picker.results" :key="r.id" type="button" @click="togglePicked(r)" ' +
    'class="w-full text-left flex items-center gap-2.5 px-2 py-2 rounded-md hover:bg-hover">' +
    '<span class="h-[18px] w-[18px] rounded border grid place-items-center shrink-0 transition-colors" ' +
    ':class="isPicked(r) ? \'bg-brand border-brand\' : \'border-stroke bg-white\'">' +
    '<span v-if="isPicked(r)">' + wiIcon('check-on-fill', 12) + '</span></span>' +
    '<span class="text-[11px] text-faint font-medium shrink-0">{{ r.identifier }}</span>' +
    '<span class="text-[13px] text-ink truncate flex-1">{{ r.title }}</span>' +
    // §7.3: adding an item that already belongs elsewhere is a MOVE, and saying so up front
    // is the difference between a deliberate act and a surprise.
    '<span v-if="r.cycle" class="text-[11px] text-amber-700 bg-amber-50 rounded px-1.5 py-0.5 shrink-0" :data-tip="\'Currently in \' + r.cycle.name">Moves from {{ r.cycle.name }}</span>' +
    '</button>' +
    '<p v-if="picker.loaded && !picker.results.length" class="px-2 py-6 text-[13px] text-sub text-center">No work items match.</p>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="picker.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!picker.selected.length || picker.busy" @click="addItems">Add {{ picker.selected.length || \'\' }}</button>' +
    '</template></pb-modal>' +

    // ============ Transfer incomplete work (§10) ============
    '<pb-modal :open="transfer.open" title="Transfer work items" @close="transfer.open = false">' +
    '<p class="text-[13px] text-sub leading-relaxed">Move unfinished work out of this cycle. Completed and cancelled items stay where they are.</p>' +
    '<div class="mt-3">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Move to</label>' +
    '<select v-model="transfer.to" class="pb-input w-full">' +
    '<option :value="null">Choose a cycle…</option>' +
    '<option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }} · {{ statusMeta(d.status).label }}</option>' +
    '</select>' +
    '<p v-if="!destinations.length" class="text-[12px] text-sub mt-1.5">There is no active or upcoming cycle to move this work into yet.</p>' +
    '</div>' +
    '<div class="mt-4 max-h-[240px] overflow-y-auto">' +
    '<button v-for="i in incompleteItems" :key="i.id" type="button" @click="toggleTransfer(i)" ' +
    'class="w-full text-left flex items-center gap-2.5 px-2 py-2 rounded-md hover:bg-hover">' +
    '<span class="h-[18px] w-[18px] rounded border grid place-items-center shrink-0 transition-colors" ' +
    ':class="isTransferring(i) ? \'bg-brand border-brand\' : \'border-stroke bg-white\'">' +
    '<span v-if="isTransferring(i)">' + wiIcon('check-on-fill', 12) + '</span></span>' +
    '<span class="text-[11px] text-faint font-medium shrink-0">{{ i.identifier }}</span>' +
    '<span class="text-[13px] text-ink truncate flex-1">{{ i.title }}</span></button>' +
    '<p v-if="!incompleteItems.length" class="px-2 py-6 text-[13px] text-sub text-center">Nothing unfinished — this cycle is fully closed out.</p>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="transfer.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!transfer.to || !transfer.selected.length || transfer.busy" @click="runTransfer">Transfer {{ transfer.selected.length || \'\' }}</button>' +
    '</template></pb-modal>' +

    // One calendar instance for both fields: `dateMenu.open` says which one it is editing,
    // and the bounds are read from the other end of the range.
    '<teleport to="body">' +
    '<div v-if="dateMenu.open" class="fixed inset-0 z-[129]" @click="closeDate"></div>' +
    '<div v-if="dateMenu.open" :style="dateMenu.style" class="rounded-lg bg-white shadow-xl outline outline-1 outline-black/5">' +
    '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none" ' +
    ':value="dateMenu.open === \'start_date\' ? form.start_date : form.end_date" ' +
    ':after="dateMenu.open === \'end_date\' ? dateBound(form.start_date, -1) : null" ' +
    ':before="dateMenu.open === \'start_date\' ? dateBound(form.end_date, 1) : null" ' +
    '@pick="onDatePick" @clear="onDateClear" />' +
    '</div></teleport>' +

    '<pb-confirm :open="confirm.open" title="Delete cycle?" ' +
    ':message="\'This deletes the cycle. Its work items are kept and simply stop belonging to a cycle. This cannot be undone.\'" ' +
    'confirm-label="Delete cycle" @close="confirm.open = false" @confirm="removeCycle" />' +

    '</div>'
});
