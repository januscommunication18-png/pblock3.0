/* Help Center › Space › Members (docs/features/help-center.md, P10).
 * ------------------------------------------------------------------
 * Deliberately built to mirror Project Settings › Members: the same Tabulator grid and skin,
 * the same toolbar shape (People count · search · filter · Add Member), the same "Actions" row
 * menu, the same pb-modal dialogs, the same empty state. Managing who works a Space's Inbox and
 * managing who works a project should not feel like two applications.
 *
 * What a membership MEANS is the only difference, and it changes exactly two columns: a Space
 * has no roles of its own — access to a Space is binary — so the Role column shows the person's
 * WORKSPACE role, and the column a project spends on "Project Role" is spent here on the
 * Department Groups they cover.
 *
 * Every control here is mirrored by a server-side check in SpaceMemberController. Hiding an
 * action in the UI is not enforcing it.
 * ------------------------------------------------------------------ */

PB.boot('help-center-members', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};

    return {
      members: Array.isArray(b.members) ? b.members : [],
      candidates: Array.isArray(b.candidates) ? b.candidates : [],
      roles: b.roles || {},
      inviteRoles: Array.isArray(b.inviteRoles) ? b.inviteRoles : [],
      groups: Array.isArray(b.groups) ? b.groups : [],
      canManage: !!b.canManage,
      endpoints: b.endpoints || {},
      table: null,
      search: '', statusFilter: 'all',
      addOpen: false, adding: false,
      addForm: { user_id: '', email: '', role: b.defaultRole || 'member', department_groups: [] },
      // The combo is the way in; this swaps it for an address field (see inviteMode below).
      inviteMode: false,
      groupModal: { open: false, member: null, groups: [], saving: false },
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
    memberCount: function () { return this.members.length; },
    isEmpty: function () { return this.members.length === 0; },
    /**
     * The coworker picker's options — a searchable combo, not a text field.
     *
     * Everyone in the workspace who is eligible and is not already on this Space. `avatar` and
     * `initial` make pb-combo render a face, which is how you tell two people with similar
     * names apart, and `desc` puts the address under the name so you can be sure which
     * "J. Smith" you picked.
     */
    candidateOptions: function () {
      return this.candidates.map(function (c) {
        return {
          value: String(c.id),
          label: c.name || c.email,
          desc: c.email || '',
          avatar: c.avatar || '',
          initial: c.initial || ''
        };
      });
    },
    chosenCandidate: function () {
      var id = String(this.addForm.user_id || '');

      return this.candidates.filter(function (c) { return String(c.id) === id; })[0] || null;
    },
    /** Only the roles the server said this actor may hand out. */
    roleOptions: function () { return this.inviteRoles; },
    addRoleDescription: function () {
      var hit = this.inviteRoles.filter(function (r) { return r.value === this.addForm.role; }, this)[0];

      return hit ? 'Invited as ' + hit.label + ' in this workspace.' : '';
    },
    /**
     * Is this an existing coworker, or somebody being invited from scratch?
     *
     * Picking from the combo is always the former — that is the whole point of the combo. Only
     * the invite-by-email escape hatch can produce the latter.
     */
    addIsExisting: function () { return ! this.inviteMode; },
    addValid: function () {
      return this.inviteMode
        ? /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test((this.addForm.email || '').trim())
        : this.addForm.user_id !== '';
    },
    groupOptions: function () {
      return this.groups.map(function (g) { return { value: g, label: g }; });
    },
    /** Status rather than role: a Space has no roles, so filtering by one would filter nothing. */
    filterOptions: function () {
      return [
        { value: 'all', label: 'All Members' },
        { value: 'active', label: 'Active' },
        { value: 'invited', label: 'Invited' },
        { value: 'bounced', label: 'Bounced' }
      ];
    }
  },
  methods: {
    escapeHtml: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    // The shared helper, not a second palette — so one person is the same colour here, in a
    // work item row and in the Inbox's assignee avatar.
    colorFor: function (person) { return PB.avatarColor(person); },
    roleLabel: function (r) { return this.roles[r] || r || '—'; },

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
          { title: 'Workspace Role', field: 'workspace_role', minWidth: 130, formatter: function (c) { return self.plainCell(self.roleLabel(c.getData().workspace_role)); } },
          { title: 'Department Groups', field: 'department_groups', minWidth: 170, widthGrow: 1, headerSort: false, formatter: function (c) { return self.groupsCell(c.getData()); } },
          { title: 'Status', field: 'status', minWidth: 100, formatter: function (c) { return self.statusCell(c.getData()); } },
          { title: 'Date Added', field: 'added_at', minWidth: 110, formatter: function (c) { return self.plainCell(c.getData().added_at || '—'); } },
          { title: 'Action', field: 'action', headerSort: false, minWidth: 120, formatter: function (c) { return self.actionButton(c.getData()); } }
        ]
      });

      this.$refs.table.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-quickaction');
        if (!btn) return;
        e.stopPropagation();
        var row = self.members.filter(function (m) { return String(m.id) === String(btn.getAttribute('data-id')); })[0];
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
    groupsCell: function (d) {
      var groups = d.department_groups || [];
      if (!groups.length) return '<span class="text-faint">—</span>';

      return groups.map(function (g) {
        return '<span class="inline-flex items-center h-6 px-2 mr-1 rounded border border-line bg-white text-[12px] text-ink whitespace-nowrap">' +
          String(g).replace(/[&<>"']/g, '') + '</span>';
      }).join('');
    },
    statusCell: function (d) {
      // Invited means the workspace invitation has not been accepted — they cannot open the
      // Inbox yet, and a row that looked active would misrepresent who is actually covering it.
      if (d.status === 'bounced') {
        // The reason on hover: the person who typed the address is the one who can fix it, and
        // "Bounced" without a cause just moves the guessing somewhere else.
        return '<span class="_moretogether-badge _moretogether-badge--error" data-tip="' +
          this.escapeHtml(d.email_error || 'The invitation email was rejected.') + '">Bounced</span>';
      }

      return d.status === 'invited'
        ? '<span class="_moretogether-badge _moretogether-badge--wait">Invited</span>'
        : '<span class="_moretogether-badge _moretogether-badge--ok">Active</span>';
    },
    actionButton: function (d) {
      if (!this.canManage) return '';

      return '<button type="button" class="pb-quickaction inline-flex items-center gap-1 h-7 pl-2.5 pr-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover" data-id="' + d.id + '">Actions' +
        wiIcon('chevron-down', 12, 'text-faint') + '</button>';
    },

    // ---------- the row action menu ----------
    openActionMenu: function (row, btn) {
      var r = btn.getBoundingClientRect(), w = 220, h = 2 * 32 + 8;
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

      if (act === 'groups') this.groupModal = { open: true, member: m, groups: (m.department_groups || []).slice(), saving: false };
      else if (act === 'remove') this.remove = { open: true, member: m, busy: false };
    },

    applyFilters: function () {
      if (!this.table) return;
      var self = this;

      this.table.setFilter(function (data) {
        if (self.statusFilter !== 'all' && data.status !== self.statusFilter) return false;

        var q = self.search.trim().toLowerCase();
        if (!q) return true;

        return (data.name || '').toLowerCase().indexOf(q) >= 0
          || (data.email || '').toLowerCase().indexOf(q) >= 0;
      });
    },
    syncTable: function () {
      if (this.table) this.table.replaceData(this.members).then(this.applyFilters);
    },

    // ---------- writes ----------
    openAdd: function () {
      this.addForm = { user_id: '', email: '', role: (this.inviteRoles[0] || {}).value || 'member', department_groups: [] };
      // Open on the picker unless there is nobody left to pick, in which case the only useful
      // thing this dialog can do is invite somebody new.
      this.inviteMode = this.candidates.length === 0;
      this.addOpen = true;
    },
    toggleInviteMode: function () {
      this.inviteMode = ! this.inviteMode;
      this.addForm.user_id = '';
      this.addForm.email = '';
    },
    submitAdd: function () {
      if (!this.addValid || this.adding) return;
      var self = this;
      this.adding = true;

      /*
       * The endpoint takes an ADDRESS either way.
       *
       * One server-side path for both cases: it looks the address up among active coworkers and
       * either links the membership to that user or sends an invitation. Sending a user_id for
       * the picked case and an email for the other would be two code paths agreeing to do the
       * same thing, which is how they stop agreeing.
       */
      var chosen = this.chosenCandidate;
      var body = {
        email: this.inviteMode ? (this.addForm.email || '').trim() : (chosen ? chosen.email : ''),
        role: this.addForm.role,
        department_groups: this.addForm.department_groups
      };

      this.$pb.api(this.endpoints.store, { method: 'POST', body: body })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not add the member.', 'error');

            return;
          }

          self.members.push(resp.member);
          // Drop them from the picker, so the same person cannot be offered twice.
          var added = String(body.email || '').toLowerCase();
          self.candidates = self.candidates.filter(function (c) {
            return String(c.email || '').toLowerCase() !== added;
          });
          self.addOpen = false;
          self.syncTable();
          self.$pb.toast(resp.message || 'Member added.');
        })
        /*
         * The REASON, not just "it failed".
         *
         * `$pb.api` throws on any non-2xx, so a 422 never reaches the branch above — it lands
         * here, and a catch that ignored its argument turned every server explanation into
         * "Could not add the member." The ones this endpoint sends are exactly the ones an
         * admin can act on: no seats left, already on this Space, a role they may not assign.
         */
        .catch(function (e) {
          self.$pb.toast(self.$pb.firstError(e, 'Could not add the member.'), 'error');
        })
        .finally(function () { self.adding = false; });
    },
    saveGroups: function () {
      var m = this.groupModal.member;
      if (!m || this.groupModal.saving) return;
      var self = this;
      this.groupModal.saving = true;

      this.$pb.api(this.endpoints.update.replace('__ID__', m.id), {
        method: 'PATCH', body: { department_groups: this.groupModal.groups }
      })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save.', 'error');

            return;
          }

          var i = self.members.findIndex(function (x) { return x.id === m.id; });
          if (i !== -1) self.members.splice(i, 1, resp.member);
          self.groupModal.open = false;
          self.syncTable();
          self.$pb.toast(resp.message || 'Saved.');
        })
        .catch(function () { self.$pb.toast('Could not save.', 'error'); })
        .finally(function () { self.groupModal.saving = false; });
    },
    doRemove: function () {
      var m = this.remove.member;
      if (!m || this.remove.busy) return;
      var self = this;
      this.remove.busy = true;

      this.$pb.api(this.endpoints.destroy.replace('__ID__', m.id), { method: 'DELETE' })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not remove the member.', 'error');

            return;
          }

          self.members = self.members.filter(function (x) { return x.id !== m.id; });
          // Back into the picker — removing somebody must not make them unaddable.
          if (m.user_id) {
            self.candidates.push({ id: m.user_id, name: m.name, email: m.email, initial: m.initial });
          }
          self.remove.open = false;
          self.syncTable();
          self.$pb.toast(resp.message || 'Member removed.');
        })
        .catch(function () { self.$pb.toast('Could not remove the member.', 'error'); })
        .finally(function () { self.remove.busy = false; });
    }
  },

  template:
    '<div>' +

    // ===== Toolbar — the same shape as the project and workspace member screens =====
    '<div class="flex flex-wrap items-center gap-3 border-b border-line">' +
    '<div class="flex items-center gap-5">' +
    '<span class="pb-2 -mb-px border-b-2 border-brand text-ink font-medium text-[14px]">People' +
    '<span v-if="memberCount" class="ml-1.5 text-[11px] bg-hover text-sub rounded px-1.5 py-0.5">{{ memberCount }}</span></span>' +
    '</div>' +
    '<div class="ml-auto flex items-center gap-2 pb-2">' +
    '<div class="relative">' + wiIcon('magnifying-glass', 14, 'absolute left-2.5 top-1/2 -translate-y-1/2 text-faint') +
    '<input class="pb-input !h-9 !w-56 !pl-8" placeholder="Search members…" v-model="search" @input="applyFilters"/></div>' +
    '<div class="w-36"><pb-combo dense :searchable="false" v-model="statusFilter" :options="filterOptions" @update:modelValue="applyFilters"/></div>' +
    '<button v-if="canManage" class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap" @click="openAdd">Add Member</button>' +
    '</div></div>' +

    // ===== Empty state =====
    '<div v-if="isEmpty" class="mt-4 border border-dashed border-stroke rounded-xl px-6 py-16 text-center">' +
    '<div class="text-[15px] font-semibold text-head">Build your support team</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Add coworkers from your Workspace to work this Space’s Inbox.</p>' +
    '<button v-if="canManage" class="mt-5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold" @click="openAdd">Add Member</button>' +
    '</div>' +

    // ===== The grid =====
    '<div v-show="!isEmpty" class="mt-4"><div class="pb-tab-wrap"><div ref="table"></div></div></div>' +

    // ===== Row action menu =====
    '<div v-if="actionMenu.open" ref="actionMenuEl" :style="actionMenu.style" role="menu" class="rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">' +
    '<button type="button" role="menuitem" @click="runAction(\'groups\')" class="w-full text-left flex items-center gap-2.5 px-3 h-8 hover:bg-hover text-[13px] text-ink">' +
    wiIcon('pen-line', 15, 'text-faint shrink-0') + 'Department Groups</button>' +
    '<button type="button" role="menuitem" @click="runAction(\'remove\')" class="w-full text-left flex items-center gap-2.5 px-3 h-8 hover:bg-hover text-[13px] text-danger">' +
    wiIcon('arrow-right-from-bracket', 15, 'shrink-0') + 'Remove from Space</button>' +
    '</div>' +

    // ===== Add Member =====
    '<pb-modal :open="addOpen" title="Invite a coworker" @close="addOpen=false">' +
    '<p class="text-[13px] text-sub mb-4">They will be able to work this Space’s Inbox. If they are not in your Workspace yet, we will email them an invitation.</p>' +

    // --- Coworker: a searchable combo of workspace coworkers, with their faces and addresses.
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Coworker</label>' +
    '<template v-if="!inviteMode">' +
    '<pb-combo v-model="addForm.user_id" :options="candidateOptions" placeholder="Search coworkers…"/>' +
    '<p v-if="!candidateOptions.length" class="text-[12px] text-sub mt-1.5">Everyone in your Workspace is already on this Space.</p>' +
    '<p v-else class="text-[12px] text-sub mt-1.5">They keep their existing Workspace role.</p>' +
    '</template>' +
    // The escape hatch. Without it a workspace with nobody left to pick has a dialog that can
    // do nothing at all — which is exactly the state this Space is in today.
    '<template v-else>' +
    '<input type="email" class="pb-input !h-9 w-full" placeholder="name@company.com" v-model="addForm.email"/>' +
    '<p class="text-[12px] text-sub mt-1.5">They are not in your Workspace yet, so we will email them an invitation.</p>' +
    '</template>' +
    '<button type="button" class="mt-1.5 text-[12px] text-brand hover:underline" @click="toggleInviteMode"' +
    ' v-text="inviteMode ? \'Pick an existing coworker instead\' : \'Not in your Workspace? Invite by email\'"></button>' +

    // --- Role: only what this actor may hand out, and only meaningful for a new invitation.
    '<template v-if="!addIsExisting">' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Role</label>' +
    '<pb-combo :searchable="false" v-model="addForm.role" :options="roleOptions"/>' +
    '<p class="text-[12px] text-sub mt-1.5">{{ addRoleDescription }}</p>' +
    '</template>' +

    // --- Department: the Space's own groups.
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Department <span class="text-faint font-normal">(optional)</span></label>' +
    '<pb-tags v-if="groups.length" v-model="addForm.department_groups" :suggestions="groups" placeholder="Add a department…"/>' +
    '<p v-else class="text-[12px] text-sub">This Space has no Department Groups yet. Add them under Edit Space and they will be offered here.</p>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="addOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!addValid || adding" @click="submitAdd">' +
    '{{ adding ? \'Sending…\' : (addIsExisting ? \'Add Member\' : \'Send Invitation\') }}</button>' +
    '</template></pb-modal>' +

    // ===== Department Groups =====
    '<pb-modal :open="groupModal.open" title="Department Groups" @close="groupModal.open=false">' +
    '<p class="text-[13px] text-sub mb-3">Which groups does <span class="font-semibold text-ink">{{ groupModal.member ? groupModal.member.name : \'\' }}</span> cover in this Space?</p>' +
    '<pb-tags v-model="groupModal.groups" :suggestions="groups" placeholder="Add a group…"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="groupModal.open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="groupModal.saving" @click="saveGroups">{{ groupModal.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Remove =====
    '<pb-modal :open="remove.open" title="Remove Member?" @close="remove.open=false">' +
    '<p class="text-[13px] text-sub leading-relaxed">Are you sure you want to remove <span class="font-semibold text-ink">{{ remove.member ? remove.member.name : \'\' }}</span> from this Space?</p>' +
    '<p class="text-[12px] text-sub mt-3">They will lose access to this Space’s Inbox but remain a member of the Workspace. Requests already assigned to them stay assigned.</p>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="remove.open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50" :disabled="remove.busy" @click="doRemove">{{ remove.busy ? \'Removing…\' : \'Remove Member\' }}</button>' +
    '</template></pb-modal>' +

    '</div>'
}, { root: 'help-center-members' });
