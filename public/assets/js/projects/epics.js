/* Project Workspace › Epics (Epic §7-§9, §16).
   ------------------------------------------------------------------
   One Vue root for both the landing page and a single epic's detail, the same way Modules,
   Cycles and Work Items handle theirs: `pageEpicId` in the bootstrap decides which renders,
   so an epic URL is real and linkable.

   An epic's status is STORED, like a module's and unlike a cycle's derived one — §14 is
   explicit that completing the work does not complete the epic. So this screen sets it;
   nothing computes it, and nothing here ever writes a work item's status either.
   ------------------------------------------------------------------ */

/**
 * A glyph per epic status, drawn with the same helpers as the work item state icons
 * (work-item-ui.js) so an epic reads like the rest of the app rather than inventing a second
 * visual language for "where something is". The vocabulary matches Modules deliberately —
 * §5 asks for their naming convention.
 *
 * Status never travels on colour alone: every one of these is rendered beside its label.
 */
function pbEpicStatusIcon(key, color) {
  switch (key) {
    case 'planned': return wiDot(color, false);
    case 'in_progress': return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none">' +
      '<circle cx="12" cy="12" r="8" stroke="' + color + '" stroke-width="2"/>' +
      '<path d="M12 4a8 8 0 010 16z" fill="' + color + '"/></svg>';
    case 'paused': return wiFilled(color,
      '<path d="M10 9v6M14 9v6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>');
    case 'completed': return wiFilled(color,
      '<path d="M8 12l3 3 5-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>');
    case 'cancelled': return wiFilled(color,
      '<path d="M9 9l6 6M15 9l-6 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>');
    // Backlog is the unscheduled default (§5) — dashed, exactly like a backlog work item.
    default: return wiDot(color, true);
  }
}

