/* Project Workspace › Modules (Module Management §7-§13).
   ------------------------------------------------------------------
   One Vue root for both the landing page and a single module's detail, the same way Cycles
   and Work Items handle theirs: `pageModuleId` in the bootstrap decides which renders, so a
   module URL is real and linkable.

   A module's status is STORED, unlike a cycle's derived one — §6.5 is explicit that
   completion is a decision someone makes, not something inferred from the calendar. So this
   screen sets it; nothing computes it.
   ------------------------------------------------------------------ */

/**
 * A glyph per module status, drawn with the same helpers as the work item state icons
 * (work-item-ui.js) so a module reads like the rest of the app rather than inventing a
 * second visual language for "where something is".
 *
 * §20 Accessibility: status must not rely on colour alone — every one of these travels with
 * its label, here and in the list rows.
 */
function pbModuleStatusIcon(key, color) {
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
    // Backlog is the unscheduled default (§6.1) — dashed, exactly like a backlog work item.
    default: return wiDot(color, true);
  }
}

PB.boot('project-modules', {
  props: { bootstrap: Object },
  components: { 'wi-list': WiList, 'wi-calendar': WiCalendar, 'work-items-screen': WorkItemsScreen },
  data: function () {
    var b = this.bootstrap || {};
    return {
      project: b.project || {},
      modules: (b.modules || []).slice(),
      items: (b.items || []).slice(),
      states: Array.isArray(b.states) ? b.states : [],
      members: Array.isArray(b.members) ? b.members : [],
      statuses: Array.isArray(b.statuses) ? b.statuses : [],
      defaultStatus: b.defaultStatus || 'backlog',
      pageModuleId: b.pageModuleId || null,
      canCreate: !!b.canCreate,
      labelsEnabled: b.labelsEnabled !== false,
      // The full work items payload for this record, or null on the landing page.
      workItems: b.workItems || null,
      // Feature Disable §3/§4: the page still loads when the feature is off so existing
      // records stay readable; these render the read-only state and say why.
      featureEnabled: b.featureEnabled !== false,
      disabledNotice: b.disabledNotice || '',
      settingsUrl: b.settingsUrl || '',
      canDelete: !!b.canDelete,
      titleMax: b.titleMax || 255,
      descriptionMax: b.descriptionMax || 2000,
      introVideo: b.introVideo || '',
      endpoints: b.endpoints || {},
      query: '',
      searchOpen: false,
      showArchived: false,
      form: {
        open: false, id: null, title: '', description: '', status: 'backlog',
        start_date: null, end_date: null, lead_user_id: '', member_ids: [],
        saving: false, errors: {}
      },
      confirm: { open: false, module: null },
      menu: { open: false, module: null, style: {} },
      picker: { open: false, query: '', results: [], selected: [], busy: false, loaded: false },
      dateMenu: { open: '', style: {} }
    };
  },
  computed: {
    pageModule: function () {
      var self = this;
      return this.pageModuleId
        ? this.modules.filter(function (m) { return m.id === self.pageModuleId; })[0] || null
        : null;
    },
    /** §7.3/§12.2: search by title, and archived modules stay out unless asked for. */
    visible: function () {
      var q = (this.query || '').trim().toLowerCase();
      var archived = this.showArchived;
      return this.modules.filter(function (m) {
        if (m.archived !== archived) return false;
        return !q || (m.title || '').toLowerCase().indexOf(q) > -1;
      });
    },
    archivedCount: function () {
      return this.modules.filter(function (m) { return m.archived; }).length;
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
        return { value: s.key, label: s.label, icon: pbModuleStatusIcon(s.key, s.color) };
      });
    }
  },
  methods: {
    // ---------- display ----------
    /**
     * The shared icon set, reachable from the template.
     *
     * `wiIcon` is a plain global from assets/js/icons.js; a Vue template can only call what
     * is on the component, so this is the one line that bridges them.
     */
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },
    /** The status glyph, for the list rows and the detail header. */
    statusIcon: function (key) {
      var meta = this.statusMeta(key);

      return pbModuleStatusIcon(key, meta.color);
    },
    statusMeta: function (key) {
      var found = this.statuses.filter(function (s) { return s.key === key; })[0];
      return found || { key: key, label: key, color: '#9ca3af' };
    },
    percent: function (m) { return (m.progress && m.progress.percent) || 0; },
    count: function (m, k) { return (m.progress && m.progress[k]) || 0; },
    fmtDate: function (iso) { return iso ? wiFmtDate(iso) : ''; },
    /** §7.2: the schedule as one line, or nothing when the module has no dates (§6.1). */
    dateRange: function (m) {
      if (!m.start_date && !m.end_date) return '';
      if (m.start_date && m.end_date) return this.fmtDate(m.start_date) + ' – ' + this.fmtDate(m.end_date);
      return m.start_date ? 'From ' + this.fmtDate(m.start_date) : 'Due ' + this.fmtDate(m.end_date);
    },
    moduleUrl: function (m) { return this.$pb.withId(this.endpoints.module, m.id); },
    itemUrl: function (i) { return this.$pb.withId(this.endpoints.workItem, i.id); },
    openItem: function (i) { window.location.href = this.itemUrl(i); },

    // ---------- create / edit (§5, §11) ----------
    openCreate: function () {
      this.form = {
        open: true, id: null, title: '', description: '', status: this.defaultStatus,
        start_date: null, end_date: null, lead_user_id: '', member_ids: [],
        saving: false, errors: {}
      };
    },
    openEdit: function (m) {
      this.closeMenu();
      this.form = {
        open: true, id: m.id, title: m.title, description: m.description || '',
        status: m.status, start_date: m.start_date, end_date: m.end_date,
        lead_user_id: m.lead ? String(m.lead.id) : '',
        member_ids: (m.members || []).map(function (u) { return String(u.id); }),
        saving: false, errors: {}
      };
    },
    save: async function () {
      if (!this.formValid || this.form.saving) return;
      this.form.saving = true; this.form.errors = {};

      var f = this.form;
      var body = {
        title: f.title.trim(), description: f.description || null, status: f.status,
        start_date: f.start_date || null, end_date: f.end_date || null,
        lead_user_id: f.lead_user_id || null,
        member_ids: f.member_ids.map(Number)
      };

      try {
        var editing = !!f.id;
        var url = editing ? this.$pb.withId(this.endpoints.update, f.id) : this.endpoints.store;
        var resp = await this.$pb.api(url, { method: editing ? 'PATCH' : 'POST', body: body });
        this.mergeModule(resp.module);
        this.form.open = false;
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) {
        this.form.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.form.saving = false;
    },
    /** Replace in place, or prepend — the server's copy is always the truth. */
    mergeModule: function (module) {
      if (!module) return;
      var at = -1;
      for (var i = 0; i < this.modules.length; i++) {
        if (this.modules[i].id === module.id) { at = i; break; }
      }
      if (at > -1) this.modules.splice(at, 1, module);
      else this.modules.unshift(module);
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
     * wi-calendar's bounds are EXCLUSIVE, but §15 only forbids an end date EARLIER than the
     * start — equal is a legal one-day module. Shifting the bound expresses that without
     * giving the shared picker a second meaning.
     */
    dateBound: function (iso, days) {
      var d = wiParseISO(iso);
      if (!d) return null;
      return wiISO(new Date(d.getFullYear(), d.getMonth(), d.getDate() + days));
    },

    // ---------- ⋯ menu, archive, delete ----------
    openMenu: function (m, btn) {
      var r = btn.getBoundingClientRect();
      this.menu = {
        open: true, module: m,
        style: { position: 'fixed', width: '190px', zIndex: 120, top: (r.bottom + 6) + 'px', left: Math.max(8, r.right - 190) + 'px' }
      };
    },
    closeMenu: function () { this.menu = { open: false, module: null, style: {} }; },
    setArchived: async function (m, archived) {
      this.closeMenu();
      try {
        var url = this.$pb.withId(this.endpoints[archived ? 'archive' : 'restore'], m.id);
        var resp = await this.$pb.api(url, { method: 'POST' });
        this.mergeModule(resp.module);
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    askDelete: function (m) { this.closeMenu(); this.confirm = { open: true, module: m }; },
    remove: async function () {
      var m = this.confirm.module; if (!m) return;
      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.destroy, m.id), { method: 'DELETE' });
        if (this.pageModuleId === m.id) { window.location.href = this.endpoints.list; return; }
        this.modules = this.modules.filter(function (x) { return x.id !== m.id; });
        this.$pb.toast('Module deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, module: null };
    },

    // ---------- work items (§8.3 / §9.4) ----------
    openPicker: function () {
      this.picker = { open: true, query: '', results: [], selected: [], busy: false, loaded: false };
      this.searchItems();
    },
    searchItems: async function () {
      if (!this.pageModuleId) return;
      this.picker.busy = true;
      try {
        var url = this.$pb.withId(this.endpoints.search, this.pageModuleId) + '?q=' + encodeURIComponent(this.picker.query || '');
        var resp = await this.$pb.api(url);
        this.picker.results = resp.items || [];
        this.picker.loaded = true;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    togglePicked: function (item) {
      // §9.4: already-linked items show as selected and cannot be picked again.
      if (item.linked) return;
      var at = this.picker.selected.indexOf(item.id);
      if (at > -1) this.picker.selected.splice(at, 1); else this.picker.selected.push(item.id);
    },
    isPicked: function (item) { return item.linked || this.picker.selected.indexOf(item.id) > -1; },
    addItems: async function () {
      if (!this.picker.selected.length || this.picker.busy) return;
      this.picker.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.addItems, this.pageModuleId), {
          method: 'POST', body: { work_item_ids: this.picker.selected }
        });
        this.items = resp.items || this.items;
        this.mergeModule(resp.module);
        this.picker.open = false;
        this.$pb.toast(resp.message || 'Added.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    removeItem: async function (item) {
      try {
        var url = this.$pb.withId(this.endpoints.removeItem, this.pageModuleId).replace('__ITEM__', item.id);
        var resp = await this.$pb.api(url, { method: 'DELETE' });
        this.items = resp.items || this.items;
        this.$pb.toast(resp.message || 'Removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    gridHeight: function () {
      var headers = (this.states.length + 1) * 40;
      return Math.min(640, this.items.length * 44 + headers + 8) + 'px';
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +
    // Feature Disable §3/§4: read-only notice, shown on the list and the detail alike.
    '<div v-if="!featureEnabled" class="flex items-start gap-2.5 px-6 py-2.5 border-b border-line bg-amber-50 shrink-0">' +
    '<span class="text-amber-700 shrink-0 mt-0.5" v-html="icon(\'circle-info\', 15)"></span>' +
    '<p class="text-[12px] text-amber-900">{{ disabledNotice }}' +
    '<a v-if="settingsUrl" :href="settingsUrl" class="ml-1 font-semibold underline">Project settings</a></p></div>' +

    // ============ Detail (§8) ============
    '<template v-if="pageModule">' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.list" data-tip="Back to modules" class="text-sub hover:text-ink shrink-0" v-html="icon(\'chevron-left\', 16)"></a>' +
    '<span class="text-[14px] font-medium text-ink truncate">{{ pageModule.title }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap" ' +
    ':style="{color: statusMeta(pageModule.status).color, background: statusMeta(pageModule.status).color + \'1a\'}">' +
    '<span class="grid place-items-center" v-html="statusIcon(pageModule.status)"></span>' +
    '{{ statusMeta(pageModule.status).label }}</span>' +
    '<span v-if="dateRange(pageModule)" class="text-[12px] text-sub whitespace-nowrap">{{ dateRange(pageModule) }}</span>' +
    '<span v-if="pageModule.archived" class="text-[11px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5">Archived</span>' +
    '<div class="ml-auto flex items-center gap-2">' +
    '<button v-if="canCreate && !pageModule.archived" type="button" @click="openPicker" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Add work items</button>' +
    '<button v-if="canCreate" type="button" @click="openMenu(pageModule, $event.currentTarget)" data-tip="More" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover border border-stroke" v-html="icon(\'ellipsis\', 16)"></button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="p-4 sm:p-6 max-w-[1200px] mx-auto w-full">' +
    '<p v-if="pageModule.description" class="text-[13px] text-sub leading-relaxed mb-4 whitespace-pre-line">{{ pageModule.description }}</p>' +

    // Progress + module information (§8.2, §10.3)
    '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">' +
    '<div class="rounded-card border border-line bg-white p-5">' +
    '<h3 class="text-[13px] font-semibold text-head">Progress</h3>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ count(pageModule, \'completed\') }} of {{ count(pageModule, \'total\') }} work items completed</p>' +
    '<div class="mt-3 h-2.5 w-full rounded-full overflow-hidden bg-line">' +
    '<span class="block h-full rounded-full" :style="{width: percent(pageModule) + \'%\', background: \'#22c55e\'}"></span></div>' +
    '<div class="mt-1.5 text-[20px] font-semibold text-head">{{ percent(pageModule) }}%</div>' +
    '</div>' +

    '<div class="rounded-card border border-line bg-white p-5 text-[13px]">' +
    '<h3 class="text-[13px] font-semibold text-head mb-3">Module information</h3>' +
    '<div class="grid grid-cols-2 gap-y-2.5">' +
    '<span class="text-sub">Lead</span>' +
    '<span class="text-ink truncate">{{ pageModule.lead ? pageModule.lead.name : \'None\' }}</span>' +
    '<span class="text-sub">Members</span>' +
    '<span class="text-ink">{{ pageModule.members.length ? pageModule.members.length + \' member\' + (pageModule.members.length > 1 ? \'s\' : \'\') : \'None\' }}</span>' +
    '<span class="text-sub">Start date</span><span class="text-ink">{{ pageModule.start_date ? fmtDate(pageModule.start_date) : \'None\' }}</span>' +
    '<span class="text-sub">End date</span><span class="text-ink">{{ pageModule.end_date ? fmtDate(pageModule.end_date) : \'None\' }}</span>' +
    '<span class="text-sub">Created by</span><span class="text-ink truncate">{{ pageModule.created_by || \'—\' }}</span>' +
    '</div></div></div>' +

    '<div class="h-6"></div></div>' +

    // The module's work items — the project's own work item grid (§8.3).
    '<div>' +
    '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line">' +
    '<span class="flex items-center gap-2 text-[13px] font-medium text-ink shrink-0">' +
    '<span v-html="icon(\'bars\', 15, \'text-sub\')"></span>' +
    'Module work items <span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ items.length }}</span></span>' +
    '</div>' +
    '<div v-show="!items.length" class="px-6 py-12 text-center">' +
    '<p class="text-[13px] text-sub">No work items in this module yet.</p>' +
    '<button v-if="canCreate && !pageModule.archived" type="button" @click="openPicker" ' +
    'class="mt-3 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Add work items</button></div>' +
    // The work items SCREEN, given this module's rows — see the note in cycles.js.
    '<work-items-screen v-if="workItems && items.length" :bootstrap="workItems" />' +
    '</div>' +
    '<div class="h-8"></div></div>' +
    '</template>' +

    // ============ List (§7) ============
    '<template v-else>' +
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<span class="inline-flex items-center gap-2 text-[13px] font-medium text-ink">' +
    '<span v-html="icon(\'grid\', 15, \'text-sub\')"></span>Modules' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ visible.length }}</span></span>' +
    '<div class="ml-auto flex items-center gap-1.5 text-sub">' +
    '<input v-if="searchOpen" v-model="query" placeholder="Search modules…" ' +
    'class="h-8 w-52 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<button type="button" @click="searchOpen = !searchOpen; query = \'\'" data-tip="Search" aria-label="Search" ' +
    'class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover" v-html="icon(\'magnifying-glass\', 16)"></button>' +
    '<button v-if="archivedCount" type="button" @click="showArchived = !showArchived" ' +
    'class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">' +
    '{{ showArchived ? \'Active\' : \'Archived\' }}</button>' +
    '<button v-if="canCreate" type="button" @click="openCreate" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0">' +
    '<span v-html="icon(\'plus\', 14)"></span><span class="hidden sm:inline">Add Module</span></button>' +
    '</div></div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto">' +

    // §7.6 empty state — what the module feature is, shown rather than described.
    '<div v-if="!visible.length" class="max-w-[640px] mx-auto px-6 py-12 text-center">' +
    '<div class="rounded-xl border border-line bg-hover/40 overflow-hidden">' +
    '<video v-if="introVideo" :src="introVideo" class="w-full block" style="aspect-ratio:16/9" ' +
    'controls preload="metadata" playsinline></video>' +
    // No clip configured yet: a labelled panel, not a broken player.
    '<div v-else class="w-full grid place-items-center bg-hover text-faint" style="aspect-ratio:16/9">' +
    '<span class="grid place-items-center gap-2">' +
    '<span class="h-12 w-12 rounded-full bg-white/80 grid place-items-center text-sub" v-html="icon(\'play\', 20)"></span>' +
    '<span class="text-[12px]">Module walkthrough</span></span></div>' +
    '</div>' +
    '<h3 class="text-[15px] font-semibold text-head mt-5">{{ showArchived ? \'No archived modules\' : \'No modules yet\' }}</h3>' +
    '<p class="text-[13px] text-sub mt-1">Create a module to group related work items and track progress toward a focused project objective.</p>' +
    '<button v-if="canCreate && !showArchived" type="button" @click="openCreate" ' +
    'class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Add Module</button>' +
    // §7.6: no CTA for someone who cannot create one — read-only text instead.
    '<p v-else-if="!canCreate" class="text-[12px] text-faint mt-4">You do not have permission to create modules in this project.</p>' +
    '</div>' +

    // §7.2 module rows
    '<div v-else class="divide-y divide-line">' +
    '<div v-for="m in visible" :key="m.id" class="group flex items-center gap-3 px-6 h-[56px] hover:bg-[#f8f9fa]">' +
    '<span class="grid place-items-center shrink-0" :data-tip="statusMeta(m.status).label" v-html="statusIcon(m.status)"></span>' +
    '<a :href="moduleUrl(m)" class="text-[14px] text-ink truncate flex-1 hover:text-brand">{{ m.title }}</a>' +
    '<span class="hidden md:inline text-[12px] text-sub whitespace-nowrap">{{ dateRange(m) }}</span>' +
    '<span class="inline-flex items-center h-6 px-2.5 rounded-md text-[12px] font-medium whitespace-nowrap" ' +
    ':style="{color: statusMeta(m.status).color, background: statusMeta(m.status).color + \'1a\'}">{{ statusMeta(m.status).label }}</span>' +
    '<span class="hidden sm:flex items-center gap-2 w-32 shrink-0" :data-tip="count(m, \'completed\') + \' of \' + count(m, \'total\') + \' work items completed\'">' +
    '<span class="flex-1 h-1.5 rounded-full bg-line overflow-hidden">' +
    '<span class="block h-full rounded-full" :style="{width: percent(m) + \'%\', background: \'#22c55e\'}"></span></span>' +
    '<span class="text-[11px] text-sub w-8 text-right">{{ percent(m) }}%</span></span>' +
    '<span v-if="m.lead" class="h-6 w-6 rounded-full overflow-hidden grid place-items-center bg-slate-600 text-white text-[10px] font-bold shrink-0" ' +
    ':data-tip="\'Lead: \' + m.lead.name">' +
    '<img v-if="m.lead.avatar_url" :src="m.lead.avatar_url" alt="" class="h-full w-full object-cover" /><span v-else>{{ m.lead.initial }}</span></span>' +
    '<button v-if="canCreate" type="button" @click="openMenu(m, $event.currentTarget)" data-tip="More" aria-label="More" ' +
    'class="h-7 w-7 grid place-items-center rounded hover:bg-line text-faint opacity-0 group-hover:opacity-100 shrink-0" v-html="icon(\'ellipsis\', 15)"></button>' +
    '</div></div>' +

    '</div></template>' +

    // ============ ⋯ menu ============
    '<div v-if="menu.open" class="fixed inset-0 z-[110]" @click="closeMenu"></div>' +
    '<div v-if="menu.open" :style="menu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5 py-1 text-[13px]">' +
    '<button type="button" @click="openEdit(menu.module)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'pen\', 15, \'text-faint\')"></span>Edit module</button>' +
    '<a :href="moduleUrl(menu.module)" class="w-full flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'arrow-up-right-from-square\', 15, \'text-faint\')"></span>Open module</a>' +
    '<button type="button" @click="setArchived(menu.module, !menu.module.archived)" ' +
    'class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink">' +
    '<span v-html="icon(\'box-archive\', 15, \'text-faint\')"></span>{{ menu.module.archived ? \'Restore module\' : \'Archive module\' }}</button>' +
    '<template v-if="canDelete"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="askDelete(menu.module)" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger">' +
    '<span v-html="icon(\'trash\', 15)"></span>Delete module</button></template>' +
    '</div>' +

    // ============ Create / edit (§5.2) ============
    // Wider than the default 520px: this form pairs fields side by side (status/lead,
    // start/end), and at the narrow width those pairs are unreadable.
    '<pb-modal :open="form.open" width="max-w-[720px]" :title="form.id ? \'Edit module\' : \'Create module\'" @close="form.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title</label>' +
    '<input v-model="form.title" :maxlength="titleMax" placeholder="Module title" @keyup.enter="save" ' +
    'class="pb-input w-full" :class="{\'is-error\': form.errors.title}" />' +
    '<p v-if="form.errors.title" class="text-[12px] text-danger mt-1">{{ form.errors.title[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description</label>' +
    '<textarea v-model="form.description" rows="3" :maxlength="descriptionMax" placeholder="What is this module for?" ' +
    'class="pb-textarea w-full resize-none"></textarea>' +

    // Status and Lead use the same searchable combo as every other picker in the app, so a
    // long member list is type-ahead rather than a scroll through a native select.
    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Status</label>' +
    '<pb-combo v-model="form.status" :options="statusOptions" :searchable="false" placeholder="Status" /></div>' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Lead</label>' +
    '<pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="No lead" /></div>' +
    '</div>' +

    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">Start date</label>' +
    '<button type="button" @click="openDate(\'start_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border border-stroke text-[13px] hover:bg-hover" ' +
    ':class="form.start_date ? \'text-ink\' : \'text-sub\'">' +
    '<span v-html="icon(\'calendar\', 14, \'text-faint\')"></span>{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</button></div>' +
    '<div class="min-w-0"><label class="block text-[13px] font-medium text-ink mb-1.5">End date</label>' +
    '<button type="button" @click="openDate(\'end_date\', $event.currentTarget)" ' +
    'class="w-full inline-flex items-center gap-1.5 h-9 px-2.5 rounded-md border text-[13px] hover:bg-hover" ' +
    ':class="[form.errors.end_date ? \'border-danger\' : \'border-stroke\', form.end_date ? \'text-ink\' : \'text-sub\']">' +
    '<span v-html="icon(\'calendar\', 14, \'text-faint\')"></span>{{ form.end_date ? fmtDate(form.end_date) : \'End date\' }}</button></div>' +
    '</div>' +
    '<p v-if="form.errors.end_date" class="text-[12px] text-danger mt-1.5">{{ form.errors.end_date[0] }}</p>' +

    '<div class="mt-4"><label class="block text-[13px] font-medium text-ink mb-1.5">Members</label>' +
    '<pb-combo v-model="form.member_ids" :options="memberOptions" multiple placeholder="Add members…" />' +
    '<p class="text-[12px] text-faint mt-1.5">Module members are not automatically assigned to its work items.</p></div>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="form.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!formValid || form.saving" @click="save">{{ form.saving ? \'Saving…\' : (form.id ? \'Save\' : \'Create Module\') }}</button>' +
    '</template></pb-modal>' +

    // The shared calendar, teleported so the dialog's scroll cannot clip it.
    '<teleport to="body">' +
    '<div v-if="dateMenu.open" class="fixed inset-0 z-[129]" @click="closeDate"></div>' +
    '<div v-if="dateMenu.open" :style="dateMenu.style" class="rounded-lg bg-white shadow-xl outline outline-1 outline-black/5">' +
    '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none" ' +
    ':value="dateMenu.open === \'start_date\' ? form.start_date : form.end_date" ' +
    ':after="dateMenu.open === \'end_date\' ? dateBound(form.start_date, -1) : null" ' +
    ':before="dateMenu.open === \'start_date\' ? dateBound(form.end_date, 1) : null" ' +
    '@pick="onDatePick" @clear="onDateClear" />' +
    '</div></teleport>' +

    // ============ Add work items (§9.4) ============
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

    '<pb-confirm :open="confirm.open" title="Delete module?" ' +
    ':message="\'The module will be removed and all work item associations cleared. The work items themselves are not deleted.\'" ' +
    'confirm-label="Delete module" @close="confirm.open = false" @confirm="remove" />' +

    '</div>'
});
