/* =====================================================================================
 * Space Overview — the reporting dashboard (docs/features/help-center.md, P50)
 * =====================================================================================
 *
 * Every panel reads from ONE `report` object the server built, and every filter change refetches
 * that whole object. Not because refetching everything is cheap, but because a dashboard whose
 * panels refresh independently is a dashboard that spends part of its life showing eight numbers
 * from one moment and two from another — and nobody can tell which.
 *
 * The charts are inline SVG rather than a charting library. Two bar series and a donut is not
 * worth 200KB of dependency, and an <svg> the browser lays out itself has no resize observer to
 * get wrong.
 */

/** Minutes as something a person reads: "14 min", "6h 24m", "2d 6h". */
function hcDuration(minutes) {
  if (minutes === null || minutes === undefined) return '—';

  var m = Math.max(0, Math.round(minutes));

  if (m < 60) return m + ' min';

  var h = Math.floor(m / 60);
  var rem = m % 60;

  if (h < 24) return h + 'h' + (rem ? ' ' + rem + 'm' : '');

  var d = Math.floor(h / 24);

  return d + 'd' + (h % 24 ? ' ' + (h % 24) + 'h' : '');
}

/** 1248 → "1,248". Thousands separators, because a four-digit count read as 1248 is a year. */
function hcNum(n) {
  return (n === null || n === undefined) ? '—' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

PB.boot('help-center-overview', {
  props: { bootstrap: { type: Object, default: function () { return {}; } } },

  data: function () {
    var b = this.bootstrap || {};

    return {
      report: b.report || null,
      options: b.options || {},
      // The filter draft. Seeded from what the SERVER resolved, not from the URL directly, so the
      // controls show what actually ran — a `range=nonsense` in the address bar falls back to the
      // default server-side, and the picker should say so rather than showing the nonsense.
      f: Object.assign(
        { range: 'last_30', from: '', to: '', assignee: [], status: [], priority: [], tag: [], group: 'auto' },
        (b.report && b.report.filters) || {},
      ),
      loading: false,
      // The Space Configuration dialog (P51). It moved off the page and into here because it is
      // reference material, not a report — see the button's own note.
      configOpen: false,
      // Which multi-select is open. One at a time: two open dropdowns is two lists competing for
      // the same click.
      openFilter: null,
    };
  },

  computed: {
    k: function () { return (this.report && this.report.kpis) || {}; },

    /** Nothing has ever arrived — the requirement's first empty state. */
    isEmptySpace: function () {
      return !!this.report && !this.report.hasAnyTickets;
    },

    /** Tickets exist, but not in this window — the SECOND, different empty state. */
    isEmptyRange: function () {
      return !!this.report && this.report.hasAnyTickets && this.report.periodCount === 0;
    },

    /** The tallest bar in the volume chart, so both series share one scale. */
    volumeMax: function () {
      var pts = (this.report && this.report.volume && this.report.volume.points) || [];
      var max = 0;

      pts.forEach(function (p) {
        max = Math.max(max, p.created, p.closed);
      });

      // Never 0: dividing by it would make every bar NaN tall and the chart would vanish.
      return max || 1;
    },

    /**
     * The status donut, as SVG arc segments.
     *
     * Computed as stroke-dasharray offsets on one circle rather than as paths: a circle with a
     * dashed stroke IS a donut, and it needs no trigonometry that could be subtly wrong.
     */
    donut: function () {
      var rows = ((this.report && this.report.statusBreakdown) || []).filter(function (r) { return r.total > 0; });
      var total = rows.reduce(function (a, r) { return a + r.total; }, 0) || 1;
      var circumference = 2 * Math.PI * 60;
      var offset = 0;

      return rows.map(function (r) {
        var len = (r.total / total) * circumference;
        var seg = { name: r.name, color: r.color || '#9ca3af', dash: len + ' ' + (circumference - len), offset: -offset, id: r.id };
        offset += len;

        return seg;
      });
    },

    workloadMax: function () {
      var rows = (this.report && this.report.workload) || [];

      return Math.max(1, Math.max.apply(null, [1].concat(rows.map(function (r) { return r.total; }))));
    },

    agingMax: function () {
      var rows = (this.report && this.report.aging) || [];

      return Math.max(1, Math.max.apply(null, [1].concat(rows.map(function (r) { return r.total; }))));
    },

    /** The volume chart's grouping choices, in `pb-combo`'s `{value,label}` shape (P61). */
    groupingOptions: function () {
      return ((this.options || {}).groupings || []).map(function (g) {
        return { value: g, label: g === 'auto' ? 'Auto' : g.charAt(0).toUpperCase() + g.slice(1) };
      });
    },

    activeFilterCount: function () {
      return this.f.assignee.length + this.f.status.length + this.f.priority.length + this.f.tag.length;
    },

    /** What the range button says. For a custom range that is the dates, not the word "Custom". */
    rangeLabel: function () {
      if (this.f.range === 'custom') {
        return (this.f.from && this.f.to) ? (this.f.from + ' → ' + this.f.to) : 'Custom Range';
      }

      var hit = (this.options.ranges || []).filter(function (r) { return r.value === this.f.range; }, this)[0];

      return hit ? hit.label : 'Last 30 Days';
    },

    /**
     * The four multi-selects, as data.
     *
     * One markup driven by a list rather than four near-identical dropdowns written out: they
     * differ only in where their options come from, and four copies is four places for a
     * behaviour to drift.
     */
    filterDefs: function () {
      var o = this.options || {};

      return [
        {
          key: 'assignee',
          label: 'Assignee',
          empty: 'No members in this Space.',
          options: (o.assignees || []).map(function (a) { return { value: a.id, label: a.name }; }),
        },
        {
          key: 'status',
          label: 'Status',
          empty: 'This Space has no workflow statuses.',
          options: (o.statuses || []).map(function (s) { return { value: s.id, label: s.name, color: s.color }; }),
        },
        {
          key: 'priority',
          label: 'Priority',
          empty: 'No priorities configured.',
          options: (o.priorities || []).map(function (p) { return { value: p.value, label: p.label }; }),
        },
        {
          key: 'tag',
          label: 'Tag',
          empty: 'No tags yet — add them on Settings › Tag.',
          options: (o.tags || []).map(function (t) { return { value: t.id, label: t.name }; }),
        },
      ];
    },

    /**
     * Print every nth date label under the volume chart.
     *
     * Thinning rather than rotating: thirty overlapping dates at an angle is less readable than
     * eight upright ones, and the tooltip carries the exact date for any bar anyway.
     */
    labelEvery: function () {
      var n = ((this.report && this.report.volume && this.report.volume.points) || []).length;

      return n <= 10 ? 1 : Math.ceil(n / 10);
    },
  },

  mounted: function () {
    var self = this;

    // A click anywhere else shuts the open dropdown. Document-level because the dropdowns are
    // ordinary children here rather than teleported overlays with a backdrop of their own.
    this._away = function (e) {
      if (!self.openFilter) return;
      if (e.target.closest && e.target.closest('[data-filter-menu]')) return;
      self.openFilter = null;
    };

    document.addEventListener('click', this._away);
  },

  unmounted: function () {
    if (this._away) document.removeEventListener('click', this._away);
  },

  methods: {
    duration: hcDuration,
    num: hcNum,

    /** Raw icon markup for `v-html` — the same helper every screen in this module uses. */
    icon: function (name, size) {
      return (window.PB_ICONS && window.PB_ICONS.svg) ? window.PB_ICONS.svg(name, size || 16) : '';
    },

    toggleFilter: function (key) {
      this.openFilter = this.openFilter === key ? null : key;
    },

    /** Multi-select: a value is added or removed, never replaced (§18). */
    toggle: function (key, value) {
      var list = this.f[key].slice();
      var at = list.indexOf(value);

      if (at === -1) {
        list.push(value);
      } else {
        list.splice(at, 1);
      }

      this.f[key] = list;
      this.refresh();
    },

    isOn: function (key, value) {
      return this.f[key].indexOf(value) !== -1;
    },

    setRange: function (range) {
      this.f.range = range;
      this.openFilter = null;

      // A custom range with no dates yet would ask the server for the default window and look
      // like nothing happened. Wait for both dates instead.
      if (range === 'custom' && (!this.f.from || !this.f.to)) return;

      this.refresh();
    },

    setGroup: function (group) {
      this.f.group = group;
      this.refresh();
    },

    clearFilters: function () {
      this.f.assignee = [];
      this.f.status = [];
      this.f.priority = [];
      this.f.tag = [];
      this.openFilter = null;
      this.refresh();
    },

    /** The filters as a query string — one place, so the fetch and the drill-downs agree. */
    query: function (extra) {
      var p = new URLSearchParams();

      p.set('range', this.f.range);

      if (this.f.range === 'custom') {
        if (this.f.from) p.set('from', this.f.from);
        if (this.f.to) p.set('to', this.f.to);
      }

      if (this.f.group && this.f.group !== 'auto') p.set('group', this.f.group);

      ['assignee', 'status', 'priority', 'tag'].forEach(function (key) {
        (this.f[key] || []).forEach(function (v) { p.append(key + '[]', v); });
      }, this);

      Object.keys(extra || {}).forEach(function (k) {
        var v = extra[k];

        if (Array.isArray(v)) {
          p.delete(k + '[]');
          v.forEach(function (x) { p.append(k + '[]', x); });
        } else {
          p.set(k, v);
        }
      });

      return p.toString();
    },

    /**
     * Refetch the whole report.
     *
     * The address bar is rewritten to match, WITHOUT a navigation: the requirement asks for no
     * full page reload, and it also asks that dashboard links carry their filters. `replaceState`
     * is how both are true at once — reload the page and you get the dashboard you were looking
     * at, not the default one.
     */
    refresh: function () {
      var self = this;
      var qs = this.query();

      this.loading = true;

      try {
        window.history.replaceState({}, '', window.location.pathname + '?' + qs);
      } catch (e) { /* A browser that refuses history is not a reason to lose the data. */ }

      this.$pb.api((this.options.endpoints || {}).data + '?' + qs)
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not load the report.', 'error');

            return;
          }

          self.report = resp.report;
          // The server is the authority on what the range resolved to — a custom range it
          // reversed, or a preset it fell back to, has to show in the controls.
          self.f = Object.assign({}, self.f, resp.report.filters);
        })
        .catch(function () { self.$pb.toast('Could not load the report.', 'error'); })
        .finally(function () { self.loading = false; });
    },

    // ---- drill-downs (§17) ----------------------------------------------------------------

    /** A filtered ticket list, carrying whatever the panel was showing. */
    drill: function (extra, view) {
      var base = (this.options.endpoints || {})[view || 'inbox'];
      if (!base) return;

      /*
       * The DATE RANGE is deliberately dropped.
       *
       * The Inbox has no notion of a reporting window — it filters on assignee, status, priority
       * and tag. Carrying `range=last_30` into it would put a parameter in the URL that nothing
       * reads, which is worse than leaving it out: it looks like it did something.
       */
      var p = new URLSearchParams();

      Object.keys(extra || {}).forEach(function (k) {
        var v = extra[k];
        (Array.isArray(v) ? v : [v]).forEach(function (x) { p.append(k + '[]', x); });
      });

      var qs = p.toString();

      window.location.href = base + (qs ? '?' + qs : '');
    },

    openRequest: function (id) {
      var url = (this.options.endpoints || {}).request;
      if (!url) return;

      window.location.href = url.replace('__ID__', id);
    },
  },

  template: [
    '<div>',

    // ===== header =====================================================================
    '  <div class="flex flex-wrap items-start justify-between gap-3">',
    '    <div class="min-w-0">',
    '      <h2 class="text-[16px] font-semibold text-head">Space Overview</h2>',
    '      <p class="text-[13px] text-sub mt-0.5 max-w-[620px]">',
    '        Monitor ticket volume, response performance, workload, and customer support activity for this Space.',
    '      </p>',
    '    </div>',
    '    <div class="flex items-center gap-1.5 shrink-0">',
    '      <button type="button" @click="refresh" :disabled="loading"',
    '              class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">',
    '        <span v-text="loading ? \'Refreshing…\' : \'Refresh\'"></span></button>',
    // Space Configuration, beside Refresh.
    //
    // It used to be a table below the reports. That put reference material — the lead, the
    // workflow, the inbound address — underneath eight panels of numbers, where it was neither
    // findable when wanted nor out of the way when not. A dialog is the right shape for
    // something you check occasionally and close.
    '      <button v-if="(options.configuration || []).length" type="button" @click="configOpen = true"',
    '              class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '        Space Configuration</button>',
    '    </div>',
    '  </div>',

    // The dialog itself — `pb-modal`, the project's standard (P44). Read-only: this reports what
    // the Space IS, and every one of these values is edited somewhere that owns it (Edit Space,
    // Settings › Workflow, Settings › Inbox). Duplicating the editing here would be a second
    // place to change them and a second place for them to disagree.
    '  <pb-modal :open="configOpen" title="Space Configuration" @close="configOpen = false">',
    '    <dl class="rounded-lg border border-line divide-y divide-line">',
    '      <div v-for="row in options.configuration" :key="row.label" class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub" v-text="row.label"></dt>',
    '        <dd class="text-[13px] text-ink min-w-0" :class="row.break ? \'_moretogether-break\' : \'\'"',
    '            v-text="row.value"></dd>',
    '      </div>',
    '    </dl>',
    '    <template #footer>',
    '      <button type="button" @click="configOpen = false"',
    '              class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Close</button>',
    '    </template>',
    '  </pb-modal>',

    // ---- filter bar ----
    '  <div class="mt-4 flex flex-wrap items-center gap-1.5" data-filter-menu>',

    // Date range
    '    <div class="relative">',
    '      <button type="button" @click.stop="toggleFilter(\'range\')"',
    '              class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">',
    '        <span class="text-sub">Range:</span>',
    '        <span class="font-medium" v-text="rangeLabel"></span></button>',
    '      <div v-if="openFilter === \'range\'"',
    '           class="absolute left-0 top-full mt-1 z-30 w-52 rounded-lg border border-line bg-white shadow-lg py-1">',
    '        <button v-for="r in options.ranges" :key="r.value" type="button" @click="setRange(r.value)"',
    '                class="w-full flex items-center px-3 h-8 text-left text-[13px] text-ink hover:bg-hover">',
    '          <span v-text="r.label"></span>',
    '          <span v-if="f.range === r.value" class="ml-auto text-brand">&check;</span></button>',
    '        <div v-if="f.range === \'custom\'" class="px-3 py-2 border-t border-line space-y-2">',
    '          <label class="block text-[11px] font-semibold text-ink">Start date',
    '            <input type="date" v-model="f.from" @change="refresh" class="pb-input !h-8 w-full mt-1" /></label>',
    '          <label class="block text-[11px] font-semibold text-ink">End date',
    '            <input type="date" v-model="f.to" @change="refresh" class="pb-input !h-8 w-full mt-1" /></label>',
    '        </div>',
    '      </div>',
    '    </div>',

    // The four multi-selects. One markup, four data sources — see `filterDefs`.
    '    <div v-for="def in filterDefs" :key="def.key" class="relative">',
    '      <button type="button" @click.stop="toggleFilter(def.key)"',
    '              :class="[\'inline-flex items-center gap-1.5 h-8 px-3 rounded-md border text-[13px]\',',
    '                       f[def.key].length ? \'border-brand bg-brand/5 text-brand font-semibold\' : \'border-stroke text-ink hover:bg-hover\']">',
    '        <span v-text="def.label"></span>',
    '        <span v-if="f[def.key].length" class="text-[11px]" v-text="f[def.key].length"></span></button>',
    '      <div v-if="openFilter === def.key"',
    '           class="absolute left-0 top-full mt-1 z-30 w-60 max-h-[300px] overflow-y-auto rounded-lg border border-line bg-white shadow-lg py-1">',
    '        <button v-for="o in def.options" :key="o.value" type="button" @click="toggle(def.key, o.value)"',
    '                class="w-full flex items-center gap-2 px-3 h-8 text-left text-[13px] text-ink hover:bg-hover">',
    '          <span v-if="o.color" class="h-2 w-2 rounded-full shrink-0" :style="{ background: o.color }"></span>',
    '          <span class="truncate" v-text="o.label"></span>',
    '          <span v-if="isOn(def.key, o.value)" class="ml-auto text-brand">&check;</span></button>',
    '        <p v-if="!def.options.length" class="px-3 py-2 text-[12px] text-faint" v-text="def.empty"></p>',
    '      </div>',
    '    </div>',

    '    <button v-if="activeFilterCount" type="button" @click="clearFilters"',
    '            class="inline-flex items-center h-8 px-3 rounded-md text-[13px] font-semibold text-danger hover:bg-hover">Clear Filters</button>',
    '  </div>',

    // ===== empty states ===============================================================
    '  <div v-if="isEmptySpace" class="mt-12 mx-auto max-w-[460px] text-center">',
    '    <h3 class="text-[15px] font-semibold text-head">Your support reports will appear here</h3>',
    '    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">',
    '      Once customers start sending requests, this dashboard will show ticket volume, response',
    '      times, workload, and support performance.</p>',
    '    <a :href="(options.endpoints || {}).inbox"',
    '       class="inline-flex items-center h-9 px-4 mt-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Go to Inbox</a>',
    '  </div>',

    '  <template v-else>',

    // ===== KPI cards ==================================================================
    '  <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">',
    '    <button type="button" @click="drill({})"',
    '            class="text-left rounded-xl border border-line px-4 py-3 hover:bg-hover">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="num(k.total)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Total Tickets</div></button>',

    '    <button type="button" @click="drill({})"',
    '            class="text-left rounded-xl border border-line px-4 py-3 hover:bg-hover">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="num(k.open)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Open Tickets <span class="text-faint">&middot; now</span></div></button>',

    '    <button type="button" @click="drill({}, \'unassigned\')"',
    '            class="text-left rounded-xl border border-line px-4 py-3 hover:bg-hover">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="num(k.unassigned)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Unassigned <span class="text-faint">&middot; now</span></div></button>',

    '    <button type="button" @click="drill({}, \'closed\')"',
    '            class="text-left rounded-xl border border-line px-4 py-3 hover:bg-hover">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="num(k.closed)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Closed Tickets</div></button>',

    // The two averages are NOT clickable: there is no ticket list that means "the average".
    '    <div class="rounded-xl border border-line px-4 py-3">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="duration(k.firstResponse)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Avg. First Response</div>',
    '      <div class="text-[11px] text-faint mt-0.5" v-text="(k.firstResponseCount || 0) + \' answered\'"></div></div>',

    '    <div class="rounded-xl border border-line px-4 py-3">',
    '      <div class="text-[22px] font-semibold text-head tabular-nums" v-text="duration(k.resolution)"></div>',
    '      <div class="text-[12px] text-sub mt-0.5">Avg. Resolution Time</div>',
    '      <div class="text-[11px] text-faint mt-0.5" v-text="(k.resolutionCount || 0) + \' closed\'"></div></div>',
    '  </div>',

    // ===== no data for this range =====================================================
    '  <div v-if="isEmptyRange" class="mt-6 rounded-xl border border-line px-4 py-10 text-center">',
    '    <h3 class="text-[14px] font-semibold text-head">No reporting data for this period</h3>',
    '    <p class="mt-1 text-[13px] text-sub">Try selecting a different date range or clearing your filters.</p>',
    '  </div>',

    '  <template v-else>',

    // ===== volume =====================================================================
    '  <section class="mt-6 rounded-xl border border-line">',
    '    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-line">',
    '      <h3 class="text-[14px] font-semibold text-head">Tickets Created vs Closed</h3>',
    '      <div class="flex items-center gap-2">',
    '        <span class="inline-flex items-center gap-1 text-[11px] text-sub"><span class="h-2 w-2 rounded-sm bg-brand"></span>Created</span>',
    '        <span class="inline-flex items-center gap-1 text-[11px] text-sub"><span class="h-2 w-2 rounded-sm bg-line"></span>Closed</span>',
    // `pb-combo`, the project's own picker (P61) — same control as every other dropdown in the
    // module, rather than a bare native select that looks like a different application.
    '        <div class="w-[110px]">',
    '          <pb-combo :model-value="f.group" @update:model-value="setGroup($event)"',
    '                    :options="groupingOptions" :searchable="false" :dense="true" />',
    '        </div>',
    '      </div>',
    '    </div>',
    '    <div class="px-4 py-4 overflow-x-auto">',
    '      <div class="flex items-end gap-1 min-w-full" style="height:180px">',
    // The bar GEOMETRY is inline style, not Tailwind classes.
    //
    // `w-1/2`, `rounded-t-sm` and `min-w-[14px]` are all absent from the built stylesheet — it
    // contains only what Tailwind found while scanning its sources, and this file is not one of
    // them. The first version of this chart used all three and rendered thirty bars of zero
    // width: the data was right, the numbers above it were right, and the chart was simply blank.
    '        <div v-for="p in report.volume.points" :key="p.key" class="flex flex-col justify-end items-center h-full"',
    '             style="flex:1 1 0;min-width:14px"',
    '             :title="p.label + \' — \' + p.created + \' created, \' + p.closed + \' closed\'">',
    '          <div class="w-full flex items-end justify-center h-full" style="gap:2px">',
    '            <span class="bg-brand" style="width:45%;border-radius:2px 2px 0 0"',
    '                  :style="{ height: Math.max(p.created ? 2 : 0, Math.round((p.created / volumeMax) * 100)) + \'%\' }"></span>',
    '            <span class="bg-line" style="width:45%;border-radius:2px 2px 0 0"',
    '                  :style="{ height: Math.max(p.closed ? 2 : 0, Math.round((p.closed / volumeMax) * 100)) + \'%\' }"></span>',
    '          </div>',
    '        </div>',
    '      </div>',
    // Labels are thinned rather than rotated: a chart with 30 unreadable overlapping dates is
    // worse than one with 8 readable ones.
    '      <div class="flex gap-1 mt-1.5">',
    '        <div v-for="(p, i) in report.volume.points" :key="p.key" class="text-center" style="flex:1 1 0;min-width:14px">',
    '          <span v-if="i % labelEvery === 0" class="text-[10px] text-faint whitespace-nowrap" v-text="p.label"></span>',
    '        </div>',
    '      </div>',
    '    </div>',
    '  </section>',

    // ===== status + priority ==========================================================
    '  <div class="mt-6 grid gap-6 lg:grid-cols-2">',

    '    <section class="rounded-xl border border-line">',
    '      <h3 class="text-[14px] font-semibold text-head px-4 py-3 border-b border-line">Ticket Status</h3>',
    '      <div class="px-4 py-4 flex items-center gap-5">',
    // Inline size, for the reason the volume bars carry one: `h-[140px]`/`w-[140px]` are not in
    // the built stylesheet. Unsized, the SVG expanded to fill its flex row and squeezed the
    // legend beside it to zero width — a giant donut next to a column of numbers with no labels.
    // Inline size, for the reason the volume bars carry one: `h-[140px]`, `w-[140px]` and
    // `-rotate-90` are none of them in the built stylesheet. Unsized, the SVG expanded to fill
    // its flex row and squeezed the legend beside it to zero width — a giant donut next to a
    // column of numbers with no labels. The rotation is an SVG `transform` rather than a
    // Tailwind class for the same reason; it starts the first segment at twelve o'clock instead
    // of three, which is where a person expects a donut to begin.
    '        <svg viewBox="0 0 160 160" class="shrink-0" style="width:140px;height:140px">',
    '          <g transform="rotate(-90 80 80)">',
    '            <circle cx="80" cy="80" r="60" fill="none" stroke="#f3f4f6" stroke-width="20"/>',
    '            <circle v-for="(s, i) in donut" :key="i" cx="80" cy="80" r="60" fill="none" stroke-width="20"',
    '                    :stroke="s.color" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset"/>',
    '          </g>',
    '        </svg>',
    '        <div class="min-w-0 flex-1 space-y-1">',
    '          <button v-for="s in report.statusBreakdown" :key="s.id || s.name" type="button"',
    '                  @click="s.id && drill({ status: [s.id] })"',
    '                  class="w-full flex items-center gap-2 text-[13px] py-0.5 hover:bg-hover rounded px-1 -mx-1">',
    '            <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: s.color }"></span>',
    '            <span class="truncate text-ink" v-text="s.name"></span>',
    '            <span class="ml-auto tabular-nums text-ink" v-text="s.total"></span>',
    '            <span class="w-11 text-right tabular-nums text-faint text-[12px]" v-text="s.percent + \'%\'"></span>',
    '          </button>',
    '        </div>',
    '      </div>',
    '    </section>',

    '    <section class="rounded-xl border border-line">',
    '      <h3 class="text-[14px] font-semibold text-head px-4 py-3 border-b border-line">Priority</h3>',
    '      <div class="px-4 py-3 space-y-2">',
    '        <button v-for="p in report.priorityBreakdown" :key="p.key" type="button"',
    '                @click="drill({ priority: [p.key] })"',
    '                class="w-full flex items-center gap-2 text-[13px] hover:bg-hover rounded px-1 -mx-1 py-1">',
    '          <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: p.color }"></span>',
    '          <span class="w-24 shrink-0 text-left text-ink truncate" v-text="p.label"></span>',
    '          <span class="flex-1 h-1.5 rounded-full bg-hover overflow-hidden">',
    '            <span class="block h-full rounded-full" :style="{ width: p.percent + \'%\', background: p.color }"></span></span>',
    '          <span class="w-10 text-right tabular-nums text-ink" v-text="p.total"></span>',
    '        </button>',
    '      </div>',
    '    </section>',
    '  </div>',

    // ===== agent performance ==========================================================
    '  <section class="mt-6 rounded-xl border border-line overflow-hidden">',
    '    <h3 class="text-[14px] font-semibold text-head px-4 py-3 border-b border-line">Agent Performance</h3>',
    '    <div class="overflow-x-auto">',
    '      <table class="w-full text-[13px]">',
    '        <thead><tr class="text-[11px] text-faint text-left border-b border-line">',
    '          <th class="font-semibold px-4 py-2">Agent</th>',
    '          <th class="font-semibold px-3 py-2 text-right">Assigned</th>',
    '          <th class="font-semibold px-3 py-2 text-right">Replied</th>',
    '          <th class="font-semibold px-3 py-2 text-right">Closed</th>',
    '          <th class="font-semibold px-3 py-2 text-right">Open</th>',
    '          <th class="font-semibold px-3 py-2 text-right whitespace-nowrap">Avg. 1st Response</th>',
    '          <th class="font-semibold px-4 py-2 text-right whitespace-nowrap">Avg. Resolution</th>',
    '        </tr></thead>',
    '        <tbody>',
    '          <tr v-for="a in report.agents" :key="a.id" @click="toggle(\'assignee\', a.id)"',
    '              class="border-b border-line last:border-0 cursor-pointer hover:bg-hover">',
    '            <td class="px-4 py-2">',
    '              <span class="flex items-center gap-2 min-w-0">',
    '                <img v-if="a.avatar_url" :src="a.avatar_url" alt="" class="h-6 w-6 rounded-full object-cover shrink-0" />',
    '                <span v-else class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0"',
    '                      :style="{ background: $pb.avatarColor(a) }" v-text="a.initial"></span>',
    '                <span class="truncate text-ink" v-text="a.name"></span></span></td>',
    '            <td class="px-3 py-2 text-right tabular-nums" v-text="a.assigned"></td>',
    '            <td class="px-3 py-2 text-right tabular-nums" v-text="a.replied"></td>',
    '            <td class="px-3 py-2 text-right tabular-nums" v-text="a.closed"></td>',
    '            <td class="px-3 py-2 text-right tabular-nums" v-text="a.open"></td>',
    '            <td class="px-3 py-2 text-right tabular-nums" v-text="duration(a.firstResponse)"></td>',
    '            <td class="px-4 py-2 text-right tabular-nums" v-text="duration(a.resolution)"></td>',
    '          </tr>',
    '          <tr v-if="!report.agents.length"><td colspan="7" class="px-4 py-6 text-center text-[13px] text-faint">',
    '            No members in this Space yet.</td></tr>',
    '        </tbody>',
    '      </table>',
    '    </div>',
    '  </section>',

    // ===== workload + aging ===========================================================
    '  <div class="mt-6 grid gap-6 lg:grid-cols-2">',

    '    <section class="rounded-xl border border-line">',
    '      <div class="px-4 py-3 border-b border-line">',
    '        <h3 class="text-[14px] font-semibold text-head">Current Workload</h3>',
    '        <p class="text-[11px] text-faint mt-0.5">Open tickets right now, ignoring the date range.</p></div>',
    '      <div class="px-4 py-3 space-y-2">',
    '        <button v-for="w in report.workload" :key="w.id" type="button" @click="drill({ assignee: [w.id] })"',
    '                class="w-full flex items-center gap-2 text-[13px] hover:bg-hover rounded px-1 -mx-1 py-1">',
    '          <span v-if="w.avatar_url" class="h-6 w-6 rounded-full overflow-hidden shrink-0">',
    '            <img :src="w.avatar_url" alt="" class="h-full w-full object-cover" /></span>',
    '          <span v-else class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0"',
    '                :style="{ background: $pb.avatarColor(w) }" v-text="w.initial"></span>',
    '          <span class="w-28 shrink-0 text-left text-ink truncate" v-text="w.name"></span>',
    '          <span class="flex-1 h-1.5 rounded-full bg-hover overflow-hidden">',
    '            <span class="block h-full rounded-full bg-brand" :style="{ width: Math.round((w.total / workloadMax) * 100) + \'%\' }"></span></span>',
    '          <span class="w-8 text-right tabular-nums text-ink" v-text="w.total"></span>',
    '        </button>',
    '      </div>',
    '    </section>',

    '    <section class="rounded-xl border border-line">',
    '      <div class="px-4 py-3 border-b border-line">',
    '        <h3 class="text-[14px] font-semibold text-head">Ticket Aging</h3>',
    '        <p class="text-[11px] text-faint mt-0.5">Unresolved tickets, by how long they have been open.</p></div>',
    '      <div class="px-4 py-3 space-y-2">',
    '        <div v-for="a in report.aging" :key="a.key" class="flex items-center gap-2 text-[13px]">',
    '          <span class="w-28 shrink-0 text-sub truncate" v-text="a.label"></span>',
    '          <span class="flex-1 h-1.5 rounded-full bg-hover overflow-hidden">',
    '            <span class="block h-full rounded-full bg-brand" :style="{ width: Math.round((a.total / agingMax) * 100) + \'%\' }"></span></span>',
    '          <span class="w-8 text-right tabular-nums text-ink" v-text="a.total"></span>',
    '        </div>',
    '      </div>',
    '    </section>',
    '  </div>',

    // ===== longest waiting ============================================================
    '  <section class="mt-6 rounded-xl border border-line overflow-hidden">',
    '    <h3 class="text-[14px] font-semibold text-head px-4 py-3 border-b border-line">Longest Waiting Tickets</h3>',
    '    <div class="overflow-x-auto">',
    '      <table class="w-full text-[13px]">',
    '        <thead><tr class="text-[11px] text-faint text-left border-b border-line">',
    '          <th class="font-semibold px-4 py-2">Ticket</th>',
    '          <th class="font-semibold px-3 py-2">Customer</th>',
    '          <th class="font-semibold px-3 py-2">Assignee</th>',
    '          <th class="font-semibold px-3 py-2">Status</th>',
    '          <th class="font-semibold px-3 py-2">Priority</th>',
    '          <th class="font-semibold px-4 py-2 text-right">Waiting</th>',
    '        </tr></thead>',
    '        <tbody>',
    '          <tr v-for="t in report.longestWaiting" :key="t.id" @click="openRequest(t.id)"',
    '              class="border-b border-line last:border-0 cursor-pointer hover:bg-hover">',
    '            <td class="px-4 py-2">',
    '              <span class="block tabular-nums text-sub text-[11px]" v-text="t.identifier"></span>',
    '              <span class="block text-ink truncate max-w-[280px]" v-text="t.subject"></span></td>',
    '            <td class="px-3 py-2 text-sub truncate max-w-[160px]" v-text="t.customer"></td>',
    '            <td class="px-3 py-2 text-sub" v-text="t.assignee || \'Unassigned\'"></td>',
    '            <td class="px-3 py-2">',
    '              <span class="inline-flex items-center gap-1.5">',
    '                <span v-if="t.status_color" class="h-2 w-2 rounded-full" :style="{ background: t.status_color }"></span>',
    '                <span class="text-ink" v-text="t.status || \'—\'"></span></span></td>',
    '            <td class="px-3 py-2 text-sub" v-text="t.priority"></td>',
    '            <td class="px-4 py-2 text-right tabular-nums text-ink whitespace-nowrap" v-text="duration(t.waiting_minutes)"></td>',
    '          </tr>',
    '          <tr v-if="!report.longestWaiting.length"><td colspan="6" class="px-4 py-6 text-center text-[13px] text-faint">',
    '            Nothing is waiting on an agent right now.</td></tr>',
    '        </tbody>',
    '      </table>',
    '    </div>',
    '  </section>',

    // ===== tags =======================================================================
    '  <section v-if="report.tags.length" class="mt-6 rounded-xl border border-line">',
    '    <h3 class="text-[14px] font-semibold text-head px-4 py-3 border-b border-line">Tags</h3>',
    '    <div class="px-4 py-3 space-y-2">',
    '      <button v-for="t in report.tags" :key="t.id" type="button" @click="drill({ tag: [t.id] })"',
    '              class="w-full flex items-center gap-2 text-[13px] hover:bg-hover rounded px-1 -mx-1 py-1">',
    '        <span class="w-32 shrink-0 text-left text-ink truncate" v-text="t.name"></span>',
    '        <span class="flex-1 h-1.5 rounded-full bg-hover overflow-hidden">',
    '          <span class="block h-full rounded-full bg-brand" :style="{ width: t.percent + \'%\' }"></span></span>',
    '        <span class="w-10 text-right tabular-nums text-ink" v-text="t.total"></span>',
    '        <span class="w-12 text-right tabular-nums text-faint text-[12px]" v-text="t.percent + \'%\'"></span>',
    '      </button>',
    '    </div>',
    '  </section>',

    '  </template>',
    '  </template>',
    '</div>',
  ].join(''),
}, { root: 'help-center-overview' });
