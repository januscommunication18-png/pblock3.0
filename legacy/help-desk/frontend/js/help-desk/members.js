/* Help Desk › Settings › Members (docs/features/help-desk.md, FR-1.4/1.6/1.7/1.8).
   ------------------------------------------------------------------
   Add an existing workspace member, give them one of the five Help Desk roles, choose which
   inboxes they may open, deactivate them, remove them — plus the small inbox list those
   choices are made against.

   A plain table rather than Tabulator (which Settings › Members uses): this list is a handful
   of rows with a control in every one of them, and the grid's cell formatters would mean
   re-implementing those controls as HTML strings.

   Every mutation renders the LIST THE SERVER RETURNED rather than patching the row locally.
   Two administrators editing at the same time is a listed edge case (§9), and a screen that
   trusts its own optimistic guess is how one of them ends up acting on a row that no longer
   exists.
   ------------------------------------------------------------------ */
PB.boot('help-desk-members', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      members: b.members || [],
      pending: b.pending || [],
      inboxes: b.inboxes || [],
      assignable: b.assignable || [],
      roles: b.roles || [],
      canAssignAdmin: !!b.canAssignAdmin,
      endpoints: b.endpoints || {},
      busy: false,
      add: { open: false, userId: '', role: 'agent', inboxIds: [], saving: false, errors: {} },
      // "Invite Coworker" (§4) — somebody who is not in the workspace at all yet. They get one
      // invitation that makes them a workspace member AND a Help Desk member on acceptance.
      invite: { open: false, email: '', role: 'agent', inboxIds: [], saving: false, errors: {} },
      revoke: { open: false, invite: null },
      remove: { open: false, member: null },
      access: { open: false, member: null, inboxIds: [], saving: false }
    };
  },
  computed: {
    // Roles this person may hand out. Help Desk Admin is workspace-authority only, so it is
    // absent rather than present-and-refused.
    assignableRoles: function () {
      var self = this;
      return this.roles.filter(function (r) { return r.value !== 'admin' || self.canAssignAdmin; });
    },
    addRoleNeedsInboxes: function () { return !this.roleReachesAll(this.add.role); },
    inviteRoleNeedsInboxes: function () { return !this.roleReachesAll(this.invite.role); },
    hasInboxes: function () { return this.inboxes.length > 0; },

    /*
     * The people who can still be added, as <pb-combo> options — searchable, with a face.
     *
     * A plain <select> was fine for a three-person workspace and unusable for a hundred: you
     * cannot type at it, and two people with similar names are told apart by an email that a
     * native option list truncates. The combobox is the control every other person-picker in
     * the app already uses, so this is reuse rather than a new widget.
     */
    assignableOptions: function () {
      return this.assignable.map(function (u) {
        return { value: String(u.id), label: u.name, desc: u.email, initial: u.initial };
      });
    },

    // The same control in multi-select mode: a workspace with a dozen inboxes is a checkbox
    // list you have to scroll and read, and the combobox summarises what is chosen instead.
    inboxOptions: function () {
      return this.inboxes.map(function (i) { return { value: String(i.id), label: i.name }; });
    }
  },
  methods: {
    roleReachesAll: function (role) {
      var found = this.roles.filter(function (r) { return r.value === role; })[0];
      return !!(found && found.all_inboxes);
    },
    roleLabel: function (role) {
      var found = this.roles.filter(function (r) { return r.value === role; })[0];
      return found ? found.label : role;
    },

    // ---------- add ----------
    openAdd: function () {
      this.add = { open: true, userId: '', role: 'agent', inboxIds: [], saving: false, errors: {} };
    },
    submitAdd: async function () {
      if (!this.add.userId || this.add.saving) return;
      this.add.saving = true; this.add.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.store, {
          method: 'POST',
          body: { user_id: this.add.userId, role: this.add.role, inbox_ids: this.add.inboxIds }
        });
        this.applyMembers(resp);
        this.add.open = false;
        this.$pb.toast('Member added.');
      } catch (e) {
        this.add.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.add.saving = false;
    },

    // ---------- invite a coworker (FR-1.5) ----------
    openInvite: function () {
      this.invite = { open: true, email: '', role: 'agent', inboxIds: [], saving: false, errors: {} };
    },
    submitInvite: async function () {
      var email = String(this.invite.email || '').trim();
      if (!email || this.invite.saving) return;
      this.invite.saving = true; this.invite.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.invite, {
          method: 'POST',
          body: { email: email, role: this.invite.role, inbox_ids: this.invite.inboxIds }
        });
        this.applyMembers(resp);
        this.invite.open = false;
        this.$pb.toast('Invitation sent to ' + email + '.');
      } catch (e) {
        // "Already a member", "no seats left" and the rest come back on the email field, so
        // they are read beside the box they are about.
        this.invite.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.invite.saving = false;
    },
    askRevoke: function (invite) { this.revoke = { open: true, invite: invite }; },
    confirmRevoke: async function () {
      var invite = this.revoke.invite;
      this.revoke = { open: false, invite: null };
      if (!invite) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.inviteItem, invite.id), { method: 'DELETE' });
        this.applyMembers(resp);
        this.$pb.toast('Invitation revoked.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- role / status / removal ----------
    changeRole: async function (member, role) {
      if (role === member.role) return;
      await this.patch(member, { role: role }, 'Role updated.');
    },
    toggleStatus: async function (member) {
      var next = member.status === 'active' ? 'inactive' : 'active';
      await this.patch(member, { status: next },
        next === 'active' ? 'Member reactivated.' : 'Member deactivated.');
    },
    askRemove: function (member) { this.remove = { open: true, member: member }; },
    confirmRemove: async function () {
      var member = this.remove.member;
      this.remove = { open: false, member: null };
      if (!member) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.member, member.id), { method: 'DELETE' });
        this.applyMembers(resp);
        // Said out loud, because it is the reassuring half of what just happened (§11).
        this.$pb.toast('Member removed. Their history is kept.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- inbox access ----------
    openAccess: function (member) {
      // Strings, because <pb-combo> compares option values as strings — numeric ids would
      // arrive already-chosen and show as unchecked.
      var chosen = (member.inbox_ids || []).map(String);
      this.access = { open: true, member: member, inboxIds: chosen, saving: false };
    },
    saveAccess: async function () {
      if (this.access.saving) return;
      this.access.saving = true;
      var member = this.access.member;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.member, member.id), {
          method: 'PATCH', body: { inbox_ids: this.access.inboxIds }
        });
        this.applyMembers(resp);
        this.access.open = false;
        this.$pb.toast('Inbox access updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.access.saving = false;
    },

    // ---------- shared ----------
    patch: async function (member, body, message) {
      if (this.busy) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.member, member.id), { method: 'PATCH', body: body });
        this.applyMembers(resp);
        this.$pb.toast(message);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.busy = false;
    },
    applyMembers: function (resp) {
      this.members = resp.members || this.members;
      // Who is still addable changes with every add and removal, so it comes back with them.
      if (resp.assignable) this.assignable = resp.assignable;
      if (resp.pending) this.pending = resp.pending;
    }
  },
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    '<div class="flex items-start justify-between gap-4">' +
    '<pb-section-head title="Members" ' +
    'desc="Help Desk membership is separate from workspace membership. Adding somebody here is what gives them Help Desk access — and their Help Desk role applies nowhere else."/>' +
    '<div class="flex items-center gap-2 shrink-0">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="openInvite">Invite coworker</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" @click="openAdd">Add member</button>' +
    '</div>' +
    '</div>' +

    // ---- member list ----
    '<pb-empty v-if="!members.length" title="No Help Desk members yet" ' +
    'subtitle="Nobody can open the Help Desk until they are added here, however they are set up in the workspace.">' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" @click="openAdd">Add member</button>' +
    '</pb-empty>' +

    '<div v-else class="border border-line rounded-xl overflow-hidden">' +
    '<div class="grid grid-cols-[minmax(0,2fr)_120px_minmax(0,1.4fr)_110px_140px] gap-3 px-4 py-2.5 bg-hover text-[11px] font-semibold text-faint uppercase tracking-wide">' +
    '<div>Member</div><div>Role</div><div>Inbox access</div><div>Status</div><div class="text-right">Actions</div></div>' +

    '<div v-for="m in members" :key="m.id" class="grid grid-cols-[minmax(0,2fr)_120px_minmax(0,1.4fr)_110px_140px] gap-3 px-4 py-3 border-t border-line items-center">' +

    '<div class="flex items-center gap-2.5 min-w-0">' +
    '<span class="h-7 w-7 rounded-full grid place-items-center text-white text-[11px] font-semibold shrink-0" ' +
    ':style="{background: $pb.avatarColor({id: m.user_id, name: m.name})}">{{ m.initial }}</span>' +
    '<div class="min-w-0"><div class="text-[13px] text-ink truncate">{{ m.name }}<span v-if="m.is_self" class="text-faint"> (you)</span></div>' +
    '<div class="text-[12px] text-sub truncate">{{ m.email }}</div></div></div>' +

    '<div>' +
    '<select v-if="m.can_manage" class="pb-input is-compact" :value="m.role" @change="changeRole(m, $event.target.value)">' +
    '<option v-for="r in assignableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>' +
    '<option v-if="m.role === \'admin\' && !canAssignAdmin" value="admin">{{ roleLabel(m.role) }}</option>' +
    '</select>' +
    '<span v-else class="text-[13px] text-ink">{{ m.role_label }}</span>' +
    '</div>' +

    '<div class="min-w-0 text-[12px]">' +
    '<span v-if="m.all_inboxes" class="text-sub">All inboxes</span>' +
    '<span v-else-if="!m.inboxes.length" class="text-danger">No inboxes</span>' +
    '<span v-else class="text-sub truncate block">{{ m.inboxes.join(", ") }}</span>' +
    '<button v-if="m.can_manage && !m.all_inboxes" class="text-[12px] text-brand font-semibold hover:underline mt-0.5" ' +
    '@click="openAccess(m)">Change</button>' +
    '</div>' +

    '<div>' +
    '<span :class="[\'inline-flex items-center gap-1.5 text-[12px]\', m.status === \'active\' ? \'text-ink\' : \'text-sub\']">' +
    '<span :class="[\'h-1.5 w-1.5 rounded-full\', m.status === \'active\' ? \'bg-emerald-500\' : \'bg-amber-500\']"></span>' +
    '{{ m.status === \'active\' ? \'Active\' : \'Deactivated\' }}</span></div>' +

    '<div class="flex items-center justify-end gap-2">' +
    '<template v-if="m.can_manage">' +
    '<button class="text-[12px] text-sub hover:text-ink" @click="toggleStatus(m)">' +
    '{{ m.status === \'active\' ? \'Deactivate\' : \'Activate\' }}</button>' +
    '<button class="text-[12px] text-danger hover:opacity-80" @click="askRemove(m)">Remove</button>' +
    '</template>' +
    '<span v-else class="text-[12px] text-faint">—</span>' +
    '</div>' +

    '</div></div>' +

    // ---- outstanding invitations ----
    '<div v-if="pending.length" class="mt-8">' +
    '<h2 class="text-[15px] font-semibold text-head">Pending invitations</h2>' +
    '<p class="text-[12px] text-sub mt-0.5">They become a workspace member and a Help Desk member when they accept.</p>' +
    '<div class="mt-3 border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="p in pending" :key="p.id" class="flex items-center gap-3 px-4 py-2.5">' +
    '<span class="text-[13px] text-ink flex-1 truncate">{{ p.email }}</span>' +
    '<span class="text-[12px] text-sub w-24 shrink-0">{{ p.role_label }}</span>' +
    '<span class="text-[12px] text-faint w-40 shrink-0 truncate">Invited {{ p.invited }} by {{ p.invited_by }}</span>' +
    '<button class="text-[12px] text-danger hover:opacity-80" @click="askRevoke(p)">Revoke</button>' +
    '</div></div></div>' +

    // ---- inboxes: a pointer, not a second place to manage them ----
    '<div class="mt-8 rounded-xl border border-line px-4 py-3 flex items-center gap-3">' +
    '<div class="min-w-0 flex-1"><div class="text-[13px] text-ink">Inbox access is granted from this screen; inboxes themselves are configured in Settings → Inboxes.</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Admins and managers see every inbox, including ones created later. Everybody else sees the ones named here.</p></div>' +
    '<a :href="endpoints.inboxesScreen" class="h-8 px-3 grid place-items-center rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0">Manage inboxes</a>' +
    '</div>' +

    // ---- add member modal ----
    '<pb-modal :open="add.open" title="Add Help Desk member" @close="add.open = false">' +
    '<div v-if="!assignable.length" class="text-[13px] text-sub">' +
    'Everybody in this workspace is already a Help Desk member. Invite a new coworker from Workspace settings first.' +
    '</div>' +
    '<div v-else class="space-y-4">' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Workspace member</label>' +
    '<pb-combo v-model="add.userId" :options="assignableOptions" placeholder="Search by name or email…" ' +
    ':invalid="!!add.errors.user_id"/>' +
    '<p v-if="add.errors.user_id" class="text-[12px] text-danger mt-1">{{ add.errors.user_id[0] }}</p></div>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Help Desk role</label>' +
    '<div class="space-y-1.5">' +
    '<label v-for="r in assignableRoles" :key="r.value" class="flex gap-2.5 items-start border border-line rounded-lg px-3 py-2 cursor-pointer" ' +
    ':class="{\'border-brand bg-sel\': add.role === r.value}">' +
    '<input type="radio" class="pb-check mt-0.5" :value="r.value" v-model="add.role"/>' +
    '<span><span class="text-[13px] font-medium text-ink block">{{ r.label }}</span>' +
    '<span class="text-[12px] text-sub">{{ r.desc }}</span></span></label>' +
    '</div></div>' +

    '<div v-if="addRoleNeedsInboxes">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbox access</label>' +
    '<p v-if="!hasInboxes" class="text-[12px] text-sub">There are no inboxes yet. Add one and you can give access to it.</p>' +
    '<pb-combo v-else v-model="add.inboxIds" :options="inboxOptions" multiple placeholder="Choose inboxes…"/>' +
    '<p class="text-[12px] text-sub mt-1">Without an inbox, this person can open the Help Desk and see nothing in it.</p>' +
    '</div>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="add.open = false">Cancel</button>' +
    '<button v-if="assignable.length" class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!add.userId || add.saving" @click="submitAdd">Add member</button>' +
    '</template></pb-modal>' +

    // ---- invite coworker modal ----
    '<pb-modal :open="invite.open" title="Invite a coworker" @close="invite.open = false">' +
    '<div class="space-y-4">' +
    '<p class="text-[13px] text-sub">They will be invited to {{ "" }}this workspace and to the Help Desk in one email. ' +
    'Somebody who is already in the workspace should be added as a member instead.</p>' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Email address</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!invite.errors.email}" v-model="invite.email" ' +
    'type="email" placeholder="name@company.com" @keyup.enter="submitInvite"/>' +
    '<p v-if="invite.errors.email" class="text-[12px] text-danger mt-1">{{ invite.errors.email[0] }}</p></div>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Help Desk role</label>' +
    '<div class="space-y-1.5">' +
    '<label v-for="r in assignableRoles" :key="r.value" class="flex gap-2.5 items-start border border-line rounded-lg px-3 py-2 cursor-pointer" ' +
    ':class="{\'border-brand bg-sel\': invite.role === r.value}">' +
    '<input type="radio" class="pb-check mt-0.5" :value="r.value" v-model="invite.role"/>' +
    '<span><span class="text-[13px] font-medium text-ink block">{{ r.label }}</span>' +
    '<span class="text-[12px] text-sub">{{ r.desc }}</span></span></label>' +
    '</div></div>' +

    '<div v-if="inviteRoleNeedsInboxes && hasInboxes">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbox access</label>' +
    '<pb-combo v-model="invite.inboxIds" :options="inboxOptions" multiple placeholder="Choose inboxes…"/>' +
    '</div>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="invite.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!invite.email || invite.saving" @click="submitInvite">Send invitation</button>' +
    '</template></pb-modal>' +

    // ---- inbox access modal ----
    '<pb-modal :open="access.open" :title="\'Inbox access\'" @close="access.open = false">' +
    '<div v-if="access.member">' +
    '<p class="text-[13px] text-sub mb-3">Which inboxes may {{ access.member.name }} open?</p>' +
    '<div v-if="!hasInboxes" class="text-[13px] text-sub">There are no inboxes yet.</div>' +
    '<pb-combo v-else v-model="access.inboxIds" :options="inboxOptions" multiple placeholder="Choose inboxes…"/>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="access.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="access.saving" @click="saveAccess">Save access</button>' +
    '</template></pb-modal>' +

    // ---- remove confirmation ----
    '<pb-confirm :open="remove.open" title="Remove from Help Desk" confirm-label="Remove" ' +
    ':message="remove.member ? remove.member.name + \' will lose Help Desk access. Their replies, notes and assignments are kept.\' : \'\'" ' +
    '@close="remove = {open: false, member: null}" @confirm="confirmRemove"/>' +

    '<pb-confirm :open="revoke.open" title="Revoke invitation" confirm-label="Revoke" ' +
    ':message="revoke.invite ? \'The invitation link sent to \' + revoke.invite.email + \' will stop working.\' : \'\'" ' +
    '@close="revoke = {open: false, invite: null}" @confirm="confirmRevoke"/>' +

    '</div>'
}, { root: 'help-desk-members-root' });
