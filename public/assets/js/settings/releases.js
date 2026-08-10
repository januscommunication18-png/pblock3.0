/* Settings › Releases — enable, release tags + labels (spec §8). */
PB.boot('releases', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { tab: 'tags', enabled: b.enabled, tags: b.tags, labels: b.labels, presets: b.color.presets, endpoints: b.endpoints };
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
    '<pb-section-head title="Releases" desc="Group work from your projects into releases and track them through to shipping."/>' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-3 mb-5">' +
    '<div><div class="text-[14px] font-medium text-ink">Enable Releases</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Let all workspace members view releases in projects they belong to.</p></div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="toggle"/></div>' +

    '<div :class="{\'opacity-50 pointer-events-none\': !enabled}">' +
    '<div class="flex items-center gap-1 border-b border-line mb-5">' +
    '<button :class="[\'px-3 h-9 text-[13px] -mb-px border-b-2\', tab===\'tags\' ? \'border-brand text-brand font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="tab=\'tags\'">Release tags</button>' +
    '<button :class="[\'px-3 h-9 text-[13px] -mb-px border-b-2\', tab===\'labels\' ? \'border-brand text-brand font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="tab=\'labels\'">Labels</button>' +
    '</div>' +

    '<pb-label-manager v-if="tab===\'tags\'" title="Release tags" description="Define tags to categorise releases across this workspace." ' +
    ':items="tags" :presets="presets" :store-url="endpoints.tags" :item-url="endpoints.tag" collection-key="tags" ' +
    'singular="tag" add-text="Add Tag" name-placeholder="v1.0.0" :with-color="false" ' +
    'empty-title="No tags yet" empty-subtitle="Create your first tag."/>' +

    '<pb-label-manager v-else title="Labels" description="Labels help you group and filter releases." ' +
    ':items="labels" :presets="presets" :store-url="endpoints.labels" :item-url="endpoints.label" ' +
    'empty-title="No labels yet" empty-subtitle="Create your first label."/>' +
    '</div></div>'
});
