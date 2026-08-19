/* Help Center — the Edit Space dialog (docs/features/help-center.md, P3 §5).
   ------------------------------------------------------------------
   The counterpart to the six-step wizard: the wizard CREATES, this UPDATES, and P3 §26 keeps
   them apart — "creating a Space must always be a CREATE operation; it must never update the
   currently selected Space". The id here comes from the route, so this can only ever write to
   the Space you are looking at.

   Same controls as the wizard's step 1, deliberately: Space Type and Department Group are the
   same free-text tag inputs, and the Lead the same searchable member picker. A field that looks
   different in two places is a field people fill in differently.
   ------------------------------------------------------------------ */
PB.boot('help-center-space-edit', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};
    var s = b.space || {};

    return {
      can: !!b.can,
      endpoint: b.endpoint || '',
      typeSuggestions: b.typeSuggestions || [],
      typeMax: b.typeMax || 8,
      typeMaxLength: b.typeMaxLength || 40,
      groupMax: b.groupMax || 20,
      leads: b.leads || [],

      open: false,
      saving: false,
      errors: {},

      // Seeded from the server, so the dialog opens filled in rather than blank.
      form: {
        name: s.name || '',
        description: s.description || '',
        types: (s.types || []).slice(),
        department_groups: (s.department_groups || []).slice(),
        lead_user_id: s.lead_user_id || ''
      },
      pristine: JSON.stringify(s)
    };
  },

  computed: {
    leadOptions: function () {
      return this.leads.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.email, avatar: p.avatar, initial: p.initial };
      });
    },

    canSave: function () {
      return !!String(this.form.name || '').trim()
        && this.form.types.length > 0
        && !!this.form.lead_user_id
        && !this.saving;
    }
  },

  mounted: function () {
    var self = this;
    var trigger = document.getElementById('help-center-edit-space');

    // The button lives in the page header, outside this root — bound by id, the same way the
    // sidebar's create actions are.
    if (trigger) trigger.addEventListener('click', function () { self.show(); });
  },

  methods: {
    show: function () {
      // Reset to what the server last confirmed, so cancelling twice cannot leave edits behind.
      var s = JSON.parse(this.pristine);
      this.form = {
        name: s.name || '',
        description: s.description || '',
        types: (s.types || []).slice(),
        department_groups: (s.department_groups || []).slice(),
        lead_user_id: s.lead_user_id || ''
      };
      this.errors = {};
      this.open = true;
    },

    close: function () { if (!this.saving) this.open = false; },

    err: function (field) { return (this.errors[field] || [])[0] || ''; },

    rowErr: function (prefix) {
      var keys = Object.keys(this.errors || {});
      for (var i = 0; i < keys.length; i++) {
        if (keys[i].indexOf(prefix) === 0) return (this.errors[keys[i]] || [])[0] || '';
      }
      return '';
    },

    save: async function () {
      if (!this.canSave) return;

      this.saving = true;
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoint, { method: 'PATCH', body: this.form });
        this.$pb.toast(res.message);
        // Reloaded rather than patched in place: the name appears in the toolbar, the sidebar
        // tree and the page title, and re-rendering them by hand is three ways to get it wrong.
        window.location.reload();
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not save the Space.'), 'error');
        this.saving = false;
      }
    }
  },

  template: [
    '<pb-modal :open="open" title="Edit Space" @close="close">',
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
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Space Type</label>',
    '      <pb-tags v-model="form.types" :suggestions="typeSuggestions" :max="typeMax" :max-length="typeMaxLength" placeholder="Type a Space type and press Enter" />',
    '      <p v-if="err(\'types\') || rowErr(\'types.\')" class="mt-1 text-[12px] text-danger">{{ err(\'types\') || rowErr(\'types.\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Department Group <span class="text-faint font-normal">(optional)</span></label>',
    '      <pb-tags v-model="form.department_groups" :max="groupMax" :max-length="typeMaxLength" placeholder="Type a department group and press Enter" />',
    '      <p v-if="err(\'department_groups\') || rowErr(\'department_groups.\')" class="mt-1 text-[12px] text-danger">{{ err(\'department_groups\') || rowErr(\'department_groups.\') }}</p>',
    '    </div>',
    '    <div>',
    '      <label class="block text-[12px] font-semibold text-ink mb-1">Space Lead</label>',
    '      <pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="Search members…" :invalid="!!err(\'lead_user_id\')" />',
    '      <p v-if="err(\'lead_user_id\')" class="mt-1 text-[12px] text-danger">{{ err(\'lead_user_id\') }}</p>',
    '    </div>',
    '  </div>',
    '  <template #footer>',
    '    <button type="button" @click="close" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '    <button type="button" @click="save" :disabled="!canSave" class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">{{ saving ? \'Saving…\' : \'Save changes\' }}</button>',
    '  </template>',
    '</pb-modal>'
  ].join('\n')
}, { root: 'help-center-space-edit' });
