/* Project Settings › Labels (PRJ-043) — project-scoped labels. */
PB.boot('project-labels', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { labels: b.labels, presets: b.color.presets, endpoints: b.endpoints };
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Labels" desc="Labels help you group and filter work items in this project."/>' +
    '<pb-label-manager title="Labels" ' +
    ':items="labels" :presets="presets" :store-url="endpoints.store" :item-url="endpoints.label" ' +
    'empty-title="No labels yet" empty-subtitle="Create your first label to get started."/>' +
    '</div>'
});
