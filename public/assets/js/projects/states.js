/* Project Settings › States (PRJ-043) — project-scoped work-item states. */
PB.boot('project-states', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      states: b.states, groups: b.groups, presets: b.color.presets, endpoints: b.endpoints,
      open: false, editing: null, saving: false, errors: {},
      form: { name: '', color: b.color.presets[0], group: Object.keys(b.groups)[0], description: '', is_default: false },
      confirm: { open: false, state: null }
    };
  },
  computed: {
    groupKeys: function () { return Object.keys(this.groups); },
    groupOptions: function () { var g = this.groups; return Object.keys(g).map(function (k) { return { value: k, label: g[k] }; }); }
  },
  methods: {
    statesIn: function (g) { return this.states.filter(function (s) { return s.group === g; }); },
    openAdd: function () { this.editing = null; this.errors = {}; this.form = { name: '', color: this.presets[0], group: this.groupKeys[0], description: '', is_default: false }; this.open = true; },
    openEdit: function (s) { this.editing = s; this.errors = {}; this.form = { name: s.name, color: s.color, group: s.group, description: s.description || '', is_default: s.is_default }; this.open = true; },
    save: async function () {
      if (!this.form.name.trim() || this.saving) return; this.saving = true; this.errors = {};
      try {
        var url = this.editing ? this.$pb.withId(this.endpoints.state, this.editing.id) : this.endpoints.store;
        var resp = await this.$pb.api(url, { method: this.editing ? 'PATCH' : 'POST', body: this.form });
        this.states = resp.states; this.open = false; this.$pb.toast('Saved.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    askDelete: function (s) { this.confirm = { open: true, state: s }; },
    doDelete: async function () {
      var s = this.confirm.state; if (!s) return;
      try { var resp = await this.$pb.api(this.$pb.withId(this.endpoints.state, s.id), { method: 'DELETE' }); this.states = resp.states; this.$pb.toast('State deleted.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, state: null };
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<div class="flex items-start justify-between gap-4">' +
    '<pb-section-head title="States" desc="Work-item states for this project."/>' +
    '<button class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0" @click="openAdd">Add state</button>' +
    '</div>' +
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
    '</div></div></div>' +

    '<pb-modal :open="open" :title="(editing ? \'Edit\' : \'Add\') + \' state\'" @close="open=false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name" placeholder="State name"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Group</label>' +
    '<pb-combo v-model="form.group" :options="groupOptions" :searchable="false"/>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description</label>' +
    '<textarea class="pb-textarea" rows="2" v-model="form.description" placeholder="Optional"></textarea>' +
    '<label class="block text-[13px] font-medium text-ink mb-2 mt-4">Color</label>' +
    '<pb-color-picker v-model="form.color" :presets="presets"/>' +
    '<label class="flex items-center gap-2 mt-4 text-[13px] text-ink"><input type="checkbox" class="pb-check" v-model="form.is_default"/> Set as default state</label>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving || !form.name.trim()" @click="save">{{ editing ? \'Save\' : \'Add\' }}</button>' +
    '</template></pb-modal>' +
    '<pb-confirm :open="confirm.open" title="Delete state?" message="This removes the state from the project. This cannot be undone." @close="confirm.open=false" @confirm="doDelete"/>' +
    '</div>'
});
