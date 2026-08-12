/* Settings › Members (spec §5) — People + Pending Invites listed with Tabulator, plus a
   Permission (Coming Soon) tab. Table listing follows the setting-member.html design tokens;
   modals (invite / edit role / view / remove) stay in Vue. */
PB.boot('members', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      tab: 'people',
      people: b.people, pending: b.pending,
      roleLabels: b.roleLabels, inviteRoles: b.inviteRoles,
      currentUserId: b.currentUserId, endpoints: b.endpoints,
      search: '',
      peopleTable: null, pendingTable: null,
      inviteOpen: false, sending: false, inviteResults: {},
      invites: [{ email: '', role: 'member' }],
      roleModal: { open: false, member: null, role: 'member', saving: false },
      view: { open: false, member: null },
      remove: { open: false, member: null },
      actionMenu: { open: false, kind: 'people', row: null, style: {} }
    };
  },
  mounted: function () {
    // People tab is visible on load — build it now; Pending builds lazily on first view.
    this.initPeople();
    document.addEventListener('click', this.onDocMenu);
    document.addEventListener('keydown', this.onMenuKey);
    window.addEventListener('resize', this.onMenuReflow);
    window.addEventListener('scroll', this.onMenuReflow, true);
  },
  beforeUnmount: function () {
    document.removeEventListener('click', this.onDocMenu);
    document.removeEventListener('keydown', this.onMenuKey);
    window.removeEventListener('resize', this.onMenuReflow);
    window.removeEventListener('scroll', this.onMenuReflow, true);
  },
  watch: {
    tab: function (t) {
      var self = this;
      this.$nextTick(function () {
        if (t === 'people' && self.peopleTable) self.peopleTable.redraw(true);
        if (t === 'pending') { if (!self.pendingTable) self.initPending(); else self.pendingTable.redraw(true); }
      });
    }
  },
  computed: {
    roleOptions: function () {
      var self = this;
      return this.inviteRoles.map(function (r) { return { value: r, label: self.roleLabel(r) }; });
    }
  },
  methods: {
    // ---------- helpers ----------
    escapeHtml: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    colorFor: function (seed) {
      var colors = ['#334155', '#1b5f8a', '#7c3aed', '#0891b2', '#be123c', '#15803d', '#b45309', '#4338ca'];
      var s = String(seed), h = 0;
      for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) >>> 0; }
      return colors[h % colors.length];
    },
    roleLabel: function (r) { return this.roleLabels[r] || r; },
    setTab: function (t) { this.tab = t; },

    // ---------- Tabulator: People ----------
    initPeople: function () {
      if (!window.Tabulator || this.peopleTable) return;
      var self = this;
      this.peopleTable = new Tabulator(this.$refs.peopleTable, {
        data: this.people,
        layout: 'fitColumns',
        columnDefaults: { vertAlign: 'middle' },
        pagination: true,
        paginationSize: 10,
        paginationCounter: 'rows',
        placeholder: 'No members match your search.',
        columns: [
          { title: 'Name', field: 'name', minWidth: 190, widthGrow: 2, formatter: function (cell) { return self.nameCell(cell.getData()); } },
          { title: 'Display name', field: 'display', minWidth: 130 },
          { title: 'Email', field: 'email', minWidth: 210, widthGrow: 2 },
          { title: 'Role', field: 'role', minWidth: 90, formatter: function (cell) { return self.roleCell(cell.getData()); } },
          { title: 'Status', field: 'status', minWidth: 110, formatter: function (cell) { return self.statusCell(cell.getData()); } },
          { title: 'Joining date', field: 'joined', minWidth: 120 },
          { title: 'Action', field: 'action', headerSort: false, minWidth: 130, formatter: function (cell) { return self.actionButton(cell.getData()); } }
        ]
      });
      // Delegated click handler: open the icon action menu for the clicked row.
      this.$refs.peopleTable.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-quickaction'); if (!btn) return; e.stopPropagation();
        var row = self.people.find(function (p) { return String(p.id) === String(btn.getAttribute('data-id')); });
        if (row) self.openActionMenu('people', row, btn);
      });
    },
    nameCell: function (d) {
      var you = d.user_id === this.currentUserId ? ' <span class="text-faint font-normal">(You)</span>' : '';
      return '<span class="flex items-center gap-2.5">' +
        '<span class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0" style="background:' + this.colorFor(d.email || d.name || '') + '">' + this.escapeHtml(d.initial || '?') + '</span>' +
        '<span class="text-ink whitespace-nowrap">' + this.escapeHtml(d.name || '') + you + '</span></span>';
    },
    roleCell: function (d) {
      return '<span class="text-ink whitespace-nowrap">' + this.escapeHtml(d.is_owner ? 'Owner' : this.roleLabel(d.role)) + '</span>';
    },
    statusCell: function (d) {
      var active = String(d.status || '').toLowerCase() === 'active';
      return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-ink"><span class="h-1.5 w-1.5 rounded-full ' + (active ? 'bg-success' : 'bg-faint') + '"></span>' + this.escapeHtml(d.status || '') + '</span>';
    },
    actionButton: function (d) {
      return '<button type="button" class="pb-quickaction inline-flex items-center gap-1 h-7 pl-2.5 pr-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover" data-id="' + d.id + '">Actions' +
        '' + wiIcon('chevron-down', 12, 'text-faint') + '</button>';
    },
    openActionMenu: function (kind, row, btn) {
      var r = btn.getBoundingClientRect();
      var w = 176;
      var items = kind === 'pending' ? 1 : (row.is_owner ? 1 : 3);
      var h = items * 32 + 8;
      var left = Math.max(8, r.right - w);
      var top = r.bottom + 4;
      if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
      this.actionMenu = { open: true, kind: kind, row: row, style: { position: 'fixed', left: left + 'px', top: top + 'px', width: w + 'px', zIndex: 120 } };
    },
    runAction: function (act) {
      var m = this.actionMenu; this.actionMenu.open = false;
      if (m.kind === 'pending') { if (act === 'revoke') this.revoke(m.row); return; }
      this.onPeopleAction(act, m.row);
    },
    onDocMenu: function (e) {
      if (!this.actionMenu.open) return;
      var el = this.$refs.actionMenuEl;
      if (el && el.contains(e.target)) return;
      if (e.target.closest && e.target.closest('.pb-quickaction')) return;
      this.actionMenu.open = false;
    },
    onMenuKey: function (e) { if (e.key === 'Escape') this.actionMenu.open = false; },
    onMenuReflow: function () { if (this.actionMenu.open) this.actionMenu.open = false; },
    onPeopleAction: function (act, d) {
      if (act === 'view') { this.view = { open: true, member: d }; }
      else if (act === 'edit') { this.roleModal = { open: true, member: d, role: d.role, saving: false }; }
      else if (act === 'delete') { this.remove = { open: true, member: d }; }
    },
    applySearch: function () {
      if (!this.peopleTable) return;
      var self = this;
      this.peopleTable.setFilter(function (data) {
        var q = self.search.trim().toLowerCase();
        if (!q) return true;
        return (data.name || '').toLowerCase().indexOf(q) >= 0
          || (data.email || '').toLowerCase().indexOf(q) >= 0
          || (data.display || '').toLowerCase().indexOf(q) >= 0;
      });
    },
    syncPeople: function (data) { this.people = data; if (this.peopleTable) this.peopleTable.replaceData(data); },

    // ---------- Tabulator: Pending ----------
    initPending: function () {
      if (!window.Tabulator || this.pendingTable) return;
      var self = this;
      this.pendingTable = new Tabulator(this.$refs.pendingTable, {
        data: this.pending,
        layout: 'fitColumns',
        columnDefaults: { vertAlign: 'middle' },
        pagination: true,
        paginationSize: 10,
        paginationCounter: 'rows',
        placeholder: 'No pending invites. Invitations you send appear here until accepted.',
        columns: [
          { title: 'Email', field: 'email', minWidth: 210, widthGrow: 2, formatter: function (cell) { return '<span class="text-ink">' + self.escapeHtml(cell.getData().email) + '</span>'; } },
          { title: 'Role', field: 'role', minWidth: 100, formatter: function (cell) { return '<span class="text-ink whitespace-nowrap">' + self.escapeHtml(self.roleLabel(cell.getData().role)) + '</span>'; } },
          { title: 'Invited', field: 'invited', minWidth: 130 },
          { title: 'Invited by', field: 'by', minWidth: 150 },
          { title: 'Action', field: 'action', headerSort: false, minWidth: 120, formatter: function (cell) { return self.actionButton(cell.getData()); } }
        ]
      });
      this.$refs.pendingTable.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-quickaction'); if (!btn) return; e.stopPropagation();
        var row = self.pending.find(function (p) { return String(p.id) === String(btn.getAttribute('data-id')); });
        if (row) self.openActionMenu('pending', row, btn);
      });
    },
    syncPending: function (data) { this.pending = data; if (this.pendingTable) this.pendingTable.replaceData(data); },

    // ---------- invite ----------
    addRow: function () { this.invites.push({ email: '', role: 'member' }); },
    dropRow: function (i) { this.invites.splice(i, 1); if (!this.invites.length) this.addRow(); },
    openInvite: function () { this.invites = [{ email: '', role: 'member' }]; this.inviteResults = {}; this.inviteOpen = true; },
    rowError: function (email) {
      var s = this.inviteResults[(email || '').trim().toLowerCase()];
      if (!s || s === 'invited') return '';
      var map = {
        invalid_email: 'Enter a valid email address.', invalid_role: 'Select a valid role.',
        duplicate: 'This email is listed more than once.', already_member: 'Already a member of this workspace.',
        already_invited: 'This person has already been invited.',
        suspended_member: 'This person has a suspended membership — reactivate it instead.',
        no_seats: 'Your workspace has reached the maximum number of members allowed by your current plan.'
      };
      return map[s] || 'Could not send this invitation.';
    },
    sendInvites: async function () {
      if (this.sending) return; this.sending = true; this.inviteResults = {};
      var rows = this.invites.filter(function (r) { return r.email.trim(); });
      try {
        var resp = await this.$pb.api(this.endpoints.invite, { method: 'POST', body: { invites: rows } });
        this.syncPending(resp.pending);
        var map = {}, anyFail = false;
        (resp.results || []).forEach(function (r) { map[(r.email || '').toLowerCase()] = r.status; if (r.status !== 'invited') anyFail = true; });
        this.inviteResults = map;
        if (!anyFail) {
          this.inviteOpen = false; this.setTab('pending');
          this.$pb.toast(resp.sent + ' invitation' + (resp.sent === 1 ? '' : 's') + ' sent.');
        } else {
          this.$pb.toast(resp.sent > 0 ? (resp.sent + ' sent — some rows need attention.') : 'Some rows need attention.', resp.sent > 0 ? 'success' : 'error');
        }
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.sending = false;
    },

    // ---------- role / remove / revoke ----------
    saveRole: async function () {
      var m = this.roleModal; if (m.saving) return; m.saving = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.role, m.member.id), { method: 'PATCH', body: { role: m.role } });
        this.syncPeople(resp.people); this.roleModal.open = false; this.$pb.toast('Role updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      m.saving = false;
    },
    doRemove: async function () {
      var m = this.remove.member; if (!m) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.remove, m.id), { method: 'DELETE' });
        this.syncPeople(resp.people); this.$pb.toast('Member removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.remove = { open: false, member: null };
    },
    revoke: async function (inv) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.revoke, inv.id), { method: 'DELETE' });
        this.syncPending(resp.pending); this.$pb.toast('Invitation revoked.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },
  template:
    '<div class="px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Members" desc="Manage access to this workspace."/>' +

    // Tabs + toolbar
    '<div class="flex flex-wrap items-center gap-3 border-b border-line">' +
    '<div class="flex items-center gap-5">' +
    '<button :class="[\'pb-2 -mb-px border-b-2 text-[14px]\', tab===\'people\' ? \'border-brand text-ink font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="setTab(\'people\')">People</button>' +
    '<button :class="[\'pb-2 -mb-px border-b-2 text-[14px]\', tab===\'pending\' ? \'border-brand text-ink font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="setTab(\'pending\')">Pending Invites<span v-if="pending.length" class="ml-1.5 text-[11px] bg-hover text-sub rounded px-1.5 py-0.5">{{ pending.length }}</span></button>' +
    '<button :class="[\'pb-2 -mb-px border-b-2 text-[14px] flex items-center gap-1.5\', tab===\'permission\' ? \'border-brand text-ink font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="setTab(\'permission\')">Permission<span class="text-[10px] font-medium text-sub bg-hover rounded px-1.5 py-0.5">Soon</span></button>' +
    '</div>' +
    '<div class="ml-auto flex items-center gap-2 pb-2">' +
    '<div class="relative">' + wiIcon('magnifying-glass', 14, 'absolute left-2.5 top-1/2 -translate-y-1/2 text-faint') + '' +
    '<input class="pb-input !h-9 !w-56 !pl-8" placeholder="Search…" v-model="search" @input="applySearch"/></div>' +
    '<button class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold" @click="openInvite">Add member</button>' +
    '</div></div>' +

    // People panel
    '<div v-show="tab===\'people\'" class="mt-4"><div class="pb-tab-wrap"><div ref="peopleTable"></div></div></div>' +

    // Pending panel
    '<div v-show="tab===\'pending\'" class="mt-4"><div class="pb-tab-wrap"><div ref="pendingTable"></div></div></div>' +

    // Permission panel (Coming Soon)
    '<div v-show="tab===\'permission\'" class="mt-4">' +
    '<div class="border border-dashed border-stroke rounded-xl px-6 py-16 text-center">' +
    '<span class="inline-block text-[11px] bg-amber-100 text-amber-700 rounded-md px-2 py-0.5 mb-3">Coming soon</span>' +
    '<div class="text-[15px] font-semibold text-head">Permissions</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Fine-grained role permissions for this workspace are on the way.</p>' +
    '</div></div>' +

    // Invite modal
    '<pb-modal :open="inviteOpen" title="Invite people to collaborate" @close="inviteOpen=false">' +
    '<p class="text-[13px] text-sub mb-3">Invite people to collaborate on your workspace.</p>' +
    '<div class="space-y-2">' +
    '<div v-for="(row,i) in invites" :key="i">' +
    '<div class="flex items-center gap-2">' +
    '<input class="pb-input !h-9 flex-1" :class="{\'is-error\': rowError(row.email)}" placeholder="name@company.com" v-model="row.email"/>' +
    '<div class="w-28 shrink-0"><pb-combo dense :searchable="false" v-model="row.role" :options="roleOptions"/></div>' +
    '<button class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover" @click="dropRow(i)" title="Remove">' +
    '' + wiIcon('xmark', 16) + '</button>' +
    '</div>' +
    '<p v-if="rowError(row.email)" class="text-[12px] text-danger mt-1 ml-1">{{ rowError(row.email) }}</p>' +
    '</div></div>' +
    '<button class="text-[13px] text-link font-medium mt-3" @click="addRow">+ Add more</button>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="inviteOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="sending" @click="sendInvites">Send invitations</button>' +
    '</template></pb-modal>' +

    // Edit role modal
    '<pb-modal :open="roleModal.open" title="Edit member role" @close="roleModal.open=false">' +
    '<div v-if="roleModal.member" class="text-[13px] text-sub mb-3">Change the role for <b class="text-ink">{{ roleModal.member.name }}</b>.</div>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Role</label>' +
    '<pb-combo v-model="roleModal.role" :options="roleOptions" :searchable="false"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="roleModal.open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="roleModal.saving" @click="saveRole">Save</button>' +
    '</template></pb-modal>' +

    // View member modal
    '<pb-modal :open="view.open" title="Member details" @close="view.open=false">' +
    '<div v-if="view.member" class="space-y-3">' +
    '<div class="flex items-center gap-3">' +
    '<span class="h-11 w-11 rounded-full text-white grid place-items-center text-[15px] font-bold" :style="{background: colorFor(view.member.email || view.member.name)}">{{ view.member.initial }}</span>' +
    '<div><div class="text-[15px] font-semibold text-head">{{ view.member.name }}</div><div class="text-[13px] text-sub">{{ view.member.email }}</div></div></div>' +
    '<div class="grid grid-cols-2 gap-x-4 gap-y-3 pt-2">' +
    '<div><div class="text-[11px] font-medium text-faint uppercase tracking-wide">Role</div><div class="text-[13px] text-ink">{{ view.member.is_owner ? \'Owner\' : roleLabel(view.member.role) }}</div></div>' +
    '<div><div class="text-[11px] font-medium text-faint uppercase tracking-wide">Billing</div><div class="text-[13px] text-ink">{{ view.member.billing }}</div></div>' +
    '<div><div class="text-[11px] font-medium text-faint uppercase tracking-wide">Authentication</div><div class="text-[13px] text-ink">{{ view.member.auth }}</div></div>' +
    '<div><div class="text-[11px] font-medium text-faint uppercase tracking-wide">Joined</div><div class="text-[13px] text-ink">{{ view.member.joined }}</div></div>' +
    '</div></div>' +
    '<template #footer><button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="view.open=false">Close</button></template>' +
    '</pb-modal>' +

    '<pb-confirm :open="remove.open" title="Remove member?" :message="(remove.member ? remove.member.name : \'\') + \' will lose access to this workspace. Authored records are preserved.\'" confirm-label="Remove" @close="remove.open=false" @confirm="doRemove"/>' +

    // Row action menu (icons) — teleported so it is never clipped by the table cell
    '<teleport to="body">' +
    '<div v-if="actionMenu.open" ref="actionMenuEl" :style="actionMenu.style" class="bg-white border border-line rounded-md shadow-lg py-1 text-[13px]">' +
    '<template v-if="actionMenu.kind===\'people\'">' +
    '<button @click="runAction(\'view\')" class="w-full flex items-center gap-2 px-3 h-8 text-ink hover:bg-hover text-left">' + wiIcon('eye', 15) + 'View</button>' +
    '<template v-if="!actionMenu.row.is_owner">' +
    '<button @click="runAction(\'edit\')" class="w-full flex items-center gap-2 px-3 h-8 text-ink hover:bg-hover text-left">' + wiIcon('pen', 15) + 'Edit</button>' +
    '<button @click="runAction(\'delete\')" class="w-full flex items-center gap-2 px-3 h-8 text-danger hover:bg-red-50 text-left">' + wiIcon('trash', 15) + 'Delete</button>' +
    '</template></template>' +
    '<template v-else>' +
    '<button @click="runAction(\'revoke\')" class="w-full flex items-center gap-2 px-3 h-8 text-danger hover:bg-red-50 text-left">' + wiIcon('circle-xmark-thin', 15) + 'Revoke</button>' +
    '</template>' +
    '</div></teleport>' +
    '</div>'
});
