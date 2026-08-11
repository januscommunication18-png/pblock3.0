/* Project Settings › Cycle (PRJ-042; Cycles §3).
   The screen mirrors what the server enforces rather than deciding anything itself: the
   entitlement verdict and the dependency chain both arrive in the catalog, and every refusal
   the UI shows is one the API would also give. */
PB.boot('project-features', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { features: Object.assign({}, b.features), catalog: b.catalog, endpoints: b.endpoints };
  },
  computed: {
    items: function () {
      var c = this.catalog; var self = this;
      return Object.keys(c).map(function (k) {
        var meta = c[k];
        // Cycles §3.3.1: a dependent feature is only meaningful once its prerequisite is on.
        var blockedBy = meta.requires && !self.features[meta.requires] ? c[meta.requires].label : null;
        return {
          key: k,
          label: meta.label,
          description: meta.description,
          enabled: !!self.features[k],
          // §13: not in the plan — show Upgrade instead of letting them switch it on.
          entitled: meta.entitled !== false,
          blockedBy: blockedBy
        };
      });
    }
  },
  methods: {
    locked: function (f) { return !f.entitled || !!f.blockedBy; },
    toggle: async function (f, v) {
      if (this.locked(f)) return;
      this.features[f.key] = v;
      try {
        var resp = await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { feature: f.key, enabled: v } });
        // Take the whole map back: switching a feature off also switches off whatever
        // depended on it, and only the server knows the full chain.
        this.features = resp.features;
        this.$pb.toast('Saved.');
      } catch (e) {
        this.features[f.key] = !v;
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Cycle" desc="Plan this project\'s work in time-boxed cycles. Turning cycles off hides them from the project without deleting a single cycle or its work items."/>' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="f in items" :key="f.key" class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink flex items-center gap-2">{{ f.label }}' +
    '<span v-if="!f.entitled" class="text-[10px] font-semibold uppercase tracking-wide text-brand bg-sel rounded px-1.5 py-0.5">Upgrade</span></div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ f.description }}</p>' +
    '<p v-if="f.blockedBy" class="text-[12px] text-faint mt-1">Turn on {{ f.blockedBy }} first.</p>' +
    '<p v-else-if="!f.entitled" class="text-[12px] text-faint mt-1">This feature is not included in your current plan.</p>' +
    '</div>' +
    '<pb-toggle :model-value="f.enabled" :disabled="locked(f)" @update:model-value="function(v){ toggle(f, v); }"/>' +
    '</div></div></div>'
});
