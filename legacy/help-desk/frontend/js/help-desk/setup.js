/* Help Desk — first-time setup wizard (docs/features/help-desk.md, FR-1.3).
   ------------------------------------------------------------------
   §4's sequence, minus the step that already happened: enabling is a workspace setting, so
   this picks up at "create first inbox" and walks Name → Inboxes → Team → Done.

   Every step writes THROUGH THE SAME ENDPOINTS the permanent screens use. A wizard with its own
   save paths is a second set of rules that can disagree with the ones the screens enforce, and
   it also means nothing here is lost if somebody closes the tab at step two: each step commits
   as it is completed, rather than everything landing at the end.
   ------------------------------------------------------------------ */
PB.boot('help-desk-setup', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      step: 1,
      name: b.name || '',
      members: b.members || [],
      pending: b.pending || [],
      inboxes: b.inboxes || [],
      assignable: b.assignable || [],
      roles: b.roles || [],
      canAssignAdmin: !!b.canAssignAdmin,
      endpoints: b.endpoints || {},
      saving: false,
      errors: {},
      inboxName: '',
      // Which way of adding somebody is showing. Both exist because a workspace usually has
      // people in it already and support teams usually also need somebody new.
      mode: 'existing',
      person: { userId: '', email: '', role: 'agent', inboxIds: [] }
    };
  },
  computed: {
    steps: function () {
      return [
        { n: 1, label: 'Name' },
        { n: 2, label: 'Inboxes' },
        { n: 3, label: 'Team' },
        { n: 4, label: 'Done' }
      ];
    },
    assignableRoles: function () {
      var self = this;
      return this.roles.filter(function (r) { return r.value !== 'admin' || self.canAssignAdmin; });
    },
    assignableOptions: function () {
      return this.assignable.map(function (u) {
        return { value: String(u.id), label: u.name, desc: u.email, initial: u.initial };
      });
    },
    inboxOptions: function () {
      return this.inboxes.map(function (i) { return { value: String(i.id), label: i.name }; });
    },
    roleNeedsInboxes: function () {
      var found = this.roles.filter(function (r) { return r.value === this.person.role; }.bind(this))[0];
      return !(found && found.all_inboxes);
    },
    canAddPerson: function () {
      return this.mode === 'existing' ? !!this.person.userId : !!String(this.person.email || '').trim();
    }
  },
  methods: {
    go: function (n) { this.step = n; },

    // ---- step 1: the Help Desk's own name ----
    saveName: async function () {
      var name = String(this.name || '').trim();
      if (!name || this.saving) return;
      this.saving = true; this.errors = {};
      try {
        await this.$pb.api(this.endpoints.settings, { method: 'PATCH', body: { name: name } });
        this.step = 2;
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },

    // ---- step 2: inboxes ----
    addInbox: async function () {
      var name = String(this.inboxName || '').trim();
      if (!name || this.saving) return;
      this.saving = true; this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.inboxes, { method: 'POST', body: { name: name } });
        this.inboxes = resp.inboxes || this.inboxes;
        this.inboxName = '';
        this.$pb.toast('Inbox created.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    renameFirstInbox: async function (inbox, name) {
      name = String(name || '').trim();
      if (!name || name === inbox.name) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.inbox, inbox.id), { method: 'PATCH', body: { name: name } });
        this.inboxes = resp.inboxes || this.inboxes;
        this.$pb.toast('Inbox renamed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---- step 3: team + roles ----
    addPerson: async function () {
      if (!this.canAddPerson || this.saving) return;
      this.saving = true; this.errors = {};

      var existing = this.mode === 'existing';
      var url = existing ? this.endpoints.store : this.endpoints.invite;
      var body = { role: this.person.role, inbox_ids: this.person.inboxIds };
      if (existing) { body.user_id = this.person.userId; } else { body.email = String(this.person.email).trim(); }

      try {
        var resp = await this.$pb.api(url, { method: 'POST', body: body });
        this.members = resp.members || this.members;
        this.pending = resp.pending || this.pending;
        this.assignable = resp.assignable || this.assignable;
        this.person = { userId: '', email: '', role: this.person.role, inboxIds: [] };
        this.$pb.toast(existing ? 'Member added.' : 'Invitation sent.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },

    // ---- step 4 ----
    finish: async function () {
      if (this.saving) return;
      this.saving = true;
      try {
        var resp = await this.$pb.api(this.endpoints.complete, { method: 'POST' });
        window.location.href = resp.redirect || this.endpoints.done;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); this.saving = false; }
    }
  },
  template:
    '<div class="max-w-[720px] mx-auto px-5 sm:px-8 py-10">' +

    '<h1 class="text-[22px] font-bold text-head">Set up your Help Desk</h1>' +
    '<p class="text-[13px] text-sub mt-1">Four steps. Everything here can be changed later in Help Desk settings.</p>' +

    // Progress. Steps already passed are clickable; steps ahead are not, so the sequence
    // cannot be skipped into a state it has not prepared.
    '<div class="flex items-center gap-2 mt-6">' +
    '<template v-for="s in steps" :key="s.n">' +
    '<button :disabled="s.n > step" @click="go(s.n)" ' +
    ':class="[\'flex items-center gap-2 px-2.5 h-8 rounded-md text-[12px]\', s.n === step ? \'bg-sel text-brand font-semibold\' : (s.n < step ? \'text-ink hover:bg-hover\' : \'text-faint cursor-default\')]">' +
    '<span :class="[\'h-5 w-5 rounded-full grid place-items-center text-[11px] font-bold\', s.n <= step ? \'bg-brand text-white\' : \'bg-hover text-faint\']">{{ s.n }}</span>' +
    '{{ s.label }}</button>' +
    '<span v-if="s.n < 4" class="h-px w-4 bg-line"></span>' +
    '</template></div>' +

    // ---- step 1 ----
    '<div v-if="step === 1" class="mt-8 border border-line rounded-xl p-5">' +
    '<h2 class="text-[15px] font-semibold text-head">What is this Help Desk called?</h2>' +
    '<p class="text-[12px] text-sub mt-0.5">It appears wherever the Help Desk is referred to. Your workspace name is a fine answer.</p>' +
    '<input class="pb-input mt-3" :class="{\'is-error\': !!errors.name}" v-model="name" @keyup.enter="saveName" placeholder="Support"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<div class="mt-4 flex justify-end">' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!name || saving" @click="saveName">Continue</button></div></div>' +

    // ---- step 2 ----
    '<div v-if="step === 2" class="mt-8 border border-line rounded-xl p-5">' +
    '<h2 class="text-[15px] font-semibold text-head">Your inboxes</h2>' +
    '<p class="text-[12px] text-sub mt-0.5">An inbox is where conversations arrive. One is enough to start — rename it to match how your team already talks about support.</p>' +
    '<div class="mt-3 space-y-2">' +
    '<div v-for="i in inboxes" :key="i.id" class="flex items-center gap-2">' +
    '<input class="pb-input" :value="i.name" @change="renameFirstInbox(i, $event.target.value)"/>' +
    '</div></div>' +
    '<div class="mt-3 flex items-center gap-2">' +
    '<input class="pb-input" v-model="inboxName" placeholder="Add another inbox — Billing, Sales…" @keyup.enter="addInbox"/>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover shrink-0 disabled:opacity-50" ' +
    ':disabled="!inboxName || saving" @click="addInbox">Add</button></div>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<div class="mt-4 flex justify-between">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(1)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" @click="go(3)">Continue</button>' +
    '</div></div>' +

    // ---- step 3 ----
    '<div v-if="step === 3" class="mt-8 border border-line rounded-xl p-5">' +
    '<h2 class="text-[15px] font-semibold text-head">Who works here?</h2>' +
    '<p class="text-[12px] text-sub mt-0.5">Help Desk membership is separate from workspace membership — nobody can open the Help Desk until they are added here.</p>' +

    '<div class="mt-3 flex gap-1 p-1 bg-hover rounded-lg w-fit">' +
    '<button :class="[\'h-7 px-3 rounded-md text-[12px]\', mode === \'existing\' ? \'bg-white text-ink font-semibold shadow-sm\' : \'text-sub\']" @click="mode = \'existing\'">Workspace member</button>' +
    '<button :class="[\'h-7 px-3 rounded-md text-[12px]\', mode === \'invite\' ? \'bg-white text-ink font-semibold shadow-sm\' : \'text-sub\']" @click="mode = \'invite\'">Invite coworker</button>' +
    '</div>' +

    '<div class="mt-3 space-y-3">' +
    '<div v-if="mode === \'existing\'">' +
    '<pb-combo v-if="assignable.length" v-model="person.userId" :options="assignableOptions" placeholder="Search by name or email…"/>' +
    '<p v-else class="text-[12px] text-sub">Everybody in this workspace is already a Help Desk member.</p>' +
    '</div>' +
    '<div v-else>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.email}" v-model="person.email" type="email" placeholder="name@company.com"/>' +
    '<p v-if="errors.email" class="text-[12px] text-danger mt-1">{{ errors.email[0] }}</p>' +
    '</div>' +

    '<div class="flex flex-wrap gap-1.5">' +
    '<button v-for="r in assignableRoles" :key="r.value" @click="person.role = r.value" ' +
    ':class="[\'h-8 px-3 rounded-md border text-[12px]\', person.role === r.value ? \'border-brand bg-sel text-brand font-semibold\' : \'border-line text-ink hover:bg-hover\']">' +
    '{{ r.label }}</button></div>' +

    '<div v-if="roleNeedsInboxes && inboxes.length">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbox access</label>' +
    '<pb-combo v-model="person.inboxIds" :options="inboxOptions" multiple placeholder="Choose inboxes…"/>' +
    '</div>' +

    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50" ' +
    ':disabled="!canAddPerson || saving" @click="addPerson">{{ mode === \'existing\' ? \'Add to Help Desk\' : \'Send invitation\' }}</button>' +
    '</div>' +

    '<div v-if="members.length || pending.length" class="mt-4 border-t border-line pt-3 space-y-1.5">' +
    '<div v-for="m in members" :key="\'m\' + m.id" class="flex items-center gap-2 text-[13px]">' +
    '<span class="h-6 w-6 rounded-full grid place-items-center text-white text-[10px] font-semibold" ' +
    ':style="{background: $pb.avatarColor({id: m.user_id, name: m.name})}">{{ m.initial }}</span>' +
    '<span class="text-ink">{{ m.name }}</span><span class="text-sub">{{ m.role_label }}</span></div>' +
    '<div v-for="p in pending" :key="\'p\' + p.id" class="flex items-center gap-2 text-[13px]">' +
    '<span class="h-6 w-6 rounded-full bg-hover grid place-items-center text-faint text-[10px] font-semibold">@</span>' +
    '<span class="text-ink">{{ p.email }}</span><span class="text-sub">{{ p.role_label }}</span>' +
    '<span class="text-[11px] text-faint">invited</span></div>' +
    '</div>' +

    '<div class="mt-4 flex justify-between">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(2)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" @click="go(4)">Continue</button>' +
    '</div></div>' +

    // ---- step 4 ----
    '<div v-if="step === 4" class="mt-8 border border-line rounded-xl p-5">' +
    '<h2 class="text-[15px] font-semibold text-head">You are set up</h2>' +
    '<p class="text-[13px] text-sub mt-1">{{ name }} has {{ inboxes.length }} inbox<span v-if="inboxes.length !== 1">es</span> ' +
    'and {{ members.length }} member<span v-if="members.length !== 1">s</span>' +
    '<span v-if="pending.length">, with {{ pending.length }} invitation<span v-if="pending.length !== 1">s</span> outstanding</span>.</p>' +
    '<p class="text-[12px] text-faint mt-3">Conversations, email channels and routing arrive in the next release. ' +
    'Members, roles and inbox access are managed in Help Desk settings from now on.</p>' +
    '<div class="mt-4 flex justify-between">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(3)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="saving" @click="finish">Finish setup</button>' +
    '</div></div>' +

    '</div>'
}, { root: 'help-desk-setup-root' });
