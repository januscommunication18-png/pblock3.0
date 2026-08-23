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
   * The status card lives in its own file, because Settings → Workflow's editor edits the same
   * rows through the same rules (P16). Loaded before this script — see setup.blade.php.
   */
  components: { 'hc-status-card': window.HC_STATUS_CARD },

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
        inbound_display_name: space.inbound_display_name || '',
        types: (space.types || []).slice(),
        department_groups: (space.department_groups || []).slice(),
        lead_user_id: space.lead_user_id ? String(space.lead_user_id) : ''
      },

      members: ((d.team || {}).members || []).slice(),
      /*
       * The coworker the trash icon is asking about, and their row index.
       *
       * Removing somebody here undoes an Add that has not been sent anywhere — but it is still
       * a row somebody typed an address into, and the icon that does it sits at the end of a
       * table row where the pointer passes over it on the way to anything else. The dialog is
       * the difference between an action and an accident.
       */
      removeTarget: null,
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
    /* The sender name a customer will see (P65) — what was typed, or the Space name as the
       fallback. The same rule HelpCenterSpace::senderName() applies on the server, so the
       preview here and the Review step both describe what will actually be sent. */
    senderPreview: function () {
      return String(this.space.inbound_display_name || '').trim()
        || String(this.space.name || '').trim();
    },

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

    /** Ask first. `askRemoveMember` opens the dialog; this is what the dialog calls. */
    removeMember: function () {
      if (!this.removeTarget) return;

      this.members.splice(this.removeTarget.index, 1);
      this.removeTarget = null;
    },

    askRemoveMember: function (i) {
      this.removeTarget = { index: i, member: this.members[i] };
    },

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
    /* 1100 rather than 792.
       The narrow measure was set when every step was a single column of inputs. Steps 2, 3, 4
       and 6 have since grown two-column grids, a status editor and a review table, and those
       were being squeezed into a width chosen for a paragraph. The FIELDS keep their own
       readable widths — this only stops the screen from being narrower than its content.

       1100 and not, say, 1040 because `max-w-[1100px]` is IN the built stylesheet and
       `max-w-[1040px]` is not: Tailwind emits only the classes it finds in the sources it
       scans, and an arbitrary value that exists solely inside this template string produces no
       rule at all — the container silently goes full width. Check the built CSS before
       inventing a new one here. */
    '<div>',

    /* ===== Page header — the same h-12 bordered bar every other Help Center screen carries
       (Spaces, Inboxes, a Space's views). The wizard was the one screen without it, so the
       module's chrome stopped one screen short. It sits OUTSIDE the 1100px measure below, so
       the bar spans the full main area like the others rather than being inset with the form.
       The sidebar-expand control moved up here from the step row for the same reason: it is
       part of the chrome, not part of the flow. ===== */
    '  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">',
    '    <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" aria-controls="sidebar" aria-expanded="false" class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" v-html="icon(\'sidebar\', 16)"></button>',
    '    <span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>',
    '    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">',
    '      <span v-html="icon(\'rectangles-pair\', 16, \'text-sub\')"></span>Set up your Help Center',
    '    </span>',
    '  </div>',

    '  <div class="mx-auto max-w-[1100px] px-5 sm:px-8 py-10">',

    /* ---- somebody who may not run this (§19) ---- */
    '  <div v-if="!canCreate" class="rounded-lg border border-line px-6 py-12 text-center">',
    '    <h1 class="text-[18px] font-semibold text-head">Help Center setup</h1>',
    '    <p class="mt-2 text-[13px] text-sub">Only a workspace owner or admin can set up the Help Center. Ask one of them to finish this step.</p>',
    '    <a :href="urls.exit" class="inline-flex items-center h-9 px-4 mt-6 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Back to projects</a>',
    '  </div>',

    '  <div v-else>',

    /* ---- progress: current step and total (P2 §2) ---- */
    '    <div class="flex items-center gap-2 mb-2">',
    '      <span class="text-[12px] font-semibold text-brand">Step {{ step }} of {{ totalSteps }}</span>',
    '    </div>',
    /* ONE ROW, always.
       It used to wrap: six full labels ("Invite Your Support Group", "Configure Your Workflow")
       are about 120 characters, more than any sensible width holds, so the bar broke over two
       lines and the numbers stopped reading as a sequence. It draws the SHORT label now —
       Space / Team / Inbox / Workflow / Settings / Review — with the full one on the row's
       title and, more importantly, as the heading of the step you are standing on, which is the
       only step whose full name you actually need.

       `flex-nowrap` and `whitespace-nowrap` mean it cannot wrap again; the connector between
       steps grows instead, so the bar spans the width rather than bunching at the left. Below
       `sm` the labels drop and the numbers stay — a phone gets one row too. */
    '    <ol class="flex flex-nowrap items-center gap-2 mb-8">',
    '      <li v-for="(s, i) in steps" :key="s.number" :title="s.label"',
    '          :class="[\'flex items-center gap-2 min-w-0\', i < steps.length - 1 ? \'flex-1\' : \'\']">',
    '        <span :class="[\'h-6 w-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold\', s.number < step ? \'bg-brand text-white\' : (s.number === step ? \'bg-sel text-brand border border-stroke\' : \'bg-hover text-faint\')]">{{ s.number }}</span>',
    // `truncate` rather than plain `whitespace-nowrap`: with the bar unable to wrap, a window
    // too narrow to hold six labels would otherwise push them out of the container. Ellipsis is
    // the last resort, and the full label is still on the row's title.
    '        <span :class="[\'hidden sm:block text-[12px] truncate\', s.number === step ? \'text-ink font-semibold\' : (s.number > step ? \'text-faint\' : \'text-ink\')]">{{ s.short || s.label }}</span>',
    // The connector lives INSIDE the row it follows — a sibling <li> would be outside the
    // v-for and could not see `i`. It grows, which is what spreads the six across the bar.
    '        <span v-if="i < steps.length - 1" class="h-px bg-line flex-1 ml-1" style="min-width:8px"></span>',
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
    '          <label class="block text-[12px] font-semibold text-ink mb-1">Inbound Email Display Name <span class="text-faint font-normal">(optional)</span></label>',
    '          <input v-model="space.inbound_display_name" maxlength="100" class="pb-input w-full" :placeholder="space.name || \'eBay Support\'" />',
    '          <p class="mt-1 text-[12px] text-faint">This name will be shown to customers as the sender name when emails are sent from this Space.</p>',
    '          <p v-if="senderPreview" class="mt-1.5 text-[12px] text-sub _moretogether-break"><span class="font-semibold text-ink">{{ senderPreview }}</span><span class="text-faint"> &lt;inbound address generated after the Space is created&gt;</span></p>',
    '          <p v-if="err(\'inbound_display_name\')" class="mt-1 text-[12px] text-danger">{{ err(\'inbound_display_name\') }}</p>',
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
    /* An icon, not the word "Remove": the column is one control wide and repeats down the
       table, so the label was five characters of chrome on every row saying what the trash
       glyph says on its own. `title` and `aria-label` keep it named for a pointer and for a
       screen reader. */
    '            <td class="py-2 text-right">',
    '              <button type="button" @click="askRemoveMember(i)" :title="\'Remove \' + memberName(m)"',
    '                      :aria-label="\'Remove \' + memberName(m)"',
    '                      class="h-7 w-7 inline-grid place-items-center rounded-md text-sub hover:bg-hover hover:text-danger"',
    '                      v-html="icon(\'trash\', 15)"></button>',
    '            </td>',
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

    /* Open, then the custom statuses, then the Add link, then Closed.
       The link used to sit directly under Open — above every custom status — while a new card
       is always inserted just ABOVE Closed, because Closed is always last (P2 §16). So the
       button and the card it produced were at opposite ends of a growing list: after three or
       four statuses you were at the bottom looking at the newest card with the only way to add
       another scrolled off the top of the screen, which reads as the button having disappeared.
       It sits at the END of the list now, where the next card actually lands. */
    '      <div class="mt-6">',
    '        <hc-status-card v-if="openStatus" :status="openStatus" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()" />',
    '      </div>',

    '      <div class="space-y-3">',
    '        <hc-status-card v-for="s in customStatuses" :key="s._uid" :status="s" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()"',
    '                        @move="move(s, $event)" @remove="removeStatus(s)"',
    '                        :draggable="true" @dragstart="onDragStart(s)" @dragover="onDragOver(s, $event)" @drop="onDrop(s)" />',
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

    '      <div class="mt-3">',
    '        <hc-status-card v-if="closedStatus" :status="closedStatus" :colors="statusColors" :responsibilities="responsibilities" :assignees="assigneeOptions()" />',
    '      </div>',

    '      <p v-if="err(\'statuses\') || rowErr(\'statuses.\')" class="mt-3 text-[12px] text-danger">{{ err(\'statuses\') || rowErr(\'statuses.\') }}</p>',
    '      <p class="mt-3 text-[12px] text-faint">Open is always first and Closed always last. Everything between them is yours — drag them, or use the arrows, to reorder.</p>',
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
    '            <div class="flex gap-4"><dt class="w-40 shrink-0 text-sub">Sender name</dt><dd class="text-ink">{{ senderPreview || \'—\' }}</dd></div>',
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

    /* ---- footer: Back / Continue / Create (P2 §2) ----
       `mb` rather than padding on the page wrapper: the 150px belongs to the ACTION ROW, so it
       travels with it and stays put whatever a step renders above. An inline style because
       `mb-[150px]` is not in the built stylesheet — see the container's note above. */
    '    <div style="margin-bottom:150px"',
    '         class="mt-8 flex flex-wrap items-center gap-2 border-t border-line pt-6">',
    /* Continue first, then Back.
       The forward action is the one nearly every visit to this screen ends with, so it leads —
       and it keeps the same position on all six steps, where Back is absent on the first. With
       Back leading, the primary button shifted sideways between Step 1 and Step 2. */
    '      <button type="button" @click="next" :disabled="!canContinue" class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">{{ saving ? \'Saving…\' : (step === totalSteps ? \'Create Space\' : \'Continue\') }}</button>',
    '      <button v-if="step > 1" type="button" @click="back" :disabled="saving" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">Back</button>',
    '      <button type="button" @click="cancel" class="ml-auto inline-flex items-center h-9 px-4 rounded-md text-[13px] font-semibold text-sub hover:text-danger">Cancel Setup</button>',
    '    </div>',

    '  </div>',
    /* closes the 1100px measure opened under the page header */
    '  </div>',

    /* ---- Remove coworker? (Step 2) ----
       Outside the step branches, at the root: pb-modal teleports to <body>, so it only has to
       exist, and `removeTarget` is the one thing that decides whether it is open. */
    '  <pb-modal :open="!!removeTarget" title="Remove coworker?" @close="removeTarget = null">',
    '    <p class="text-[13px] text-sub leading-relaxed">',
    '      Remove <span class="font-semibold text-ink">{{ removeTarget ? memberName(removeTarget.member) : \'\' }}</span>',
    '      from this Space?',
    '    </p>',
    /* Honest about how little this does — nothing has been created yet, and saying so is what
       stops the dialog reading like a warning about deleting an account. */
    '    <p class="mt-3 text-[12px] text-sub">',
    '      They have not been invited yet, so nothing is sent or undone — they are simply taken',
    '      off this list. You can add them again at any point before you finish setup.',
    '    </p>',
    '    <template #footer>',
    '      <button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="removeTarget = null">Cancel</button>',
    '      <button type="button" class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold" @click="removeMember">Remove</button>',
    '    </template>',
    '  </pb-modal>',
    '</div>'
  ].join('\n')
}, { root: 'help-center-setup' });