PB.boot('project-epics', {
  props: { bootstrap: Object },
  // The Work Items tab mounts the work items SCREEN, not a read-only copy of its grid: the
  // chips are its chips, the drawer is its drawer, and a row means the same thing here as it
  // does on the project's own list.
  components: { 'wi-list': WiList, 'wi-calendar': WiCalendar, 'work-items-screen': WorkItemsScreen },
  data: function () {
    var b = this.bootstrap || {};
    return {
      project: b.project || {},
      epics: (b.epics || []).slice(),
      items: (b.items || []).slice(),
      // The full work items payload for this epic, or null on the landing page.
      workItems: b.workItems || null,
      activity: (b.activity || []).slice(),
      distribution: b.distribution || null,
      states: Array.isArray(b.states) ? b.states : [],
      members: Array.isArray(b.members) ? b.members : [],
      statuses: Array.isArray(b.statuses) ? b.statuses : [],
      priorities: Array.isArray(b.priorities) ? b.priorities : [],
      defaultStatus: b.defaultStatus || 'backlog',
      pageEpicId: b.pageEpicId || null,
      canCreate: !!b.canCreate,
      canDelete: !!b.canDelete,
      // Disable is a configuration change, never a delete. The page still loads so existing
      // epics stay accessible for historical reference; what changes is that every control
      // that would WRITE is gone, and the screen says why.
      featureEnabled: b.featureEnabled !== false,
      disabledNotice: b.disabledNotice || '',
      settingsUrl: b.settingsUrl || '',
      titleMax: b.titleMax || 255,
      descriptionMax: b.descriptionMax || 2000,
      introVideo: b.introVideo || '',
      endpoints: b.endpoints || {},
      query: '',
      searchOpen: false,
      showArchived: false,
      // §8's three tabs. Overview first: it is the summary the rest drills into.
      /* The tab lives in the ADDRESS, not only in memory.

         Anything that reloads this screen — applying a filter, following a link, pressing Back —
         used to land on the default tab, so filtering from the Work items tab appeared to throw
         the user back to Overview and lose the result they had just asked for. */
      tab: (function () {
        try { return new URLSearchParams(window.location.search).get('tab') || 'overview'; }
        catch (e) { return 'overview'; }
      })(),
      // §8: the Work Items tab filters by module and cycle, which is how an epic's spread
      // across those dimensions becomes legible without nesting them (§11/§12).
      filters: { module: '', cycle: '' },
      form: {
        open: false, id: null, title: '', description: '', status: 'backlog', priority: 'none',
        start_date: null, target_date: null, lead_user_id: '', member_ids: [],
        saving: false, errors: {}
      },
      confirm: { open: false, epic: null },
      menu: { open: false, epic: null, style: {} },
      picker: { open: false, query: '', results: [], selected: [], busy: false, loaded: false },
      dateMenu: { open: '', style: {} }
    };
  },
  watch: {
    /* replaceState, not push: switching tabs is not a navigation somebody wants to press Back
       through, but it does have to survive the next reload. */
    tab: function (value) {
      try {
        var params = new URLSearchParams(window.location.search);
        params.set('tab', value);
        window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
      } catch (e) {}
    },
  },

  computed: {
    pageEpic: function () {
      var self = this;
      return this.pageEpicId
        ? this.epics.filter(function (e) { return e.id === self.pageEpicId; })[0] || null
        : null;
    },
    /** §7/§16: search by title or ID, and archived epics stay out unless asked for. */
    visible: function () {
      var q = (this.query || '').trim().toLowerCase();
      var archived = this.showArchived;
      return this.epics.filter(function (e) {
        if (e.archived !== archived) return false;
        if (!q) return true;
        return (e.title || '').toLowerCase().indexOf(q) > -1 || String(e.identifier) === q;
      });
    },
    archivedCount: function () {
      return this.epics.filter(function (e) { return e.archived; }).length;
    },
    formValid: function () { return !!this.form.title.trim(); },
    memberOptions: function () {
      return this.members.map(function (m) { return { value: String(m.id), label: m.name }; });
    },
    leadOptions: function () {
      return [{ value: '', label: 'No lead' }].concat(this.memberOptions);
    },
    statusOptions: function () {
      return this.statuses.map(function (s) {
        return { value: s.key, label: s.label, icon: pbEpicStatusIcon(s.key, s.color) };
      });
    },
    priorityOptions: function () {
      return this.priorities.map(function (p) {
        var meta = WI_PRI[p.key] || WI_PRI.none;
        return { value: p.key, label: p.label, icon: meta.icon };
      });
    },
    /** Cycle options for the Work Items tab filter, taken from what is actually there. */
    cycleFilterOptions: function () {
      var seen = {};
      var out = [{ value: '', label: 'All cycles' }];
      this.items.forEach(function (i) {
        var key = i.cycle ? String(i.cycle.id) : 'none';
        if (seen[key]) return;
        seen[key] = true;
        out.push({ value: key, label: i.cycle ? i.cycle.name : 'No cycle' });
      });
      return out;
    },
    moduleFilterOptions: function () {
      var seen = {};
      var out = [{ value: '', label: 'All modules' }];
      this.items.forEach(function (i) {
        if (!i.modules || !i.modules.length) {
          if (!seen.none) { seen.none = true; out.push({ value: 'none', label: 'No module' }); }
          return;
        }
        i.modules.forEach(function (m) {
          if (seen[m.id]) return;
          seen[m.id] = true;
          out.push({ value: String(m.id), label: m.title });
        });
      });
      return out;
    },
    /** §8: the epic's work items, narrowed by the module and cycle filters. */
    filteredItems: function () {
      var f = this.filters;
      return this.items.filter(function (i) {
        if (f.cycle) {
          var cycleKey = i.cycle ? String(i.cycle.id) : 'none';
          if (cycleKey !== f.cycle) return false;
        }
        if (f.module) {
          var mods = i.modules || [];
          if (f.module === 'none') return mods.length === 0;
          if (!mods.some(function (m) { return String(m.id) === f.module; })) return false;
        }
        return true;
      });
    },
    filtered: function () { return !!(this.filters.module || this.filters.cycle); }
  },
  methods: {
    // ---------- display ----------
    /**
     * The shared icon set, reachable from the template.
     *
     * `wiIcon` is a plain global from assets/js/icons.js; a Vue template can only call what is
     * on the component, so this is the one line that bridges them.
     */
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },
    statusIcon: function (key) { return pbEpicStatusIcon(key, this.statusMeta(key).color); },
    statusMeta: function (key) {
      var found = this.statuses.filter(function (s) { return s.key === key; })[0];
      return found || { key: key, label: key, color: '#9ca3af' };
    },
    priorityMeta: function (key) { return WI_PRI[key] || WI_PRI.none; },
    percent: function (e) { return (e.progress && e.progress.percent) || 0; },
    count: function (e, k) { return (e.progress && e.progress[k]) || 0; },
    /** §13: an epic with no work items reads as such rather than as 0 of 0. */
    hasWork: function (e) { return !!(e && e.progress && e.progress.total > 0); },
    fmtDate: function (iso) { return iso ? wiFmtDate(iso) : ''; },
    fmtWhen: function (iso) {
      if (!iso) return '';
      var d = new Date(iso);
      return wiFmtDate(wiISO(d)) + ' · ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },
    /** §7: the schedule as one line, or nothing when the epic has no dates. */
    dateRange: function (e) {
      if (!e.start_date && !e.target_date) return '';
      if (e.start_date && e.target_date) return this.fmtDate(e.start_date) + ' – ' + this.fmtDate(e.target_date);
      return e.start_date ? 'From ' + this.fmtDate(e.start_date) : 'Target ' + this.fmtDate(e.target_date);
    },
    epicUrl: function (e) { return this.$pb.withId(this.endpoints.epic, e.id); },
    itemUrl: function (i) { return this.$pb.withId(this.endpoints.workItem, i.id); },
    openItem: function (i) { window.location.href = this.itemUrl(i); },

    /** §22's events, as a sentence. The labels were frozen when the entry was written. */
    activityText: function (a) {
      var meta = a.meta || {};
      if (a.event === 'created') return 'created this epic';
      if (a.event === 'archived') return 'archived this epic';
      if (a.event === 'restored') return 'restored this epic';
      if (a.event === 'work_item_added') return 'added ' + (meta.item_label || 'a work item');
      if (a.event === 'work_item_removed') return 'removed ' + (meta.item_label || 'a work item');

      var label = meta.label || a.field || 'a field';
      if (!meta.new_label) return 'cleared the ' + label;
      if (!meta.old_label) return 'set the ' + label + ' to ' + meta.new_label;
      return 'changed the ' + label + ' to ' + meta.new_label;
    },

    // ---------- create / edit (§6, §7) ----------
    openCreate: function () {
      this.form = {
        open: true, id: null, title: '', description: '', status: this.defaultStatus, priority: 'none',
        start_date: null, target_date: null, lead_user_id: '', member_ids: [],
        saving: false, errors: {}
      };
    },
    openEdit: function (e) {
      this.closeMenu();
      this.form = {
        open: true, id: e.id, title: e.title, description: e.description || '',
        status: e.status, priority: e.priority || 'none',
        start_date: e.start_date, target_date: e.target_date,
        lead_user_id: e.lead ? String(e.lead.id) : '',
        member_ids: (e.members || []).map(function (u) { return String(u.id); }),
        saving: false, errors: {}
      };
    },
    save: async function () {
      if (!this.formValid || this.form.saving) return;
      this.form.saving = true; this.form.errors = {};

      var f = this.form;
      var body = {
        title: f.title.trim(), description: f.description || null,
        status: f.status, priority: f.priority,
        start_date: f.start_date || null, target_date: f.target_date || null,
        lead_user_id: f.lead_user_id || null,
        member_ids: f.member_ids.map(Number)
      };

      try {
        var editing = !!f.id;
        var url = editing ? this.$pb.withId(this.endpoints.update, f.id) : this.endpoints.store;
        var resp = await this.$pb.api(url, { method: editing ? 'PATCH' : 'POST', body: body });
        this.mergeEpic(resp.epic);
        this.form.open = false;
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) {
        this.form.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.form.saving = false;
    },
    /** Replace in place, or prepend — the server's copy is always the truth. */
    mergeEpic: function (epic) {
      if (!epic) return;
      var at = -1;
      for (var i = 0; i < this.epics.length; i++) {
        if (this.epics[i].id === epic.id) { at = i; break; }
      }
      if (at > -1) this.epics.splice(at, 1, epic);
      else this.epics.unshift(epic);
    },

    // ---------- dates ----------
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
    onDatePick: function (iso) { this.form[this.dateMenu.open] = iso; this.closeDate(); },
    onDateClear: function () { this.form[this.dateMenu.open] = null; this.closeDate(); },
    /**
     * The other end of the range, moved a day out.
     *
     * wi-calendar's bounds are EXCLUSIVE, but §6 only forbids a target date EARLIER than the
     * start — equal is a legal one-day epic. Shifting the bound expresses that without giving
     * the shared picker a second meaning.
     */
    dateBound: function (iso, days) {
      var d = wiParseISO(iso);
      if (!d) return null;
      return wiISO(new Date(d.getFullYear(), d.getMonth(), d.getDate() + days));
    },

    // ---------- ⋯ menu, archive, delete ----------
    openMenu: function (e, btn) {
      var r = btn.getBoundingClientRect();
      this.menu = {
        open: true, epic: e,
        style: { position: 'fixed', width: '190px', zIndex: 120, top: (r.bottom + 6) + 'px', left: Math.max(8, r.right - 190) + 'px' }
      };
    },
    closeMenu: function () { this.menu = { open: false, epic: null, style: {} }; },
    setArchived: async function (e, archived) {
      this.closeMenu();
      try {
        var url = this.$pb.withId(this.endpoints[archived ? 'archive' : 'restore'], e.id);
        var resp = await this.$pb.api(url, { method: 'POST' });
        this.mergeEpic(resp.epic);
        this.$pb.toast(resp.message || 'Saved.');
      } catch (err) { this.$pb.toast(this.$pb.firstError(err), 'error'); }
    },
    askDelete: function (e) { this.closeMenu(); this.confirm = { open: true, epic: e }; },
    /** §16: the confirmation names how many work items are involved before anything happens. */
    deleteMessage: function () {
      var e = this.confirm.epic;
      var n = e ? this.count(e, 'total') : 0;
      if (!n) return 'The epic will be deleted. This cannot be undone.';
      return 'The epic will be deleted and ' + n + ' work item' + (n > 1 ? 's' : '') +
        ' will lose their epic. The work items themselves, and their modules and cycles, are not touched.';
    },
    remove: async function () {
      var e = this.confirm.epic; if (!e) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.destroy, e.id), { method: 'DELETE' });
        if (this.pageEpicId === e.id) { window.location.href = this.endpoints.list; return; }
        this.epics = this.epics.filter(function (x) { return x.id !== e.id; });
        this.$pb.toast(resp.message || 'Epic deleted.');
      } catch (err) { this.$pb.toast(this.$pb.firstError(err), 'error'); }
      this.confirm = { open: false, epic: null };
    },

    // ---------- work items (§9) ----------
    openPicker: function () {
      this.picker = { open: true, query: '', results: [], selected: [], busy: false, loaded: false };
      this.searchItems();
    },
    searchItems: async function () {
      if (!this.pageEpicId) return;
      this.picker.busy = true;
      try {
        var url = this.$pb.withId(this.endpoints.search, this.pageEpicId) + '?q=' + encodeURIComponent(this.picker.query || '');
        var resp = await this.$pb.api(url);
        this.picker.results = resp.items || [];
        this.picker.loaded = true;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    togglePicked: function (item) {
      // Already in this epic: shown as selected and not offered again.
      if (item.linked) return;
      var at = this.picker.selected.indexOf(item.id);
      if (at > -1) this.picker.selected.splice(at, 1); else this.picker.selected.push(item.id);
    },
    isPicked: function (item) { return item.linked || this.picker.selected.indexOf(item.id) > -1; },
    /**
     * The embedded work items screen changed WHICH items this epic holds.
     *
     * Clearing a row's chip is how a work item is taken out of an epic, and creating one from
     * that grid puts it in — both happen inside the child component, so the count above it
     * only moves if the child says so. The card it hands back carries every field these rows
     * read, so it goes straight in.
     */
    hostItemsChanged: function (change) {
      var card = change && change.card;
      if (!card) return;

      var rest = this.items.filter(function (i) { return String(i.id) !== String(card.id); });
      this.items = change.type === 'added' ? rest.concat([card]) : rest;
    },
    /**
     * One response, both views of this epic's work.
     *
     * `items` is the lean shape behind the header count (and, on epics, the Overview's own
     * filters); `gridItems` is the same work in the work items screen's card shape, which is
     * what the grid actually renders. Writing only the first is what left the count saying 5
     * while the grid still showed 3 until the page was reloaded.
     */
    applyItems: function (resp) {
      if (Array.isArray(resp.items)) this.items = resp.items;
      // Mutated in place, not replaced: <work-items-screen> watches this property, and the
      // object it was handed is the one it is watching.
      if (this.workItems && Array.isArray(resp.gridItems)) this.workItems.items = resp.gridItems;
    },
    addItems: async function () {
      if (!this.picker.selected.length || this.picker.busy) return;
      this.picker.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withFilters(this.$pb.withId(this.endpoints.addItems, this.pageEpicId)), {
          method: 'POST', body: { work_item_ids: this.picker.selected }
        });
        this.applyItems(resp);
        this.mergeEpic(resp.epic);
        this.picker.open = false;
        this.$pb.toast(resp.message || 'Added.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    removeItem: async function (item) {
      try {
        var url = this.$pb.withId(this.endpoints.removeItem, this.pageEpicId).replace('__ITEM__', item.id);
        var resp = await this.$pb.api(this.$pb.withFilters(url), { method: 'DELETE' });
        this.applyItems(resp);
        this.$pb.toast(resp.message || 'Removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    gridHeight: function () {
      var headers = (this.states.length + 1) * 40;
      return Math.min(640, this.filteredItems.length * 44 + headers + 8) + 'px';
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // Read-only notice, shown on the list and the detail alike.
    '<div v-if="!featureEnabled" class="flex items-start gap-2.5 px-6 py-2.5 border-b border-line bg-amber-50 shrink-0">' +
    '<span class="text-amber-700 shrink-0 mt-0.5" v-html="icon(\'circle-info\', 15)"></span>' +
    '<p class="text-[12px] text-amber-900">{{ disabledNotice }}' +
    '<a v-if="settingsUrl" :href="settingsUrl" class="ml-1 font-semibold underline">Project settings</a></p></div>' +

    // ============ Detail (§8) ============
    '<template v-if="pageEpic">' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.list" data-tip="Back to epics" class="text-sub hover:text-ink shrink-0" v-html="icon(\'chevron-left\', 16)"></a>' +
    '<span class="text-[12px] text-sub shrink-0">{{ pageEpic.identifier }}</span>' +
    '<span class="text-[14px] font-medium text-ink truncate">{{ pageEpic.title }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap" ' +
    ':style="{color: statusMeta(pageEpic.status).color, background: statusMeta(pageEpic.status).color + \'1a\'}">' +
    '<span class="grid place-items-center" v-html="statusIcon(pageEpic.status)"></span>' +
    '{{ statusMeta(pageEpic.status).label }}</span>' +
    '<span class="hidden sm:inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink whitespace-nowrap">' +
    '<span class="grid place-items-center" v-html="priorityMeta(pageEpic.priority).icon"></span>' +
    '{{ priorityMeta(pageEpic.priority).label }}</span>' +
    '<span v-if="dateRange(pageEpic)" class="hidden md:inline text-[12px] text-sub whitespace-nowrap">{{ dateRange(pageEpic) }}</span>' +
    '<span v-if="pageEpic.archived" class="text-[11px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5">Archived</span>' +
    '<div class="ml-auto flex items-center gap-2">' +
    '<button v-if="canCreate && !pageEpic.archived" type="button" @click="openPicker" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Add work items</button>' +
    '<button v-if="canCreate" type="button" @click="openMenu(pageEpic, $event.currentTarget)" data-tip="More" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover border border-stroke" v-html="icon(\'ellipsis\', 16)"></button>' +
    '</div></div>' +

    // §8's three tabs.
    '<div class="flex items-center gap-1 px-6 h-10 border-b border-line shrink-0">' +
    '<button v-for="t in [{k:\'overview\',l:\'Overview\'},{k:\'items\',l:\'Work Items\'},{k:\'activity\',l:\'Activity\'}]" :key="t.k" ' +
    'type="button" @click="tab = t.k" class="h-8 px-3 rounded-md text-[13px] font-medium" ' +
    ':class="tab === t.k ? \'bg-sel text-brand\' : \'text-sub hover:bg-hover\'">{{ t.l }}' +
    '<span v-if="t.k === \'items\'" class="ml-1.5 text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ items.length }}</span>' +
    '</button></div>' +

    // ---- Overview (§8) ----
    '<div v-show="tab === \'overview\'" class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="p-4 sm:p-6 max-w-[1200px] mx-auto w-full">' +
    '<p v-if="pageEpic.description" class="text-[13px] text-sub leading-relaxed mb-4 whitespace-pre-line">{{ pageEpic.description }}</p>' +

    '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">' +
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[13px] font-semibold text-head">Progress</h3>' +
    // §13: no work items reads as such, rather than as a confident 0 of 0.
    '<p v-if="!hasWork(pageEpic)" class="text-[12px] text-sub mt-0.5">No work items</p>' +
    '<p v-else class="text-[12px] text-sub mt-0.5">{{ count(pageEpic, \'completed\') }} of {{ count(pageEpic, \'total\') }} work items completed</p>' +
    '<div class="mt-3 h-2.5 w-full rounded-full overflow-hidden bg-line">' +
    '<span class="block h-full rounded-full" :style="{width: percent(pageEpic) + \'%\', background: \'#22c55e\'}"></span></div>' +
    '<div class="mt-1.5 text-[20px] font-semibold text-head">{{ percent(pageEpic) }}%</div>' +
    '<div class="mt-4 grid grid-cols-2 gap-y-2 text-[13px]">' +
    '<span class="text-sub">In progress</span><span class="text-ink">{{ count(pageEpic, \'started\') }}</span>' +
    '<span class="text-sub">Overdue</span>' +
    '<span :class="count(pageEpic, \'overdue\') ? \'text-danger font-medium\' : \'text-ink\'">{{ count(pageEpic, \'overdue\') }}</span>' +
    // §13: cancelled work is out of the denominator, so it is reported separately rather
    // than silently disappearing from the arithmetic.
    '<span v-if="count(pageEpic, \'cancelled\')" class="text-sub">Cancelled, not counted</span>' +
    '<span v-if="count(pageEpic, \'cancelled\')" class="text-ink">{{ count(pageEpic, \'cancelled\') }}</span>' +
    '</div></div>' +

    '<div class="rounded-card border border-line bg-white p-5 text-[13px]">' +
    '<h3 class="text-[13px] font-semibold text-head mb-3">Epic information</h3>' +
    '<div class="grid grid-cols-2 gap-y-2.5">' +
    '<span class="text-sub">Epic ID</span><span class="text-ink">{{ pageEpic.identifier }}</span>' +
    '<span class="text-sub">Lead</span>' +
    '<span class="text-ink truncate">{{ pageEpic.lead ? pageEpic.lead.name : \'None\' }}</span>' +
    '<span class="text-sub">Members</span>' +
    '<span class="text-ink">{{ pageEpic.members.length ? pageEpic.members.length + \' member\' + (pageEpic.members.length > 1 ? \'s\' : \'\') : \'None\' }}</span>' +
    '<span class="text-sub">Priority</span><span class="text-ink">{{ priorityMeta(pageEpic.priority).label }}</span>' +
    '<span class="text-sub">Start date</span><span class="text-ink">{{ pageEpic.start_date ? fmtDate(pageEpic.start_date) : \'None\' }}</span>' +
    '<span class="text-sub">Target date</span><span class="text-ink">{{ pageEpic.target_date ? fmtDate(pageEpic.target_date) : \'None\' }}</span>' +
    '<span class="text-sub">Created by</span><span class="text-ink truncate">{{ pageEpic.created_by || \'—\' }}</span>' +
    '</div></div></div>' +

    // §8: the cycle and module spread. This is the payoff of keeping the three dimensions
    // independent (§11/§12) — the answer is a distribution, not a single parent.
    '<div v-if="distribution" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">' +
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[13px] font-semibold text-head">Cycle distribution</h3>' +
    '<p v-if="!distribution.cycles.length" class="text-[12px] text-sub mt-2">No work items yet.</p>' +
    '<div v-for="row in distribution.cycles" :key="\'c\' + row.name" class="flex items-center gap-3 mt-2.5 text-[13px]">' +
    '<span class="text-ink truncate flex-1">{{ row.name }}</span>' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ row.count }}</span></div>' +
    '</div>' +
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[13px] font-semibold text-head">Module distribution</h3>' +
    '<p v-if="!distribution.modules.length" class="text-[12px] text-sub mt-2">No work items yet.</p>' +
    '<div v-for="row in distribution.modules" :key="\'m\' + row.name" class="flex items-center gap-3 mt-2.5 text-[13px]">' +
    '<span class="text-ink truncate flex-1">{{ row.name }}</span>' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ row.count }}</span></div>' +
    // A work item can be in several modules, so these deliberately sum to more than the
    // item count. Saying so beats a reader quietly deciding the numbers are wrong.
    '<p v-if="distribution.modules.length" class="text-[11px] text-faint mt-3">' +
    'A work item can be in more than one module, so these can add up to more than the total.</p>' +
    '</div></div>' +

    '<div class="h-8"></div></div></div>' +

    // ---- Work Items (§8) ----
    // v-if, not v-show: the grid inside measures itself on mount, and a container that is
    // display:none at that moment gives it a height of zero. Mounting when the tab is first
    // opened costs one render and avoids a collapsed grid.
    '<div v-if="tab === \'items\'" class="flex-1 min-h-0 flex flex-col">' +
    '<div v-if="!items.length" class="px-6 py-12 text-center">' +
    '<p class="text-[13px] text-sub">No work items in this epic yet.</p>' +
    '<button v-if="canCreate && !pageEpic.archived" type="button" @click="openPicker" ' +
    'class="mt-3 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Add work items</button></div>' +
    // The work items screen itself, given this epic\'s rows. Editable chips, the real drawer,
    // the same everything — because it IS the same component.
    '<work-items-screen v-else-if="workItems" :bootstrap="workItems" @items-changed="hostItemsChanged" />' +
    '</div>' +

    // ---- Activity (§8/§22) ----
    '<div v-show="tab === \'activity\'" class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="p-4 sm:p-6 max-w-[820px] mx-auto w-full">' +
    '<p v-if="!activity.length" class="text-[13px] text-sub text-center py-10">Nothing has happened to this epic yet.</p>' +
    '<div v-for="a in activity" :key="a.id" class="flex items-start gap-3 py-2.5 border-b border-line last:border-0">' +
    '<span class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0 mt-0.5" ' +
    ':style="{ background: $pb.avatarColor(a.actor) }">' +
    '<img v-if="a.actor && a.actor.avatar_url" :src="a.actor.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ a.actor ? a.actor.initial : \'?\' }}</span></span>' +
    '<div class="min-w-0 flex-1">' +
    '<p class="text-[13px] text-ink"><span class="font-medium">{{ a.actor ? a.actor.name : \'Someone\' }}</span> {{ activityText(a) }}</p>' +
    '<p class="text-[11px] text-faint mt-0.5">{{ fmtWhen(a.at) }}</p></div>' +
    '</div></div></div>' +
    '</template>' +

    // ============ List (§7) ============
    '<template v-else>' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<span class="inline-flex items-center gap-2 text-[13px] font-medium text-ink">' +
    '<span v-html="icon(\'rectangles-pair\', 15, \'text-sub\')"></span>Epics' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ visible.length }}</span></span>' +
    '<div class="ml-auto flex items-center gap-1.5 text-sub">' +
    '<input v-if="searchOpen" v-model="query" placeholder="Search epics…" ' +
    'class="h-8 w-52 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<button type="button" @click="searchOpen = !searchOpen; query = \'\'" data-tip="Search" aria-label="Search" ' +
    'class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover" v-html="icon(\'magnifying-glass\', 16)"></button>' +
    '<button v-if="archivedCount" type="button" @click="showArchived = !showArchived" ' +
    'class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">' +
    '{{ showArchived ? \'Active\' : \'Archived\' }}</button>' +
    '<button v-if="canCreate" type="button" @click="openCreate" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0">' +
    '<span v-html="icon(\'plus\', 14)"></span><span class="hidden sm:inline">Create Epic</span></button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto">' +

    // §23's empty state — what an epic is, shown rather than described.
    '<div v-if="!visible.length" class="max-w-[640px] mx-auto px-6 py-12 text-center">' +
    '<div class="rounded-xl border border-line bg-hover/40 overflow-hidden">' +
    '<video v-if="introVideo" :src="introVideo" class="w-full block" style="aspect-ratio:16/9" ' +
    'controls preload="metadata" playsinline></video>' +
    // No clip configured yet: a labelled panel, not a broken player.
    '<div v-else class="w-full grid place-items-center bg-hover text-faint" style="aspect-ratio:16/9">' +
    '<span class="grid place-items-center gap-2">' +
    '<span class="h-12 w-12 rounded-full bg-white/80 grid place-items-center text-sub" v-html="icon(\'play\', 20)"></span>' +
    '<span class="text-[12px]">Epic walkthrough</span></span></div>' +
    '</div>' +
    '<h3 class="text-[15px] font-semibold text-head mt-5">{{ showArchived ? \'No archived epics\' : \'No Epics yet\' }}</h3>' +
    '<p class="text-[13px] text-sub mt-1">Create an Epic to organize related Work Items around a larger initiative or outcome.</p>' +
    '<button v-if="canCreate && !showArchived" type="button" @click="openCreate" ' +
    'class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Create Epic</button>' +
    // No CTA for someone who cannot create one — read-only text instead.
    // Two different reasons for the same missing button, and they must not be confused: the
    // feature being off is not the same as the user lacking permission.
    '<p v-else-if="!featureEnabled" class="text-[12px] text-faint mt-4">Epics are disabled for this project.</p>' +
    '<p v-else-if="!canCreate" class="text-[12px] text-faint mt-4">You do not have permission to create epics in this project.</p>' +
    '</div>' +

    // §7's rows: Epic, Status, Priority, Lead, Progress, Work Items, dates.
    '<div v-else class="divide-y divide-line">' +
    '<div v-for="e in visible" :key="e.id" class="group flex items-center gap-3 px-6 h-[56px] hover:bg-[#f8f9fa]">' +
    '<span class="grid place-items-center shrink-0" :data-tip="statusMeta(e.status).label" v-html="statusIcon(e.status)"></span>' +
    '<span class="text-[12px] text-sub w-8 shrink-0">{{ e.identifier }}</span>' +
    '<a :href="epicUrl(e)" class="text-[14px] text-ink truncate flex-1 hover:text-brand">{{ e.title }}</a>' +
    '<span class="hidden lg:inline text-[12px] text-sub whitespace-nowrap">{{ dateRange(e) }}</span>' +
    '<span class="hidden sm:inline-flex items-center gap-1.5 shrink-0" :data-tip="\'Priority: \' + priorityMeta(e.priority).label">' +
    '<span class="grid place-items-center" v-html="priorityMeta(e.priority).icon"></span></span>' +
    '<span class="inline-flex items-center h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap" ' +
    ':style="{color: statusMeta(e.status).color, background: statusMeta(e.status).color + \'1a\'}">{{ statusMeta(e.status).label }}</span>' +
    '<span class="hidden md:inline text-[11px] text-sub w-16 text-right shrink-0">{{ count(e, \'total\') }} items</span>' +
    '<span class="hidden sm:flex items-center gap-2 w-32 shrink-0" ' +
    ':data-tip="hasWork(e) ? count(e, \'completed\') + \' of \' + count(e, \'total\') + \' work items completed\' : \'No work items\'">' +
    '<span class="flex-1 h-1.5 rounded-full bg-line overflow-hidden">' +
    '<span class="block h-full rounded-full" :style="{width: percent(e) + \'%\', background: \'#22c55e\'}"></span></span>' +
    '<span class="text-[11px] text-sub w-8 text-right">{{ percent(e) }}%</span></span>' +
    '<span v-if="e.lead" :style="{ background: $pb.avatarColor(e.lead) }" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" ' +
    ':data-tip="\'Lead: \' + e.lead.name">' +
    '<img v-if="e.lead.avatar_url" :src="e.lead.avatar_url" alt="" class="h-full w-full object-cover" /><span v-else>{{ e.lead.initial }}</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(e, $event.currentTarget)" data-tip="More" aria-label="More" ' +
    'class="h-7 w-7 grid place-items-center rounded hover:bg-line text-faint opacity-0 group-hover:opacity-100 shrink-0" v-html="icon(\'ellipsis\', 15)"></button>' +
    '</div></div>' +

    '</div></template>' +

    // ============ ⋯ menu ============
    '<div v-if="menu.open" class="fixed inset-0 z-[110]" @click="closeMenu"></div>' +
    '<div v-if="menu.open" :style="menu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5 py-1 text-[13px]">' +
    '<button type="button" @click="openEdit(menu.epic)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'pen\', 15, \'text-faint\')"></span>Edit epic</button>' +
    '<a :href="epicUrl(menu.epic)" class="w-full flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'arrow-up-right-from-square\', 15, \'text-faint\')"></span>Open epic</a>' +
    '<button type="button" @click="setArchived(menu.epic, !menu.epic.archived)" ' +
    'class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'box-archive\', 15, \'text-faint\')"></span>{{ menu.epic.archived ? \'Restore epic\' : \'Archive epic\' }}</button>' +
    '<template v-if="canDelete"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="askDelete(menu.epic)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger">' +
    '<span v-html="icon(\'trash\', 15)"></span>Delete epic</button></template>' +
    '</div>' +

    // ============ Create / edit (§6) ============
    // Wider than the default 520px: this form pairs fields side by side, and at the narrow
    // width those pairs are unreadable.
    '<pb-modal :open="form.open" width="max-w-[720px]" :title="form.id ? \'Edit epic\' : \'Create Epic\'" @close="form.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title</label>' +
    '<input v-model="form.title" :maxlength="titleMax" placeholder="Epic title" @keyup.enter="save" ' +
    'class="pb-input w-full" :class="{\'is-error\': form.errors.title}" />' +
    '<p v-if="form.errors.title" class="text-[12px] text-danger mt-1">{{ form.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description</label>' +
    '<textarea v-model="form.description" rows="3" :maxlength="descriptionMax" placeholder="What is this initiative for?" ' +
    'class="pb-textarea w-full resize-none"></textarea>' +

    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Status</label>' +
    '<pb-combo v-model="form.status" :options="statusOptions" :searchable="false" placeholder="Status" /></div>' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Priority</label>' +
    '<pb-combo v-model="form.priority" :options="priorityOptions" :searchable="false" placeholder="Priority" /></div>' +
    '</div>' +

    '<div class="mt-4"><label class="block text-[13px] font-medium text-ink mb-1.5">Lead</label>' +
    '<pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="No lead" /></div>' +

    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Start date</label>' +
    '<button type="button" @click="openDate(\'start_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border border-stroke text-[13px] hover:bg-hover" ' +
    ':class="form.start_date ? \'text-ink\' : \'text-sub\'">' +
    '<span v-html="icon(\'calendar\', 14, \'text-faint\')"></span>{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</button></div>' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Target date</label>' +
    '<button type="button" @click="openDate(\'target_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border text-[13px] hover:bg-hover" ' +
    ':class="[form.errors.target_date ? \'border-danger\' : \'border-stroke\', form.target_date ? \'text-ink\' : \'text-sub\']">' +
    '<span v-html="icon(\'calendar\', 14, \'text-faint\')"></span>{{ form.target_date ? fmtDate(form.target_date) : \'Target date\' }}</button></div>' +
    '</div>' +
    '<p v-if="form.errors.target_date" class="text-[12px] text-danger mt-1.5">{{ form.errors.target_date[0] }}</p>' +

    '<div class="mt-4"><label class="block text-[13px] font-medium text-ink mb-1.5">Members</label>' +
    '<pb-combo v-model="form.member_ids" :options="memberOptions" multiple placeholder="Add members…" />' +
    '<p class="text-[12px] text-faint mt-1.5">Epic members are not automatically assigned to its work items.</p></div>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="form.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!formValid || form.saving" @click="save">{{ form.saving ? \'Saving…\' : (form.id ? \'Save\' : \'Create Epic\') }}</button>' +
    '</template></pb-modal>' +

    // The shared calendar, teleported so the dialog's scroll cannot clip it.
    '<teleport to="body">' +
    '<div v-if="dateMenu.open" class="fixed inset-0 z-[129]" @click="closeDate"></div>' +
    '<div v-if="dateMenu.open" :style="dateMenu.style" class="rounded-lg bg-white shadow-xl outline outline-1 outline-black/5">' +
    '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none" ' +
    ':value="dateMenu.open === \'start_date\' ? form.start_date : form.target_date" ' +
    ':after="dateMenu.open === \'target_date\' ? dateBound(form.start_date, -1) : null" ' +
    ':before="dateMenu.open === \'start_date\' ? dateBound(form.target_date, 1) : null" ' +
    '@pick="onDatePick" @clear="onDateClear" />' +
    '</div></teleport>' +

    // ============ Add work items (§9) ============
    '<pb-modal :open="picker.open" title="Add work items" @close="picker.open = false">' +
    '<input v-model="picker.query" @input="searchItems" placeholder="Search work items…" ' +
    'class="w-full h-9 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="mt-2 max-h-[320px] overflow-y-auto">' +
    '<button v-for="r in picker.results" :key="r.id" type="button" @click="togglePicked(r)" :disabled="r.linked" ' +
    'class="w-full text-left flex items-center gap-2.5 px-2 py-2 rounded-md hover:bg-hover disabled:opacity-60">' +
    '<span class="h-[18px] w-[18px] rounded border grid place-items-center shrink-0 transition-colors" ' +
    ':class="isPicked(r) ? \'bg-brand border-brand\' : \'border-stroke bg-white\'">' +
    '<svg v-if="isPicked(r)" width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4L19 7" stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '<span class="text-[11px] text-faint font-medium shrink-0">{{ r.identifier }}</span>' +
    '<span class="text-[13px] text-ink truncate flex-1">{{ r.title }}</span>' +
    '<span v-if="r.linked" class="text-[11px] text-faint shrink-0">Already added</span>' +
    '</button>' +
    '<p v-if="picker.loaded && !picker.results.length" class="px-2 py-6 text-[13px] text-sub text-center">No work items match.</p>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="picker.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!picker.selected.length || picker.busy" @click="addItems">Add {{ picker.selected.length || \'\' }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="confirm.open" title="Delete epic?" :message="deleteMessage()" ' +
    'confirm-label="Delete epic" @close="confirm.open = false" @confirm="remove" />' +

    '</div>'
});
