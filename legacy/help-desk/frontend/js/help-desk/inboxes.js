/* Help Desk › Settings › Inboxes (docs/features/help-desk.md — Phase 2, FR-2.1/2.3/2.5/2.6,
   and the Inbound Email requirements §2).
   ------------------------------------------------------------------
   An inbox is a name, the identity replies come from, and where new conversations land. What it
   is NOT any more is a typed inbound address: that address is generated, read-only, and shown
   here to be copied rather than edited (§2). Editing it would break a forwarding rule living in
   a mail provider this application cannot see.

   What an administrator connects instead is their own customer-facing address — support@acme.com
   — which is a page of its own, one press away from every row.
   ------------------------------------------------------------------ */
PB.boot('help-desk-inboxes', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      inboxes: b.inboxes || [],
      members: b.members || [],
      spaces: b.spaces || [],
      endpoints: b.endpoints || {},
      form: { open: false, editing: null, saving: false, errors: {}, model: this.blank() },
      regenerating: null
    };
  },
  // §11: arriving from a space with `?space=` opens the form with that space already chosen, so
  // an inbox created from inside a space is assigned to it without anybody having to remember.
  mounted: function () {
    var preset = (this.bootstrap || {}).new_in_space;
    if (preset) {
      this.openNew();
      this.form.model.help_desk_space_id = String(preset);
    }
  },
  computed: {
    spaceOptions: function () {
      return [{ value: '', label: 'No space — this inbox is not in one' }].concat(
        this.spaces.map(function (s) { return { value: String(s.id), label: s.name }; })
      );
    },
    assigneeOptions: function () {
      var options = [{ value: '', label: 'Nobody — leave new conversations unassigned' }];
      return options.concat(this.members.map(function (m) {
        return { value: String(m.id), label: m.name, desc: m.role_label, initial: m.initial };
      }));
    }
  },
  methods: {
    blank: function () {
      return {
        name: '', outbound_from_name: '', outbound_from_address: '',
        default_assignee_id: '', help_desk_space_id: ''
      };
    },
    openNew: function () {
      this.form = { open: true, editing: null, saving: false, errors: {}, model: this.blank() };
    },
    openEdit: function (inbox) {
      this.form = {
        open: true, editing: inbox, saving: false, errors: {},
        model: {
          name: inbox.name || '',
          // §12: an inbox's space is changed from its own settings, as well as from the space.
          help_desk_space_id: inbox.space_id ? String(inbox.space_id) : '',
          outbound_from_name: inbox.outbound_from_name || '',
          outbound_from_address: inbox.outbound_from_address || '',
          // A string, because <pb-combo> compares option values as strings.
          default_assignee_id: inbox.default_assignee_id ? String(inbox.default_assignee_id) : ''
        }
      };
    },
    copy: function (text) {
      var self = this;
      if (!text) return;
      var done = function () { self.$pb.toast('Address copied.'); };

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
      try { document.execCommand('copy'); done(); } catch (e) { self.$pb.toast('Could not copy.', 'error'); }
      document.body.removeChild(field);
    },
    save: async function () {
      if (!String(this.form.model.name || '').trim() || this.form.saving) return;
      this.form.saving = true; this.form.errors = {};

      var body = {
        name: String(this.form.model.name).trim(),
        help_desk_space_id: this.form.model.help_desk_space_id || null,
        // Empty means "not set", not "empty string" — the columns are nullable and a blank
        // sender is not the same as no sender.
        outbound_from_name: String(this.form.model.outbound_from_name || '').trim() || null,
        outbound_from_address: String(this.form.model.outbound_from_address || '').trim() || null,
        default_assignee_id: this.form.model.default_assignee_id || null
      };

      try {
        var url = this.form.editing
          ? this.$pb.withId(this.endpoints.inbox, this.form.editing.id)
          : this.endpoints.store;
        var resp = await this.$pb.api(url, { method: this.form.editing ? 'PATCH' : 'POST', body: body });
        this.inboxes = resp.inboxes || this.inboxes;
        this.form.open = false;
        this.$pb.toast(this.form.editing ? 'Inbox saved.' : 'Inbox created.');
      } catch (e) {
        this.form.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.form.saving = false;
    },
    // §2's explicit administrative action, behind a confirmation that says what it costs:
    // every forwarding rule pointing at the old address stops working, silently, elsewhere.
    regenerate: async function () {
      var inbox = this.regenerating;
      this.regenerating = null;
      if (!inbox) return;

      try {
        var resp = await this.$pb.api(inbox.regenerate_url, { method: 'POST' });
        this.inboxes = resp.inboxes || this.inboxes;
        if (this.form.editing && this.form.editing.id === inbox.id) {
          this.form.editing = this.inboxes.filter(function (i) { return i.id === inbox.id; })[0] || null;
        }
        this.$pb.toast('New inbound address generated. Update your forwarding rules.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    }
  },
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    '<div class="flex items-start justify-between gap-4">' +
    '<pb-section-head title="Inboxes" ' +
    'desc="A shared inbox is where customer conversations arrive. Each one has its own generated inbound address — forward your support addresses to it, decide who replies appear to come from, and choose where new conversations land."/>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0" @click="openNew">New inbox</button>' +
    '</div>' +

    '<div class="space-y-3">' +
    '<div v-for="i in inboxes" :key="i.id" class="border border-line rounded-xl px-4 py-3">' +
    '<div class="flex items-start gap-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[14px] font-medium text-ink">{{ i.name }}</div>' +

    '<div class="mt-1.5 flex items-center gap-2">' +
    '<code class="min-w-0 truncate rounded-md bg-hover border border-line px-2.5 h-7 flex items-center text-[12px] text-ink">' +
    '{{ i.inbound_address }}</code>' +
    '<button class="text-[12px] text-sub hover:text-ink shrink-0" @click="copy(i.inbound_address)">Copy</button>' +
    '</div>' +

    '<div class="mt-1.5 text-[12px] text-sub space-y-0.5">' +
    '<div v-if="i.space">In <span class="text-ink">{{ i.space }}</span></div>' +
    // An inbox in no space still receives mail — it just does so where nobody is looking.
    '<div v-else class="text-warning">Not in a space</div>' +
    // Said plainly. An inbox with no connected address receives nothing until somebody
    // configures forwarding — and nothing on the screen would otherwise say so.
    '<div v-if="!i.email_addresses_count" class="text-danger">No email addresses connected — nothing is being forwarded here yet</div>' +
    '<div v-else-if="!i.connected_addresses_count" class="text-danger">' +
    '{{ i.email_addresses_count }} address<span v-if="i.email_addresses_count !== 1">es</span> connected, none receiving mail yet</div>' +
    '<div v-else>{{ i.connected_addresses_count }} of {{ i.email_addresses_count }} address<span v-if="i.email_addresses_count !== 1">es</span> receiving mail</div>' +
    '<div>Replies from <span class="text-ink">{{ i.sender.name }} &lt;{{ i.sender.address }}&gt;</span></div>' +
    '<div v-if="i.default_assignee">New conversations assigned to <span class="text-ink">{{ i.default_assignee }}</span></div>' +
    '<div v-else>New conversations are left unassigned</div>' +
    '</div>' +

    '<div class="mt-2 flex items-center gap-3 text-[12px] text-faint">' +
    '<span>{{ i.conversations_count }} conversation<span v-if="i.conversations_count !== 1">s</span></span>' +
    '<span>·</span>' +
    '<span>{{ i.members_count }} member<span v-if="i.members_count !== 1">s</span> with named access</span>' +
    '</div></div>' +

    '<div class="flex items-center gap-3 shrink-0">' +
    '<a :href="i.addresses_url" class="text-[12px] text-sub hover:text-ink">Email addresses</a>' +
    '<button class="text-[12px] text-sub hover:text-ink" @click="openEdit(i)">Configure</button>' +
    '</div>' +
    '</div></div></div>' +

    // ---- form ----
    '<pb-modal :open="form.open" :title="form.editing ? \'Configure inbox\' : \'New inbox\'" @close="form.open = false" width="max-w-[560px]">' +
    '<div class="space-y-4">' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!form.errors.name}" v-model="form.model.name" placeholder="Support"/>' +
    '<p v-if="form.errors.name" class="text-[12px] text-danger mt-1">{{ form.errors.name[0] }}</p></div>' +

    // The generated address (§2). Shown, never edited — and on a new inbox it does not exist
    // yet, because it is generated when the inbox is saved.
    '<div v-if="form.editing">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbound address</label>' +
    '<div class="flex items-center gap-2">' +
    '<code class="flex-1 min-w-0 truncate rounded-md bg-hover border border-line px-3 h-9 flex items-center text-[13px] text-ink">' +
    '{{ form.editing.inbound_address }}</code>' +
    '<button class="h-9 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    '@click="copy(form.editing.inbound_address)">Copy</button>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-1">Generated for this inbox and read-only. Forward your support addresses here — ' +
    '<a :href="form.editing.addresses_url" class="text-brand hover:opacity-80">manage them</a>.</p>' +
    '<button class="text-[12px] text-danger hover:opacity-80 mt-1" @click="regenerating = form.editing">Generate a new address</button>' +
    '</div>' +
    '<p v-else class="text-[12px] text-sub">An inbound address is generated for this inbox when you create it.</p>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Space</label>' +
    '<pb-combo v-model="form.model.help_desk_space_id" :options="spaceOptions" placeholder="No space"/>' +
    '<p class="text-[12px] text-sub mt-1">An inbox belongs to one space. Moving it takes its conversations with it — they stay attached to the inbox.</p></div>' +

    '<div class="grid grid-cols-2 gap-3">' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Reply-from name</label>' +
    '<input class="pb-input" v-model="form.model.outbound_from_name" placeholder="Acme Support"/></div>' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Reply-from address</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!form.errors.outbound_from_address}" ' +
    'v-model="form.model.outbound_from_address" type="email" placeholder="support@yourcompany.com"/>' +
    '<p v-if="form.errors.outbound_from_address" class="text-[12px] text-danger mt-1">{{ form.errors.outbound_from_address[0] }}</p></div>' +
    '</div>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Default assignee</label>' +
    '<pb-combo v-model="form.model.default_assignee_id" :options="assigneeOptions" placeholder="Nobody"/>' +
    '<p class="text-[12px] text-sub mt-1">Only Help Desk members can be assigned. Leaving it empty makes this inbox a shared queue.</p></div>' +

    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="form.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!form.model.name || form.saving" @click="save">{{ form.editing ? \'Save inbox\' : \'Create inbox\' }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="!!regenerating" title="Generate a new inbound address?" ' +
    'message="Mail sent to the current address will stop arriving. Every forwarding rule pointing at it has to be updated in your mail provider before this inbox receives anything again." ' +
    'confirm-label="Generate" @close="regenerating = null" @confirm="regenerate"/>' +

    '</div>'
}, { root: 'help-desk-inboxes-root' });
