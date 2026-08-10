/* Settings › Wiki — enable/disable (off by default); wiki labels appear once enabled. */
PB.boot('wiki', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { enabled: b.enabled, labels: b.labels, presets: b.color.presets, endpoints: b.endpoints };
  },
  methods: {
    toggle: async function (v) {
      this.enabled = v;
      try { await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { enabled: v } }); this.$pb.toast('Saved.'); }
      catch (e) { this.enabled = !v; this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Wiki" desc="A living knowledge base for your team. Write docs, capture decisions, and keep context close to the work that needs it."/>' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-3">' +
    '<div><div class="text-[14px] font-medium text-ink">Turn on wiki for this workspace</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Manage workspace-level wiki access and page organization.</p></div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="toggle"/></div>' +

    // Labels appear only once Wiki is enabled.
    '<div v-if="enabled" class="mt-5">' +
    '<pb-label-manager title="Labels" description="Organize pages with custom labels to make information easier to group, discover, and manage across your workspace." ' +
    ':items="labels" :presets="presets" :store-url="endpoints.labels" :item-url="endpoints.label" ' +
    'empty-title="No labels yet" empty-subtitle="Create your first label to get started."/>' +
    '</div></div>'
});
