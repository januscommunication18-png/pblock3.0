/* Help Center — six-step Space onboarding (docs/features/help-center.md, P2 §2–§30).
   ------------------------------------------------------------------
   Create Your Space → Invite Your Support Group → Set Up Your Inbox → Configure Your Workflow
   → Conversation Settings → Review & Confirm.

   ONE component for all six, because they are one flow over one draft. The SERVER owns that
   draft (HC-D11): every Continue posts its step and gets the merged draft back, and nothing is
   created until Step 6's "Create Help Desk". So a reload — or a different machine tomorrow —
   resumes where the user stopped, and abandoning the wizard leaves no Space behind.

   Back is a POST too, not a local `step--`: P2 §2 requires data to survive moving backward, so
   what is on screen is sent before the step changes. Review's per-section Edit (§27) is the
   same call with a different destination.

   Supersedes setup.js, which is the previous three-step wizard and is no longer loaded.
   ------------------------------------------------------------------ */
PB.boot('help-center-setup', {
  props: { bootstrap: Object },

  /*
   * One status card, used three times: for Open, for each custom status, and for Closed.
   *
   * A component rather than three copies of the markup, because the only thing that differs
   * between them is whether the row is a SYSTEM one — and that difference is four small
   * conditionals, where three copies would be three places to fix every future change.
   *
   * The `status` object is mutated in place. That is deliberate: it is the same object the
   * parent holds in `statuses`, so the fields the user edits are already where the payload
   * reads them. Ordering and deletion are emitted instead, because those are facts about the
   * LIST, which the card does not own.
   */
  components: {
    'hc-status-card': {
      props: {
        status: { type: Object, required: true },
        colors: { type: Array, default: function () { return []; } },
        responsibilities: { type: Array, default: function () { return []; } },
        assignees: { type: Array, default: function () { return []; } }
      },
      emits: ['move', 'remove'],
      computed: {
        system: function () { return !!this.status.system_key; },
        lockLabel: function () {
          return this.status.system_key === 'open' ? 'Always Active' : 'Always Inactive';
        }
      },
      methods: {
        icon: function (name, size) { return window.wiIcon ? window.wiIcon(name, size || 16) : ''; },
        // pb-combo works in strings; the payload wants integers.
        assigneeValues: function () { return (this.status.default_assignees || []).map(String); },
        setAssignees: function (values) {
          this.status.default_assignees = (values || []).map(function (v) { return parseInt(v, 10); });
        }
      },
      template: [
        '<div :class="[\'rounded-lg border px-4 py-3\', system ? \'border-line bg-[#f9fafb]\' : \'border-line bg-white\']">',
        '  <div class="flex items-start gap-3">',
        '    <div class="pt-2 text-faint" :class="system ? \'opacity-40\' : \'cursor-move\'" v-html="icon(system ? \'lock\' : \'grip-vertical\', 14)"></div>',
        '    <div class="flex-1 min-w-0 grid gap-3 sm:grid-cols-2">',
        '      <div>',
        '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Status Name</label>',
        '        <input v-if="!system" v-model="status.name" maxlength="60" class="pb-input w-full" placeholder="Waiting on Customer" />',
        '        <div v-else class="h-9 flex items-center gap-2 text-[13px] font-semibold text-ink">{{ status.name }}<span v-html="icon(\'lock\', 11)" class="text-faint"></span></div>',
        '      </div>',
        '      <div>',
        '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Responsibility</label>',
        '        <pb-combo v-model="status.responsibility" :options="responsibilities" :searchable="false" />',
        '      </div>',
        '      <div>',
        '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Colour</label>',
        '        <pb-color-picker v-model="status.color" :presets="colors" />',
        '      </div>',
        '      <div>',
        '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Default Assignees</label>',
        '        <pb-combo :model-value="assigneeValues()" @update:model-value="setAssignees($event)" :options="assignees" :multiple="true" placeholder="Anyone" />',
        '      </div>',
        '    </div>',
        '    <div class="w-[136px] shrink-0 text-right">',
        '      <div class="text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Preview</div>',
        '      <span class="inline-flex items-center h-6 px-2 rounded-full text-[11px] font-semibold text-white" :style="{ background: status.color }">{{ status.name || \'Untitled\' }}</span>',
        /* Open is always Active and Closed always Inactive (P2 §16), so neither gets a toggle. */
        '      <div class="mt-2 flex items-center justify-end">',
        '        <pb-toggle v-if="!system" :model-value="status.is_active" @update:model-value="status.is_active = $event" />',
        '        <span v-else class="text-[11px] text-faint">{{ lockLabel }}</span>',
        '      </div>',
        /* Only custom statuses can be reordered or deleted (P2 §16). */
        '      <div v-if="!system" class="mt-2 flex items-center justify-end gap-1">',
        '        <button type="button" @click="$emit(\'move\', -1)" aria-label="Move up" class="_moretogether-iconbtn" v-html="icon(\'arrow-up\', 12)"></button>',
        '        <button type="button" @click="$emit(\'move\', 1)" aria-label="Move down" class="_moretogether-iconbtn" v-html="icon(\'arrow-down\', 12)"></button>',
        '        <button type="button" @click="$emit(\'remove\')" aria-label="Delete status" class="_moretogether-iconbtn _moretogether-iconbtn--danger" v-html="icon(\'trash-can\', 12)"></button>',
        '      </div>',
        '    </div>',
        '  </div>',
        '</div>'
      ].join('\n')
    }
  },

  data: function () {
    var b = this.bootstrap || {};
    var d = b.draft || {};
    var space = d.space || {};
    var inbox = d.inbox || {};
    var settings = d.settings || {};

    return {
      step: b.step || 1,
      totalSteps: b.totalSteps || 6,
      steps: b.steps || [],
      canCreate: !!b.canCreate,

      // ---- reference data ----
      typeSuggestions: b.typeSuggestions || [],
      typeMax: b.typeMax || 8,
      typeMaxLength: b.typeMaxLength || 40,
      groupMax: b.groupMax || 20,
      leads: b.leads || [],
      roles: b.roles || [],
      providers: b.providers || [],
      statusColors: b.statusColors || [],
      statusMax: b.statusMax || 20,
      responsibilities: b.responsibilities || [],
      metadataOptions: b.metadata || [],
      destinations: b.destinations || [],
      inboundDomain: b.inboundDomain || 'inbound.projectblock.app',
      inboundPrefix: b.inboundPrefix || 'inbox',
      endpoints: b.endpoints || {},
      urls: b.urls || {},

      // ---- the form, one object per step, seeded from the draft ----
      space: {
        name: space.name || '',
        description: space.description || '',
        types: (space.types || []).slice(),
        department_groups: (space.department_groups || []).slice(),
        lead_user_id: space.lead_user_id ? String(space.lead_user_id) : ''
      },

      members: ((d.team || {}).members || []).slice(),
      memberForm: { email: '', role: '', department_groups: [] },
      memberError: '',

      inbox: {
        name: inbox.name || '',
        addresses: (inbox.addresses || []).slice(),
        inbound_id: inbox.inbound_id || ''
      },
      addressForm: { email: '', name: '' },
      addressError: '',
      addingAddress: false,

      /* `_uid` is a CLIENT-ONLY key. Index keys break the moment a row is dragged or deleted
         — Vue reuses the DOM node and the text you typed follows the wrong card. The server
         rebuilds each row field by field (WorkflowStepRequest::prepareForValidation), so this
         never reaches the database. */
      statuses: ((d.workflow || {}).statuses || []).map(function (s, i) {
        return Object.assign({ _uid: 'seed-' + i }, s);
      }),
      nextUid: 1,
      dragKey: null,

      settings: {
        metadata: Object.assign({}, settings.metadata || {}),
        auto_bcc_enabled: !!settings.auto_bcc_enabled,
        auto_bcc_email: settings.auto_bcc_email || '',
        reassign_enabled: !!settings.reassign_enabled,
        reassign_hours: settings.reassign_hours || 0,
        reassign_minutes: settings.reassign_minutes || 0,
        reassign_destination: settings.reassign_destination || 'unassigned',
        auto_follow_mentions: settings.auto_follow_mentions !== false
      },

      openProvider: '',
      copied: false,
      saving: false,
      errors: {}
    };
  },

  computed: {
    leadOptions: function () {
      return this.leads.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
      });
    },

    roleOptions: function () {
      return this.roles.map(function (r) { return { value: r.value, label: r.label }; });
    },

    /* Department Groups defined in Step 1 are what Step 2 assigns from (P2 §6). */
    groupOptions: function () {
      return this.space.department_groups.map(function (g) { return { value: g, label: g }; });
    },

    /* Somebody already in the list is not offered again. */
    coworkerOptions: function () {
      var taken = this.members.map(function (m) { return String(m.email).toLowerCase(); });
      return this.leads
        .filter(function (p) { return taken.indexOf(String(p.email).toLowerCase()) === -1; })
        .map(function (p) {
          return { value: p.email, label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
        });
    },

    openStatus: function () { return this.statuses.find(function (s) { return s.system_key === 'open'; }); },
    closedStatus: function () { return this.statuses.find(function (s) { return s.system_key === 'closed'; }); },
    customStatuses: function () { return this.statuses.filter(function (s) { return !s.system_key; }); },

    /* Open → custom → Closed (P2 §16). The server normalizes this too; doing it here as well
       means the two system rows cannot drift out of place on screen either. */
    orderedStatuses: function () {
      var out = [];
      if (this.openStatus) out.push(this.openStatus);
      out = out.concat(this.customStatuses);
      if (this.closedStatus) out.push(this.closedStatus);
      return out;
    },

    inboundAddress: function () {
      /* Prefix and domain both come from the server (config/help-center.php), so the address
         shown here is the one HelpCenterInbox::inboundAddress() will compose. Hardcoding
         either would silently disagree the moment a deployment changed it. */
      return this.inbox.inbound_id
        ? this.inboundPrefix + '-' + this.inbox.inbound_id + '@' + this.inboundDomain
        : '';
    },

    leadName: function () {
      var self = this;
      var hit = this.leads.find(function (p) { return String(p.id) === String(self.space.lead_user_id); });
      return hit ? hit.name : '—';
    },

    canContinue: function () {
      if (this.saving) return false;
      if (this.step === 1) {
        return !!String(this.space.name || '').trim()
          && this.space.types.length > 0
          && !!this.space.lead_user_id;
      }
      if (this.step === 3) return !!String(this.inbox.name || '').trim();
      if (this.step === 4) {
        return this.customStatuses.every(function (s) { return !!String(s.name || '').trim(); });
      }
      return true;
    },

    canAddMember: function () {
      return !!String(this.memberForm.email || '').trim() && !!this.memberForm.role;
    }
  },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    err: function (field) { return (this.errors[field] || [])[0] || ''; },

    /* The first error under a prefix — the server keys row errors as "members.2.role". */
    rowErr: function (prefix) {
      var keys = Object.keys(this.errors || {});
      for (var i = 0; i < keys.length; i++) {
        if (keys[i].indexOf(prefix) === 0) return (this.errors[keys[i]] || [])[0] || '';
      }
      return '';
    },

    payloadFor: function (step) {
      if (step === 1) return this.space;
      if (step === 2) return { members: this.members };
      if (step === 3) return { name: this.inbox.name, addresses: this.inbox.addresses };
      if (step === 4) return { statuses: this.orderedStatuses };
      if (step === 5) return this.settings;
      return {};
    },

    sectionFor: function (step) {
      var hit = this.steps.find(function (s) { return s.number === step; });
      return hit ? hit.section : null;
    },

    endpointFor: function (step) {
      return {
        1: this.endpoints.space, 2: this.endpoints.team, 3: this.endpoints.inbox,
        4: this.endpoints.workflow, 5: this.endpoints.settings
      }[step];
    },

    /** Continue — validate this step server-side, merge it into the draft, move on. */
    next: async function () {
      if (!this.canContinue) return;
      if (this.step === 6) return this.create();

      /*
       * Commit anything typed into a row form but not yet "Add"-ed.
       *
       * Steps 2 and 3 both build a LIST from a small form, and the payload only ever carried
       * the list. So typing an address, then pressing Continue instead of Add, silently threw
       * it away — the Inbox was created with no customer-facing address at all, and the first
       * sign of it was the inbound test having nothing to send to.
       *
       * Adding it here rather than warning: the user has typed the thing and asked to move on,
       * and that is not ambiguous. A validation failure still stops the step, so a bad address
       * surfaces its error instead of being swallowed.
       */
      if (this.step === 3 && String(this.addressForm.email || '').trim()) {
        await this.addAddress();

        if (this.addressError) return;
      }

      if (this.step === 2 && String(this.memberForm.email || '').trim()) {
        if (!this.memberForm.role) {
          this.memberError = 'Choose a role for this coworker, or clear the field to continue.';

          return;
        }

        this.addMember();

        if (this.memberError) return;
      }

      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpointFor(this.step), {
          method: 'POST', body: this.payloadFor(this.step)
        });
        this.absorb(res.draft);
        this.step = res.step;
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Please check the highlighted fields.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    /**
     * Back, and Review's Edit (P2 §27).
     *
     * Sends what is on screen FIRST, so a half-finished step is remembered rather than thrown
     * away. That is the whole requirement, and it is why this is a POST rather than `step--`.
     */
    goTo: async function (step) {
      if (step === this.step || this.saving) return;

      var section = this.sectionFor(this.step);
      this.errors = {};
      this.saving = true;

      try {
        if (section) {
          await this.$pb.api(this.endpoints.back, {
            method: 'POST', body: { section: section, values: this.payloadFor(this.step) }
          });
        }
        this.step = step;
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not go back.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    back: function () { if (this.step > 1) this.goTo(this.step - 1); },

    /* The server's merged draft is the truth; the inbound id is the part it allocates. */
    absorb: function (draft) {
      if (draft && draft.inbox && draft.inbox.inbound_id) this.inbox.inbound_id = draft.inbox.inbound_id;
    },

    // ---- Step 2: the support group (P2 §8) --------------------------------------------------

    addMember: function () {
      var email = String(this.memberForm.email || '').trim().toLowerCase();
      if (!email || !this.memberForm.role) return;

      this.memberError = '';

      var dupe = this.members.some(function (m) { return String(m.email).toLowerCase() === email; });
      if (dupe) { this.memberError = 'This coworker is already in the list.'; return; }

      var known = this.leads.find(function (p) { return String(p.email).toLowerCase() === email; });

      this.members.push({
        user_id: known ? known.id : null,
        email: email,
        role: this.memberForm.role,
        department_groups: (this.memberForm.department_groups || []).slice()
      });

      this.memberForm = { email: '', role: '', department_groups: [] };
    },

    removeMember: function (i) { this.members.splice(i, 1); },

    memberName: function (m) {
      var known = this.leads.find(function (p) {
        return String(p.email).toLowerCase() === String(m.email).toLowerCase();
      });
      return known ? known.name : m.email;
    },

    roleLabel: function (value) {
      var hit = this.roles.find(function (r) { return r.value === value; });
      return hit ? hit.label : value;
    },

    // ---- Step 3: the inbox (P2 §9, §10) -----------------------------------------------------

    addAddress: async function () {
      var email = String(this.addressForm.email || '').trim().toLowerCase();
      if (!email || this.addingAddress) return;

      this.addressError = '';

      var dupe = this.inbox.addresses.some(function (a) { return String(a.email).toLowerCase() === email; });
      if (dupe) { this.addressError = 'This address is already in the list.'; return; }

      this.addingAddress = true;

      try {
        // Asked server-side so §7's "already connected to another Inbox" is said HERE, as the
        // address is added, rather than several fields later on Continue.
        await this.$pb.api(this.endpoints.checkAddress, { method: 'POST', body: { email: email } });
        this.inbox.addresses.push({ email: email, name: String(this.addressForm.name || '').trim() });
        this.addressForm = { email: '', name: '' };
      } catch (e) {
        this.addressError = (e.data && e.data.message) || 'Could not add that address.';
      } finally {
        this.addingAddress = false;
      }
    },

    removeAddress: function (i) { this.inbox.addresses.splice(i, 1); },

    copyAddress: function () {
      var self = this;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(this.inboundAddress).then(function () {
          self.copied = true;
          window.setTimeout(function () { self.copied = false; }, 2000);
        }, function () { self.$pb.toast('Select the address and copy it manually.', 'error'); });
        return;
      }
      this.$pb.toast('Select the address and copy it manually.', 'error');
    },

    toggleProvider: function (key) { this.openProvider = this.openProvider === key ? '' : key; },

    // ---- Step 4: the workflow (P2 §12–§16) --------------------------------------------------

    addStatus: function () {
      if (this.customStatuses.length >= this.statusMax) return;

      var closedAt = this.statuses.findIndex(function (s) { return s.system_key === 'closed'; });

      var row = {
        _uid: 'new-' + (this.nextUid++),
        name: '', color: this.statusColors[0] || '#3b82f6', responsibility: 'assignee',
        is_active: true, system_key: null, default_assignees: []
      };

      // Inserted BEFORE Closed, because Closed is always last (P2 §16).
      if (closedAt === -1) this.statuses.push(row); else this.statuses.splice(closedAt, 0, row);
    },

    removeStatus: function (row) {
      if (row.system_key) return;
      var i = this.statuses.indexOf(row);
      if (i > -1) this.statuses.splice(i, 1);
    },

    /* Move a custom status among the other custom ones. The system rows are never operands:
       Open cannot leave the front and Closed cannot leave the end (P2 §16). */
    move: function (row, delta) {
      if (row.system_key) return;

      var custom = this.customStatuses;
      var from = custom.indexOf(row);
      var to = from + delta;
      if (from < 0 || to < 0 || to >= custom.length) return;

      var a = this.statuses.indexOf(custom[from]);
      var b = this.statuses.indexOf(custom[to]);
      this.statuses.splice(b, 0, this.statuses.splice(a, 1)[0]);
    },

    onDragStart: function (row) { if (!row.system_key) this.dragKey = row; },
    onDragOver: function (row, e) { if (!row.system_key && this.dragKey) e.preventDefault(); },
    onDrop: function (row) {
      if (row.system_key || !this.dragKey || this.dragKey === row) { this.dragKey = null; return; }
      var custom = this.customStatuses;
      var from = custom.indexOf(this.dragKey);
      var to = custom.indexOf(row);
      if (from > -1 && to > -1) this.move(this.dragKey, to - from);
      this.dragKey = null;
    },

    assigneeOptions: function () {
      return this.leads.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
      });
    },

    /* Still used by Step 6's review table; the card owns its own conversion. */
    assigneeNames: function (row) {
      var self = this;
      return (row.default_assignees || []).map(function (id) {
        var hit = self.leads.find(function (p) { return String(p.id) === String(id); });
        return hit ? hit.name : id;
      });
    },

    // ---- Step 5 (P2 §18) --------------------------------------------------------------------

    metaOn: function (key) { return !!this.settings.metadata[key]; },
    setMeta: function (key, on) { this.settings.metadata[key] = !!on; },

    // ---- Step 6 (P2 §28) --------------------------------------------------------------------

    create: async function () {
      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoints.store, { method: 'POST' });
        window.location.href = res.redirect;
      } catch (e) {
        // §29: nothing was created and the draft is kept, so this is recoverable.
        this.$pb.toast(
          (e.data && e.data.message) || 'Your Help Desk could not be created. Your setup has been kept.',
          'error'
        );
        this.saving = false;
      }
    },

    cancel: async function () {
      if (!window.confirm('Cancel setup? Everything you have entered will be discarded.')) return;

      try {
        var res = await this.$pb.api(this.endpoints.cancel, { method: 'POST' });
        window.location.href = res.redirect;
      } catch (e) {
        window.location.href = this.urls.exit;
      }
    }
  },

  template: [
    '<div class="mx-auto max-w-[792px] px-5 sm:px-8 py-10">',

    /* ---- somebody who may not run this (§19) ---- */
    '  <div v-if="!canCreate" class="rounded-lg border border-line px-6 py-12 text-center">',
    '    <h1 class="text-[18px] font-semibold text-head">Help Center setup</h1>',
    '    <p class="mt-2 text-[13px] text-sub">Only a workspace owner or admin can set up the Help Center. Ask one of them to finish this step.</p>',
    '    <a :href="urls.exit" class="inline-flex items-center h-9 px-4 mt-6 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Back to projects</a>',
    '  </div>',

    '  <div v-else>',

    /* ---- progress: current step and total (P2 §2) ---- */
    /* Brings the collapsed Help Center panel back; hidden by CSS while it is open. */
    '    <div class="flex items-center gap-2 mb-2">',
    '      <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" aria-controls="sidebar" aria-expanded="false" class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" v-html="icon(\'sidebar\', 16)"></button>',
    '      <span class="text-[12px] font-semibold text-brand">Step {{ step }} of {{ totalSteps }}</span>',
    '    </div>',
    '    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-8">',
    '      <li v-for="(s, i) in steps" :key="s.number" class="flex items-center gap-2 min-w-0">',
    '        <span :class="[\'h-6 w-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold\', s.number < step ? \'bg-brand text-white\' : (s.number === step ? \'bg-sel text-brand border border-stroke\' : \'bg-hover text-faint\')]">{{ s.number }}</span>',
    '        <span :class="[\'text-[12px] truncate\', s.number > step ? \'text-faint\' : \'text-ink\']">{{ s.label }}</span>',
    '        <span v-if="i < steps.length - 1" class="w-4 h-px bg-line shrink-0"></span>',
    '      </li>',
    '    </ol>',

    /* ================= STEP 1 — Create Your Space (P2 §4–§6) ================= */
    '    <div v-if="step === 1">',
    '      <h1 class="text-[18px] font-semibold text-head">Create your Space</h1>',
    '      <p class="mt-1 text-[13px] text-sub">A Space is the top level of your Help Desk — Customer Support, Billing, Partner Support.</p>',
    '      <div class="mt-6 space-y-4">',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Space Name</label>',
    '          <input v-model="space.name" maxlength="100" class="pb-input w-full" placeholder="Customer Support" />',
    '          <p v-if="err(\'name\')" class="mt-1 text-[12px] text-danger">{{ err(\'name\') }}</p>',
    '        </div>',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Description <span class="text-faint font-normal">(optional)</span></label>',
    '          <textarea v-model="space.description" maxlength="500" rows="3" class="pb-textarea w-full" placeholder="Handles customer product questions, technical issues, and account support."></textarea>',
    '          <p v-if="err(\'description\')" class="mt-1 text-[12px] text-danger">{{ err(\'description\') }}</p>',
    '        </div>',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Space Type</label>',
    '          <pb-tags v-model="space.types" :suggestions="typeSuggestions" :max="typeMax" :max-length="typeMaxLength" placeholder="Type a Space type and press Enter" />',
    '          <p v-if="err(\'types\') || rowErr(\'types.\')" class="mt-1 text-[12px] text-danger">{{ err(\'types\') || rowErr(\'types.\') }}</p>',
    '        </div>',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Department Group <span class="text-faint font-normal">(optional)</span></label>',
    '          <pb-tags v-model="space.department_groups" :max="groupMax" :max-length="typeMaxLength" placeholder="Type a department group and press Enter" />',
    '          <p class="mt-1 text-[12px] text-sub">You can assign coworkers to these in the next step.</p>',
    '          <p v-if="err(\'department_groups\') || rowErr(\'department_groups.\')" class="mt-1 text-[12px] text-danger">{{ err(\'department_groups\') || rowErr(\'department_groups.\') }}</p>',
    '        </div>',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Space Lead</label>',
    '          <pb-combo v-model="space.lead_user_id" :options="leadOptions" placeholder="Search members…" :invalid="!!err(\'lead_user_id\')" />',
    '          <p class="mt-1 text-[12px] text-sub">The person primarily responsible for this Space.</p>',
    '          <p v-if="err(\'lead_user_id\')" class="mt-1 text-[12px] text-danger">{{ err(\'lead_user_id\') }}</p>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* ================= STEP 2 — Invite Your Support Group (P2 §7, §8) ================= */
    '    <div v-else-if="step === 2">',
    '      <h1 class="text-[18px] font-semibold text-head">Invite your support group</h1>',
    '      <p class="mt-1 text-[13px] text-sub">Add the coworkers who will work in this Space. You can skip this and add people later.</p>',

    '      <div class="mt-6 rounded-lg border border-line px-4 py-4">',
    '        <div class="grid gap-3 sm:grid-cols-2">',
    '          <div>',
    '            <label class="block text-[12px] font-semibold text-ink mb-1">Coworker</label>',
    '            <pb-combo v-model="memberForm.email" :options="coworkerOptions" placeholder="Search members…" />',
    '          </div>',
    '          <div>',
    '            <label class="block text-[12px] font-semibold text-ink mb-1">Role</label>',
    '            <pb-combo v-model="memberForm.role" :options="roleOptions" placeholder="Choose a role…" :searchable="false" />',
    '            <p class="mt-1 text-[12px] text-sub">Their workspace role, used when inviting them.</p>',
    '          </div>',
    '        </div>',
    '        <div v-if="groupOptions.length" class="mt-3">',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Department Group <span class="text-faint font-normal">(optional)</span></label>',
    '          <pb-combo v-model="memberForm.department_groups" :options="groupOptions" :multiple="true" placeholder="Choose groups…" />',
    '        </div>',
    '        <div class="mt-3 flex items-center gap-2">',
    '          <button type="button" @click="addMember" :disabled="!canAddMember" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">Add</button>',
    '          <span v-if="memberError" class="text-[12px] text-danger">{{ memberError }}</span>',
    '        </div>',
    '      </div>',

    '      <table v-if="members.length" class="mt-5 w-full text-[13px]">',
    '        <thead><tr class="text-left text-[12px] text-faint border-b border-line"><th class="py-2 font-medium">Coworker</th><th class="py-2 font-medium">Role</th><th class="py-2 font-medium">Department Groups</th><th class="py-2 font-medium text-right">Action</th></tr></thead>',
    '        <tbody>',
    '          <tr v-for="(m, i) in members" :key="m.email" class="border-b border-line">',
    '            <td class="py-2 text-ink _moretogether-break">{{ memberName(m) }} <span v-if="!m.user_id" class="_moretogether-badge _moretogether-badge--wait">Will be invited</span></td>',
    '            <td class="py-2 text-ink">{{ roleLabel(m.role) }}</td>',
    '            <td class="py-2"><span v-for="g in m.department_groups" :key="g" class="_moretogether-tag _moretogether-tag--static mr-1">{{ g }}</span><span v-if="!m.department_groups.length" class="text-faint">—</span></td>',
    '            <td class="py-2 text-right"><button type="button" @click="removeMember(i)" class="text-[12px] text-sub hover:text-danger">Remove</button></td>',
    '          </tr>',
    '        </tbody>',
    '      </table>',
    '      <p v-else class="mt-5 text-[12px] text-faint">Nobody added yet.</p>',
    '      <p v-if="rowErr(\'members.\')" class="mt-2 text-[12px] text-danger">{{ rowErr(\'members.\') }}</p>',
    '    </div>',

    /* ================= STEP 3 — Set Up Your Inbox (P2 §9, §10) ================= */
    '    <div v-else-if="step === 3">',
    '      <h1 class="text-[18px] font-semibold text-head">Set up your Inbox</h1>',
    '      <p class="mt-1 text-[13px] text-sub">An Inbox is where incoming customer conversations are delivered.</p>',

    '      <div class="mt-6">',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Inbox Name</label>',
    '        <input v-model="inbox.name" maxlength="100" class="pb-input w-full" placeholder="General Support" />',
    '        <p v-if="err(\'name\')" class="mt-1 text-[12px] text-danger">{{ err(\'name\') }}</p>',
    '      </div>',

    '      <div class="mt-8">',
    '        <h2 class="text-[14px] font-semibold text-head">Email Addresses</h2>',
    '        <p class="mt-1 text-[13px] text-sub">Add the email addresses customers use to contact your team. Messages sent to these addresses can be forwarded into this Inbox.</p>',
    '        <div class="mt-4 flex flex-wrap items-start gap-2">',
    '          <input v-model="addressForm.email" type="email" class="pb-input flex-1 min-w-[200px]" placeholder="support@company.com" @keydown.enter.prevent="addAddress" />',
    '          <input v-model="addressForm.name" maxlength="100" class="pb-input flex-1 min-w-[160px]" placeholder="Company Support" @keydown.enter.prevent="addAddress" />',
    '          <button type="button" @click="addAddress" :disabled="addingAddress || !addressForm.email" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">Add</button>',
    '        </div>',
    '        <p v-if="addressError" class="mt-2 text-[12px] text-danger">{{ addressError }}</p>',
    '        <p v-if="rowErr(\'addresses.\')" class="mt-2 text-[12px] text-danger">{{ rowErr(\'addresses.\') }}</p>',

    '        <table v-if="inbox.addresses.length" class="mt-4 w-full text-[13px]">',
    '          <thead><tr class="text-left text-[12px] text-faint border-b border-line"><th class="py-2 font-medium">Name</th><th class="py-2 font-medium">Email Address</th><th class="py-2 font-medium">Status</th><th class="py-2 font-medium text-right">Action</th></tr></thead>',
    '          <tbody><tr v-for="(a, i) in inbox.addresses" :key="a.email" class="border-b border-line">',
    '            <td class="py-2 text-ink">{{ a.name || \'—\' }}</td>',
    '            <td class="py-2 text-ink _moretogether-break">{{ a.email }}</td>',
    '            <td class="py-2"><span class="_moretogether-badge _moretogether-badge--off">Setup Required</span></td>',
    '            <td class="py-2 text-right"><button type="button" @click="removeAddress(i)" class="text-[12px] text-sub hover:text-danger">Remove</button></td>',
    '          </tr></tbody>',
    '        </table>',
    '        <p v-else class="mt-4 text-[12px] text-faint">No addresses added yet. You can also add them later.</p>',
    '      </div>',

    /* The address is reserved before this step renders, so it is shown here rather than after
       Continue — and it does not change if you go Back (HC-D12). */
    '      <div class="mt-8 rounded-lg border border-line bg-[#f9fafb] px-4 py-4">',
    '        <div class="text-[12px] font-semibold text-ink">Your ProjectBlock inbound address</div>',
    '        <div class="mt-2 flex flex-wrap items-center gap-2">',
    '          <code class="_moretogether-break flex-1 min-w-[240px] rounded-md border border-line bg-white px-3 py-2 text-[13px] text-ink">{{ inboundAddress }}</code>',
    '          <button type="button" @click="copyAddress" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">{{ copied ? \'Copied\' : \'Copy Address\' }}</button>',
    '        </div>',
    '        <p class="mt-2 text-[12px] text-sub">Forward your existing support email here. Your customers keep writing to the address they already know.</p>',
    '      </div>',

    '      <div class="mt-6">',
    '        <h2 class="text-[14px] font-semibold text-head">Set up forwarding</h2>',
    '        <div class="mt-3 divide-y divide-line border-y border-line">',
    '          <div v-for="p in providers" :key="p.key">',
    '            <button type="button" @click="toggleProvider(p.key)" :aria-expanded="String(openProvider === p.key)" class="w-full flex items-center gap-2 py-3 text-left text-[13px] font-semibold text-ink"><span class="text-faint text-[12px] w-3">{{ openProvider === p.key ? \'−\' : \'+\' }}</span>{{ p.label }}</button>',
    '            <ol v-if="openProvider === p.key" class="pb-3 pl-9 space-y-1.5 list-decimal text-[13px] text-sub"><li v-for="(s, i) in p.steps" :key="i">{{ s }}</li></ol>',
    '          </div>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* ================= STEP 4 — Configure Your Workflow (P2 §11–§16) ================= */
    '    <div v-else-if="step === 4">',
    '      <h1 class="text-[18px] font-semibold text-head">Configure your workflow</h1>',
    '      <p class="mt-1 text-[13px] text-sub">Build the workflow your team will use to manage conversations from open to closed.</p>',

    /* Open, then the Add link, then the custom statuses, then Closed.
       The link sits BETWEEN Open and everything below it and stays there however many custom
       statuses exist — but a new card is always inserted directly above Closed, because Closed
       is always last (P2 §16). So "where you click" and "where it lands" are deliberately
       different, which is why the button says so. */
    '      <div class="mt-6">',
    '        <hc-status-card v-if="openStatus" :status="openStatus" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()" />',
    '      </div>',

    '      <div class="my-4 flex items-center gap-3">',
    '        <span class="flex-1 h-px bg-line"></span>',
    '        <button type="button" @click="addStatus" :disabled="customStatuses.length >= statusMax"',
    '                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full border border-dashed border-stroke text-[13px] font-semibold text-brand hover:bg-hover disabled:opacity-50">',
    '          <span v-html="icon(\'plus\', 13)"></span> Add Workflow Status',
    '        </button>',
    '        <span class="flex-1 h-px bg-line"></span>',
    '      </div>',
    '      <p v-if="customStatuses.length >= statusMax" class="mb-4 text-center text-[12px] text-faint">That is the most statuses a workflow can hold.</p>',

    '      <div class="space-y-3">',
    '        <hc-status-card v-for="s in customStatuses" :key="s._uid" :status="s" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()"',
    '                        @move="move(s, $event)" @remove="removeStatus(s)"',
    '                        :draggable="true" @dragstart="onDragStart(s)" @dragover="onDragOver(s, $event)" @drop="onDrop(s)" />',
    '      </div>',

    '      <div class="mt-3">',
    '        <hc-status-card v-if="closedStatus" :status="closedStatus" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()" />',
    '      </div>',

    '      <p v-if="err(\'statuses\') || rowErr(\'statuses.\')" class="mt-3 text-[12px] text-danger">{{ err(\'statuses\') || rowErr(\'statuses.\') }}</p>',
    '      <p class="mt-3 text-[12px] text-faint">Open is always first and Closed always last. New statuses are added just above Closed; drag them, or use the arrows, to reorder.</p>',
    '    </div>',

    /* ================= STEP 5 — Conversation Settings (P2 §17–§24) ================= */
    '    <div v-else-if="step === 5">',
    '      <h1 class="text-[18px] font-semibold text-head">Conversation settings</h1>',
    '      <p class="mt-1 text-[13px] text-sub">Choose what your team sees on a conversation, and how conversations behave.</p>',

    '      <h2 class="mt-6 text-[14px] font-semibold text-head">Metadata</h2>',
    '      <div class="mt-3 divide-y divide-line border-y border-line">',
    '        <div v-for="m in metadataOptions" :key="m.key" class="flex items-center gap-4 py-3">',
    '          <div class="flex-1 min-w-0">',
    '            <div class="text-[13px] font-semibold text-ink">{{ m.label }} <span v-if="!m.available" class="_moretogether-badge _moretogether-badge--off">Coming Soon</span></div>',
    '            <div class="text-[12px] text-sub">{{ m.help }}</div>',
    '          </div>',
    '          <pb-toggle :model-value="metaOn(m.key)" :disabled="!m.available" @update:model-value="setMeta(m.key, $event)" />',
    '        </div>',
    '      </div>',
    '      <p v-if="rowErr(\'metadata.\')" class="mt-2 text-[12px] text-danger">{{ rowErr(\'metadata.\') }}</p>',

    '      <h2 class="mt-8 text-[14px] font-semibold text-head">Auto BCC</h2>',
    '      <div class="mt-3 flex items-center gap-4">',
    '        <p class="flex-1 text-[13px] text-sub">Automatically send a copy of outgoing Help Desk replies to a specified external email address.</p>',
    '        <pb-toggle :model-value="settings.auto_bcc_enabled" @update:model-value="settings.auto_bcc_enabled = $event" />',
    '      </div>',
    '      <div v-if="settings.auto_bcc_enabled" class="mt-3 max-w-[420px]">',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">BCC Email Address</label>',
    '        <input v-model="settings.auto_bcc_email" type="email" class="pb-input w-full" placeholder="archive@company.com" />',
    '        <p v-if="err(\'auto_bcc_email\')" class="mt-1 text-[12px] text-danger">{{ err(\'auto_bcc_email\') }}</p>',
    '      </div>',

    '      <h2 class="mt-8 text-[14px] font-semibold text-head">Conversation reassignment</h2>',
    '      <div class="mt-3 flex items-center gap-4">',
    '        <p class="flex-1 text-[13px] text-sub">Automatically reassign conversations assigned to you when they become active while you are away.</p>',
    '        <pb-toggle :model-value="settings.reassign_enabled" @update:model-value="settings.reassign_enabled = $event" />',
    '      </div>',
    '      <div v-if="settings.reassign_enabled" class="mt-3 space-y-3">',
    '        <div>',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Reassign after being away for</label>',
    '          <div class="flex items-center gap-2">',
    '            <input v-model.number="settings.reassign_hours" type="number" min="0" max="720" class="pb-input w-20" />',
    '            <span class="text-[13px] text-sub">Hours</span>',
    '            <input v-model.number="settings.reassign_minutes" type="number" min="0" max="59" class="pb-input w-20" />',
    '            <span class="text-[13px] text-sub">Minutes</span>',
    '          </div>',
    '          <p v-if="err(\'reassign_hours\') || err(\'reassign_minutes\')" class="mt-1 text-[12px] text-danger">{{ err(\'reassign_hours\') || err(\'reassign_minutes\') }}</p>',
    '        </div>',
    '        <div class="max-w-[420px]">',
    '          <label class="block text-[12px] font-semibold text-ink mb-1">When the conversation becomes active</label>',
    '          <pb-combo v-model="settings.reassign_destination" :options="destinations" :searchable="false" />',
    '        </div>',
    '      </div>',

    '      <h2 class="mt-8 text-[14px] font-semibold text-head">Auto-follow when mentioned</h2>',
    '      <div class="mt-3 flex items-center gap-4">',
    '        <p class="flex-1 text-[13px] text-sub">Automatically follow a conversation when someone @mentions you in a note.</p>',
    '        <pb-toggle :model-value="settings.auto_follow_mentions" @update:model-value="settings.auto_follow_mentions = $event" />',
    '      </div>',

    /* Honest about what is stored versus what runs (HC-D17). */
    '      <p class="mt-6 text-[12px] text-sub">Reassignment and auto-follow are saved with your Space and take effect once conversations arrive in a future release.</p>',
    '    </div>',

    /* ================= STEP 6 — Review & Confirm (P2 §25–§28) ================= */
    '    <div v-else>',
    '      <h1 class="text-[18px] font-semibold text-head">Review your Help Desk setup</h1>',
    '      <p class="mt-1 text-[13px] text-sub">Review your configuration before creating your Help Desk Space and Inbox.</p>',

    '      <div class="mt-6 space-y-4">',

    '        <div class="rounded-lg border border-line">',
    '          <div class="flex items-center px-4 py-3 border-b border-line"><h2 class="text-[13px] font-semibold text-head flex-1">Space</h2><button type="button" @click="goTo(1)" class="text-[12px] font-semibold text-brand hover:underline">Edit</button></div>',
    '          <dl class="px-4 py-3 space-y-2 text-[13px]">',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Name</dt><dd class="text-ink">{{ space.name || \'—\' }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Description</dt><dd class="text-ink">{{ space.description || \'—\' }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Space Type</dt><dd><span v-for="t in space.types" :key="t" class="_moretogether-tag _moretogether-tag--static mr-1">{{ t }}</span></dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Department Groups</dt><dd><span v-for="g in space.department_groups" :key="g" class="_moretogether-tag _moretogether-tag--static mr-1">{{ g }}</span><span v-if="!space.department_groups.length" class="text-faint">—</span></dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Space Lead</dt><dd class="text-ink">{{ leadName }}</dd></div>',
    '          </dl>',
    '        </div>',

    '        <div class="rounded-lg border border-line">',
    '          <div class="flex items-center px-4 py-3 border-b border-line"><h2 class="text-[13px] font-semibold text-head flex-1">Support Group</h2><button type="button" @click="goTo(2)" class="text-[12px] font-semibold text-brand hover:underline">Edit</button></div>',
    '          <div class="px-4 py-3 text-[13px]">',
    '            <div v-if="!members.length" class="text-faint">Nobody added — you can invite people later.</div>',
    '            <div v-for="m in members" :key="m.email" class="flex flex-wrap items-center gap-2 py-1">',
    '              <span class="text-ink _moretogether-break">{{ memberName(m) }}</span>',
    '              <span class="text-sub">{{ roleLabel(m.role) }}</span>',
    '              <span v-for="g in m.department_groups" :key="g" class="_moretogether-tag _moretogether-tag--static">{{ g }}</span>',
    '              <span v-if="!m.user_id" class="_moretogether-badge _moretogether-badge--wait">Will be invited</span>',
    '            </div>',
    '          </div>',
    '        </div>',

    '        <div class="rounded-lg border border-line">',
    '          <div class="flex items-center px-4 py-3 border-b border-line"><h2 class="text-[13px] font-semibold text-head flex-1">Inbox</h2><button type="button" @click="goTo(3)" class="text-[12px] font-semibold text-brand hover:underline">Edit</button></div>',
    '          <dl class="px-4 py-3 space-y-2 text-[13px]">',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Name</dt><dd class="text-ink">{{ inbox.name || \'—\' }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Receiving addresses</dt><dd class="text-ink _moretogether-break"><span v-if="inbox.addresses.length"><span v-for="(a, i) in inbox.addresses" :key="a.email">{{ i ? \', \' : \'\' }}{{ a.email }}</span></span><span v-else class="text-faint">None</span></dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Inbound address</dt><dd class="text-ink _moretogether-break">{{ inboundAddress }}</dd></div>',
    '          </dl>',
    '        </div>',

    '        <div class="rounded-lg border border-line">',
    '          <div class="flex items-center px-4 py-3 border-b border-line"><h2 class="text-[13px] font-semibold text-head flex-1">Workflow</h2><button type="button" @click="goTo(4)" class="text-[12px] font-semibold text-brand hover:underline">Edit</button></div>',
    '          <div class="px-4 py-3">',
    '            <div class="flex flex-wrap items-center gap-2">',
    '              <template v-for="(s, i) in orderedStatuses" :key="i">',
    '                <span class="inline-flex items-center h-6 px-2 rounded-full text-[11px] font-semibold text-white" :style="{ background: s.color }">{{ s.name }}</span>',
    '                <span v-if="i < orderedStatuses.length - 1" class="text-faint">→</span>',
    '              </template>',
    '            </div>',
    '            <table class="mt-3 w-full text-[12px]">',
    '              <thead><tr class="text-left text-faint border-b border-line"><th class="py-1 font-medium">Status</th><th class="py-1 font-medium">Responsibility</th><th class="py-1 font-medium">State</th><th class="py-1 font-medium">Default assignees</th></tr></thead>',
    '              <tbody><tr v-for="(s, i) in orderedStatuses" :key="i" class="border-b border-line last:border-0">',
    '                <td class="py-1 text-ink">{{ s.name }}</td>',
    '                <td class="py-1 text-sub">{{ s.responsibility === \'creator\' ? \'Creator\' : \'Assignee\' }}</td>',
    '                <td class="py-1 text-sub">{{ s.is_active ? \'Active\' : \'Inactive\' }}</td>',
    '                <td class="py-1 text-sub">{{ assigneeNames(s).join(\', \') || \'—\' }}</td>',
    '              </tr></tbody>',
    '            </table>',
    '          </div>',
    '        </div>',

    '        <div class="rounded-lg border border-line">',
    '          <div class="flex items-center px-4 py-3 border-b border-line"><h2 class="text-[13px] font-semibold text-head flex-1">Metadata &amp; Automation</h2><button type="button" @click="goTo(5)" class="text-[12px] font-semibold text-brand hover:underline">Edit</button></div>',
    '          <dl class="px-4 py-3 space-y-2 text-[13px]">',
    '            <div v-for="m in metadataOptions" :key="m.key" class="flex gap-4"><dt class="w-40 shrink-0 text-sub">{{ m.label }}</dt><dd class="text-ink">{{ !m.available ? \'Coming Soon\' : (metaOn(m.key) ? \'On\' : \'Off\') }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Auto BCC</dt><dd class="text-ink _moretogether-break">{{ settings.auto_bcc_enabled ? settings.auto_bcc_email : \'Off\' }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Reassignment</dt><dd class="text-ink">{{ settings.reassign_enabled ? (settings.reassign_hours + \'h \' + settings.reassign_minutes + \'m\') : \'Off\' }}</dd></div>',
    '            <div v-if="settings.reassign_enabled" class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Then</dt><dd class="text-ink">{{ settings.reassign_destination === \'unassigned\' ? \'Move to Unassigned\' : \'Assign to an available agent\' }}</dd></div>',
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Auto-follow on mention</dt><dd class="text-ink">{{ settings.auto_follow_mentions ? \'On\' : \'Off\' }}</dd></div>',
    '          </dl>',
    '        </div>',

    '      </div>',
    '    </div>',

    /* ---- footer: Back / Continue / Create (P2 §2) ---- */
    '    <div class="mt-8 flex flex-wrap items-center gap-2 border-t border-line pt-6">',
    '      <button v-if="step > 1" type="button" @click="back" :disabled="saving" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">Back</button>',
    '      <button type="button" @click="next" :disabled="!canContinue" class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">{{ saving ? \'Saving…\' : (step === totalSteps ? \'Create Space\' : \'Continue\') }}</button>',
    '      <button type="button" @click="cancel" class="ml-auto inline-flex items-center h-9 px-4 rounded-md text-[13px] font-semibold text-sub hover:text-danger">Cancel Setup</button>',
    '    </div>',

    '  </div>',
    '</div>'
  ].join('\n')
}, { root: 'help-center-setup' });
