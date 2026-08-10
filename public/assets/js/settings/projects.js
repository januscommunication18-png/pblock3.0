/* Settings › Projects — project states + labels, applied workspace-wide (spec §6). */
PB.boot('projects', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      tab: 'states',
      enabled: b.enabled, states: b.states, labels: b.labels, priorities: b.priorities,
      groups: b.groups, presets: b.color.presets, endpoints: b.endpoints,
      open: false, editing: null, saving: false, errors: {},
      form: { name: '', color: b.color.presets[0], group: Object.keys(b.groups)[0], description: '', is_default: false },
      confirm: { open: false, state: null }
    };
  },
  computed: {
    groupKeys: function () { return Object.keys(this.groups); },
    groupOptions: function () {
      var g = this.groups;
      return Object.keys(g).map(function (k) { return { value: k, label: g[k] }; });
    }
  },
  methods: {
    toggle: async function (v) {
      this.enabled = v;
      try { await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { enabled: v } }); this.$pb.toast('Saved.'); }
      catch (e) { this.enabled = !v; this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    statesIn: function (g) { return this.states.filter(function (s) { return s.group === g; }); },
    openAdd: function () { this.editing = null; this.errors = {}; this.form = { name: '', color: this.presets[0], group: this.groupKeys[0], description: '', is_default: false }; this.open = true; },
    openEdit: function (s) { this.editing = s; this.errors = {}; this.form = { name: s.name, color: s.color, group: s.group, description: s.description || '', is_default: s.is_default }; this.open = true; },
    saveState: async function () {
      if (!this.form.name.trim() || this.saving) return; this.saving = true; this.errors = {};
      try {
        var url = this.editing ? this.$pb.withId(this.endpoints.state, this.editing.id) : this.endpoints.states;
        var resp = await this.$pb.api(url, { method: this.editing ? 'PATCH' : 'POST', body: this.form });
        this.states = resp.states; this.open = false; this.$pb.toast('Saved.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    askDelete: function (s) { this.confirm = { open: true, state: s }; },
    doDelete: async function () {
      var s = this.confirm.state; if (!s) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.state, s.id), { method: 'DELETE' });
        this.states = resp.states; this.$pb.toast('State deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, state: null };
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Projects" desc="States and labels here apply to every project in this workspace, so all projects follow the same structure."/>' +

    '<div class="flex items-center gap-1 border-b border-line mb-5">' +
    '<button :class="[\'px-3 h-9 text-[13px] -mb-px border-b-2\', tab===\'states\' ? \'border-brand text-brand font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="tab=\'states\'">Project states</button>' +
    '<button :class="[\'px-3 h-9 text-[13px] -mb-px border-b-2\', tab===\'labels\' ? \'border-brand text-brand font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="tab=\'labels\'">Project labels</button>' +
    '<button :class="[\'px-3 h-9 text-[13px] -mb-px border-b-2\', tab===\'priorities\' ? \'border-brand text-brand font-medium\' : \'border-transparent text-sub hover:text-ink\']" @click="tab=\'priorities\'">Project priority</button>' +
    '</div>' +

    // States
    '<div v-if="tab===\'states\'">' +
    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-3 mb-4">' +
    '<div><div class="text-[14px] font-medium text-ink">Project states</div>' +
    '<p class="text-[12px] text-sub mt-0.5">States show where each project is in its lifecycle. Customize them for all projects in this workspace.</p></div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="toggle"/></div>' +

    '<div :class="{\'opacity-50 pointer-events-none\': !enabled}">' +
    '<div class="flex justify-end mb-3"><button class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold" @click="openAdd">Add state</button></div>' +
    '<div v-for="g in groupKeys" :key="g" class="mb-4">' +
    '<div v-if="statesIn(g).length" class="text-[11px] font-semibold text-faint uppercase tracking-wide mb-1.5">{{ groups[g] }}</div>' +
    '<div class="space-y-2">' +
    '<div v-for="s in statesIn(g)" :key="s.id" class="flex items-center gap-3 px-3 py-2.5 border border-line rounded-lg">' +
    '<span class="h-3.5 w-3.5 rounded-full shrink-0" :style="{background:s.color}"></span>' +
    '<span class="text-[13px] text-ink font-medium">{{ s.name }}</span>' +
    '<span v-if="s.is_default" class="text-[11px] bg-sel text-brand rounded px-1.5 py-0.5">Default</span>' +
    '<span v-if="s.description" class="text-[12px] text-faint truncate">{{ s.description }}</span>' +
    '<div class="ml-auto flex items-center gap-1">' +
    '<button class="text-[12px] text-sub hover:text-ink px-1.5" @click="openEdit(s)">Edit</button>' +
    '<button class="text-[12px] text-danger hover:opacity-80 px-1.5" @click="askDelete(s)">Delete</button></div>' +
    '</div></div></div></div></div>' +

    // Labels
    '<div v-else-if="tab===\'labels\'">' +
    '<pb-label-manager title="Project labels" description="Labels help you group and filter work items consistently across every project." ' +
    ':items="labels" :presets="presets" :store-url="endpoints.labels" :item-url="endpoints.label" ' +
    'singular="label" add-text="Add label" empty-title="No project labels yet" empty-subtitle="Create your first label to get started."/>' +
    '</div>' +

    // Priorities
    '<div v-else-if="tab===\'priorities\'">' +
    '<pb-label-manager title="Project priority" description="Priority levels you can assign to projects and work items. Rename or recolor them to fit your team." ' +
    ':items="priorities" :presets="presets" :store-url="endpoints.priorities" :item-url="endpoints.priority" collection-key="priorities" ' +
    'singular="priority" add-text="Add priority" name-placeholder="Priority name" empty-title="No priorities yet" empty-subtitle="Create your first priority to get started."/>' +
    '</div>' +

    // State modal
    '<pb-modal :open="open" :title="(editing ? \'Edit\' : \'Add\') + \' state\'" @close="open=false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name" placeholder="State name"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Group</label>' +
    '<pb-combo v-model="form.group" :options="groupOptions" :invalid="!!errors.group"/>' +
    '<p v-if="errors.group" class="text-[12px] text-danger mt-1">{{ errors.group[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description</label>' +
    '<textarea class="pb-textarea" :class="{\'is-error\': errors.description}" rows="2" v-model="form.description" placeholder="Optional"></textarea>' +
    '<p v-if="errors.description" class="text-[12px] text-danger mt-1">{{ errors.description[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-2 mt-4">Color</label>' +
    '<pb-color-picker v-model="form.color" :presets="presets"/>' +
    '<p v-if="errors.color" class="text-[12px] text-danger mt-1">{{ errors.color[0] }}</p>' +
    '<label class="flex items-center gap-2 mt-4 text-[13px] text-ink"><input type="checkbox" class="pb-check" v-model="form.is_default"/> Set as default state</label>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving || !form.name.trim()" @click="saveState">{{ editing ? \'Save\' : \'Add\' }}</button>' +
    '</template></pb-modal>' +
    '<pb-confirm :open="confirm.open" title="Delete state?" message="Projects using this state will need another. This action cannot be undone." @close="confirm.open=false" @confirm="doDelete"/>' +
    '</div>'
});
