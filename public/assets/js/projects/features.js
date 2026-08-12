/* Project Settings › feature switches (PRJ-042).

   One screen, mounted by every settings section that is a list of toggles — Cycle, Module
   and Epic today. Which switches appear is decided server-side from the catalog's `section`,
   so adding a feature does not mean editing this file.
   The screen mirrors what the server enforces rather than deciding anything itself: the
   entitlement verdict and the dependency chain both arrive in the catalog, and every refusal
   the UI shows is one the API would also give. */
PB.boot('project-features', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      features: Object.assign({}, b.features), catalog: b.catalog, endpoints: b.endpoints,
      // Feature Disable §7: the server refuses to disable a feature that still has records
      // until the user has seen what happens. It answers 409 with the whole dialog — title,
      // what is preserved, what stops working — so the wording lives in one place for all
      // three features rather than three copies here.
      confirm: { open: false, feature: null, dialog: null, busy: false },
      // Title and blurb come from the server: the Cycle and Module sections are the same
      // screen with different switches, and each one names itself.
      title: b.title || 'Features', description: b.description || ''
    };
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
    toggle: async function (f, v, confirmed) {
      if (this.locked(f)) return;
      var was = !!this.features[f.key];
      this.features[f.key] = v;
      try {
        var body = { feature: f.key, enabled: v };
        if (confirmed) body.confirm = true;
        var resp = await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: body });
        // Take the whole map back: switching a feature off also switches off whatever
        // depended on it, and only the server knows the full chain.
        this.features = resp.features;
        this.$pb.toast('Saved.');
      } catch (e) {
        this.features[f.key] = was;
        // 409 is not a failure — it is the server asking the question §4 requires, with the
        // record count the user needs in order to answer it.
        if (e && e.status === 409 && e.data && e.data.confirm) {
          this.confirm = { open: true, feature: f, dialog: e.data, busy: false };
          return;
        }
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },
    cancelConfirm: function () { this.confirm = { open: false, feature: null, dialog: null, busy: false }; },
    acceptConfirm: async function () {
      var f = this.confirm.feature;
      if (!f || this.confirm.busy) return;
      this.confirm.busy = true;
      await this.toggle(f, false, true);
      this.cancelConfirm();
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head :title="title" :desc="description"/>' +
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
    '</div></div>' +

    // Feature Disable §7. Every word comes from the server so Epics, Modules and Cycles
    // cannot drift apart, and so the dialog names what SURVIVES before what stops — the
    // point of the warning is that turning a feature off is reversible.
    '<div v-if="confirm.open && confirm.dialog" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-28">' +
    '<div class="absolute inset-0 bg-black/40" @click="cancelConfirm"></div>' +
    '<div class="relative w-full max-w-[460px] bg-white rounded-xl shadow-xl p-5">' +
    '<h3 class="text-[15px] font-semibold text-head">{{ confirm.dialog.title }}</h3>' +
    '<p class="text-[13px] text-sub mt-2">{{ confirm.dialog.intro }}</p>' +
    '<p class="text-[13px] text-sub mt-3">While it is disabled:</p>' +
    '<ul class="mt-1.5 space-y-1">' +
    '<li v-for="(b, i) in confirm.dialog.bullets" :key="i" class="flex items-start gap-2 text-[13px] text-sub">' +
    '<span class="mt-[7px] h-1 w-1 rounded-full bg-faint shrink-0"></span><span>{{ b }}</span></li>' +
    '</ul>' +
    '<p class="text-[13px] text-sub mt-3">{{ confirm.dialog.footer }}</p>' +
    '<div class="flex items-center justify-end gap-2 mt-5">' +
    '<button type="button" @click="cancelConfirm" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="acceptConfirm" :disabled="confirm.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ confirm.busy ? \'Disabling…\' : confirm.dialog.confirmLabel }}</button>' +
    '</div>' +
    // Closes, in order: the dialog's button row, its panel, its backdrop wrapper, the screen.
    '</div></div></div>'
});
