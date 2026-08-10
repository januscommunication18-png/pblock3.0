/* Project Settings › Features (PRJ-042). */
PB.boot('project-features', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { features: Object.assign({}, b.features), catalog: b.catalog, endpoints: b.endpoints };
  },
  computed: {
    items: function () {
      var c = this.catalog; var self = this;
      return Object.keys(c).map(function (k) { return { key: k, label: c[k].label, description: c[k].description, enabled: !!self.features[k] }; });
    }
  },
  methods: {
    toggle: async function (key, v) {
      this.features[key] = v;
      try { var resp = await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { feature: key, enabled: v } }); this.features = resp.features; this.$pb.toast('Saved.'); }
      catch (e) { this.features[key] = !v; this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Features" desc="Turn project capabilities on or off. Disabling a feature hides it from the project without deleting data."/>' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="f in items" :key="f.key" class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div><div class="text-[14px] font-medium text-ink">{{ f.label }}</div><p class="text-[12px] text-sub mt-0.5">{{ f.description }}</p></div>' +
    '<pb-toggle :model-value="f.enabled" @update:model-value="function(v){ toggle(f.key, v); }"/>' +
    '</div></div></div>'
});
