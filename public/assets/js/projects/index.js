/* Projects list + Add Project modal (Phase 4) — matches html/projects.html look & feel. */

// Fallback cover gradients so the cover header/swatches always look right, even if
// the server config doesn't provide a cover list.
var PB_COVER_FALLBACK = [
  'linear-gradient(120deg,#0b0b0d 0%,#7f1d1d 55%,#0e7490 100%)',
  'linear-gradient(120deg,#1e3a8a 0%,#6d28d9 100%)',
  'linear-gradient(120deg,#065f46 0%,#0891b2 100%)',
  'linear-gradient(120deg,#9a3412 0%,#b45309 100%)',
  'linear-gradient(120deg,#334155 0%,#0f172a 100%)',
  'linear-gradient(120deg,#be123c 0%,#7c3aed 100%)'
];

// Inline icons used by the Access/Lead chips (from projects.html).
var PB_SVG = {
  globe: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" stroke="currentColor" stroke-width="1.6"/></svg>',
  lock: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7"/></svg>',
  person: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'
};

PB.boot('projects-index', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    var covers = Array.isArray(b.coverPresets) && b.coverPresets.length ? b.coverPresets : PB_COVER_FALLBACK;
    return {
      projects: Array.isArray(b.projects) ? b.projects : [],
      archived: !!b.archived,
      canCreate: !!b.canCreate,
      members: Array.isArray(b.members) ? b.members : [],
      visibilities: Array.isArray(b.visibilities) ? b.visibilities : ['public', 'private'],
      states: Array.isArray(b.states) ? b.states : [],
      priorities: Array.isArray(b.priorities) ? b.priorities : [],
      coverPresets: covers,
      endpoints: b.endpoints || {},
      statusMenu: { open: false, projectId: null, style: {} },
      priorityMenu: { open: false, projectId: null, style: {} },
      leadMenu: { open: false, projectId: null, style: {} }, leadMenuQuery: '',
      // Design-system calendar popover (quick options + Custom Date grid), matches projects.html.
      dateMenu: { open: false, projectId: null, field: 'start_date', mode: 'quick', vy: 2026, vm: 0, style: {}, monthOpen: false, yearOpen: false },
      open: false, creating: false, idEdited: false, errors: {},
      accessOpen: false, leadOpen: false, leadQuery: '',
      coverImage: '', coverUploading: false, coverPct: 0, coverName: '',
      form: { name: '', identifier: '', description: '', visibility: 'public', lead_user_id: '', emoji: '', cover_gradient: covers[0] }
    };
  },
  mounted: function () {
    // Open the create modal automatically when arrived via "New project" (?create=1).
    try {
      if (this.canCreate && !this.archived && new URLSearchParams(window.location.search).get('create') === '1') {
        this.openCreate();
      }
    } catch (e) {}
  },
  computed: {
    accessOptions: function () {
      var byKey = {
        private: { key: 'private', label: 'Private', desc: 'Accessible only by invite', icon: PB_SVG.lock },
        public: { key: 'public', label: 'Public', desc: 'Anyone in the workspace except Guests can join', icon: PB_SVG.globe }
      };
      var order = this.visibilities.length ? this.visibilities : ['private', 'public'];
      var seen = {}, out = [];
      order.forEach(function (k) { if (byKey[k] && !seen[k]) { seen[k] = 1; out.push(byKey[k]); } });
      if (!out.length) { out = [byKey.private, byKey.public]; }
      return out;
    },
    accessCurrent: function () {
      var v = this.form.visibility, opts = this.accessOptions;
      return opts.find(function (o) { return o.key === v; }) || opts[opts.length - 1];
    },
    personIcon: function () { return PB_SVG.person; },
    filteredMembers: function () {
      var q = (this.leadQuery || '').toLowerCase();
      return this.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    leadSelected: function () {
      var id = this.form.lead_user_id;
      if (!id) return null;
      return this.members.find(function (m) { return String(m.id) === String(id); }) || null;
    },
    statusProject: function () {
      var id = this.statusMenu.projectId;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    priorityMenuProject: function () {
      var id = this.priorityMenu.projectId;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    leadMenuProject: function () {
      var id = this.leadMenu.projectId;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    leadMenuMembers: function () {
      var q = (this.leadMenuQuery || '').toLowerCase();
      return this.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    dateMenuProject: function () {
      var id = this.dateMenu.projectId;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    // The currently-selected date (Date obj) for the field being edited, or null.
    dateSelected: function () {
      var p = this.dateMenuProject; if (!p) return null;
      var raw = this.dateMenu.field === 'end_date' ? p.end_date : p.start_date;
      return this._parseISO(raw);
    },
    // Selectable range for the field being edited, so the two dates stay ordered:
    // an end date can't precede the start; a start date can't follow the end.
    dateBounds: function () {
      var p = this.dateMenuProject; if (!p) return { min: null, max: null };
      if (this.dateMenu.field === 'end_date') return { min: this._parseISO(p.start_date), max: null };
      return { min: null, max: this._parseISO(p.end_date) };
    },
    calMonths: function () {
      var M = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      return M.map(function (name, i) { return { i: i, name: name }; });
    },
    calMonthLabel: function () { return this.calMonths[this.dateMenu.vm] ? this.calMonths[this.dateMenu.vm].name : ''; },
    calYears: function () { var out = []; for (var y = 2015; y <= 2035; y++) out.push(y); return out; },
    // 42-cell month grid (6 weeks) for the visible month/year.
    calCells: function () {
      var y = this.dateMenu.vy, m = this.dateMenu.vm;
      var sel = this.dateSelected, today = new Date(); today.setHours(0, 0, 0, 0);
      var bounds = this.dateBounds;
      var start = new Date(y, m, 1); start.setDate(1 - start.getDay());
      var same = function (a, b) { return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); };
      var cells = [];
      for (var i = 0; i < 42; i++) {
        var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        var disabled = (bounds.min && d < bounds.min) || (bounds.max && d > bounds.max);
        cells.push({
          key: d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(),
          day: d.getDate(),
          iso: this._isoOf(d),
          inMonth: d.getMonth() === m,
          isSel: same(d, sel),
          isToday: same(d, today),
          disabled: !!disabled
        });
      }
      return cells;
    },
    modalCoverStyle: function () {
      return this.coverImage
        ? { backgroundImage: 'url(' + this.coverImage + ')', backgroundSize: 'cover', backgroundPosition: 'center' }
        : { background: this.form.cover_gradient || this.coverPresets[0] };
    }
  },
  methods: {
    // @mention handle shown wherever a project id appears (PRJ mention handle).
    handle: function (id) { return '@' + String(id || '').toLowerCase(); },
    openStatusMenu: function (p, e) {
      if (!p || !p.can_manage || !this.endpoints.state) return;
      var r = e.currentTarget.getBoundingClientRect();
      var width = 208;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
      this.statusMenu = {
        open: true, projectId: p.id,
        style: { position: 'fixed', left: left + 'px', bottom: (window.innerHeight - r.top + 6) + 'px', width: width + 'px', zIndex: 120 }
      };
    },
    closeStatusMenu: function () { this.statusMenu = { open: false, projectId: null, style: {} }; },
    setStatus: async function (p, s) {
      if (!p || !this.endpoints.state) { this.closeStatusMenu(); return; }
      try {
        var url = this.$pb.withId(this.endpoints.state, p.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: { state_id: s ? s.id : '' } });
        p.state = resp.state;
        this.$pb.toast('Status updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.closeStatusMenu();
    },
    openPriorityMenu: function (p, e) {
      if (!p || !p.can_manage || !this.endpoints.priority) return;
      var r = e.currentTarget.getBoundingClientRect();
      var width = 200;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
      this.priorityMenu = {
        open: true, projectId: p.id,
        style: { position: 'fixed', left: left + 'px', bottom: (window.innerHeight - r.top + 6) + 'px', width: width + 'px', zIndex: 120 }
      };
    },
    closePriorityMenu: function () { this.priorityMenu = { open: false, projectId: null, style: {} }; },
    setPriority: async function (p, pr) {
      if (!p || !this.endpoints.priority) { this.closePriorityMenu(); return; }
      try {
        var url = this.$pb.withId(this.endpoints.priority, p.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: { priority_id: pr ? pr.id : '' } });
        p.priority = resp.priority;
        this.$pb.toast('Priority updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.closePriorityMenu();
    },
    setDates: async function (p, field, value) {
      if (!p || !this.endpoints.dates) return;
      var body = {}; body[field] = value || '';
      try {
        var url = this.$pb.withId(this.endpoints.dates, p.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: body });
        p.start_date = resp.start_date; p.end_date = resp.end_date;
        this.$pb.toast('Dates updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    fmtDate: function (d) {
      if (!d) return '';
      var parts = String(d).slice(0, 10).split('-');
      if (parts.length !== 3) return d;
      var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      return months[(parseInt(parts[1], 10) || 1) - 1] + ' ' + (parseInt(parts[2], 10) || '') + ', ' + parts[0];
    },
    // --- Calendar plugin (design-system) helpers ---
    _parseISO: function (raw) {
      if (!raw) return null;
      var p = String(raw).slice(0, 10).split('-');
      if (p.length !== 3) return null;
      var d = new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1);
      return isNaN(d.getTime()) ? null : d;
    },
    _isoOf: function (d) {
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    },
    openDateMenu: function (p, field, e) {
      if (!p || !p.can_manage || !this.endpoints.dates) return;
      var r = e.currentTarget.getBoundingClientRect();
      var width = 300, menuH = 380;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
      var style = { position: 'fixed', left: left + 'px', width: width + 'px', zIndex: 120 };
      // Prefer opening upward (chips sit low in the card) unless there's no room above.
      if (r.top > menuH || r.top > (window.innerHeight - r.bottom)) {
        style.bottom = (window.innerHeight - r.top + 6) + 'px';
      } else {
        style.top = (r.bottom + 6) + 'px';
      }
      // Seed the visible month from the current value; failing that, from the paired
      // date (so the end-date picker lands where selectable days begin), else today.
      var other = this._parseISO(field === 'end_date' ? p.start_date : p.end_date);
      var cur = this._parseISO(field === 'end_date' ? p.end_date : p.start_date) || other || new Date();
      this.dateMenu = {
        open: true, projectId: p.id, field: field, mode: 'quick',
        vy: cur.getFullYear(), vm: cur.getMonth(), style: style, monthOpen: false, yearOpen: false
      };
    },
    closeDateMenu: function () {
      this.dateMenu = { open: false, projectId: null, field: 'start_date', mode: 'quick', vy: 2026, vm: 0, style: {}, monthOpen: false, yearOpen: false };
    },
    // A quick option (today+days) that would fall outside the allowed range is blocked.
    quickDisabled: function (days) {
      var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + days);
      var b = this.dateBounds;
      return !!((b.min && d < b.min) || (b.max && d > b.max));
    },
    dateQuickPick: function (days) {
      if (this.quickDisabled(days)) return;
      var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + days);
      this._applyDate(this._isoOf(d));
    },
    dateCustom: function () {
      var sel = this.dateSelected;
      if (sel) { this.dateMenu.vy = sel.getFullYear(); this.dateMenu.vm = sel.getMonth(); }
      this.dateMenu.monthOpen = false; this.dateMenu.yearOpen = false;
      this.dateMenu.mode = 'cal';
    },
    dateBack: function () { this.dateMenu.mode = 'quick'; this.dateMenu.monthOpen = false; this.dateMenu.yearOpen = false; },
    calNav: function (delta) {
      var m = this.dateMenu.vm + delta, y = this.dateMenu.vy;
      if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
      this.dateMenu.vm = m; this.dateMenu.vy = y;
    },
    calSetMonth: function (i) { this.dateMenu.vm = i; this.dateMenu.monthOpen = false; },
    calSetYear: function (y) { this.dateMenu.vy = y; this.dateMenu.yearOpen = false; },
    calPick: function (c) { if (c && !c.disabled) this._applyDate(c.iso); },
    _applyDate: function (iso) {
      var p = this.dateMenuProject, field = this.dateMenu.field;
      this.closeDateMenu();
      if (p) this.setDates(p, field, iso);
    },
    dateClear: function () {
      var p = this.dateMenuProject, field = this.dateMenu.field;
      this.closeDateMenu();
      if (p) this.setDates(p, field, '');
    },
    cellClass: function (c) {
      var base = 'h-8 w-8 grid place-items-center rounded-md text-[13px] ';
      if (c.disabled) return base + 'text-faint/50 line-through cursor-not-allowed';
      var cls = base + 'cursor-pointer ';
      if (c.isSel) return cls + 'bg-brand text-white font-medium';
      if (!c.inMonth) return cls + 'text-faint hover:bg-hover';
      return cls + 'text-ink hover:bg-hover' + (c.isToday ? ' ring-1 ring-brand' : '');
    },
    openLeadMenu: function (p, e) {
      if (!p || !p.can_manage || !this.endpoints.lead) return;
      var r = e.currentTarget.getBoundingClientRect();
      var width = 240, menuH = 280;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
      var style = { position: 'fixed', left: left + 'px', width: width + 'px', zIndex: 120 };
      if ((window.innerHeight - r.bottom) > menuH || (window.innerHeight - r.bottom) > r.top) {
        style.top = (r.bottom + 4) + 'px';
      } else {
        style.bottom = (window.innerHeight - r.top + 4) + 'px';
      }
      this.leadMenu = { open: true, projectId: p.id, style: style };
      this.leadMenuQuery = '';
      var self = this;
      this.$nextTick(function () { if (self.$refs.leadMenuSearch) self.$refs.leadMenuSearch.focus(); });
    },
    closeLeadMenu: function () { this.leadMenu = { open: false, projectId: null, style: {} }; },
    setLead: async function (p, m) {
      if (!p || !this.endpoints.lead) { this.closeLeadMenu(); return; }
      try {
        var url = this.$pb.withId(this.endpoints.lead, p.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: { lead_user_id: m ? m.id : '' } });
        p.lead = resp.lead;
        this.$pb.toast('Lead updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.closeLeadMenu();
    },
    coverStyle: function (p) {
      return p.cover_url ? { backgroundImage: 'url(' + p.cover_url + ')', backgroundSize: 'cover', backgroundPosition: 'center' } : { background: p.cover_gradient || this.coverPresets[0] };
    },
    openCreate: function () {
      this.errors = {}; this.idEdited = false; this.accessOpen = false; this.leadOpen = false; this.leadQuery = '';
      this.coverImage = ''; this.coverUploading = false; this.coverPct = 0; this.coverName = ''; this._coverData = null;
      if (this._coverTimer) { clearInterval(this._coverTimer); this._coverTimer = null; }
      this.form = { name: '', identifier: '', description: '', visibility: 'public', lead_user_id: '', emoji: '', cover_gradient: this.coverPresets[0] };
      this.open = true;
    },
    pickCover: function () { if (this.$refs.coverInput) this.$refs.coverInput.click(); },
    onCoverChange: function (e) {
      var f = e.target.files && e.target.files[0];
      e.target.value = ''; // allow re-selecting the same file later
      if (!f) return;
      var self = this;
      var reader = new FileReader();
      reader.onload = function (ev) { self._coverData = ev.target.result; };
      reader.readAsDataURL(f);
      this.coverName = f.name; this.coverUploading = true; this.coverPct = 0;
      if (this._coverTimer) clearInterval(this._coverTimer);
      // Animate a progress bar while the file is read (parity with projects.html).
      this._coverTimer = setInterval(function () {
        self.coverPct += Math.floor(Math.random() * 14) + 6;
        if (self.coverPct >= 100) {
          self.coverPct = 100; clearInterval(self._coverTimer); self._coverTimer = null;
          setTimeout(function () { self.coverImage = self._coverData || ''; self.coverUploading = false; }, 400);
        }
      }, 150);
    },
    openLead: function () {
      this.leadOpen = !this.leadOpen; this.accessOpen = false;
      if (this.leadOpen) {
        this.leadQuery = '';
        var self = this;
        this.$nextTick(function () { if (self.$refs.leadSearch) self.$refs.leadSearch.focus(); });
      }
    },
    onName: function () {
      if (this.idEdited) return;
      this.form.identifier = (this.form.name || '').toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 10);
    },
    onId: function (e) {
      this.form.identifier = (e.target.value || '').toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 10);
      this.idEdited = this.form.identifier.length > 0;
    },
    toggleArchived: function () {
      window.location = this.endpoints.list + (this.archived ? '' : '?archived=1');
    },
    soon: function () { this.$pb.toast('Filters & sorting are coming soon.'); },
    create: async function () {
      if (this.creating) return; this.creating = true; this.errors = {};
      var payload = Object.assign({}, this.form);
      // Field is shown lowercase (the @handle look); the server stores the canonical
      // uppercase identifier, so normalize before posting.
      if (payload.identifier) payload.identifier = String(payload.identifier).toUpperCase();
      if (!payload.lead_user_id) delete payload.lead_user_id;
      try {
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: payload });
        this.$pb.toast('Project created.');
        window.location = resp.redirect;
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.creating = false;
    }
  },
  template:
    '<div>' +

    // ===== Toolbar (matches projects.html ProjectsToolbar) =====
    '<div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">' +
    '<span class="flex items-center gap-2 text-[14px] font-medium text-ink">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>' +
    '{{ archived ? \'Archived projects\' : \'Projects\' }}' +
    '</span>' +
    '<div class="ml-auto flex items-center gap-1.5 sm:gap-2">' +
    '<button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Search" @click="soon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '<button class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="soon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M4 7h16M7 12h10M10 17h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Created date<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<button class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="soon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M4 5h16l-6 8v5l-4 2v-7L4 5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>Filters</button>' +
    '<button class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="toggleArchived">{{ archived ? \'Active\' : \'Archived\' }}</button>' +
    '<button v-if="canCreate && !archived" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap" @click="openCreate"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add Project</button>' +
    '</div></div>' +

    // ===== Card grid (when projects exist) =====
    '<div v-if="projects.length" class="px-5 sm:px-8 py-6 grid gap-5 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">' +
    '<a v-for="p in projects" :key="p.id" :href="p.url" class="group block border border-line rounded-xl overflow-hidden hover:shadow-md transition-shadow">' +
    '<div class="relative h-24" :style="coverStyle(p)">' +
    '<span v-if="p.emoji" class="absolute top-2.5 left-2.5 h-7 w-7 rounded-md bg-white/90 grid place-items-center text-[15px] shadow-sm">{{ p.emoji }}</span>' +
    '<span class="absolute top-2.5 right-2.5 text-[11px] bg-white/90 rounded px-1.5 py-0.5 text-sub capitalize">{{ p.visibility }}</span>' +
    '</div>' +
    '<div class="p-4">' +
    '<div class="text-[15px] font-semibold text-head truncate">{{ p.name }}</div>' +
    '<div class="text-[12px] text-brand mt-0.5 font-medium">{{ handle(p.identifier) }}</div>' +

    // Lead chip — sits directly under the @mention line
    '<div class="mt-2.5">' +
    '<button v-if="p.can_manage" type="button" @click.stop.prevent="openLeadMenu(p, $event)" class="inline-flex items-center gap-1.5 h-8 pl-1.5 pr-2 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover max-w-full min-w-0">' +
    '<template v-if="p.lead"><span class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[9px] font-bold shrink-0">{{ p.lead.initial }}</span><span class="truncate">{{ p.lead.name }}</span></template>' +
    '<template v-else><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><span class="text-sub">No lead</span></template>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<span v-else class="inline-flex items-center gap-1.5 h-8 pl-1.5 pr-2 rounded-md border border-stroke text-[13px] text-ink max-w-full min-w-0">' +
    '<template v-if="p.lead"><span class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[9px] font-bold shrink-0">{{ p.lead.initial }}</span><span class="truncate">{{ p.lead.name }}</span></template>' +
    '<template v-else><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><span class="text-sub">No lead</span></template>' +
    '</span>' +
    '</div>' +
    '</div>' +

    // Footer — Status, Priority and Start/Due date chips (Lead now sits under the @handle)
    '<div class="px-4 py-3 border-t border-line flex flex-wrap items-center gap-2 min-w-0">' +

    // Status (editable / read-only / none)
    '<button v-if="p.can_manage" type="button" @click.stop.prevent="openStatusMenu(p, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.state ? p.state.color : \'#94a3b8\'}"></span>' +
    '<span>{{ p.state ? p.state.name : \'Set status\' }}</span>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint ml-0.5"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<span v-else-if="p.state" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.state.color}"></span>' +
    '<span>{{ p.state.name }}</span>' +
    '</span>' +
    '<span v-else class="text-[12px] text-sub shrink-0">No status</span>' +

    // Priority (editable combo) — only when priorities are available
    '<button v-if="p.can_manage && endpoints.priority" type="button" @click.stop.prevent="openPriorityMenu(p, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.priority ? p.priority.color : \'#cbd5e1\'}"></span>' +
    '<span>{{ p.priority ? p.priority.name : \'Priority\' }}</span>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint ml-0.5"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<span v-else-if="p.priority" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.priority.color}"></span><span>{{ p.priority.name }}</span></span>' +

    // Start date (opens the design-system calendar popover)
    '<button v-if="p.can_manage && endpoints.dates" type="button" @click.stop.prevent="openDateMenu(p, \'start_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover shrink-0" title="Start date">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    '<span>{{ p.start_date ? fmtDate(p.start_date) : \'Start date\' }}</span>' +
    '</button>' +
    '<span v-else-if="p.start_date" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>{{ fmtDate(p.start_date) }}</span>' +

    // End date (opens the design-system calendar popover)
    '<button v-if="p.can_manage && endpoints.dates" type="button" @click.stop.prevent="openDateMenu(p, \'end_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover shrink-0" title="End date">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    '<span>{{ p.end_date ? fmtDate(p.end_date) : \'Due date\' }}</span>' +
    '</button>' +
    '<span v-else-if="p.end_date" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>{{ fmtDate(p.end_date) }}</span>' +

    '</div></a>' +

    // Add Project card tile
    '<button v-if="canCreate && !archived" type="button" @click="openCreate" class="border border-dashed border-stroke rounded-xl min-h-[172px] flex flex-col items-center justify-center gap-2 text-sub hover:border-brand hover:text-brand hover:bg-hover/40 transition-colors">' +
    '<span class="h-10 w-10 rounded-full border border-current grid place-items-center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>' +
    '<span class="text-[13px] font-semibold">Add Project</span>' +
    '</button>' +
    '</div>' +

    // ===== Empty state — archived =====
    '<div v-else-if="archived" class="px-8 py-20 text-center text-sub text-[13px]">No archived projects.</div>' +

    // ===== Empty state — video placeholder + title + description + Add Project =====
    '<div v-else class="flex flex-col items-center text-center px-6 py-14 max-w-lg mx-auto">' +
    '<div class="relative w-full max-w-md aspect-video rounded-xl bg-hover border border-line grid place-items-center overflow-hidden">' +
    '<div class="absolute inset-0 opacity-60" style="background:linear-gradient(120deg,#eef2ff 0%,#f5f3ff 55%,#ecfeff 100%)"></div>' +
    '<span class="relative h-14 w-14 rounded-full bg-white shadow grid place-items-center text-brand"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>' +
    '<span class="absolute bottom-2.5 left-2.5 text-[11px] text-sub bg-white/80 rounded px-1.5 py-0.5">Watch a 60-sec intro</span>' +
    '</div>' +
    '<h2 class="text-[18px] font-bold text-head mt-6">Create your first project</h2>' +
    '<p class="text-[14px] text-sub mt-1.5 max-w-sm">Projects keep your work items, cycles, and docs together in one place. Watch the quick intro, then spin up your first project.</p>' +
    '<button v-if="canCreate" type="button" @click="openCreate" class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add Project</button>' +
    '</div>' +

    // ===== Project status menu (shared, fixed-positioned to escape card clipping) =====
    '<div v-if="statusMenu.open" class="fixed inset-0 z-[110]" @click="closeStatusMenu"></div>' +
    '<div v-if="statusMenu.open" :style="statusMenu.style" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button v-for="s in states" :key="s.id" type="button" @click="setStatus(statusProject, s)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: s.color}"></span>' +
    '<span class="flex-1 truncate">{{ s.name }}</span>' +
    '<svg v-if="statusProject && statusProject.state && statusProject.state.id===s.id" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!states.length" class="px-2.5 py-2 text-[12px] text-sub">No statuses yet. Add them in Settings → Projects.</div>' +
    '</div>' +

    // ===== Priority picker menu (shared, fixed-positioned) =====
    '<div v-if="priorityMenu.open" class="fixed inset-0 z-[110]" @click="closePriorityMenu"></div>' +
    '<div v-if="priorityMenu.open" :style="priorityMenu.style" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button v-for="pr in priorities" :key="pr.id" type="button" @click="setPriority(priorityMenuProject, pr)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: pr.color}"></span>' +
    '<span class="flex-1 truncate">{{ pr.name }}</span>' +
    '<svg v-if="priorityMenuProject && priorityMenuProject.priority && priorityMenuProject.priority.id===pr.id" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!priorities.length" class="px-2.5 py-2 text-[12px] text-sub">No priorities yet. Add them in Settings → Projects.</div>' +
    '</div>' +

    // ===== Date calendar popover (shared, fixed-positioned) — design-system calendar =====
    '<div v-if="dateMenu.open" class="fixed inset-0 z-[110]" @click="closeDateMenu"></div>' +
    '<div v-if="dateMenu.open" :style="dateMenu.style" class="rounded-lg bg-white p-2 shadow-lg ring-1 ring-black/5">' +

    // -- Quick options view --
    '<template v-if="dateMenu.mode===\'quick\'">' +
    '<button type="button" :disabled="quickDisabled(0)" @click="dateQuickPick(0)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(0) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Today</button>' +
    '<button type="button" :disabled="quickDisabled(1)" @click="dateQuickPick(1)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(1) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Tomorrow</button>' +
    '<button type="button" :disabled="quickDisabled(3)" @click="dateQuickPick(3)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(3) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Next 3 days</button>' +
    '<button type="button" :disabled="quickDisabled(5)" @click="dateQuickPick(5)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(5) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Next 5 days</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="dateCustom" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Custom Date</button>' +
    '<template v-if="dateSelected"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="dateClear" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Clear</button></template>' +
    '</template>' +

    // -- Custom Date calendar grid --
    '<template v-else>' +
    '<button type="button" @click="dateBack" class="mb-2 inline-flex items-center gap-1 text-[12px] text-sub hover:text-ink"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Back</button>' +
    '<div class="flex items-center gap-1.5 mb-2">' +
    '<button type="button" @click="calNav(-1)" class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover text-sub shrink-0"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    // Month dropdown
    '<div class="relative flex-1 min-w-0">' +
    '<button type="button" @click.stop="dateMenu.monthOpen=!dateMenu.monthOpen; dateMenu.yearOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="truncate">{{ calMonthLabel }}</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<ul v-if="dateMenu.monthOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="mo in calMonths" :key="mo.i" @click="calSetMonth(mo.i)" :class="[\'cursor-pointer select-none py-1.5 px-3 text-[13px] hover:bg-brand hover:text-white\', mo.i===dateMenu.vm ? \'text-brand font-medium\' : \'text-ink\']">{{ mo.name }}</li>' +
    '</ul></div>' +
    // Year dropdown
    '<div class="relative w-[92px] shrink-0">' +
    '<button type="button" @click.stop="dateMenu.yearOpen=!dateMenu.yearOpen; dateMenu.monthOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="shrink-0 whitespace-nowrap">{{ dateMenu.vy }}</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<ul v-if="dateMenu.yearOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="y in calYears" :key="y" @click="calSetYear(y)" :class="[\'cursor-pointer select-none py-1.5 px-3 text-[13px] hover:bg-brand hover:text-white\', y===dateMenu.vy ? \'text-brand font-medium\' : \'text-ink\']">{{ y }}</li>' +
    '</ul></div>' +
    '<button type="button" @click="calNav(1)" class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover text-sub shrink-0"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '</div>' +
    // Day-of-week header
    '<div class="grid grid-cols-7 gap-0.5 mb-1">' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Su</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Mo</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Tu</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">We</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Th</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Fr</div>' +
    '<div class="h-7 grid place-items-center text-[11px] font-medium text-faint">Sa</div>' +
    '</div>' +
    // Day grid
    '<div class="grid grid-cols-7 gap-0.5">' +
    '<button v-for="c in calCells" :key="c.key" type="button" :disabled="c.disabled" @click="calPick(c)" :class="cellClass(c)">{{ c.day }}</button>' +
    '</div>' +
    '</template>' +

    '</div>' +

    // ===== Lead picker menu (shared, fixed-positioned) =====
    '<div v-if="leadMenu.open" class="fixed inset-0 z-[110]" @click="closeLeadMenu"></div>' +
    '<div v-if="leadMenu.open" :style="leadMenu.style" class="rounded-md bg-white p-2 shadow-lg ring-1 ring-black/5">' +
    '<div class="relative mb-1">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    '<input v-model="leadMenuQuery" ref="leadMenuSearch" name="member-search" autocomplete="off" placeholder="Search members..." class="w-full h-9 pl-8 pr-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '</div>' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button type="button" @click="setLead(leadMenuProject, null)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center text-faint" v-html="personIcon"></span><span>No lead</span></button>' +
    '<button v-for="m in leadMenuMembers" :key="m.id" type="button" @click="setLead(leadMenuProject, m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-6 w-6 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold shrink-0">{{ m.initial }}</span><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<svg v-if="leadMenuProject && leadMenuProject.lead && leadMenuProject.lead.id===m.id" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!leadMenuMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div>' +
    '</div>' +

    // ===== Add Project modal (matches projects.html AddProjectModal) =====
    '<div v-if="open" class="fixed inset-0 z-[70] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @click="open=false"></div>' +
    '<div class="relative w-full max-w-[860px] bg-white rounded-xl shadow-xl flex flex-col max-h-[88vh]">' +

    // Cover header (gradient or uploaded image) with Change cover + progress
    '<div class="relative h-32 rounded-t-xl shrink-0 bg-center bg-cover" :style="modalCoverStyle">' +
    '<button @click="pickCover" class="absolute top-3 left-3 h-8 px-3 rounded-md bg-white/85 text-[12px] font-medium text-ink hover:bg-white shadow-sm">Change cover</button>' +
    '<input ref="coverInput" type="file" accept="image/*" class="hidden" @change="onCoverChange" />' +
    '<button @click="open=false" class="absolute top-3 right-3 h-8 w-8 grid place-items-center rounded-md bg-white/85 text-sub hover:bg-white shadow-sm" title="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '<div v-if="!coverUploading" class="absolute bottom-3 right-3 flex items-center gap-1.5">' +
    '<button v-for="g in coverPresets" :key="g" type="button" @click="coverImage=\'\'; form.cover_gradient=g" :style="{background:g}" :class="[\'h-6 w-8 rounded-md ring-2 ring-offset-1 ring-offset-black/10 transition\', (!coverImage && form.cover_gradient===g) ? \'ring-white\' : \'ring-transparent hover:ring-white/60\']"></button>' +
    '</div>' +
    // Upload progress bar
    '<div v-if="coverUploading" class="absolute inset-x-3 bottom-3 bg-white/95 rounded-md px-3 py-2 shadow">' +
    '<div class="flex items-center justify-between mb-1"><span class="text-[12px] text-sub truncate max-w-[70%]">{{ coverName || \'Uploading…\' }}</span><span class="text-[12px] text-sub tabular-nums">{{ coverPct }}%</span></div>' +
    '<div class="h-1.5 w-full bg-line rounded-full overflow-hidden"><div class="h-full bg-brand rounded-full transition-all duration-150" :style="{width: coverPct + \'%\'}"></div></div>' +
    '</div>' +
    '</div>' +

    // Body
    '<div class="px-5 sm:px-6 pt-5 pb-3 overflow-visible">' +
    '<div class="flex flex-col sm:flex-row gap-3">' +
    '<div class="flex-1"><input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name" @input="onName" placeholder="Project name" />' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p></div>' +
    '<div class="sm:w-44"><input class="pb-input lowercase" :class="{\'is-error\': errors.identifier}" :value="form.identifier" @input="onId" placeholder="project id" maxlength="10" />' +
    '<p v-if="errors.identifier" class="text-[12px] text-danger mt-1">{{ errors.identifier[0] }}</p>' +
    '<p v-else class="text-[11px] text-sub mt-1">Team handle: <span class="text-brand font-medium">{{ form.identifier ? handle(form.identifier) : \'@…\' }}</span></p></div>' +
    '</div>' +
    '<textarea class="pb-textarea mt-3" rows="3" v-model="form.description" placeholder="Description"></textarea>' +

    // Access + Lead chips
    '<div class="flex flex-wrap items-center gap-2 mt-3">' +

    // Access chip
    '<div class="relative">' +
    '<button type="button" @click.stop="accessOpen=!accessOpen; leadOpen=false" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span class="grid place-items-center text-sub" v-html="accessCurrent.icon"></span>' +
    '<span>{{ accessCurrent.label }}</span>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="accessOpen" class="fixed inset-0 z-40" @click="accessOpen=false"></div>' +
    '<div v-if="accessOpen" class="absolute left-0 top-full mt-1 w-72 rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 z-50">' +
    '<button v-for="o in accessOptions" :key="o.key" type="button" @click="form.visibility=o.key; accessOpen=false" :class="[\'w-full text-left flex items-start gap-2.5 px-2.5 py-2 hover:bg-hover\', form.visibility===o.key ? \'bg-hover\' : \'\']">' +
    '<span class="mt-0.5 text-sub" v-html="o.icon"></span>' +
    '<span class="flex-1 min-w-0"><span class="block text-[13px] font-medium text-ink">{{ o.label }}</span><span class="block text-[12px] text-sub">{{ o.desc }}</span></span>' +
    '<svg v-if="form.visibility===o.key" width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-ink shrink-0 mt-0.5"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '</div>' +
    '</div>' +

    // Lead chip
    '<div class="relative">' +
    '<button type="button" @click.stop="openLead" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span v-if="leadSelected" class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[9px] font-bold">{{ leadSelected.initial }}</span>' +
    '<span v-else class="grid place-items-center text-sub" v-html="personIcon"></span>' +
    '<span>{{ leadSelected ? leadSelected.name : \'Lead\' }}</span>' +
    '</button>' +
    '<div v-if="leadOpen" class="fixed inset-0 z-40" @click="leadOpen=false"></div>' +
    '<div v-if="leadOpen" class="absolute left-0 top-full mt-1 w-72 rounded-md bg-white p-2 shadow-lg ring-1 ring-black/5 z-50">' +
    '<div class="relative mb-1">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    '<input v-model="leadQuery" ref="leadSearch" name="member-search" autocomplete="off" placeholder="Search members..." class="w-full h-9 pl-8 pr-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '</div>' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button type="button" @click="form.lead_user_id=\'\'; leadOpen=false" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center text-faint" v-html="personIcon"></span><span>No lead</span></button>' +
    '<button v-for="m in filteredMembers" :key="m.id" type="button" @click="form.lead_user_id=String(m.id); leadOpen=false" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-6 w-6 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold shrink-0">{{ m.initial }}</span><span class="truncate">{{ m.name }}</span></button>' +
    '<div v-if="!filteredMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div>' +
    '</div>' +
    '</div>' +

    '</div>' +
    '</div>' +

    // Footer
    '<div class="flex items-center justify-end gap-2 px-5 sm:px-6 py-4 border-t border-line shrink-0">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="creating || !form.name.trim() || !form.identifier" @click="create">Create project</button>' +
    '</div>' +

    '</div></div>' +
    '</div>'
});
