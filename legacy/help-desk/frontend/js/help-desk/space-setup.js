/* Help Desk › Continue to Setup Inbox — the four-step stepper (Setup Inbox flow).
   ------------------------------------------------------------------
   Name the inbox → add the addresses customers write to → connect the email → invite the team.

   Two things shape this file:

   1. NOTHING IS HELD AS A DRAFT. Each step posts as it is completed and the server answers with
      the whole state, which this then renders. So the screen never has an opinion about what
      just happened — and closing the tab at step 3 leaves a real inbox with real addresses
      rather than a half-filled form nobody can get back to.

   2. STEP 3 CANNOT BE PROVEN HERE. The forwarding rule lives in the customer's mail provider,
      and the only evidence it works is a message arriving — possibly days later. So Continue
      marks the step read rather than verified, and the connection status goes on telling the
      truth afterwards on the inbox's own screen.
   ------------------------------------------------------------------ */
PB.boot('help-desk-space-setup', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      space: b.space || {},
      inbox: b.inbox || null,
      addresses: b.addresses || [],
      team: b.team || [],
      roles: b.roles || [],
      endpoints: b.endpoints || {},
      // Opens where the server says the reader got to, not at step 1.
      step: (b.space && b.space.setup_step) || 1,
      nameDraft: (b.inbox && b.inbox.name) || '',
      address: { address: '', label: '', editing: null },
      invite: { email: '', role: 'agent' },
      errors: {},
      busy: false,
      steps: [
        { n: 1, label: 'Inbox name' },
        { n: 2, label: 'Your inboxes' },
        { n: 3, label: 'Connect email' },
        { n: 4, label: 'Invite team' }
      ]
    };
  },
  computed: {
    roleOptions: function () {
      return this.roles.map(function (r) { return { value: r.value, label: r.label, desc: r.desc }; });
    },
    // Step 2 is done when at least one address is on the list — the step exists to collect them.
    canLeaveAddresses: function () { return this.addresses.length > 0; },
    connectedCount: function () {
      return this.addresses.filter(function (a) { return a.connected; }).length;
    }
  },
  methods: {
    // A step is reachable once it has been reached before: the stepper lets you go back to
    // change something without letting you skip forward past work that has not been done.
    reached: function (n) { return n <= (this.space.setup_step || 1); },
    go: function (n) { if (this.reached(n)) { this.step = n; this.errors = {}; } },

    apply: function (resp) {
      this.space = resp.space || this.space;
      this.inbox = resp.inbox || this.inbox;
      this.addresses = resp.addresses || [];
      this.team = resp.team || [];
      this.errors = {};
    },
    fail: function (e) {
      this.errors = this.$pb.fieldErrors(e);
      this.$pb.toast(this.$pb.firstError(e), 'error');
    },

    copy: function (text, what) {
      var self = this;
      if (!text) return;
      var done = function () { self.$pb.toast((what || 'Address') + ' copied.'); };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {
          self.$pb.toast('Could not copy — select the text and copy it manually.', 'error');
        });
        return;
      }
      var field = document.createElement('textarea');
      field.value = text;
      document.body.appendChild(field);
      field.select();
      try { document.execCommand('copy'); done(); } catch (err) { self.$pb.toast('Could not copy.', 'error'); }
      document.body.removeChild(field);
    },

    // ---- step 1 ----
    saveName: async function () {
      if (this.busy || !String(this.nameDraft || '').trim()) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.name, {
          method: 'POST', body: { name: String(this.nameDraft).trim() }
        });
        this.apply(resp);
        this.step = 2;
      } catch (e) { this.fail(e); }
      this.busy = false;
    },

    // ---- step 2 ----
    addAddress: async function () {
      if (this.busy || !String(this.address.address || '').trim()) return;
      this.busy = true;
      var editing = this.address.editing;
      try {
        var body = {
          address: String(this.address.address).trim(),
          label: String(this.address.label || '').trim() || null
        };
        var resp = editing
          ? await this.$pb.api(this.$pb.withId(this.endpoints.address, editing), { method: 'PATCH', body: body })
          : await this.$pb.api(this.endpoints.addresses, { method: 'POST', body: body });
        this.apply(resp);
        this.address = { address: '', label: '', editing: null };
      } catch (e) { this.fail(e); }
      this.busy = false;
    },
    editAddress: function (row) {
      this.address = { address: row.address, label: row.label || '', editing: row.id };
    },
    cancelEdit: function () { this.address = { address: '', label: '', editing: null }; },
    removeAddress: async function (row) {
      if (this.busy) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.address, row.id), { method: 'DELETE' });
        this.apply(resp);
        if (this.address.editing === row.id) this.cancelEdit();
      } catch (e) { this.fail(e); }
      this.busy = false;
    },
    leaveAddresses: function () { if (this.canLeaveAddresses) this.step = 3; },

    // ---- step 3 ----
    verify: async function (row) {
      if (this.busy) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.verify, {
          method: 'POST', body: { email_address_id: row.id }
        });
        this.apply(resp);
        this.$pb.toast('Send a message to ' + row.address + '. It appears here once it reaches us.');
      } catch (e) { this.fail(e); }
      this.busy = false;
    },
    leaveConnect: async function () {
      if (this.busy) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.connect, { method: 'POST' });
        this.apply(resp);
        this.step = 4;
      } catch (e) { this.fail(e); }
      this.busy = false;
    },

    // ---- step 4 ----
    addTeamMember: async function () {
      if (this.busy || !String(this.invite.email || '').trim()) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.team, {
          method: 'POST',
          body: { email: String(this.invite.email).trim(), role: this.invite.role }
        });
        this.apply(resp);
        this.$pb.toast(resp.invited ? ('Invitation sent to ' + resp.invited + '.') : (resp.added + ' added.'));
        this.invite.email = '';
      } catch (e) { this.fail(e); }
      this.busy = false;
    },
    finish: async function () {
      if (this.busy) return;
      this.busy = true;
      try {
        var resp = await this.$pb.api(this.endpoints.finish, { method: 'POST' });
        window.location = resp.redirect || this.endpoints.cancel;
      } catch (e) { this.fail(e); this.busy = false; }
    }
  },
  template:
    '<div class="max-w-[760px] mx-auto px-5 sm:px-8 py-8">' +

    // ---- header ----
    '<div class="flex items-center gap-3 mb-6">' +
    '<div class="h-9 w-9 shrink-0 rounded-lg grid place-items-center text-white text-[14px] font-semibold" ' +
    ':style="{ backgroundColor: space.color || \'#64748B\' }">{{ space.initial }}</div>' +
    '<div><div class="text-[12px] text-sub">{{ space.name }}</div>' +
    '<h1 class="text-[20px] font-bold text-head">Set up your inbox</h1></div>' +
    '</div>' +

    // ---- the stepper: where you are, what is done, what is left ----
    '<ol class="flex items-center gap-2 mb-8">' +
    '<li v-for="(s, i) in steps" :key="s.n" class="flex items-center gap-2 min-w-0">' +
    '<button class="_moretogether-step" :class="{ \'is-current\': step === s.n, \'is-done\': reached(s.n) && step !== s.n }" ' +
    ':disabled="!reached(s.n)" @click="go(s.n)">' +
    '<span class="_moretogether-step__n">{{ s.n }}</span>' +
    '<span class="truncate">{{ s.label }}</span>' +
    '</button>' +
    '<span v-if="i < steps.length - 1" class="text-faint text-[12px]">›</span>' +
    '</li></ol>' +

    // ================= step 1 =================
    '<section v-if="step === 1">' +
    '<h2 class="text-[16px] font-semibold text-head">Name your inbox</h2>' +
    '<p class="text-[13px] text-sub mt-1">Give this inbox a clear name so your team can easily identify where conversations belong.</p>' +

    '<div class="mt-5">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbox name</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.name}" v-model="nameDraft" ' +
    'placeholder="Customer Support" @keyup.enter="saveName"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<p class="text-[12px] text-sub mt-1.5">For example: Customer Support, Partner Support, Retail Support, Billing Support.</p>' +
    '</div>' +

    '<div class="flex items-center justify-end gap-2 mt-8">' +
    '<a :href="endpoints.cancel" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover flex items-center">Cancel</a>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!nameDraft || busy" @click="saveName">Continue</button>' +
    '</div></section>' +

    // ================= step 2 =================
    '<section v-if="step === 2">' +
    '<h2 class="text-[16px] font-semibold text-head">Your inboxes</h2>' +
    '<p class="text-[13px] text-sub mt-1">An inbox is where conversations arrive. One is enough to start — rename it to match how your team already talks about support.</p>' +

    '<div class="mt-5 flex flex-col sm:flex-row gap-3 items-end">' +
    '<div class="flex-1 w-full"><label class="block text-[12px] font-semibold text-ink mb-1">Email address</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.address}" v-model="address.address" ' +
    'type="email" placeholder="support@company.com" @keyup.enter="addAddress"/></div>' +
    '<div class="flex-1 w-full"><label class="block text-[12px] font-semibold text-ink mb-1">Name</label>' +
    '<input class="pb-input" v-model="address.label" placeholder="Customer Support" @keyup.enter="addAddress"/></div>' +
    '<button class="h-11 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!address.address || busy" @click="addAddress">{{ address.editing ? \'Save\' : \'Add\' }}</button>' +
    '</div>' +
    '<p v-if="errors.address" class="text-[12px] text-danger mt-1">{{ errors.address[0] }}</p>' +
    '<button v-if="address.editing" class="text-[12px] text-sub hover:text-ink mt-1.5" @click="cancelEdit">Cancel edit</button>' +

    '<div v-if="addresses.length" class="mt-5 border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="a in addresses" :key="a.id" class="flex items-center gap-3 px-4 py-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[13px] font-medium text-ink truncate">{{ a.name }}</div>' +
    '<div class="text-[12px] text-sub _moretogether-break">{{ a.address }}</div>' +
    '</div>' +
    // Icons, each named for the pointer and for everything else.
    '<button class="_moretogether-iconbtn" data-tip="Edit" aria-label="Edit address" @click="editAddress(a)">' +
    wiIcon('pen', 15) + '</button>' +
    '<button class="_moretogether-iconbtn _moretogether-iconbtn--danger" data-tip="Remove" aria-label="Remove address" ' +
    ':disabled="busy" @click="removeAddress(a)">' + wiIcon('trash-can', 15) + '</button>' +
    '</div></div>' +

    '<p v-else class="text-[12px] text-sub mt-4">Add the address your customers already write to. You can add more than one.</p>' +

    '<div class="flex items-center justify-end gap-2 mt-8">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(1)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!canLeaveAddresses || busy" @click="leaveAddresses">Continue</button>' +
    '</div></section>' +

    // ================= step 3 =================
    '<section v-if="step === 3 && inbox">' +
    '<h2 class="text-[16px] font-semibold text-head">Connect your email</h2>' +
    '<p class="text-[13px] text-sub mt-1">Forward your existing support email to the Project Block inbound address below. Incoming messages will automatically arrive in this inbox.</p>' +

    '<div class="mt-5 border border-line rounded-xl p-4">' +
    '<div class="text-[12px] font-semibold text-ink">Project Block inbound address</div>' +
    '<div class="mt-2 flex items-center gap-2">' +
    '<code class="flex-1 min-w-0 truncate rounded-md bg-hover border border-line px-3 h-9 flex items-center text-[13px] text-ink">' +
    '{{ inbox.inbound_address }}</code>' +
    '<button class="h-9 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    '@click="copy(inbox.inbound_address, \'Address\')">Copy inbound address</button>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-2">Generated for this inbox and read-only. It never changes when you rename the inbox or move it to another space.</p>' +
    '<button class="text-[12px] font-semibold text-brand hover:opacity-80 mt-2" ' +
    '@click="copy(inbox.instructions, \'Instructions\')">Copy forwarding instructions</button>' +
    '</div>' +

    '<div class="mt-4 space-y-2">' +
    '<div v-for="a in addresses" :key="a.id" class="border border-line rounded-xl px-4 py-3">' +
    '<div class="flex items-center gap-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[13px] text-ink _moretogether-break">{{ a.address }} <span class="text-faint">→</span> ' +
    '<span class="text-sub _moretogether-break">{{ inbox.inbound_address }}</span></div>' +
    '<div class="text-[12px] text-sub mt-0.5">{{ a.status_hint }}</div>' +
    '<div v-if="a.verified_at" class="text-[12px] text-success mt-0.5">Connection verified {{ a.verified_at }}</div>' +
    '</div>' +
    '<span class="_moretogether-badge shrink-0" :class="a.connected ? \'_moretogether-badge--ok\' : \'\'">{{ a.status_label }}</span>' +
    '<button v-if="!a.connected" class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    ':disabled="busy" @click="verify(a)">Verify connection</button>' +
    '</div></div></div>' +

    '<p class="text-[12px] text-sub mt-3">Send a test email to your existing support address. Once Project Block receives the forwarded message, the connection is verified automatically — you do not have to wait here for it.</p>' +

    '<div class="flex items-center justify-end gap-2 mt-8">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(2)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="busy" @click="leaveConnect">Continue</button>' +
    '</div></section>' +

    // ================= step 4 =================
    '<section v-if="step === 4">' +
    '<h2 class="text-[16px] font-semibold text-head">Invite your team</h2>' +
    '<p class="text-[13px] text-sub mt-1">Invite the people who will manage conversations in this space. They get access to this space\'s inboxes and nothing else.</p>' +

    '<div class="mt-5 flex flex-col sm:flex-row gap-3 items-end">' +
    '<div class="flex-1 w-full"><label class="block text-[12px] font-semibold text-ink mb-1">Email address</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.email}" v-model="invite.email" ' +
    'type="email" placeholder="agent@company.com" @keyup.enter="addTeamMember"/></div>' +
    '<div class="w-full sm:w-44"><label class="block text-[12px] font-semibold text-ink mb-1">Role</label>' +
    '<pb-combo v-model="invite.role" :options="roleOptions" placeholder="Agent"/></div>' +
    '<button class="h-11 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!invite.email || busy" @click="addTeamMember">Add</button>' +
    '</div>' +
    '<p v-if="errors.email" class="text-[12px] text-danger mt-1">{{ errors.email[0] }}</p>' +
    '<p v-if="errors.role" class="text-[12px] text-danger mt-1">{{ errors.role[0] }}</p>' +

    '<div v-if="team.length" class="mt-5 border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="m in team" :key="m.id" class="flex items-center gap-3 px-4 py-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[13px] text-ink truncate">{{ m.name }}</div>' +
    '<div class="text-[12px] text-sub truncate">{{ m.email }}</div>' +
    '</div>' +
    '<span v-if="m.pending" class="text-[11px] text-faint shrink-0">Invited</span>' +
    // Said out loud: these two roles reach every space by definition, so "this space only"
    // would be untrue for them.
    '<span v-if="m.all_spaces" class="text-[11px] text-faint shrink-0" data-tip="This role reaches every space">All spaces</span>' +
    '<span class="text-[12px] text-sub shrink-0">{{ m.role_label }}</span>' +
    '</div></div>' +

    '<div class="flex items-center justify-end gap-2 mt-8">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="go(3)">Back</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="busy" @click="finish">Finish setup</button>' +
    '</div></section>' +

    '</div>'
}, { root: 'help-desk-space-setup-root' });
