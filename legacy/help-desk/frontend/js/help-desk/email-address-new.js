/* Help Desk › New Email Address (Inbound Email requirements §4, §5, §6, §9).
   ------------------------------------------------------------------
   A page with a URL, which used to be a modal. The reason is in the task rather than in the
   taste: connecting an address means going to another system — Gmail, Microsoft 365, cPanel —
   setting up a forwarding rule, and coming back. A modal cannot be come back to. This can be
   refreshed, bookmarked, reached with the Back button, and pasted to whoever actually
   administers the customer's mail.

   The page is built around the one fact somebody has to leave here with: the generated inbound
   address they must forward to. It is shown large, it cannot be edited, and both it and the
   whole instruction block can be copied in one press.
   ------------------------------------------------------------------ */
PB.boot('help-desk-email-address-new', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      inboxes: b.inboxes || [],
      endpoints: b.endpoints || {},
      // Preselected from the URL (§5, "if the page was opened from an Inbox"). A string,
      // because <pb-combo> compares option values as strings.
      inboxId: b.inbox_id ? String(b.inbox_id) : '',
      address: '',
      saving: false,
      errors: {}
    };
  },
  computed: {
    inboxOptions: function () {
      return this.inboxes.map(function (i) { return { value: String(i.id), label: i.name }; });
    },
    // The inbox the messages will land in, and with it the generated address to forward to.
    // Switching the picker changes the instructions, which is the whole reason the page carries
    // every inbox's address rather than fetching one.
    inbox: function () {
      var id = this.inboxId;
      return this.inboxes.filter(function (i) { return String(i.id) === id; })[0] || null;
    },
    inboundAddress: function () { return this.inbox ? this.inbox.inbound_address : ''; },
    // §6's copyable block. Built with the address they typed, when they have typed one — an
    // instruction that names the actual mailbox is one fewer thing for the reader to work out.
    instructions: function () {
      if (!this.inbox) return '';
      var typed = String(this.address || '').trim();
      return typed
        ? this.inbox.instructions.split('your support address').join(typed)
        : this.inbox.instructions;
    },
    canSave: function () { return !!String(this.address || '').trim() && !!this.inboxId && !this.saving; }
  },
  methods: {
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

      // Older browsers, and any page not served over https, where the clipboard API is absent.
      var field = document.createElement('textarea');
      field.value = text;
      document.body.appendChild(field);
      field.select();
      try { document.execCommand('copy'); done(); } catch (e) { self.$pb.toast('Could not copy.', 'error'); }
      document.body.removeChild(field);
    },
    save: async function () {
      if (!this.canSave) return;
      this.saving = true; this.errors = {};

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.store, this.inboxId), {
          method: 'POST',
          body: { address: String(this.address).trim(), help_desk_inbox_id: this.inboxId }
        });
        // A real navigation, because this page is a real page (§9: back to the inbox's Email
        // Addresses list, where the server has flashed the success notification).
        window.location = resp.redirect || this.endpoints.cancel;
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.saving = false;
      }
    }
  },
  template:
    '<div class="max-w-[760px] mx-auto px-5 sm:px-8 py-8">' +

    '<pb-section-head title="New Email Address" ' +
    'desc="Connect an existing email address to this Inbox. We\'ll provide a unique Project Block inbound address that you can use as your forwarding destination."/>' +

    // ---- the address customers write to (§5) ----
    '<div class="_moretogether-stack">' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Email address</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.address}" v-model="address" type="email" ' +
    'placeholder="support@acme.com" @keyup.enter="save"/>' +
    '<p class="text-[12px] text-sub mt-1">The address your customers already send messages to.</p>' +
    '<p v-if="errors.address" class="text-[12px] text-danger mt-1">{{ errors.address[0] }}</p>' +
    '</div>' +

    // ---- which inbox receives it (§5) ----
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Inbox</label>' +
    '<pb-combo v-model="inboxId" :options="inboxOptions" placeholder="Choose an inbox"/>' +
    '<p class="text-[12px] text-sub mt-1">Messages forwarded from this address become conversations here.</p>' +
    '</div>' +

    // ---- the generated address (§5, §6) ----
    '<div class="border border-line rounded-xl p-4">' +
    '<div class="text-[13px] font-semibold text-ink">Project Block inbound address</div>' +
    '<p class="text-[12px] text-sub mt-1">Generated for this inbox and read-only. Forward your email here.</p>' +

    '<div class="mt-3 flex items-center gap-2">' +
    // Read-only, and looks it. An input somebody can put a caret in is an input they will try
    // to edit — and editing it would break a forwarding rule we cannot see (§2).
    '<code class="flex-1 min-w-0 truncate rounded-md bg-hover border border-line px-3 h-9 flex items-center text-[13px] text-ink">' +
    '{{ inboundAddress || \'—\' }}</code>' +
    '<button class="h-9 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    '@click="copy(inboundAddress, \'Address\')">Copy</button>' +
    '</div>' +

    // ---- forward your email (§6) ----
    '<div class="mt-4 rounded-lg bg-hover px-3.5 py-3">' +
    '<div class="text-[12px] font-semibold text-ink">Forward your email</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Configure your existing email provider to forward incoming messages to the address above.</p>' +

    '<div class="mt-3 space-y-1.5">' +
    '<div class="text-[11px] uppercase tracking-wide text-faint">Your email</div>' +
    '<div class="text-[13px] text-ink">{{ address || \'support@acme.com\' }}</div>' +
    '<div class="text-faint">↓</div>' +
    '<div class="text-[11px] uppercase tracking-wide text-faint">Forward to</div>' +
    '<div class="text-[13px] text-ink _moretogether-break">{{ inboundAddress || \'—\' }}</div>' +
    '</div>' +

    '<button class="mt-3 text-[12px] font-semibold text-brand hover:opacity-80" ' +
    '@click="copy(instructions, \'Instructions\')">Copy forwarding instructions</button>' +
    '</div></div>' +

    // ---- footer (§9) ----
    '<div class="flex items-center justify-end gap-2 pt-2">' +
    '<a :href="endpoints.cancel" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover flex items-center">Cancel</a>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!canSave" @click="save">Save email address</button>' +
    '</div>' +

    '</div></div>'
}, { root: 'help-desk-email-address-new-root' });
