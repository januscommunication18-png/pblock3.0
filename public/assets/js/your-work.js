/* Your Work — one person's work items across every project they can see.
   ------------------------------------------------------------------
   Five tabs. Three of them ARE the work items screen: <work-items-screen> is mounted with a
   multi-project payload, so a row here has the same chips, opens the same drawer and edits
   the same way as it does on its own project's list — because it is the same component.

   What makes that possible is the payload's `projects` map: the screen resolves each row's
   states, labels, members, cycles and endpoints from the project that row belongs to, rather
   than from one flat set that would only ever be right for one of them. Everything on this
   screen is the server's answer to "what may this person see" — see YourWorkController.

   The tab is a path segment, not client state: each tab is somewhere you can send a link to,
   and the back button should move between them. So switching tabs is a navigation, and the
   only thing this component does with the others is render their counts.
   ------------------------------------------------------------------ */
PB.boot('your-work', {
  props: { bootstrap: Object },
  components: { 'work-items-screen': WorkItemsScreen },
  data: function () {
    var b = this.bootstrap || {};
    return {
      tab: b.tab || 'summary',
      tabs: Array.isArray(b.tabs) ? b.tabs : [],
      // The list payload for THIS tab, or null on Summary and Activity.
      workItems: b.workItems || null,
      activity: Array.isArray(b.activity) ? b.activity : [],
      counts: b.counts || {},
      summary: b.summary || null
    };
  },
  computed: {
    isList: function () { return !!this.workItems; },

    // ---- Summary ------------------------------------------------------------------------
    /** The three Overview tiles, in the order the tabs are in. */
    overview: function () {
      var o = (this.summary && this.summary.overview) || {};

      return [
        { key: 'created', label: 'Work items created', icon: 'plus', value: o.created || 0 },
        { key: 'assigned', label: 'Work items assigned', icon: 'user-thin', value: o.assigned || 0 },
        { key: 'subscribed', label: 'Work items subscribed', icon: 'inbox', value: o.subscribed || 0 }
      ];
    },
    workload: function () { return (this.summary && this.summary.workload) || []; },
    byPriority: function () { return (this.summary && this.summary.byPriority) || []; },
    /** Nothing assigned: the charts would be an empty frame, so they say so instead. */
    hasWork: function () { return !!(this.summary && this.summary.total); },

    /**
     * The priority bars.
     *
     * A nice round top so the gridlines land on whole work items — a y-axis reading 3.5 is a
     * fiction, since there is no half a work item.
     */
    priorityChart: function () {
      var rows = this.byPriority;
      var max = Math.max.apply(null, rows.map(function (r) { return r.count; }).concat([1]));
      var top = Math.max(1, Math.ceil(max / 4) * 4);
      var ticks = [0, 1, 2, 3, 4].map(function (i) { return Math.round(top * i / 4); });

      return {
        top: top,
        // Deduplicated: a small `top` (4 or fewer) repeats values once rounded.
        ticks: ticks.filter(function (v, i) { return ticks.indexOf(v) === i; }),
        bars: rows.map(function (r) {
          return {
            key: r.key, label: r.label, color: r.color, count: r.count,
            // Percent of the plot height, so the SVG stays resolution-independent.
            height: top ? (r.count / top) * 100 : 0
          };
        })
      };
    },

    /**
     * The state donut, as ready-to-draw arc segments.
     *
     * A 2px gap between neighbours, per the mark spec — abutting fills of similar lightness
     * read as one wedge, and this donut has two greys and an amber/green pair in it.
     */
    stateDonut: function () {
      var rows = this.workload;
      var total = rows.reduce(function (sum, r) { return sum + r.count; }, 0);
      var r = 54, circumference = 2 * Math.PI * r;
      var gap = total > 1 ? 2 : 0;
      var offset = 0;

      return {
        total: total,
        radius: r,
        circumference: circumference,
        segments: rows.filter(function (s) { return s.count > 0; }).map(function (s) {
          var length = (s.count / total) * circumference;
          var seg = {
            key: s.key, label: s.label, color: s.color, count: s.count,
            // dasharray draws `length - gap`, then skips the rest of the ring.
            dash: Math.max(0, length - gap) + ' ' + (circumference - Math.max(0, length - gap)),
            offset: -offset
          };
          offset += length;

          return seg;
        })
      };
    },
    /** Nothing to show is a different thing from a tab that lists nothing — say which. */
    isEmpty: function () {
      return this.isList && !(this.workItems.items || []).length;
    },
    emptyText: function () {
      var text = {
        assigned: 'Nothing is assigned to you right now. Work items assigned to you in any project you can see will appear here.',
        created: 'You have not created any work items yet. Anything you open in any project you can see will appear here.',
        subscribed: 'You are not subscribed to any projects yet. Subscribe from a project’s settings to follow its work here.'
      };

      return text[this.tab] || '';
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },
    count: function (key) { return this.counts[key]; },

    when: function (iso) {
      if (!iso) return '';
      var mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + 'm ago';
      if (mins < 1440) return Math.floor(mins / 60) + 'h ago';
      return Math.floor(mins / 1440) + 'd ago';
    },

    /**
     * One activity line in words.
     *
     * Deliberately plain: the feed is a record of what you did, and "changed state from Todo
     * to In Progress" reads better in a list of a hundred than a chip cluster repeating the
     * row above it.
     */
    activityText: function (a) {
      if (a.event === 'created') return 'created';

      var field = String(a.field || '').replace(/_/g, ' ');
      if (a.old_value && a.new_value) return 'changed ' + field + ' from ' + a.old_value + ' to ' + a.new_value;
      if (a.new_value) return 'set ' + field + ' to ' + a.new_value;
      if (a.old_value) return 'cleared ' + field;

      return 'updated ' + (field || 'this item');
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ============ header ============
    '<div class="h-12 shrink-0 px-4 sm:px-6 flex items-center gap-2 border-b border-line">' +
    '<button data-sidebar-expand class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'title="Expand sidebar" v-html="icon(\'sidebar\', 15)"></button>' +
    '<span class="grid place-items-center text-sub" v-html="icon(\'user\', 16)"></span>' +
    '<h1 class="text-[14px] font-semibold text-head">Your work</h1>' +
    '</div>' +

    // ============ tabs ============
    // Anchors, not buttons: each tab is a URL, so middle-click and "open in new tab" work.
    '<nav class="h-10 shrink-0 px-4 sm:px-6 flex items-end gap-1 border-b border-line overflow-x-auto">' +
    '<a v-for="t in tabs" :key="t.key" :href="t.url" ' +
    'class="inline-flex items-center gap-1.5 h-9 px-3 text-[13px] whitespace-nowrap border-b-2 -mb-px" ' +
    ':class="t.key === tab ? \'border-brand text-brand font-semibold\' : \'border-transparent text-sub hover:text-ink\'">' +
    '{{ t.label }}' +
    '<span v-if="count(t.key) !== undefined" class="text-[11px] font-semibold rounded-full px-1.5 py-0.5 bg-hover text-sub">{{ count(t.key) }}</span>' +
    '</a>' +
    '</nav>' +

    // ============ Summary ============
    '<div v-if="tab === \'summary\'" class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="px-4 sm:px-6 py-6 max-w-[1200px]">' +

    // ---- Overview: three totals, one per list tab ----
    '<h2 class="text-[15px] font-semibold text-head">Overview</h2>' +
    '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-3">' +
    '<div v-for="o in overview" :key="o.key" class="rounded-card border border-line bg-white p-4 flex items-center gap-3">' +
    '<span class="h-10 w-10 rounded-lg border border-line grid place-items-center text-sub shrink-0" v-html="icon(o.icon, 18)"></span>' +
    '<div class="min-w-0">' +
    '<div class="text-[13px] text-sub truncate">{{ o.label }}</div>' +
    '<div class="text-[20px] font-semibold text-head leading-tight">{{ o.value }}</div>' +
    '</div></div></div>' +

    // ---- Workload: the assigned items, split by state group ----
    // Swatch + name + count on every tile, so the colour is never the only thing saying
    // which group this is.
    '<h2 class="text-[15px] font-semibold text-head mt-8">Workload</h2>' +
    '<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mt-3">' +
    '<div v-for="w in workload" :key="w.key" class="rounded-card border border-line bg-white p-4">' +
    '<div class="flex items-center gap-2">' +
    '<span class="h-3 w-3 rounded-sm shrink-0" :style="{ background: w.color }"></span>' +
    '<span class="text-[13px] text-sub truncate">{{ w.label }}</span></div>' +
    '<div class="text-[20px] font-semibold text-head leading-tight mt-1">{{ w.count }}</div>' +
    '</div></div>' +

    // ---- The two charts ----
    '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-8">' +

    // Bar chart: assigned work by priority. Ordinal, so the order is the severity and never
    // the counts. Every bar is labelled, because there are five of them.
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[14px] font-semibold text-head">Work items by Priority</h3>' +
    '<p v-if="!hasWork" class="text-[13px] text-sub mt-6 mb-6 text-center">Nothing is assigned to you yet.</p>' +
    '<div v-else class="mt-4 flex gap-3">' +
    // Y axis: whole work items only.
    '<div class="w-8 shrink-0 relative h-[200px]">' +
    '<div v-for="(t, i) in priorityChart.ticks.slice().reverse()" :key="t" ' +
    'class="absolute right-0 text-[11px] text-faint -translate-y-1/2" ' +
    ':style="{ top: (i / (priorityChart.ticks.length - 1) * 100) + \'%\' }">{{ t }}</div>' +
    '</div>' +
    '<div class="flex-1 min-w-0">' +
    '<div class="relative h-[200px]">' +
    // Recessive gridlines, behind the bars.
    '<div v-for="(t, i) in priorityChart.ticks" :key="\'g\' + t" class="absolute inset-x-0 border-t border-line" ' +
    ':style="{ bottom: (i / (priorityChart.ticks.length - 1) * 100) + \'%\' }"></div>' +
    '<div class="absolute inset-0 flex items-end gap-2">' +
    // 4px rounded top, square to the baseline; the gap between bars is the flex gap.
    '<div v-for="b in priorityChart.bars" :key="b.key" class="flex-1 min-w-0 flex justify-center items-end h-full" ' +
    ':data-tip="b.label + \': \' + b.count + (b.count === 1 ? \' work item\' : \' work items\')">' +
    '<div class="w-full max-w-[46px] rounded-t transition-all" ' +
    ':style="{ height: Math.max(b.height, b.count ? 2 : 0) + \'%\', background: b.color }"></div>' +
    '</div></div></div>' +
    // Direct labels: five bars, so each carries its own count rather than a legend.
    '<div class="flex gap-2 mt-2">' +
    '<div v-for="b in priorityChart.bars" :key="\'l\' + b.key" class="flex-1 min-w-0 text-center">' +
    '<div class="text-[13px] font-semibold text-head">{{ b.count }}</div>' +
    '<div class="text-[11px] text-sub truncate">{{ b.label }}</div>' +
    '</div></div>' +
    '</div></div></div>' +

    // Donut: the same assigned items by state. Part of a whole, and only five parts.
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[14px] font-semibold text-head">Work items by state</h3>' +
    '<p v-if="!hasWork" class="text-[13px] text-sub mt-6 mb-6 text-center">Nothing is assigned to you yet.</p>' +
    '<div v-else class="mt-4 flex flex-col sm:flex-row items-center gap-6">' +
    '<svg width="140" height="140" viewBox="0 0 140 140" class="shrink-0" role="img" aria-label="Assigned work items by state">' +
    '<circle cx="70" cy="70" :r="stateDonut.radius" fill="none" stroke="#e5e7eb" stroke-width="16" />' +
    '<circle v-for="s in stateDonut.segments" :key="s.key" cx="70" cy="70" :r="stateDonut.radius" fill="none" ' +
    ':stroke="s.color" stroke-width="16" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" ' +
    'transform="rotate(-90 70 70)" :data-tip="s.label + \': \' + s.count" />' +
    // The whole, in the hole — the number the ring is a breakdown of.
    '<text x="70" y="66" text-anchor="middle" class="fill-head" style="font-size:22px;font-weight:600">{{ stateDonut.total }}</text>' +
    '<text x="70" y="84" text-anchor="middle" class="fill-faint" style="font-size:11px">assigned</text>' +
    '</svg>' +
    // Legend: always present, and carrying the counts so nobody has to read them off the arc.
    '<div class="flex-1 min-w-0 w-full">' +
    '<div v-for="w in workload" :key="\'lg\' + w.key" class="flex items-center gap-2.5 py-1.5">' +
    '<span class="h-3 w-3 rounded-sm shrink-0" :style="{ background: w.color }"></span>' +
    '<span class="text-[13px] text-ink flex-1 truncate">{{ w.label }}</span>' +
    '<span class="text-[13px] font-semibold text-head tabular-nums">{{ w.count }}</span>' +
    '</div></div>' +
    '</div></div>' +

    '</div></div></div>' +

    // ============ Activity — what you did, newest first ============
    '<div v-else-if="tab === \'activity\'" class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="px-4 sm:px-6 py-5 max-w-[820px] mx-auto w-full">' +
    '<p v-if="!activity.length" class="text-[13px] text-sub text-center py-10">' +
    'Nothing yet. Anything you create or change on a work item shows up here.</p>' +
    '<div v-for="a in activity" :key="a.id" class="flex items-start gap-3 py-2.5 border-b border-line last:border-0">' +
    '<span class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0 mt-0.5" ' +
    ':style="{ background: $pb.avatarColor(a.actor) }">' +
    '<img v-if="a.actor && a.actor.avatar_url" :src="a.actor.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ a.actor ? a.actor.initial : \'?\' }}</span></span>' +
    '<div class="min-w-0 flex-1">' +
    '<p class="text-[13px] text-ink">' +
    '<span class="font-medium">You</span> {{ activityText(a) }}' +
    '<template v-if="a.item"> on <a :href="a.item.url" class="text-brand hover:underline">{{ a.item.identifier }}</a> ' +
    '<span class="text-sub">{{ a.item.title }}</span></template>' +
    '</p>' +
    '<p class="text-[12px] text-faint mt-0.5">{{ when(a.created_at) }}</p>' +
    '</div></div>' +
    '</div></div>' +

    // ============ Assigned / Created / Subscribed — the work items screen itself ============
    '<div v-else-if="isEmpty" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-md">' +
    '<div class="text-[14px] font-medium text-head">Nothing here yet</div>' +
    '<p class="text-[13px] text-sub mt-1">{{ emptyText }}</p>' +
    '</div></div>' +
    // Editable chips, the real drawer, the same everything — because it IS the same component.
    '<work-items-screen v-else-if="isList" :bootstrap="workItems" />' +

    '</div>'
});
