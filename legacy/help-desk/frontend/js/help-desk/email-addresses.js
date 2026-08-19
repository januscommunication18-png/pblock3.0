/* Help Desk › Settings › Inboxes › Email Addresses (Inbound Email requirements §7, §8, §10).
   ------------------------------------------------------------------
   One inbox's connected addresses, each with the status of a forwarding rule that lives in
   somebody else's mail provider.

   Which is the thing this screen has to be honest about: Project Block cannot see that rule, so
   every status here is either "a message arrived" or "nothing has arrived yet". The hints say so
   in words rather than leaving it to the colour of a badge, because each state needs a different
   thing done about it, in a different system.
   ------------------------------------------------------------------ */
PB.boot('help-desk-email-addresses', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      inbox: b.inbox || {},
      addresses: b.addresses || [],
      endpoints: b.endpoints || {},
      busy: null,
      confirming: null
    };
  },
  methods: {
    // The tint classes live in styles.css: a 10%-opacity utility is not in the compiled
    // Tailwind build, so `bg-success/10` here would render as no background at all.
    badgeClass: function (row) {
      if (row.status === 'connected') return '_moretogether-badge--ok';
      if (row.status === 'error') return '_moretogether-badge--error';
      if (row.status === 'waiting_for_email') return '_moretogether-badge--wait';
      if (row.status === 'disabled') return '_moretogether-badge--off';
      return '';
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
      try { document.execCommand('copy'); done(); } catch (e) { self.$pb.toast('Could not copy.', 'error'); }
      document.body.removeChild(field);
    },
    send: async function (url, options, message) {
      this.busy = url;
      try {
        var resp = await this.$pb.api(url, options);
        this.addresses = resp.addresses || this.addresses;
        if (message) this.$pb.toast(message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.busy = null;
    },
    // §8. Nothing is sent from here — it cannot be: the test message has to travel through the
    // customer's own mail provider, so the only thing that can send it is a person writing to
    // their support address. This starts the clock and says what to do.
    verify: function (row) {
      this.send(
        this.$pb.withId(this.endpoints.verify, row.id),
        { method: 'POST' },
        'Send a message to ' + row.address + '. It appears here once it reaches us.'
      );
    },
    setDisabled: function (row, disabled) {
      this.send(
        this.$pb.withId(this.endpoints.address, row.id),
        { method: 'PATCH', body: { disabled: disabled } },
        disabled ? 'Address disabled.' : 'Address enabled.'
      );
    },
    remove: function () {
      var row = this.confirming;
      this.confirming = null;
      if (!row) return;
      this.send(this.$pb.withId(this.endpoints.address, row.id), { method: 'DELETE' }, 'Address removed.');
    }
  },
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    '<div class="flex items-start justify-between gap-4">' +
    '<pb-section-head :title="inbox.name + \' — email addresses\'" ' +
    'desc="The addresses your customers write to, forwarded into this inbox. Each one shows whether mail is actually arriving."/>' +
    '<a :href="endpoints.create" class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 flex items-center">New email address</a>' +
    '</div>' +

    // ---- the inbox's own generated address (§2, §6) ----
    '<div class="border border-line rounded-xl p-4 mb-5">' +
    '<div class="text-[13px] font-semibold text-ink">Inbound address for this inbox</div>' +
    '<p class="text-[12px] text-sub mt-1">Generated and read-only. Forward your support addresses here.</p>' +
    '<div class="mt-3 flex items-center gap-2">' +
    '<code class="flex-1 min-w-0 truncate rounded-md bg-hover border border-line px-3 h-9 flex items-center text-[13px] text-ink">' +
    '{{ inbox.inbound_address }}</code>' +
    '<button class="h-9 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    '@click="copy(inbox.inbound_address, \'Address\')">Copy</button>' +
    '<button class="h-9 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover shrink-0" ' +
    '@click="copy(inbox.instructions, \'Instructions\')">Copy instructions</button>' +
    '</div></div>' +

    // ---- the list (§10) ----
    '<pb-empty v-if="!addresses.length" title="No email addresses yet" ' +
    'subtitle="Connect the address your customers already write to, then forward it here to start receiving conversations.">' +
    '<a :href="endpoints.create" class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 flex items-center">New email address</a>' +
    '</pb-empty>' +

    '<div v-else class="space-y-3">' +
    '<div v-for="a in addresses" :key="a.id" class="border border-line rounded-xl px-4 py-3">' +
    '<div class="flex items-start gap-3">' +
    '<div class="min-w-0 flex-1">' +

    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-medium text-ink">{{ a.address }}</span>' +
    '<span class="_moretogether-badge" :class="badgeClass(a)">{{ a.status_label }}</span>' +
    '</div>' +

    // What the status MEANS, in a sentence. Every one of these states is something the reader
    // has to act on in another system, and a word on its own does not say what.
    '<p class="text-[12px] text-sub mt-1">{{ a.status_hint }}</p>' +

    '<div class="mt-2 text-[12px] text-sub space-y-0.5">' +
    '<div>Forwards to <span class="text-ink _moretogether-break">{{ a.inbound_address }}</span></div>' +
    '<div v-if="a.last_email_at">Last email {{ a.last_email_at }}</div>' +
    '<div v-if="a.verified_at" class="text-success">Connection verified {{ a.verified_at }}</div>' +
    '</div>' +

    '<div class="mt-2 flex items-center gap-3 text-[12px]">' +
    '<button class="text-sub hover:text-ink" @click="copy(a.inbound_address, \'Address\')">Copy inbound address</button>' +
    '<button class="text-sub hover:text-ink" @click="copy(a.instructions, \'Instructions\')">Copy setup</button>' +
    '<button v-if="!a.disabled" class="text-sub hover:text-ink" :disabled="busy" @click="verify(a)">Verify</button>' +
    '<button v-if="!a.disabled" class="text-sub hover:text-ink" :disabled="busy" @click="setDisabled(a, true)">Disable</button>' +
    '<button v-else class="text-sub hover:text-ink" :disabled="busy" @click="setDisabled(a, false)">Enable</button>' +
    '<button class="text-danger hover:opacity-80" @click="confirming = a">Delete</button>' +
    '</div>' +

    '</div></div></div></div>' +

    '<pb-confirm :open="!!confirming" title="Remove this email address?" ' +
    ':message="\'Conversations already received stay where they are. Mail forwarded to this inbox will still arrive — only your mail provider can stop that.\'" ' +
    'confirm-label="Remove" @close="confirming = null" @confirm="remove"/>' +

    '</div>'
}, { root: 'help-desk-email-addresses-root' });
