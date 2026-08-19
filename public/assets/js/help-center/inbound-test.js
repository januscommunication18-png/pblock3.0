/* Help Center — the Space Overview's inbound test card (docs/features/help-center.md, P7).
   ------------------------------------------------------------------
   Proves the WHOLE chain, not just that we can send mail:

     ProjectBlock → customer inbox → forwarding rule → Postmark Inbound → webhook → parser

   Which is why "Send Test Email" succeeding never shows a pass. The probe goes to the Inbox's
   customer-facing address and only counts when its token comes back through the user's own
   forwarding rule — the server enforces that, and this only ever reports what it is told.

   Polls rather than subscribes: the answer arrives from an email round trip measured in tens of
   seconds, and a broadcast channel would add a Reverb dependency to the one screen whose job is
   diagnosing infrastructure.
   ------------------------------------------------------------------ */
PB.boot('help-center-inbound-test', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      test: b.test || null,
      lastPassed: b.lastPassed || null,
      inboundAddress: b.inboundAddress || '',
      canRun: !!b.canRun,
      endpoints: b.endpoints || {},
      urls: b.urls || {},
      starting: false,
      error: '',
      timer: null
    };
  },

  computed: {
    running: function () { return !!(this.test && this.test.running); },
    passed: function () { return !!(this.test && this.test.passed); },
    failed: function () { return !!(this.test && !this.test.running && !this.test.passed && this.test.explanation); },

    statusLabel: function () { return this.test ? this.test.status_label : 'Not tested'; },
    statusTone: function () { return this.test ? this.test.status_tone : 'off'; },

    countdown: function () {
      if (!this.running) return '';
      var s = this.test.seconds_remaining || 0;
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }
  },

  mounted: function () {
    // A test already in flight when the page loads keeps being watched.
    if (this.running) this.watch();
  },

  beforeUnmount: function () { this.stop(); },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    start: async function () {
      if (this.starting || this.running) return;

      this.starting = true;
      this.error = '';

      try {
        var res = await this.$pb.api(this.endpoints.start, { method: 'POST' });
        this.test = res.test;
        if (this.running) this.watch();
      } catch (e) {
        this.error = this.$pb.firstError(e, 'Could not start the test.');
      } finally {
        this.starting = false;
      }
    },

    /* Every 5s while a test is in flight — often enough to feel live, rarely enough not to
       hammer the server for the two minutes a test can take. */
    watch: function () {
      var self = this;
      this.stop();
      this.timer = window.setInterval(function () { self.poll(); }, 5000);
    },

    stop: function () {
      if (this.timer) { window.clearInterval(this.timer); this.timer = null; }
    },

    poll: async function () {
      try {
        var res = await this.$pb.api(this.endpoints.status, { method: 'GET' });
        this.test = res.test;
        this.lastPassed = res.last_passed;
        // Settled — passed, timed out or failed. Nothing more to wait for.
        if (!this.running) this.stop();
      } catch (e) {
        this.stop();
      }
    }
  },

  template: [
    '<div class="mt-6 max-w-[720px] rounded-lg border border-line">',

    '  <div class="px-4 py-3 border-b border-line">',
    '    <h2 class="text-[14px] font-semibold text-head">Test your inbound email</h2>',
    '    <p class="mt-1 text-[13px] text-sub">Confirm that your forwarding configuration is working correctly. Send a test email through your configured support address and verify that ProjectBlock receives and parses it successfully.</p>',
    '  </div>',

    '  <div class="px-4 py-3">',

    /* The address under test, always visible. */
    '    <div class="flex flex-wrap items-center gap-2">',
    '      <span class="text-[12px] text-sub shrink-0">Inbound address</span>',
    '      <code class="_moretogether-break flex-1 min-w-[220px] rounded-md border border-line bg-[#f9fafb] px-2 py-1 text-[12px] text-ink">{{ inboundAddress }}</code>',
    '    </div>',

    '    <div class="mt-3 flex flex-wrap items-center gap-2">',
    '      <span class="text-[12px] text-sub">Status</span>',
    '      <span :class="[\'_moretogether-badge\', \'_moretogether-badge--\' + statusTone]">{{ statusLabel }}</span>',
    '      <span v-if="lastPassed && !running" class="text-[12px] text-sub">Last tested {{ lastPassed.received_at || lastPassed.sent_at }}</span>',
    '    </div>',

    /* ---- testing ---- */
    '    <div v-if="running" class="mt-4 rounded-md border border-line bg-[#f9fafb] px-3 py-3">',
    '      <div class="flex items-center gap-2">',
    '        <span class="_moretogether-spinner" aria-hidden="true"></span>',
    '        <span class="text-[13px] font-semibold text-ink">Testing inbound configuration…</span>',
    '        <span class="ml-auto text-[12px] text-sub">{{ countdown }}</span>',
    '      </div>',
    '      <p class="mt-1.5 text-[12px] text-sub">We sent a test email to your configured inbox. We&rsquo;re waiting for it to be forwarded back to ProjectBlock.</p>',
    '    </div>',

    /* ---- passed ---- */
    '    <div v-else-if="passed" class="mt-4 rounded-md border border-line bg-[#f9fafb] px-3 py-3">',
    '      <div class="flex items-center gap-2">',
    '        <span class="text-[13px] font-semibold text-ink"><span v-html="icon(\'check\', 14)"></span> Inbound email is working</span>',
    '      </div>',
    '      <p class="mt-1.5 text-[12px] text-sub">Your forwarding configuration is correct and ProjectBlock successfully received and parsed the test email.</p>',
    '      <dl class="mt-3 text-[12px]">',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">Received</dt><dd class="text-ink">{{ test.received_at }}</dd></div>',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">From</dt><dd class="text-ink _moretogether-break">{{ test.received.from }}</dd></div>',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">To</dt><dd class="text-ink _moretogether-break">{{ test.received.to }}</dd></div>',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">Subject</dt><dd class="text-ink _moretogether-break">{{ test.received.subject }}</dd></div>',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">Inbound ID</dt><dd class="text-ink _moretogether-break">{{ test.received.inbound_id }}</dd></div>',
    '        <div class="flex gap-3 py-0.5"><dt class="w-28 shrink-0 text-sub">Processing</dt><dd class="text-ink">{{ test.received.processing }}</dd></div>',
    '      </dl>',
    '    </div>',

    /* ---- failed: says WHICH leg broke ---- */
    '    <div v-else-if="failed" class="mt-4 _moretogether-notice px-3 py-3">',
    '      <div class="text-[13px] font-semibold text-ink">We couldn&rsquo;t verify your inbound email</div>',
    '      <p class="mt-1.5 text-[12px] text-sub">{{ test.explanation }}</p>',
    '      <p v-if="test.failure_reason" class="mt-1 text-[12px] text-sub _moretogether-break">{{ test.failure_reason }}</p>',
    '      <ul class="mt-2 pl-4 list-disc text-[12px] text-sub space-y-0.5">',
    '        <li>Confirm that email forwarding is enabled.</li>',
    '        <li>Confirm that the forwarding destination is correct.</li>',
    '        <li>Check that the inbound address was entered without additional spaces.</li>',
    '        <li>Check whether your email provider requires forwarding verification.</li>',
    '        <li>Confirm that the forwarding rule applies to incoming messages.</li>',
    '        <li>Check Postmark inbound activity for rejected or failed messages.</li>',
    '      </ul>',
    '    </div>',

    '    <p v-if="error" class="mt-3 text-[12px] text-danger">{{ error }}</p>',

    '    <div v-if="canRun" class="mt-4 flex flex-wrap items-center gap-2">',
    '      <button type="button" @click="start" :disabled="running || starting"',
    '              class="inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">',
    '        {{ starting ? \'Sending…\' : (passed ? \'Test Again\' : (failed ? \'Try Again\' : \'Send Test Email\')) }}',
    '      </button>',
    '      <a v-if="failed" :href="urls.inbox" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">View Inbound Setup</a>',
    '    </div>',
    '    <p v-else class="mt-4 text-[12px] text-sub">Only a workspace admin or this Space&rsquo;s lead can run the test.</p>',

    '  </div>',
    '</div>'
  ].join('\n')
}, { root: 'help-center-inbound-test' });
