/* Settings › Initiatives — enable (default off); labels locked while disabled (spec §9). */
PB.boot('initiatives', {
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
    '<pb-section-head title="Initiatives" desc="Group multiple projects and work items under one goal and track their combined progress."/>' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-3 mb-5">' +
    '<div><div class="text-[14px] font-medium text-ink">Enable Initiatives</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Group projects under shared goals and track their combined progress.</p></div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="toggle"/></div>' +

    '<pb-label-manager title="Labels" description="Labels help you group and filter initiatives." ' +
    ':items="labels" :presets="presets" :store-url="endpoints.labels" :item-url="endpoints.label" ' +
    ':disabled="!enabled" locked-title="Enable initiatives to manage labels" ' +
    'locked-subtitle="Turn on initiatives to organize and track your initiative labels." ' +
    'empty-title="No initiative labels yet" empty-subtitle="Create your first label to get started."/>' +
    '</div>'
});
