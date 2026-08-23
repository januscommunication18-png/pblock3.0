/* Help Center — the Create Space dialog (docs/features/help-center.md §4, §15).
   ------------------------------------------------------------------
   "After initial onboarding, authorized users can create additional Spaces using Spaces + …
   Creating additional Spaces should not trigger the complete first-time onboarding" (§4).

   Same four fields as the wizard's step 1 and the same endpoint's twin, because it is the same
   act — HelpCenterSpaceManager is what stops the two from producing different Spaces.

   The trigger lives OUTSIDE this root: it is the "+" in the Help Center sidebar, rendered by a
   partial that every screen includes. Bound by id on mount, the way the global "New work item"
   button is, so the sidebar does not have to know this component exists.
   ------------------------------------------------------------------ */
PB.boot('help-center-space', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      typeSuggestions: b.typeSuggestions || [],
      typeMax: b.typeMax || 8,
      typeMaxLength: b.typeMaxLength || 40,
      leads: b.leads || [],
      endpoint: b.endpoint || '',
      open: false,
      saving: false,
      errors: {},
      form: { name: '', description: '', inbound_display_name: '', types: [], lead_user_id: '' }
    };
  },

  computed: {
    leadOptions: function () {
      return this.leads.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
      });
    },

    /* What the customer will see (P65).
       The typed name, or the Space name when nobody has typed one — the same fallback
       HelpCenterSpace::senderName() applies on the server, so the preview is a promise rather
       than a guess. The address half is deliberately not shown as a real address: it does not
       exist yet, and printing a made-up one would be showing somebody a mailbox they cannot use. */
    senderPreview: function () {
      return String(this.form.inbound_display_name || '').trim()
        || String(this.form.name || '').trim();
    },

    canSubmit: function () {
      return !!String(this.form.name || '').trim() && this.form.types.length > 0
        && !!this.form.lead_user_id && !this.saving;
    }
  },

  mounted: function () {
    var self = this;
    var trigger = document.getElementById('help-center-new-space');

    if (trigger) trigger.addEventListener('click', function () { self.show(); });
  },

  methods: {
    show: function () {
      this.form = { name: '', description: '', inbound_display_name: '', types: [], lead_user_id: '' };
      this.errors = {};
      this.open = true;
    },

    close: function () { if (!this.saving) this.open = false; },

    submit: async function () {
      if (!this.canSubmit) return;
      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoint, { method: 'POST', body: this.form });
        // A full navigation rather than splicing the new Space into the sidebar: the sidebar is
        // server-rendered on every screen, and the natural next thing to do with a Space you
        // just made is to open it.
        window.location.href = res.url;
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.saving = false;
      }
    },

    err: function (field) { return (this.errors[field] || [])[0] || ''; }
  },

  template: [
    '<pb-modal :open="open" title="Create a Space" @close="close">',
    '  <div class="space-y-4">',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Space Name</label>',
    '      <input v-model="form.name" maxlength="100" class="pb-input w-full" placeholder="Customer Support" />',
    '      <p v-if="err(\'name\')" class="mt-1 text-[12px] text-danger">{{ err(\'name\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Description <span class="text-faint font-normal">(optional)</span></label>',
    '      <textarea v-model="form.description" maxlength="500" rows="3" class="pb-textarea w-full"></textarea>',
    '      <p v-if="err(\'description\')" class="mt-1 text-[12px] text-danger">{{ err(\'description\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Inbound Email Display Name <span class="text-faint font-normal">(optional)</span></label>',
    '      <input v-model="form.inbound_display_name" maxlength="100" class="pb-input w-full" :placeholder="form.name || \'eBay Support\'" />',
    '      <p class="mt-1 text-[12px] text-faint">This name will be shown to customers as the sender name when emails are sent from this Space.</p>',
    '      <p v-if="senderPreview" class="mt-1.5 text-[12px] text-sub _moretogether-break">',
    '        <span class="font-semibold text-ink">{{ senderPreview }}</span>',
    '        <span class="text-faint"> &lt;inbound address generated after the Space is created&gt;</span>',
    '      </p>',
    '      <p v-if="err(\'inbound_display_name\')" class="mt-1 text-[12px] text-danger">{{ err(\'inbound_display_name\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Space Type</label>',
    '      <pb-tags v-model="form.types" :suggestions="typeSuggestions" :max="typeMax" :max-length="typeMaxLength"',
    '               placeholder="Type a Space type and press Enter" />',
    '      <p v-if="err(\'types\')" class="mt-1 text-[12px] text-danger">{{ err(\'types\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Space Lead</label>',
    '      <pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="Search members…" :invalid="!!err(\'lead_user_id\')" />',
    '      <p v-if="err(\'lead_user_id\')" class="mt-1 text-[12px] text-danger">{{ err(\'lead_user_id\') }}</p>',
    '    </div>',
    '  </div>',
    '  <template #footer>',
    '    <button type="button" @click="close" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '    <button type="button" @click="submit" :disabled="!canSubmit"',
    '            class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">',
    '      {{ saving ? \'Creating…\' : \'Create Space\' }}',
    '    </button>',
    '  </template>',
    '</pb-modal>'
  ].join('\n')
}, { root: 'help-center-space-root' });
