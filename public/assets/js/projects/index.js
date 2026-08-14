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
  globe: '' + wiIcon('globe', 15) + '',
  lock: '' + wiIcon('lock-small', 15) + '',
  person: '' + wiIcon('user', 15) + ''
};

// The modal's form doubles as a popover target so the status / priority / date pickers
// built for the cards can drive it too. It therefore carries the same shape a card payload
// has (id, can_manage, state, priority, dates); PB_FORM_ID is the sentinel that tells the
// shared handlers "edit this object in memory, don't PATCH the server yet".
var PB_FORM_ID = '__form__';

function pbBlankForm(gradient) {
  return {
    id: PB_FORM_ID, can_manage: true,
    name: '', identifier: '', description: '', visibility: 'public',
    lead_user_id: '', emoji: '', cover_gradient: gradient || '',
    state: null, priority: null, start_date: '', end_date: ''
  };
}

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
      // Per-card action menu (Edit / Archive|Restore / Delete) + typed-confirmation delete.
      actionsMenu: { open: false, projectId: null, style: {} },
      deleteModal: { open: false, project: null, typed: '', busy: false },
      // Design-system calendar popover (quick options + Custom Date grid), matches projects.html.
      dateMenu: { open: false, projectId: null, field: 'start_date', mode: 'quick', vy: 2026, vm: 0, style: {}, monthOpen: false, yearOpen: false },
      // The one modal serves both Add Project and Edit project; `editing` picks the mode.
      open: false, editing: false, editId: null, creating: false, idEdited: false, errors: {},
      accessOpen: false, leadOpen: false, leadQuery: '',
      coverImage: '', coverUploading: false, coverPct: 0, coverName: '',
      form: pbBlankForm(covers[0])
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
    statusProject: function () { return this.menuTarget(this.statusMenu.projectId); },
    priorityMenuProject: function () { return this.menuTarget(this.priorityMenu.projectId); },
    leadMenuProject: function () { return this.menuTarget(this.leadMenu.projectId); },
    actionsProject: function () {
      var id = this.actionsMenu.projectId;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    // The server requires the project identifier typed back before deleting (PRJ-047).
    deleteConfirmed: function () {
      var p = this.deleteModal.project;
      if (!p) return false;
      return this.deleteModal.typed.trim().toLowerCase() === String(p.identifier || '').toLowerCase();
    },
    leadMenuMembers: function () {
      var q = (this.leadMenuQuery || '').toLowerCase();
      return this.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    dateMenuProject: function () { return this.menuTarget(this.dateMenu.projectId); },
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
    // Validation messages for fields edited through chips rather than inputs — they have
    // nowhere of their own to render, so the modal lists them under the chip row.
    chipErrors: function () {
      var e = this.errors, out = [];
      ['visibility', 'lead_user_id', 'state_id', 'priority_id', 'start_date', 'end_date'].forEach(function (k) {
        if (e[k] && e[k][0]) out.push(e[k][0]);
      });
      return out;
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
    // True when a popover is editing the modal's form rather than a live card.
    isForm: function (p) { return !!p && p.id === PB_FORM_ID; },
    // Resolve what a shared popover is pointed at: the modal form, or a card by id.
    menuTarget: function (id) {
      if (id === PB_FORM_ID) return this.form;
      return this.projects.find(function (p) { return p.id === id; }) || null;
    },
    // A popover may open when its target is the modal form (saved later, on submit) or a
    // manageable card whose per-chip endpoint exists (saved immediately).
    canOpenMenu: function (p, endpoint) {
      if (this.isForm(p)) return true;
      return !!(p && p.can_manage && endpoint);
    },
    openStatusMenu: function (p, e) {
      if (!this.canOpenMenu(p, this.endpoints.state)) return;
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
      // Modal form: hold the choice locally, the whole modal saves in one PATCH.
      if (this.isForm(p)) { p.state = s; this.closeStatusMenu(); return; }
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
      if (!this.canOpenMenu(p, this.endpoints.priority)) return;
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
      if (this.isForm(p)) { p.priority = pr; this.closePriorityMenu(); return; }
      if (!p || !this.endpoints.priority) { this.closePriorityMenu(); return; }
      try {
        var url = this.$pb.withId(this.endpoints.priority, p.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: { priority_id: pr ? pr.id : '' } });
        p.priority = resp.priority;
        this.$pb.toast('Priority updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.closePriorityMenu();
    },
    // ---- Card action menu: Edit / Archive|Restore / Delete (PRJ-045/046/047) ----
    openActionsMenu: function (p, e) {
      if (!p || !p.can_manage) return;
      var r = e.currentTarget.getBoundingClientRect();
      var width = 184;
      // Right-align to the kebab and open downward — the button sits on the card cover,
      // so there is always room below.
      var left = Math.max(8, Math.min(r.right - width, window.innerWidth - width - 8));
      this.actionsMenu = {
        open: true, projectId: p.id,
        style: { position: 'fixed', left: left + 'px', top: (r.bottom + 6) + 'px', width: width + 'px', zIndex: 120 }
      };
    },
    closeActionsMenu: function () { this.actionsMenu = { open: false, projectId: null, style: {} }; },
    // Edit opens the same modal the Add Project button uses, pre-filled from the card —
    // including the four chips (status, priority, start/end date) and the lead. Without an
    // update endpoint we fall back to the project's own settings screen.
    editProject: function (p) {
      this.closeActionsMenu();
      // Editing is workspace Owner/Admin only — the server enforces it too.
      if (!p || !p.can_edit) return;
      if (!this.endpoints.update) {
        if (p.settings_url) { window.location = p.settings_url; }
        return;
      }
      this.resetModal();
      this.idEdited = true; // the identifier already exists; never re-derive it from the name
      this.coverImage = p.cover_url || '';
      this.editing = true; this.editId = p.id;
      this.form = {
        id: PB_FORM_ID, can_manage: true,
        name: p.name || '',
        // Identifiers are lower case throughout (the @handle look).
        identifier: String(p.identifier || '').toLowerCase(),
        description: p.description || '',
        visibility: p.visibility || 'public',
        lead_user_id: p.lead ? String(p.lead.id) : '',
        emoji: p.emoji || '',
        cover_gradient: p.cover_gradient || this.coverPresets[0],
        state: p.state || null,
        priority: p.priority || null,
        start_date: p.start_date || '',
        end_date: p.end_date || ''
      };
      this.open = true;
    },
    // Swap a card in the grid for the fresh payload the server returned after a save.
    applyCard: function (card) {
      if (!card) return;
      for (var i = 0; i < this.projects.length; i++) {
        if (this.projects[i].id === card.id) { this.projects.splice(i, 1, card); return; }
      }
    },
    // Archive and restore both drop the card from the current list, since the list is
    // filtered by status (active vs. archived).
    setArchived: async function (p, archive) {
      this.closeActionsMenu();
      var url = archive ? this.endpoints.archive : this.endpoints.restore;
      if (!p || !url) return;
      try {
        await this.$pb.api(this.$pb.withId(url, p.id), { method: 'POST' });
        this.removeCard(p);
        this.$pb.toast(archive ? 'Project archived.' : 'Project restored.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    askDelete: function (p) {
      this.closeActionsMenu();
      if (!p || !this.endpoints.destroy) return;
      this.deleteModal = { open: true, project: p, typed: '', busy: false };
    },
    closeDelete: function () { this.deleteModal = { open: false, project: null, typed: '', busy: false }; },
    confirmDelete: async function () {
      var p = this.deleteModal.project;
      if (!p || !this.deleteConfirmed || this.deleteModal.busy) return;
      this.deleteModal.busy = true;
      try {
        await this.$pb.api(this.$pb.withId(this.endpoints.destroy, p.id), {
          method: 'DELETE', body: { confirm: this.deleteModal.typed.trim() }
        });
        this.removeCard(p);
        this.closeDelete();
        this.$pb.toast('Project deleted.');
      } catch (e) {
        this.deleteModal.busy = false;
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },
    removeCard: function (p) {
      var i = this.projects.indexOf(p);
      if (i > -1) this.projects.splice(i, 1);
    },
    setDates: async function (p, field, value) {
      if (this.isForm(p)) { p[field] = value || ''; return; }
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
      if (!this.canOpenMenu(p, this.endpoints.dates)) return;
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
    // Shared reset for both modal modes — clears errors, dropdowns and any cover upload.
    resetModal: function () {
      this.errors = {}; this.idEdited = false; this.accessOpen = false; this.leadOpen = false; this.leadQuery = '';
      this.editing = false; this.editId = null;
      this.coverImage = ''; this.coverUploading = false; this.coverPct = 0; this.coverName = ''; this._coverData = null;
      if (this._coverTimer) { clearInterval(this._coverTimer); this._coverTimer = null; }
    },
    openCreate: function () {
      this.resetModal();
      this.form = pbBlankForm(this.coverPresets[0]);
      this.open = true;
    },
    closeModal: function () {
      this.open = false;
      this.closeStatusMenu(); this.closePriorityMenu(); this.closeDateMenu();
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
    // Fields common to create and edit. Identifiers are lower case everywhere — stored,
    // displayed and posted — so nothing has to be re-cased on the way in or out.
    basePayload: function () {
      var f = this.form;
      return {
        name: f.name,
        identifier: f.identifier ? String(f.identifier).toLowerCase() : '',
        description: f.description,
        visibility: f.visibility,
        emoji: f.emoji,
        cover_gradient: f.cover_gradient,
        lead_user_id: f.lead_user_id
      };
    },
    submit: function () { return this.editing ? this.update() : this.create(); },
    create: async function () {
      if (this.creating) return; this.creating = true; this.errors = {};
      var payload = this.basePayload();
      if (!payload.lead_user_id) delete payload.lead_user_id;
      try {
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: payload });
        this.$pb.toast('Project created.');
        window.location = resp.redirect;
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.creating = false;
    },
    // One PATCH saves everything the modal shows, including the four chips.
    update: async function () {
      if (this.creating || !this.endpoints.update) return;
      this.creating = true; this.errors = {};
      var f = this.form;
      var payload = Object.assign(this.basePayload(), {
        state_id: f.state ? f.state.id : '',
        priority_id: f.priority ? f.priority.id : '',
        start_date: f.start_date || '',
        end_date: f.end_date || ''
      });
      // The identifier is immutable — never post it back from the edit modal.
      delete payload.identifier;
      try {
        var url = this.$pb.withId(this.endpoints.update, this.editId);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: payload });
        this.applyCard(resp.project);
        this.closeModal();
        this.$pb.toast('Project updated.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.creating = false;
    }
  },
  template:
    '<div>' +

    // ===== Toolbar (matches projects.html ProjectsToolbar) =====
    '<div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">' +
    // Brings the collapsed sidebar back. Same control as the Blade headers — hidden by CSS
    // until there is something to expand, and handled by the sidebar's delegated listener.
    '<button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" ' +
    'aria-controls="sidebar" aria-expanded="false" ' +
    'class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none">' +
    '<rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>' +
    '<path d="M9 4v16" stroke="currentColor" stroke-width="1.7"/></svg></button>' +
    '<span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>' +
    '<span class="flex items-center gap-2 text-[14px] font-medium text-ink">' +
    '' + wiIcon('folder', 16, 'text-sub') + '' +
    '{{ archived ? \'Archived projects\' : \'Projects\' }}' +
    '</span>' +
    '<div class="ml-auto flex items-center gap-1.5 sm:gap-2">' +
    '<button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Search" @click="soon">' + wiIcon('magnifying-glass', 16) + '</button>' +
    '<button class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="soon">' + wiIcon('bars-sort', 14, 'text-faint') + 'Created date' + wiIcon('chevron-down', 12, 'text-faint') + '</button>' +
    '<button class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="soon">' + wiIcon('filter', 14, 'text-faint') + 'Filters</button>' +
    '<button class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" @click="toggleArchived">{{ archived ? \'Active\' : \'Archived\' }}</button>' +
    '<button v-if="canCreate && !archived" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap" @click="openCreate">' + wiIcon('plus', 14) + 'Add Project</button>' +
    '</div></div>' +

    // ===== Card grid (when projects exist) =====
    '<div v-if="projects.length" class="px-5 sm:px-8 py-6 grid gap-5 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">' +
    '<a v-for="p in projects" :key="p.id" :href="p.url" class="group block border border-line rounded-xl overflow-hidden hover:shadow-md transition-shadow">' +
    '<div class="relative h-24" :style="coverStyle(p)">' +
    '<span v-if="p.emoji" class="absolute top-2.5 left-2.5 h-7 w-7 rounded-md bg-white/90 grid place-items-center text-[15px] shadow-sm">{{ p.emoji }}</span>' +
    '<div class="absolute top-2.5 right-2.5 flex items-center gap-1.5">' +
    '<span class="text-[11px] bg-white/90 rounded px-1.5 py-0.5 text-sub capitalize">{{ p.visibility }}</span>' +
    // Actions kebab — Edit / Archive|Restore / Delete. The card is a link, so stop the click.
    '<button v-if="p.can_manage" type="button" @click.stop.prevent="openActionsMenu(p, $event)" :aria-expanded="actionsMenu.open && actionsMenu.projectId===p.id ? \'true\' : \'false\'" aria-haspopup="menu" title="Project actions" class="h-6 w-6 grid place-items-center rounded bg-white/90 text-sub hover:bg-white hover:text-ink shadow-sm">' +
    '' + wiIcon('ellipsis-vertical', 15) + '' +
    '</button>' +
    '</div>' +
    '</div>' +
    '<div class="p-4">' +
    '<div class="text-[15px] font-semibold text-head truncate">{{ p.name }}</div>' +
    '<div class="text-[12px] text-brand mt-0.5 font-medium">{{ handle(p.identifier) }}</div>' +

    // Lead chip — sits directly under the @mention line
    '<div class="mt-2.5">' +
    '<button v-if="p.can_manage" type="button" @click.stop.prevent="openLeadMenu(p, $event)" class="inline-flex items-center gap-1.5 h-8 pl-1.5 pr-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover max-w-full min-w-0">' +
    '<template v-if="p.lead"><span :style="{ background: $pb.avatarColor(p.lead) }" class="h-5 w-5 rounded-full text-white grid place-items-center text-[9px] font-bold shrink-0">{{ p.lead.initial }}</span><span class="truncate">{{ p.lead.name }}</span></template>' +
    '<template v-else>' + wiIcon('user', 15, 'text-faint shrink-0') + '<span class="text-sub">No lead</span></template>' +
    '' + wiIcon('chevron-down', 12, 'text-faint shrink-0') + '' +
    '</button>' +
    '<span v-else class="inline-flex items-center gap-1.5 h-8 pl-1.5 pr-2 rounded-md border border-stroke text-[12px] text-ink max-w-full min-w-0">' +
    '<template v-if="p.lead"><span :style="{ background: $pb.avatarColor(p.lead) }" class="h-5 w-5 rounded-full text-white grid place-items-center text-[9px] font-bold shrink-0">{{ p.lead.initial }}</span><span class="truncate">{{ p.lead.name }}</span></template>' +
    '<template v-else>' + wiIcon('user', 15, 'text-faint shrink-0') + '<span class="text-sub">No lead</span></template>' +
    '</span>' +
    '</div>' +
    '</div>' +

    // Footer — Status, Priority and Start/Due date chips (Lead now sits under the @handle)
    '<div class="px-4 py-3 border-t border-line flex flex-wrap items-center gap-2 min-w-0">' +

    // Status (editable / read-only / none)
    '<button v-if="p.can_manage" type="button" @click.stop.prevent="openStatusMenu(p, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.state ? p.state.color : \'#94a3b8\'}"></span>' +
    '<span>{{ p.state ? p.state.name : \'Set status\' }}</span>' +
    '' + wiIcon('chevron-down', 12, 'text-faint ml-0.5') + '' +
    '</button>' +
    '<span v-else-if="p.state" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.state.color}"></span>' +
    '<span>{{ p.state.name }}</span>' +
    '</span>' +
    '<span v-else class="text-[12px] text-sub shrink-0">No status</span>' +

    // Priority (editable combo) — only when priorities are available
    '<button v-if="p.can_manage && endpoints.priority" type="button" @click.stop.prevent="openPriorityMenu(p, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.priority ? p.priority.color : \'#cbd5e1\'}"></span>' +
    '<span>{{ p.priority ? p.priority.name : \'Priority\' }}</span>' +
    '' + wiIcon('chevron-down', 12, 'text-faint ml-0.5') + '' +
    '</button>' +
    '<span v-else-if="p.priority" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink shrink-0">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: p.priority.color}"></span><span>{{ p.priority.name }}</span></span>' +

    // Start date (opens the design-system calendar popover)
    '<button v-if="p.can_manage && endpoints.dates" type="button" @click.stop.prevent="openDateMenu(p, \'start_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover shrink-0" title="Start date">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '<span>{{ p.start_date ? fmtDate(p.start_date) : \'Start date\' }}</span>' +
    '</button>' +
    '<span v-else-if="p.start_date" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink shrink-0">' + wiIcon('calendar', 14, 'text-faint') + '{{ fmtDate(p.start_date) }}</span>' +

    // End date (opens the design-system calendar popover)
    '<button v-if="p.can_manage && endpoints.dates" type="button" @click.stop.prevent="openDateMenu(p, \'end_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover shrink-0" title="End date">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '<span>{{ p.end_date ? fmtDate(p.end_date) : \'Due date\' }}</span>' +
    '</button>' +
    '<span v-else-if="p.end_date" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink shrink-0">' + wiIcon('calendar', 14, 'text-faint') + '{{ fmtDate(p.end_date) }}</span>' +

    '</div></a>' +

    // Add Project card tile
    '<button v-if="canCreate && !archived" type="button" @click="openCreate" class="border border-dashed border-stroke rounded-xl min-h-[172px] flex flex-col items-center justify-center gap-2 text-sub hover:border-brand hover:text-brand hover:bg-hover/40 transition-colors">' +
    '<span class="h-10 w-10 rounded-full border border-current grid place-items-center">' + wiIcon('plus', 18) + '</span>' +
    '<span class="text-[13px] font-semibold">Add Project</span>' +
    '</button>' +
    '</div>' +

    // ===== Empty state — archived =====
    '<div v-else-if="archived" class="px-8 py-20 text-center text-sub text-[13px]">No archived projects.</div>' +

    // ===== Empty state — video placeholder + title + description + Add Project =====
    '<div v-else class="flex flex-col items-center text-center px-6 py-14 max-w-lg mx-auto">' +
    '<div class="relative w-full max-w-md aspect-video rounded-xl bg-hover border border-line grid place-items-center overflow-hidden">' +
    '<div class="absolute inset-0 opacity-60" style="background:linear-gradient(120deg,#eef2ff 0%,#f5f3ff 55%,#ecfeff 100%)"></div>' +
    '<span class="relative h-14 w-14 rounded-full bg-white shadow grid place-items-center text-brand">' + wiIcon('play', 24) + '</span>' +
    '<span class="absolute bottom-2.5 left-2.5 text-[11px] text-sub bg-white/80 rounded px-1.5 py-0.5">Watch a 60-sec intro</span>' +
    '</div>' +
    '<h2 class="text-[18px] font-bold text-head mt-6">Create your first project</h2>' +
    '<p class="text-[14px] text-sub mt-1.5 max-w-sm">Projects keep your work items, cycles, and docs together in one place. Watch the quick intro, then spin up your first project.</p>' +
    '<button v-if="canCreate" type="button" @click="openCreate" class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' + wiIcon('plus', 15) + 'Add Project</button>' +
    '</div>' +

    // ===== Card actions menu (shared, fixed-positioned to escape card clipping) =====
    '<div v-if="actionsMenu.open" class="fixed inset-0 z-[110]" @click="closeActionsMenu"></div>' +
    '<div v-if="actionsMenu.open" :style="actionsMenu.style" role="menu" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button v-if="actionsProject && actionsProject.can_edit && (endpoints.update || actionsProject.settings_url)" type="button" role="menuitem" @click="editProject(actionsProject)" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('pen-line', 15, 'text-faint shrink-0') + 'Edit</button>' +
    '<button v-if="!archived && endpoints.archive" type="button" role="menuitem" @click="setArchived(actionsProject, true)" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('box-archive-thin', 15, 'text-faint shrink-0') + 'Archive</button>' +
    '<button v-if="archived && endpoints.restore" type="button" role="menuitem" @click="setArchived(actionsProject, false)" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('arrow-rotate-left', 15, 'text-faint shrink-0') + 'Restore</button>' +
    '<div v-if="endpoints.destroy" class="my-1 border-t border-line"></div>' +
    '<button v-if="endpoints.destroy" type="button" role="menuitem" @click="askDelete(actionsProject)" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 hover:bg-hover text-[13px] text-danger">' +
    '' + wiIcon('trash', 15, 'shrink-0') + 'Delete</button>' +
    '</div>' +

    // ===== Delete confirmation — typed project identifier required (PRJ-047) =====
    '<pb-modal :open="deleteModal.open" title="Delete project?" @close="closeDelete">' +
    '<p class="text-[13px] text-sub leading-relaxed">This permanently deletes <span class="font-semibold text-ink">{{ deleteModal.project ? deleteModal.project.name : \'\' }}</span> and everything in it — work items, members, states and labels. This cannot be undone.</p>' +
    '<label class="block text-[13px] text-ink mt-4 mb-1.5">Type <span class="font-semibold">{{ deleteModal.project ? deleteModal.project.identifier : \'\' }}</span> to confirm</label>' +
    '<input v-model="deleteModal.typed" type="text" name="confirm-identifier" autocomplete="off" @keyup.enter="confirmDelete" class="w-full h-9 px-3 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke focus:outline-brand" />' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="closeDelete">Cancel</button>' +
    '<button type="button" :disabled="!deleteConfirmed || deleteModal.busy" @click="confirmDelete" :class="[\'h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold\', (!deleteConfirmed || deleteModal.busy) ? \'opacity-50 cursor-not-allowed\' : \'hover:opacity-90\']">{{ deleteModal.busy ? \'Deleting…\' : \'Delete project\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Project status menu (shared, fixed-positioned to escape card clipping) =====
    '<div v-if="statusMenu.open" class="fixed inset-0 z-[110]" @click="closeStatusMenu"></div>' +
    '<div v-if="statusMenu.open" :style="statusMenu.style" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button v-for="s in states" :key="s.id" type="button" @click="setStatus(statusProject, s)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: s.color}"></span>' +
    '<span class="flex-1 truncate">{{ s.name }}</span>' +
    '<span v-if="statusProject && statusProject.state && statusProject.state.id===s.id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!states.length" class="px-2.5 py-2 text-[12px] text-sub">No statuses yet. Add them in Settings → Projects.</div>' +
    '</div>' +

    // ===== Priority picker menu (shared, fixed-positioned) =====
    '<div v-if="priorityMenu.open" class="fixed inset-0 z-[110]" @click="closePriorityMenu"></div>' +
    '<div v-if="priorityMenu.open" :style="priorityMenu.style" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button v-for="pr in priorities" :key="pr.id" type="button" @click="setPriority(priorityMenuProject, pr)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: pr.color}"></span>' +
    '<span class="flex-1 truncate">{{ pr.name }}</span>' +
    '<span v-if="priorityMenuProject && priorityMenuProject.priority && priorityMenuProject.priority.id===pr.id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!priorities.length" class="px-2.5 py-2 text-[12px] text-sub">No priorities yet. Add them in Settings → Projects.</div>' +
    '</div>' +

    // ===== Date calendar popover (shared, fixed-positioned) — design-system calendar =====
    '<div v-if="dateMenu.open" class="fixed inset-0 z-[110]" @click="closeDateMenu"></div>' +
    '<div v-if="dateMenu.open" :style="dateMenu.style" class="rounded-lg bg-white p-2 shadow-lg ring-1 ring-black/5">' +

    // -- Quick options view --
    '<template v-if="dateMenu.mode===\'quick\'">' +
    '<button type="button" :disabled="quickDisabled(0)" @click="dateQuickPick(0)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(0) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']">' + wiIcon('calendar', 15, 'text-faint shrink-0') + 'Today</button>' +
    '<button type="button" :disabled="quickDisabled(1)" @click="dateQuickPick(1)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(1) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']">' + wiIcon('calendar', 15, 'text-faint shrink-0') + 'Tomorrow</button>' +
    '<button type="button" :disabled="quickDisabled(3)" @click="dateQuickPick(3)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(3) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']">' + wiIcon('calendar', 15, 'text-faint shrink-0') + 'Next 3 days</button>' +
    '<button type="button" :disabled="quickDisabled(5)" @click="dateQuickPick(5)" :class="[\'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink\', quickDisabled(5) ? \'opacity-40 cursor-not-allowed\' : \'hover:bg-hover\']">' + wiIcon('calendar', 15, 'text-faint shrink-0') + 'Next 5 days</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="dateCustom" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover">' + wiIcon('calendar', 15, 'text-faint shrink-0') + 'Custom Date</button>' +
    '<template v-if="dateSelected"><div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="dateClear" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover">' + wiIcon('xmark', 15, 'shrink-0') + 'Clear</button></template>' +
    '</template>' +

    // -- Custom Date calendar grid --
    '<template v-else>' +
    '<button type="button" @click="dateBack" class="mb-2 inline-flex items-center gap-1 text-[12px] text-sub hover:text-ink">' + wiIcon('chevron-left', 13) + 'Back</button>' +
    '<div class="flex items-center gap-1.5 mb-2">' +
    '<button type="button" @click="calNav(-1)" class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover text-sub shrink-0">' + wiIcon('chevron-left', 16) + '</button>' +
    // Month dropdown
    '<div class="relative flex-1 min-w-0">' +
    '<button type="button" @click.stop="dateMenu.monthOpen=!dateMenu.monthOpen; dateMenu.yearOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="truncate">{{ calMonthLabel }}</span>' + wiIcon('chevron-down', 14, 'text-faint shrink-0 ml-1') + '</button>' +
    '<ul v-if="dateMenu.monthOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="mo in calMonths" :key="mo.i" @click="calSetMonth(mo.i)" :class="[\'cursor-pointer select-none py-1.5 px-3 text-[13px] hover:bg-brand hover:text-white\', mo.i===dateMenu.vm ? \'text-brand font-medium\' : \'text-ink\']">{{ mo.name }}</li>' +
    '</ul></div>' +
    // Year dropdown
    '<div class="relative w-[92px] shrink-0">' +
    '<button type="button" @click.stop="dateMenu.yearOpen=!dateMenu.yearOpen; dateMenu.monthOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="shrink-0 whitespace-nowrap">{{ dateMenu.vy }}</span>' + wiIcon('chevron-down', 14, 'text-faint shrink-0 ml-1') + '</button>' +
    '<ul v-if="dateMenu.yearOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="y in calYears" :key="y" @click="calSetYear(y)" :class="[\'cursor-pointer select-none py-1.5 px-3 text-[13px] hover:bg-brand hover:text-white\', y===dateMenu.vy ? \'text-brand font-medium\' : \'text-ink\']">{{ y }}</li>' +
    '</ul></div>' +
    '<button type="button" @click="calNav(1)" class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover text-sub shrink-0">' + wiIcon('chevron-right', 16) + '</button>' +
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
    '' + wiIcon('magnifying-glass', 14, 'absolute left-2.5 top-1/2 -translate-y-1/2 text-faint') + '' +
    '<input v-model="leadMenuQuery" ref="leadMenuSearch" name="member-search" autocomplete="off" placeholder="Search members..." class="w-full h-9 pl-8 pr-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '</div>' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button type="button" @click="setLead(leadMenuProject, null)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center text-faint" v-html="personIcon"></span><span>No lead</span></button>' +
    '<button v-for="m in leadMenuMembers" :key="m.id" type="button" @click="setLead(leadMenuProject, m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span :style="{ background: $pb.avatarColor(m) }" class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0">{{ m.initial }}</span><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<span v-if="leadMenuProject && leadMenuProject.lead && leadMenuProject.lead.id===m.id" class="text-brand shrink-0">' + wiIcon('check', 15) + '</span>' +
    '</button>' +
    '<div v-if="!leadMenuMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div>' +
    '</div>' +

    // ===== Add Project modal (matches projects.html AddProjectModal) =====
    '<div v-if="open" class="fixed inset-0 z-[70] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @click="closeModal"></div>' +
    '<div class="relative w-full max-w-[860px] bg-white rounded-xl shadow-xl flex flex-col max-h-[88vh]">' +

    // Cover header (gradient or uploaded image) with Change cover + progress
    '<div class="relative h-32 rounded-t-xl shrink-0 bg-center bg-cover" :style="modalCoverStyle">' +
    '<button @click="pickCover" class="absolute top-3 left-3 h-8 px-3 rounded-md bg-white/85 text-[12px] font-medium text-ink hover:bg-white shadow-sm">Change cover</button>' +
    '<input ref="coverInput" type="file" accept="image/*" class="hidden" @change="onCoverChange" />' +
    '<button @click="closeModal" class="absolute top-3 right-3 h-8 w-8 grid place-items-center rounded-md bg-white/85 text-sub hover:bg-white shadow-sm" title="Close">' + wiIcon('xmark', 16) + '</button>' +
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
    // Identifier: editable while creating, permanently read-only once the project exists —
    // it is the @mention handle everything else references.
    '<div class="sm:w-44"><input class="pb-input lowercase" :class="{\'is-error\': errors.identifier, \'bg-hover text-sub cursor-not-allowed\': editing}" :value="form.identifier" @input="onId" :readonly="editing" :title="editing ? \'The identifier cannot be changed\' : null" placeholder="identifier" maxlength="10" />' +
    '<p v-if="errors.identifier" class="text-[12px] text-danger mt-1">{{ errors.identifier[0] }}</p>' +
    '<p v-else class="text-[11px] text-sub mt-1">Team handle: <span class="text-brand font-medium">{{ form.identifier ? handle(form.identifier) : \'@…\' }}</span></p></div>' +
    '</div>' +
    '<textarea class="pb-textarea mt-3" rows="3" v-model="form.description" placeholder="Description"></textarea>' +

    // Access + Lead chips
    '<div class="flex flex-wrap items-center gap-2 mt-3">' +

    // Access chip
    '<div class="relative">' +
    '<button type="button" @click.stop="accessOpen=!accessOpen; leadOpen=false" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">' +
    '<span class="grid place-items-center text-sub" v-html="accessCurrent.icon"></span>' +
    '<span>{{ accessCurrent.label }}</span>' +
    '' + wiIcon('chevron-down', 12, 'text-faint') + '' +
    '</button>' +
    '<div v-if="accessOpen" class="fixed inset-0 z-40" @click="accessOpen=false"></div>' +
    '<div v-if="accessOpen" class="absolute left-0 top-full mt-1 w-72 rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 z-50">' +
    '<button v-for="o in accessOptions" :key="o.key" type="button" @click="form.visibility=o.key; accessOpen=false" :class="[\'w-full text-left flex items-start gap-2.5 px-2.5 py-2 hover:bg-hover\', form.visibility===o.key ? \'bg-hover\' : \'\']">' +
    '<span class="mt-0.5 text-sub" v-html="o.icon"></span>' +
    '<span class="flex-1 min-w-0"><span class="block text-[13px] font-medium text-ink">{{ o.label }}</span><span class="block text-[12px] text-sub">{{ o.desc }}</span></span>' +
    '<span v-if="form.visibility===o.key" class="text-ink shrink-0 mt-0.5">' + wiIcon('check-thin', 16) + '</span>' +
    '</button>' +
    '</div>' +
    '</div>' +

    // Lead chip
    '<div class="relative">' +
    '<button type="button" @click.stop="openLead" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">' +
    '<span v-if="leadSelected" :style="{ background: $pb.avatarColor(leadSelected) }" class="h-5 w-5 rounded-full text-white grid place-items-center text-[9px] font-bold">{{ leadSelected.initial }}</span>' +
    '<span v-else class="grid place-items-center text-sub" v-html="personIcon"></span>' +
    '<span>{{ leadSelected ? leadSelected.name : \'Lead\' }}</span>' +
    '</button>' +
    '<div v-if="leadOpen" class="fixed inset-0 z-40" @click="leadOpen=false"></div>' +
    '<div v-if="leadOpen" class="absolute left-0 top-full mt-1 w-72 rounded-md bg-white p-2 shadow-lg ring-1 ring-black/5 z-50">' +
    '<div class="relative mb-1">' +
    '' + wiIcon('magnifying-glass', 14, 'absolute left-2.5 top-1/2 -translate-y-1/2 text-faint') + '' +
    '<input v-model="leadQuery" ref="leadSearch" name="member-search" autocomplete="off" placeholder="Search members..." class="w-full h-9 pl-8 pr-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '</div>' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button type="button" @click="form.lead_user_id=\'\'; leadOpen=false" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center text-faint" v-html="personIcon"></span><span>No lead</span></button>' +
    '<button v-for="m in filteredMembers" :key="m.id" type="button" @click="form.lead_user_id=String(m.id); leadOpen=false" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span :style="{ background: $pb.avatarColor(m) }" class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0">{{ m.initial }}</span><span class="truncate">{{ m.name }}</span></button>' +
    '<div v-if="!filteredMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div>' +
    '</div>' +
    '</div>' +

    // Status / Priority / Start / Due — edit only, since create has no such fields yet.
    // All four reuse the shared card popovers, pointed at the form instead of a card.
    '<template v-if="editing">' +

    // Status chip
    '<button type="button" @click.stop.prevent="openStatusMenu(form, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: form.state ? form.state.color : \'#94a3b8\'}"></span>' +
    '<span>{{ form.state ? form.state.name : \'Status\' }}</span>' +
    '' + wiIcon('chevron-down', 12, 'text-faint') + '' +
    '</button>' +

    // Priority chip
    '<button type="button" @click.stop.prevent="openPriorityMenu(form, $event)" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: form.priority ? form.priority.color : \'#cbd5e1\'}"></span>' +
    '<span>{{ form.priority ? form.priority.name : \'Priority\' }}</span>' +
    '' + wiIcon('chevron-down', 12, 'text-faint') + '' +
    '</button>' +

    // Start date chip
    '<button type="button" @click.stop.prevent="openDateMenu(form, \'start_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover" title="Start date">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '<span>{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</span>' +
    '</button>' +

    // End date chip
    '<button type="button" @click.stop.prevent="openDateMenu(form, \'end_date\', $event)" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover" title="Due date">' +
    '' + wiIcon('calendar', 14, 'text-faint shrink-0') + '' +
    '<span>{{ form.end_date ? fmtDate(form.end_date) : \'Due date\' }}</span>' +
    '</button>' +

    '</template>' +

    '</div>' +

    // Server-side messages for the chip fields (the inputs above show their own).
    '<p v-for="msg in chipErrors" :key="msg" class="text-[12px] text-danger mt-2">{{ msg }}</p>' +

    '</div>' +

    // Footer
    '<div class="flex items-center justify-end gap-2 px-5 sm:px-6 py-4 border-t border-line shrink-0">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="closeModal">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="creating || !form.name.trim() || !form.identifier" @click="submit">{{ creating ? (editing ? \'Saving…\' : \'Creating…\') : (editing ? \'Save changes\' : \'Create project\') }}</button>' +
    '</div>' +

    '</div></div>' +
    '</div>'
});
