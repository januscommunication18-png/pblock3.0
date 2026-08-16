/* Settings › Security — session idle timeout (docs/features/session-timeout.md). */
PB.boot('security', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      minutes: String(b.timeoutMinutes),
      saved: String(b.timeoutMinutes),
      options: b.options,
      warningMinutes: b.warningMinutes,
      endpoints: b.endpoints,
      saving: false
    };
  },
  computed: {
    comboOptions: function () {
      return this.options.map(function (o) { return { value: String(o.value), label: o.label }; });
    },
    dirty: function () { return this.minutes !== this.saved; }
  },
  methods: {
    save: async function () {
      if (this.saving || !this.dirty) return;
      this.saving = true;
      try {
        var resp = await this.$pb.api(this.endpoints.update, {
          method: 'PATCH',
          body: { session_timeout_minutes: Number(this.minutes) }
        });
        this.saved = String(resp.timeoutMinutes);
        this.$pb.toast('Session timeout updated.');

        /* The guard on THIS page is still counting down to the old deadline. Re-asking the
           server is cheaper than reloading, and stops the first thing an admin sees after
           lengthening the timeout being the old warning firing on schedule. */
        if (window.PB_SESSION) {
          try { await fetch(window.PB_SESSION.statusUrl, { headers: { Accept: 'application/json' } }); } catch (e) {}
        }
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Security" desc="Control how long people stay signed in to this workspace."/>' +

    '<div class="border border-line rounded-xl p-5">' +
    '<div class="text-[14px] font-medium text-ink">Session timeout</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[520px]">' +
    'Sign people out after this long without activity. Anything they do — typing, clicking, ' +
    'moving between pages — starts the clock again.</p>' +

    '<div class="mt-4 max-w-[260px]">' +
    '<pb-combo v-model="minutes" :options="comboOptions" :searchable="false" placeholder="Session timeout" />' +
    '</div>' +

    '<p class="text-[12px] text-sub mt-3">' +
    'A warning appears {{ warningMinutes }} minute<span v-if="warningMinutes !== 1">s</span> before the session ends, ' +
    'so nobody loses work without being asked first.</p>' +

    '<div class="mt-5 flex items-center gap-3">' +
    '<button type="button" :disabled="!dirty || saving" @click="save" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save changes\' }}</button>' +
    '<span v-if="dirty" class="text-[12px] text-sub">Unsaved change</span>' +
    '</div>' +
    '</div>' +
    '</div>'
});
