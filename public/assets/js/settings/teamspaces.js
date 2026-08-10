/* Settings › Teamspaces — one-way enable, cannot be turned off (spec §10). */
PB.boot('teamspaces', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { enabled: b.enabled, locked: b.locked, endpoints: b.endpoints, confirmOpen: false, saving: false };
  },
  methods: {
    confirmEnable: async function () {
      if (this.saving) return; this.saving = true;
      try {
        var resp = await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { enabled: true } });
        this.enabled = resp.enabled; this.locked = resp.locked; this.confirmOpen = false;
        this.$pb.toast('Teamspaces enabled.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Teamspaces" desc="Teamspaces bring everything a team works on into one place — link their projects and see all their work together."/>' +

    '<div class="border border-line rounded-xl p-5">' +
    '<div v-if="!enabled">' +
    '<div class="text-[14px] font-medium text-ink">Turn on Teamspaces for this workspace</div>' +
    '<p class="text-[13px] text-sub mt-1">Once turned on, you can\'t turn this feature off.</p>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold mt-4" @click="confirmOpen=true">Enable Teamspaces</button>' +
    '</div>' +
    '<div v-else class="flex items-center gap-3">' +
    '<span class="h-8 w-8 rounded-full bg-success/15 text-success grid place-items-center shrink-0">' +
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '<div><div class="text-[14px] font-medium text-ink">Teamspaces is enabled for this workspace.</div>' +
    '<p class="text-[12px] text-sub">This setting is permanent and cannot be turned off.</p></div>' +
    '</div></div>' +

    '<pb-confirm :open="confirmOpen" title="Enable Teamspaces?" ' +
    'message="This is a one-way action. Once Teamspaces is enabled for this workspace it cannot be turned off." ' +
    'confirm-label="Enable Teamspaces" @close="confirmOpen=false" @confirm="confirmEnable"/>' +
    '</div>'
});
