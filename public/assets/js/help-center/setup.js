/* Help Center — first-run onboarding (docs/features/help-center.md §2–§12, §21).
   ------------------------------------------------------------------
   Welcome → Create Space → Set Up Inbox → Inbound Configuration → Ready.

   ONE component for all five screens, because they are one flow with one piece of state: the
   Space made in step 1 is what step 2 attaches an Inbox to, and step 3 shows the address that
   Inbox was given. Split into five, each would have to re-fetch what the one before it already
   had in hand.

   The server owns which step is outstanding (HelpCenterOnboarding, HC-D3) and says so in the
   bootstrap, so reloading mid-wizard — or coming back tomorrow — resumes rather than restarts.
   ------------------------------------------------------------------ */
PB.boot('help-center-setup', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      // 0 is the welcome screen (§2); 1–3 are the steps; 4 is "Ready" (§12). The server sends
      // 1–4, and a run that has not started yet opens on the welcome screen rather than
      // dropping somebody straight into a form.
      step: b.step > 1 ? b.step : 0,
      resumeStep: b.step || 1,
      progress: b.progress || [],
      space: b.space || null,
      inbox: b.inbox || null,
      typeSuggestions: b.typeSuggestions || [],
      typeMax: b.typeMax || 8,
      typeMaxLength: b.typeMaxLength || 40,
      leads: b.leads || [],
      providers: b.providers || [],
      canCreate: !!b.canCreate,
      endpoints: b.endpoints || {},
      urls: b.urls || {},

      spaceForm: { name: '', description: '', types: [], lead_user_id: '' },
      inboxForm: { name: '' },

      // Addresses live here until step 2 is submitted: §6's table shows rows with a Remove
      // action, so they exist on screen before they exist in the database.
      addresses: [],
      addressForm: { email: '', name: '' },
      addressError: '',
      addingAddress: false,

      openProvider: '',
      copied: false,
      saving: false,
      errors: {}
    };
  },

  computed: {
    /** The Space Lead picker (§3): a face, a name and an email, searchable. */
    leadOptions: function () {
      return this.leads.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
      });
    },

    canContinueSpace: function () {
      return !!String(this.spaceForm.name || '').trim()
        && this.spaceForm.types.length > 0
        && !!this.spaceForm.lead_user_id
        && !this.saving;
    },

    canContinueInbox: function () {
      return !!String(this.inboxForm.name || '').trim() && !this.saving;
    },

    inboundAddress: function () {
      return (this.inbox && this.inbox.inbound_address) || '';
    },

    leadName: function () {
      return (this.space && this.space.lead && this.space.lead.name) || '—';
    },

    /* A per-VALUE type error — the server keys these `types.0`, `types.1`, and the field is one
       control, so the first message is shown against the control rather than against a row the
       user cannot see. */
    typeError: function () {
      var keys = Object.keys(this.errors || {});

      for (var i = 0; i < keys.length; i++) {
        if (keys[i].indexOf('types.') === 0) return (this.errors[keys[i]] || [])[0] || '';
      }

      return '';
    }
  },

  mounted: function () {
    // A resumed run already has a Space; show its name in the summary rather than an empty
    // field the user would have to fill in again.
    if (this.space && this.space.name) this.spaceForm.name = this.space.name;
  },

  methods: {
    start: function () { this.step = this.resumeStep; },

    cancel: function () { window.location.href = this.urls.cancel; },

    /** Step 1 → 2 (§3). */
    submitSpace: async function () {
      if (!this.canContinueSpace) return;
      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoints.space, { method: 'POST', body: this.spaceForm });
        this.space = res.space;
        this.progress = res.progress;
        this.step = res.step;
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not create the Space.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    /**
     * Add one address to the list (§6).
     *
     * Asks the server first, because §7 wants "already connected to another Inbox" said at the
     * moment the address is added — not two fields later when the step is submitted. Step 2's
     * own validation re-checks everything: a check made a minute ago is not a guarantee.
     */
    addAddress: async function () {
      var email = String(this.addressForm.email || '').trim().toLowerCase();
      if (!email || this.addingAddress) return;

      this.addressError = '';

      // Already in the list — answerable here, and a round trip to be told so would be a
      // round trip that taught the user nothing.
      var dupe = this.addresses.some(function (a) { return a.email === email; });
      if (dupe) { this.addressError = 'This address is already in the list.'; return; }

      this.addingAddress = true;

      try {
        await this.$pb.api(this.endpoints.checkAddress, { method: 'POST', body: { email: email } });
        this.addresses.push({ email: email, name: String(this.addressForm.name || '').trim() });
        this.addressForm = { email: '', name: '' };
      } catch (e) {
        this.addressError = (e.data && e.data.message) || 'Could not add that address.';
      } finally {
        this.addingAddress = false;
      }
    },

    removeAddress: function (i) { this.addresses.splice(i, 1); },

    /** Step 2 → 3 (§5, §6). The Inbox and its addresses are written together. */
    submitInbox: async function () {
      if (!this.canContinueInbox) return;
      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoints.inbox, {
          method: 'POST',
          body: { name: this.inboxForm.name, addresses: this.addresses }
        });
        this.inbox = res.inbox;
        this.progress = res.progress;
        this.step = res.step;
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not create the Inbox.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    /** Step 3 → Ready (§12). */
    complete: async function () {
      this.saving = true;

      try {
        var res = await this.$pb.api(this.endpoints.complete, { method: 'POST' });
        this.progress = res.progress;
        this.space = (res.summary && res.summary.space) || this.space;
        this.inbox = (res.summary && res.summary.inbox) || this.inbox;
        this.step = 4;
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not finish setup.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    copyAddress: function () {
      var self = this;
      var done = function () {
        self.copied = true;
        window.setTimeout(function () { self.copied = false; }, 2000);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(this.inboundAddress).then(done, function () {
          self.$pb.toast('Could not copy — select the address and copy it manually.', 'error');
        });
        return;
      }

      // No clipboard API (an insecure origin, an old browser). Saying so beats a button that
      // silently does nothing.
      this.$pb.toast('Select the address and copy it manually.', 'error');
    },

    toggleProvider: function (key) { this.openProvider = this.openProvider === key ? '' : key; },

    goToHelpCenter: function () { window.location.href = this.urls.helpCenter; },

    invite: function () { window.location.href = this.urls.invite; },

    err: function (field) { return (this.errors[field] || [])[0] || ''; },

    /* wiIcon() is the JS twin of pb_icon(): same registry, same names, so an icon cannot mean
       one thing in Blade and another here. Returns markup, hence v-html. */
    icon: function (name, size) { return window.wiIcon ? window.wiIcon(name, size || 16) : ''; }
  },

  template: [
    /* 792px = 720 + 10%. `mx-auto` keeps the panel centred and the padding is untouched, so
       the change is only visible on viewports wide enough for the old cap to have been the
       constraint — narrower screens were already governed by `px-5 sm:px-8` and look the same.
       Scoped to the wizard: the Help Center's other screens have their own wrappers. */
    '<div class="mx-auto max-w-[792px] px-5 sm:px-8 py-10">',

    /* ---- progress (§2) — on every screen, so the four stages stay visible throughout ---- */
    '  <ol v-if="step > 0" class="flex items-center gap-2 mb-8">',
    '    <li v-for="(s, i) in progress" :key="s.number" class="flex items-center gap-2 min-w-0">',
    '      <span :class="[\'h-6 w-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold\',',
    '            s.state === \'done\' ? \'bg-brand text-white\' : (s.state === \'current\' ? \'bg-sel text-brand border border-stroke\' : \'bg-hover text-faint\')]">',
    '        {{ s.number }}',
    '      </span>',
    '      <span :class="[\'text-[12px] truncate\', s.state === \'todo\' ? \'text-faint\' : \'text-ink\']">{{ s.label }}</span>',
    '      <span v-if="i < progress.length - 1" class="w-6 h-px bg-line shrink-0"></span>',
    '    </li>',
    '  </ol>',

    /* ---- 0: welcome (§2) ---- */
    '  <div v-if="step === 0">',
    '    <h1 class="text-[22px] font-semibold text-head">Help Center</h1>',
    '    <p class="mt-2 text-[13px] text-sub leading-relaxed max-w-[560px]">',
    '      Set up your Help Center to receive, organize, assign, and manage customer conversations from one place.',
    '    </p>',
    '    <div class="mt-6 rounded-xl border border-line bg-[#f9fafb] aspect-video grid place-items-center">',
    '      <div class="text-center text-sub">',
    '        <div class="mx-auto h-12 w-12 rounded-full bg-white border border-line grid place-items-center" v-html="icon(\'play\', 20)"></div>',
    '        <p class="mt-2 text-[12px]">Product tour — coming soon</p>',
    '      </div>',
    '    </div>',
    '    <h2 class="mt-8 text-[14px] font-semibold text-head">Getting started</h2>',
    '    <p class="mt-1 text-[13px] text-sub leading-relaxed">',
    '      Three short steps: create a Space to organize your support, add the Inbox that receives it,',
    '      then point your existing support address at it. Your customers keep writing to the address they already use.',
    '    </p>',
    '    <ol class="mt-4 space-y-2">',
    '      <li v-for="s in progress" :key="s.number" class="flex items-center gap-3 text-[13px]">',
    '        <span class="h-6 w-6 shrink-0 rounded-full bg-hover text-faint grid place-items-center text-[11px] font-semibold">{{ s.number }}</span>',
    '        <span class="text-ink">{{ s.label }}</span>',
    '      </li>',
    '    </ol>',
    '    <div class="mt-8 flex items-center gap-2">',
    '      <button type="button" @click="start" :disabled="!canCreate"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">',
    '        Get Started',
    '      </button>',
    '      <button type="button" @click="cancel" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '        Cancel Setup',
    '      </button>',
    '    </div>',
    /* Somebody who may not configure the Help Center should be told why the button is down,
       rather than left clicking a disabled control (§19). */
    '    <p v-if="!canCreate" class="mt-3 text-[12px] text-sub">',
    '      Only a workspace owner or admin can set up the Help Center. Ask one of them to finish this step.',
    '    </p>',
    '  </div>',

    /* ---- 1: create Space (§3) ---- */
    '  <div v-else-if="step === 1">',
    '    <h1 class="text-[18px] font-semibold text-head">Create a Space</h1>',
    '    <p class="mt-1 text-[13px] text-sub max-w-[560px]">',
    '      A Space is the top level of your Help Center — Customer Support, Billing, Partner Support.',
    '      You can add more later.',
    '    </p>',

    '    <div class="mt-6 space-y-4 max-w-[520px]">',
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Space Name</label>',
    '        <input v-model="spaceForm.name" maxlength="100" class="pb-input w-full" placeholder="Customer Support" />',
    '        <p v-if="err(\'name\')" class="mt-1 text-[12px] text-danger">{{ err(\'name\') }}</p>',
    '      </div>',

    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Description <span class="text-faint font-normal">(optional)</span></label>',
    '        <textarea v-model="spaceForm.description" maxlength="500" rows="3" class="pb-textarea w-full"',
    '                  placeholder="Handles customer product questions, technical issues, and account support."></textarea>',
    '        <p v-if="err(\'description\')" class="mt-1 text-[12px] text-danger">{{ err(\'description\') }}</p>',
    '      </div>',

    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Space Type</label>',
    /* Free text, many values, chips with a x (§3). pb-tags already does Enter/comma to add,
       case-insensitive de-duplication, trimming and Backspace-on-empty; the suggestions below
       it are additive shortcuts, NOT a list this validates against. */
    '        <pb-tags v-model="spaceForm.types" :suggestions="typeSuggestions" :max="typeMax" :max-length="typeMaxLength"',
    '                 placeholder="Type a Space type and press Enter" />',
    '        <p v-if="err(\'types\')" class="mt-1 text-[12px] text-danger">{{ err(\'types\') }}</p>',
    '        <p v-else-if="typeError" class="mt-1 text-[12px] text-danger">{{ typeError }}</p>',
    '      </div>',

    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Space Lead</label>',
    '        <pb-combo v-model="spaceForm.lead_user_id" :options="leadOptions" placeholder="Search members…" :invalid="!!err(\'lead_user_id\')" />',
    '        <p class="mt-1 text-[12px] text-sub">The person primarily responsible for this Space.</p>',
    '        <p v-if="err(\'lead_user_id\')" class="mt-1 text-[12px] text-danger">{{ err(\'lead_user_id\') }}</p>',
    '      </div>',
    '    </div>',

    '    <div class="mt-8 flex items-center gap-2">',
    '      <button type="button" @click="submitSpace" :disabled="!canContinueSpace"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">',
    '        {{ saving ? \'Creating…\' : \'Continue\' }}',
    '      </button>',
    '      <button type="button" @click="cancel" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '        Cancel Setup',
    '      </button>',
    '    </div>',
    '  </div>',

    /* ---- 2: set up Inbox (§5, §6) ---- */
    '  <div v-else-if="step === 2">',
    '    <h1 class="text-[18px] font-semibold text-head">Set up your Inbox</h1>',
    '    <p class="mt-1 text-[13px] text-sub max-w-[560px]">',
    '      An Inbox is where incoming customer conversations are delivered.',
    '      <span v-if="space">It belongs to <span class="font-semibold text-ink">{{ space.name }}</span>.</span>',
    '    </p>',

    '    <div class="mt-6 max-w-[520px]">',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Inbox Name</label>',
    '      <input v-model="inboxForm.name" maxlength="100" class="pb-input w-full" placeholder="General Support" />',
    '      <p v-if="err(\'name\')" class="mt-1 text-[12px] text-danger">{{ err(\'name\') }}</p>',
    '    </div>',

    '    <div class="mt-8">',
    '      <h2 class="text-[14px] font-semibold text-head">Email Addresses</h2>',
    '      <p class="mt-1 text-[13px] text-sub max-w-[560px]">',
    '        Add the email addresses customers use to contact your team. Messages sent to these addresses',
    '        can be forwarded into this Inbox.',
    '      </p>',

    '      <div class="mt-4 flex flex-wrap items-start gap-2">',
    '        <div class="flex-1 min-w-[200px]">',
    '          <input v-model="addressForm.email" type="email" class="pb-input w-full" placeholder="support@company.com"',
    '                 @keydown.enter.prevent="addAddress" />',
    '        </div>',
    '        <div class="flex-1 min-w-[160px]">',
    '          <input v-model="addressForm.name" maxlength="100" class="pb-input w-full" placeholder="Company Support"',
    '                 @keydown.enter.prevent="addAddress" />',
    '        </div>',
    '        <button type="button" @click="addAddress" :disabled="addingAddress || !addressForm.email"',
    '                class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">',
    '          Add',
    '        </button>',
    '      </div>',
    '      <p v-if="addressError" class="mt-2 text-[12px] text-danger">{{ addressError }}</p>',

    '      <table v-if="addresses.length" class="mt-4 w-full text-[13px]">',
    '        <thead>',
    '          <tr class="text-left text-[12px] text-faint border-b border-line">',
    '            <th class="py-2 font-medium">Name</th>',
    '            <th class="py-2 font-medium">Email Address</th>',
    '            <th class="py-2 font-medium">Status</th>',
    '            <th class="py-2 font-medium text-right">Action</th>',
    '          </tr>',
    '        </thead>',
    '        <tbody>',
    '          <tr v-for="(a, i) in addresses" :key="a.email" class="border-b border-line">',
    '            <td class="py-2 text-ink">{{ a.name || \'—\' }}</td>',
    '            <td class="py-2 text-ink">{{ a.email }}</td>',
    '            <td class="py-2"><span class="_moretogether-badge _moretogether-badge--off">Setup Required</span></td>',
    '            <td class="py-2 text-right">',
    '              <button type="button" @click="removeAddress(i)" class="text-[12px] text-sub hover:text-danger">Remove</button>',
    '            </td>',
    '          </tr>',
    '        </tbody>',
    '      </table>',
    '      <p v-else class="mt-4 text-[12px] text-faint">No addresses added yet. You can also add them later.</p>',
    '    </div>',

    '    <div class="mt-8 flex items-center gap-2">',
    '      <button type="button" @click="submitInbox" :disabled="!canContinueInbox"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">',
    '        {{ saving ? \'Creating…\' : \'Continue\' }}',
    '      </button>',
    '      <button type="button" @click="cancel" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '        Cancel Setup',
    '      </button>',
    '    </div>',
    '  </div>',

    /* ---- 3: inbound configuration (§9, §10, §11) ---- */
    '  <div v-else-if="step === 3">',
    '    <h1 class="text-[18px] font-semibold text-head">Configure inbound email</h1>',
    '    <p class="mt-1 text-[13px] text-sub max-w-[560px]">',
    '      Forward your existing support email to the address below. Your customers keep writing to the',
    '      address they already know.',
    '    </p>',

    '    <div class="mt-6 rounded-lg border border-line bg-[#f9fafb] px-4 py-4">',
    '      <div class="text-[12px] font-semibold text-ink">Your ProjectBlock inbound address</div>',
    '      <div class="mt-2 flex flex-wrap items-center gap-2">',
    '        <code class="flex-1 min-w-[240px] rounded-md border border-line bg-white px-3 py-2 text-[13px] text-ink break-all">{{ inboundAddress }}</code>',
    '        <button type="button" @click="copyAddress"',
    '                class="inline-flex items-center h-9 px-4 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">',
    '          {{ copied ? \'Copied\' : \'Copy Address\' }}',
    '        </button>',
    '      </div>',
    '    </div>',

    /* §9's diagram, said as text: customer address → forward → inbound address → Inbox. */
    '    <div class="mt-6 rounded-lg border border-line px-4 py-4">',
    '      <div class="text-[12px] font-semibold text-ink mb-3">How mail reaches you</div>',
    '      <div class="space-y-1 text-[13px]">',
    '        <div class="text-ink">{{ (inbox && inbox.addresses && inbox.addresses.length) ? inbox.addresses[0].email : \'support@company.com\' }}</div>',
    '        <div class="text-faint text-[12px]">↓ forwarded by your mail provider</div>',
    '        <div class="text-ink break-all">{{ inboundAddress }}</div>',
    '        <div class="text-faint text-[12px]">↓</div>',
    '        <div class="text-ink font-semibold">{{ inbox ? inbox.name : \'Your Inbox\' }}</div>',
    '      </div>',
    '    </div>',

    /* §10's provider guides. */
    '    <div class="mt-6">',
    '      <h2 class="text-[14px] font-semibold text-head">Set up forwarding</h2>',
    '      <div class="mt-3 divide-y divide-line border-y border-line">',
    '        <div v-for="p in providers" :key="p.key">',
    '          <button type="button" @click="toggleProvider(p.key)" :aria-expanded="String(openProvider === p.key)"',
    '                  class="w-full flex items-center gap-2 py-3 text-left text-[13px] font-semibold text-ink">',
    '            <span class="text-faint text-[12px] w-3">{{ openProvider === p.key ? \'−\' : \'+\' }}</span>',
    '            {{ p.label }}',
    '          </button>',
    '          <ol v-if="openProvider === p.key" class="pb-3 pl-9 space-y-1.5 list-decimal text-[13px] text-sub">',
    '            <li v-for="(s, i) in p.steps" :key="i">{{ s }}</li>',
    '          </ol>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* §11's verification, stated honestly (HC-D7): the status exists, and nothing here can
       set it to Verified until inbound mail is actually received. */
    '    <div class="mt-6 rounded-lg border border-line px-4 py-3">',
    '      <div class="flex items-center gap-2">',
    '        <span class="text-[12px] font-semibold text-ink">Forwarding status</span>',
    '        <span class="_moretogether-badge _moretogether-badge--off">Setup Required</span>',
    '      </div>',
    '      <p class="mt-1 text-[12px] text-sub">',
    '        Your addresses will show as Verified once we receive forwarded mail from them. Receiving',
    '        inbound email arrives in the next release — you can finish setup now and your configuration is saved.',
    '      </p>',
    '    </div>',

    '    <div class="mt-8 flex items-center gap-2">',
    '      <button type="button" @click="complete" :disabled="saving"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">',
    '        {{ saving ? \'Finishing…\' : \'Continue\' }}',
    '      </button>',
    '    </div>',
    '  </div>',

    /* ---- 4: ready (§12) ---- */
    '  <div v-else>',
    '    <h1 class="text-[18px] font-semibold text-head">Your Help Center is ready</h1>',
    '    <p class="mt-1 text-[13px] text-sub">Here is what you set up.</p>',

    '    <dl class="mt-6 rounded-lg border border-line divide-y divide-line">',
    '      <div class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub">Space</dt>',
    '        <dd class="text-[13px] text-ink">{{ space ? space.name : \'—\' }}</dd>',
    '      </div>',
    '      <div class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub">Inbox</dt>',
    '        <dd class="text-[13px] text-ink">{{ inbox ? inbox.name : \'—\' }}</dd>',
    '      </div>',
    '      <div class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub">Connected addresses</dt>',
    '        <dd class="text-[13px] text-ink">',
    '          <span v-if="inbox && inbox.addresses && inbox.addresses.length">',
    '            <span v-for="(a, i) in inbox.addresses" :key="a.id">{{ i ? \', \' : \'\' }}{{ a.email }}</span>',
    '          </span>',
    '          <span v-else class="text-faint">None yet</span>',
    '        </dd>',
    '      </div>',
    '      <div class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub">Inbound address</dt>',
    '        <dd class="text-[13px] text-ink break-all">{{ inboundAddress }}</dd>',
    '      </div>',
    '      <div class="flex gap-4 px-4 py-3">',
    '        <dt class="w-40 shrink-0 text-[12px] text-sub">Space Lead</dt>',
    '        <dd class="text-[13px] text-ink">{{ leadName }}</dd>',
    '      </div>',
    '    </dl>',

    '    <div class="mt-8 flex items-center gap-2">',
    '      <button type="button" @click="goToHelpCenter"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold">',
    '        Go to Help Center',
    '      </button>',
    '      <button type="button" @click="invite"',
    '              class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '        Invite Team Members',
    '      </button>',
    '    </div>',
    '  </div>',

    '</div>'
  ].join('\n')
}, { root: 'help-center-setup' });
