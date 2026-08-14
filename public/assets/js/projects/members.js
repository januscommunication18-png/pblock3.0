/* Project Settings › Members — Project Member Management (§5, §6, §21, §22, §23, §31-§33).
 *
 * Deliberately built to mirror Workspace → Settings → Members: same Tabulator grid and skin,
 * same toolbar shape, same "Actions" row menu, same pb-modal dialogs. The two member screens
 * differ only in what a membership MEANS — workspace membership says you belong to the
 * organisation, project membership says you participate in THIS project (§38).
 *
 * Every control here is mirrored by a server-side check; §24 is explicit that hiding actions
 * in the UI is not sufficient. */
PB.boot('project-members', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      members: Array.isArray(b.members) ? b.members : [],
      candidates: Array.isArray(b.candidates) ? b.candidates : [],
      roles: b.roles || {},
      workspaceRoles: b.workspaceRoles || {},
      defaultRole: b.defaultRole || 'contributor',
      canManage: !!b.canManage,
      endpoints: b.endpoints || {},
      table: null,
      search: '', roleFilter: 'all',
      // Dialogs, mirroring the workspace screen's invite / role / remove modals.
      addOpen: false, adding: false, addForm: { user_id: '', role: b.defaultRole || 'contributor' },
      roleModal: { open: false, member: null, role: '', saving: false },
      remove: { open: false, member: null, busy: false },
      actionMenu: { open: false, row: null, style: {} }
    };
  },
  mounted: function () {
    this.initTable();
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
    if (this.table) { this.table.destroy(); this.table = null; }
  },
  computed: {
    roleList: function () {
      var r = this.roles;
      return Object.keys(r).map(function (k) {
        return { key: k, label: r[k].label || k, description: r[k].description || '' };
      });
    },
    roleOptions: function () {
      return this.roleList.map(function (r) { return { value: r.key, label: r.label }; });
    },
    /** §22: All Roles, then one entry per project role. */
    filterOptions: function () {
      return [{ value: 'all', label: 'All Roles' }].concat(this.roleOptions);
    },
    /** §9: only active coworkers not already on the project. */
    candidateOptions: function () {
      var self = this;
      return this.candidates.map(function (c) {
        return { value: String(c.id), label: c.name + ' · ' + c.email + ' · ' + self.workspaceRoleLabel(c.workspace_role) };
      });
    },
    addRoleDescription: function () {
      var self = this;
      var r = this.roleList.find(function (x) { return x.key === self.addForm.role; });
      return r ? r.description : '';
    },
    memberCount: function () { return this.members.length; },
    isEmpty: function () { return this.members.length === 0; }
  },
  methods: {
    // ---------- helpers (shared with the workspace members screen) ----------
    escapeHtml: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    /*
     * The shared helper, not a second palette. This screen had its own — same idea, different
     * eight colours, three of which did not reach 4.5:1 against the white initial sitting on
     * them — so the same person came out one colour here and another in a work item row.
     */
    colorFor: function (person) { return PB.avatarColor(person); },
    roleLabel: function (r) { return (this.roles[r] || {}).label || r || '—'; },
    workspaceRoleLabel: function (r) { return this.workspaceRoles[r] || r || '—'; },

    // ---------- Tabulator ----------
    initTable: function () {
      if (!window.Tabulator || this.table || !this.$refs.table) return;
      var self = this;
      this.table = new Tabulator(this.$refs.table, {
        data: this.members,
        layout: 'fitColumns',
        columnDefaults: { vertAlign: 'middle' },
        pagination: true,
        paginationSize: 10,
        paginationCounter: 'rows',
        placeholder: 'No members match your search.',
        columns: [
          { title: 'Member', field: 'name', minWidth: 200, widthGrow: 2, formatter: function (c) { return self.nameCell(c.getData()); } },
          { title: 'Email', field: 'email', minWidth: 200, widthGrow: 2 },
          { title: 'Workspace Role', field: 'workspace_role', minWidth: 130, formatter: function (c) { return self.plainCell(self.workspaceRoleLabel(c.getData().workspace_role)); } },
          { title: 'Project Role', field: 'role', minWidth: 120, formatter: function (c) { return self.projectRoleCell(c.getData()); } },
          { title: 'Date Added', field: 'added_at', minWidth: 110, formatter: function (c) { return self.plainCell(c.getData().added_at || '—'); } },
          { title: 'Added By', field: 'added_by', minWidth: 140, formatter: function (c) { return self.plainCell(c.getData().added_by || '—'); } },
          { title: 'Action', field: 'action', headerSort: false, minWidth: 120, formatter: function (c) { return self.actionButton(c.getData()); } }
        ]
      });

      // Delegated click: open the row action menu (§33).
      this.$refs.table.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-quickaction');
        if (!btn) return;
        e.stopPropagation();
        var row = self.members.find(function (m) { return String(m.id) === String(btn.getAttribute('data-id')); });
        if (row) self.openActionMenu(row, btn);
      });
    },
    nameCell: function (d) {
      var lead = d.is_lead ? ' <span class="text-[10px] font-semibold uppercase tracking-wide text-brand">Lead</span>' : '';
      return '<span class="flex items-center gap-2.5">' +
        '<span class="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-bold shrink-0" style="background:' + this.colorFor(d) + '">' + this.escapeHtml(d.initial || '?') + '</span>' +
        '<span class="text-ink whitespace-nowrap">' + this.escapeHtml(d.name || '') + lead + '</span></span>';
    },
    plainCell: function (text) {
      return '<span class="text-ink whitespace-nowrap">' + this.escapeHtml(text) + '</span>';
    },
    projectRoleCell: function (d) {
      return '<span class="inline-flex items-center h-6 px-2 rounded border border-line bg-white text-[12px] text-ink whitespace-nowrap">' +
        this.escapeHtml(this.roleLabel(d.role)) + '</span>';
    },
    actionButton: function (d) {
      // §33: members without manage rights never see row actions.
      if (!this.canManage) return '';
      return '<button type="button" class="pb-quickaction inline-flex items-center gap-1 h-7 pl-2.5 pr-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover" data-id="' + d.id + '">Actions' +
        '' + wiIcon('chevron-down', 12, 'text-faint') + '</button>';
    },
    openActionMenu: function (row, btn) {
      var r = btn.getBoundingClientRect(), w = 200, h = 2 * 32 + 8;
      var left = Math.max(8, r.right - w);
      var top = r.bottom + 4;
      if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
      this.actionMenu = {
        open: true, row: row,
        style: { position: 'fixed', left: left + 'px', top: top + 'px', width: w + 'px', zIndex: 120 }
      };
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
    runAction: function (act) {
      var m = this.actionMenu.row;
      this.actionMenu.open = false;
      if (!m) return;
      if (act === 'role') this.roleModal = { open: true, member: m, role: m.role, saving: false };
      else if (act === 'remove') this.remove = { open: true, member: m, busy: false };
    },
    /** §21 search + §22 role filter, applied through Tabulator like the workspace screen. */
    applyFilters: function () {
      if (!this.table) return;
      var self = this;
      this.table.setFilter(function (data) {
        if (self.roleFilter !== 'all' && data.role !== self.roleFilter) return false;
        var q = self.search.trim().toLowerCase();
        if (!q) return true;
        return (data.name || '').toLowerCase().indexOf(q) >= 0
          || (data.email || '').toLowerCase().indexOf(q) >= 0;
      });
    },
    /** Every mutation returns both lists, so the coworker picker stays in sync (§9). */
    applyLists: function (resp) {
      if (Array.isArray(resp.members)) {
        this.members = resp.members;
        if (this.table) this.table.replaceData(resp.members);
      }
      if (Array.isArray(resp.candidates)) this.candidates = resp.candidates;
    },

    // ---------- Add Member (§7-§11) ----------
    openAdd: function () {
      if (!this.canManage) return;
      this.addForm = { user_id: '', role: this.defaultRole };
      this.addOpen = true;
    },
    submitAdd: async function () {
      if (!this.addForm.user_id || this.adding) return;
      this.adding = true;
      try {
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: this.addForm });
        this.applyLists(resp);
        this.addOpen = false;
        this.$pb.toast(resp.message || 'Member added to project successfully.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.adding = false;
    },

    // ---------- Change role (§13) ----------
    saveRole: async function () {
      var m = this.roleModal.member;
      if (!m || this.roleModal.saving) return;
      this.roleModal.saving = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.role, m.id), {
          method: 'PATCH', body: { role: this.roleModal.role }
        });
        this.applyLists(resp);
        this.roleModal.open = false;
        this.$pb.toast(resp.message || 'Project role updated successfully.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.roleModal.saving = false;
    },

    // ---------- Remove (§14) ----------
    doRemove: async function () {
      var m = this.remove.member;
      if (!m || this.remove.busy) return;
      this.remove.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.remove, m.id), { method: 'DELETE' });
        this.applyLists(resp);
        this.remove = { open: false, member: null, busy: false };
        this.$pb.toast(resp.message || 'Member removed from project.');
      } catch (e) {
        this.remove.busy = false;
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    }
  },
  template:
    '<div class="px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Members" desc="Manage coworkers who have access to this project and assign their project roles."/>' +

    // ===== Toolbar — same shape as the workspace members screen =====
    '<div class="flex flex-wrap items-center gap-3 border-b border-line">' +
    '<div class="flex items-center gap-5">' +
    '<span class="pb-2 -mb-px border-b-2 border-brand text-ink font-medium text-[14px]">People' +
    '<span v-if="memberCount" class="ml-1.5 text-[11px] bg-hover text-sub rounded px-1.5 py-0.5">{{ memberCount }}</span></span>' +
    '</div>' +
    '<div class="ml-auto flex items-center gap-2 pb-2">' +
    '<div class="relative">' + wiIcon('magnifying-glass', 14, 'absolute left-2.5 top-1/2 -translate-y-1/2 text-faint') + '' +
    '<input class="pb-input !h-9 !w-56 !pl-8" placeholder="Search members…" v-model="search" @input="applyFilters"/></div>' +
    '<div class="w-36"><pb-combo dense :searchable="false" v-model="roleFilter" :options="filterOptions" @update:modelValue="applyFilters"/></div>' +
    '<button v-if="canManage" class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap" @click="openAdd">Add Member</button>' +
    '</div></div>' +

    // ===== Empty state (§23) =====
    '<div v-if="isEmpty" class="mt-4 border border-dashed border-stroke rounded-xl px-6 py-16 text-center">' +
    '<div class="text-[15px] font-semibold text-head">Build your project team</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Add coworkers from your Workspace to collaborate on this project.</p>' +
    '<button v-if="canManage" class="mt-5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold" @click="openAdd">Add Member</button>' +
    '</div>' +

    // ===== Member grid (§6) =====
    '<div v-show="!isEmpty" class="mt-4"><div class="pb-tab-wrap"><div ref="table"></div></div></div>' +

    // ===== Row action menu (§33) =====
    '<div v-if="actionMenu.open" ref="actionMenuEl" :style="actionMenu.style" role="menu" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button type="button" role="menuitem" @click="runAction(\'role\')" class="w-full text-left flex items-center gap-2.5 px-3 h-8 hover:bg-hover text-[13px] text-ink">' +
    '' + wiIcon('pen-line', 15, 'text-faint shrink-0') + 'Change Role</button>' +
    '<button type="button" role="menuitem" @click="runAction(\'remove\')" class="w-full text-left flex items-center gap-2.5 px-3 h-8 hover:bg-hover text-[13px] text-danger">' +
    '' + wiIcon('arrow-right-from-bracket', 15, 'shrink-0') + 'Remove from Project</button>' +
    '</div>' +

    // ===== Add Member modal (§7, §32) =====
    '<pb-modal :open="addOpen" title="Add Member to Project" @close="addOpen=false">' +
    '<p class="text-[13px] text-sub mb-3">Select a coworker from this Workspace and assign their project role.</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Coworker</label>' +
    '<pb-combo v-model="addForm.user_id" :options="candidateOptions" placeholder="Search workspace members…"/>' +
    '<p v-if="!candidateOptions.length" class="text-[12px] text-sub mt-1.5">Everyone in this workspace is already on the project.</p>' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Project Role</label>' +
    '<pb-combo :searchable="false" v-model="addForm.role" :options="roleOptions"/>' +
    '<p class="text-[12px] text-sub mt-1.5">{{ addRoleDescription }}</p>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="addOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!addForm.user_id || adding" @click="submitAdd">{{ adding ? \'Adding…\' : \'Add Member\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Change role modal (§13) =====
    '<pb-modal :open="roleModal.open" title="Change project role" @close="roleModal.open=false">' +
    '<p class="text-[13px] text-sub mb-3">Update <span class="font-semibold text-ink">{{ roleModal.member ? roleModal.member.name : \'\' }}</span>’s role in this project.</p>' +
    '<pb-combo :searchable="false" v-model="roleModal.role" :options="roleOptions"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="roleModal.open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="roleModal.saving" @click="saveRole">{{ roleModal.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Remove confirmation (§14) =====
    '<pb-modal :open="remove.open" title="Remove Member?" @close="remove.open=false">' +
    '<p class="text-[13px] text-sub leading-relaxed">Are you sure you want to remove <span class="font-semibold text-ink">{{ remove.member ? remove.member.name : \'\' }}</span> from this project?</p>' +
    '<p class="text-[12px] text-sub mt-3">The member will lose access to this project but will remain a member of the Workspace.</p>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="remove.open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50" :disabled="remove.busy" @click="doRemove">{{ remove.busy ? \'Removing…\' : \'Remove Member\' }}</button>' +
    '</template></pb-modal>' +

    '</div>'
});
