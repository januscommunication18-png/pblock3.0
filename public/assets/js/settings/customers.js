/* Settings › Customers — enable, default + custom properties (spec §11). */
PB.boot('customers', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      enabled: b.enabled, defaults: b.defaults, types: b.types, properties: b.properties, endpoints: b.endpoints,
      open: false, editing: null, saving: false, errors: {},
      form: { title: '', description: '', type: b.types[0], mandatory: false, active: true, options: [''] },
      confirm: { open: false, prop: null }
    };
  },
  methods: {
    toggle: async function (v) {
      this.enabled = v;
      try { await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { enabled: v } }); this.$pb.toast('Saved.'); }
      catch (e) { this.enabled = !v; this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    openAdd: function () { this.editing = null; this.errors = {}; this.form = { title: '', description: '', type: this.types[0], mandatory: false, active: true, options: [''] }; this.open = true; },
    openEdit: function (p) { this.editing = p; this.errors = {}; this.form = { title: p.title, description: p.description || '', type: p.type, mandatory: p.mandatory, active: p.active, options: (p.options && p.options.length) ? p.options.slice() : [''] }; this.open = true; },
    addOption: function () { this.form.options.push(''); },
    dropOption: function (i) { this.form.options.splice(i, 1); if (!this.form.options.length) this.form.options.push(''); },
    save: async function () {
      if (!this.form.title.trim() || this.saving) return; this.saving = true; this.errors = {};
      var payload = {
        title: this.form.title.trim(), description: this.form.description,
        type: this.form.type, mandatory: this.form.mandatory, active: this.form.active,
        options: this.form.type === 'Dropdown' ? this.form.options.filter(function (o) { return o.trim(); }) : null
      };
      try {
        var url = this.editing ? this.$pb.withId(this.endpoints.property, this.editing.id) : this.endpoints.properties;
        var resp = await this.$pb.api(url, { method: this.editing ? 'PATCH' : 'POST', body: payload });
        this.properties = resp.properties; this.open = false; this.$pb.toast('Saved.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    askDelete: function (p) { this.confirm = { open: true, prop: p }; },
    doDelete: async function () {
      var p = this.confirm.prop; if (!p) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.property, p.id), { method: 'DELETE' });
        this.properties = resp.properties; this.$pb.toast('Property deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, prop: null };
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Customers" desc="Link customer requests to work items and roll their state up into customer records."/>' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-3 mb-5">' +
    '<div><div class="text-[14px] font-medium text-ink">Enable customers</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Link customer requests to work items and track progress by customer.</p></div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="toggle"/></div>' +

    '<div :class="{\'opacity-50 pointer-events-none\': !enabled}">' +
    // Default properties
    '<div class="mb-6"><h2 class="text-[15px] font-semibold text-head mb-1">Default properties</h2>' +
    '<p class="text-[12px] text-sub mb-3">Built-in attributes available on every customer record.</p>' +
    '<div class="flex flex-wrap gap-2">' +
    '<span v-for="d in defaults" :key="d.title" class="inline-flex items-center gap-1.5 text-[12px] border border-line rounded-md px-2.5 py-1 text-ink">' +
    '{{ d.title }}<span class="text-faint">· {{ d.type }}</span></span></div></div>' +

    // Custom properties
    '<div class="flex items-start justify-between gap-4 mb-3">' +
    '<div><h2 class="text-[15px] font-semibold text-head">Custom properties</h2>' +
    '<p class="text-[12px] text-sub mt-0.5 max-w-xl">Custom properties let you capture the details that matter about each customer.</p></div>' +
    '<button class="h-9 px-3.5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold shrink-0" @click="openAdd">Add new property</button></div>' +

    '<pb-empty v-if="!properties.length" title="You don\'t have any custom properties yet." ' +
    'subtitle="Custom properties you add will show up here and on your customer records.">' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="openAdd">Add new property</button></pb-empty>' +
    '<div v-else class="space-y-2">' +
    '<div v-for="p in properties" :key="p.id" class="flex items-center gap-3 px-3 py-2.5 border border-line rounded-lg">' +
    '<div class="min-w-0 flex-1"><div class="text-[13px] text-ink font-medium truncate">{{ p.title }}' +
    '<span v-if="p.mandatory" class="text-[11px] text-danger ml-1">*</span>' +
    '<span v-if="!p.active" class="text-[11px] bg-hover text-sub rounded px-1.5 py-0.5 ml-2">Inactive</span></div>' +
    '<div class="text-[12px] text-faint truncate">{{ p.type }}<template v-if="p.description"> · {{ p.description }}</template></div></div>' +
    '<button class="text-[12px] text-sub hover:text-ink px-1.5" @click="openEdit(p)">Edit</button>' +
    '<button class="text-[12px] text-danger hover:opacity-80 px-1.5" @click="askDelete(p)">Delete</button>' +
    '</div></div>' +
    '</div>' +

    // Property modal
    '<pb-modal :open="open" :title="editing ? \'Edit property\' : \'Create new custom property\'" @close="open=false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Title <span class="text-danger">*</span></label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.title}" v-model="form.title" placeholder="Title"/>' +
    '<p v-if="errors.title" class="text-[12px] text-danger mt-1">{{ errors.title[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description</label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.description}" v-model="form.description" placeholder="Description"/>' +
    '<p v-if="errors.description" class="text-[12px] text-danger mt-1">{{ errors.description[0] }}</p>' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Property type <span class="text-danger">*</span></label>' +
    '<pb-combo v-model="form.type" :options="types" :invalid="!!errors.type"/>' +
    '<p v-if="errors.type" class="text-[12px] text-danger mt-1">{{ errors.type[0] }}</p>' +
    '<div v-if="form.type===\'Dropdown\'" class="mt-4">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Options</label>' +
    '<div class="space-y-2"><div v-for="(o,i) in form.options" :key="i" class="flex items-center gap-2">' +
    '<input class="pb-input !h-9 flex-1" v-model="form.options[i]" placeholder="Option"/>' +
    '<button class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover" @click="dropOption(i)">' +
    '' + wiIcon('xmark', 16) + '</button></div></div>' +
    '<button class="text-[13px] text-link font-medium mt-2" @click="addOption">+ Add option</button>' +
    '<p v-if="errors.options" class="text-[12px] text-danger mt-1">{{ errors.options[0] }}</p></div>' +
    '<div class="flex items-center gap-6 mt-4">' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" class="pb-check" v-model="form.mandatory"/> Mandatory property</label>' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" class="pb-check" v-model="form.active"/> Active</label></div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="open=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving || !form.title.trim()" @click="save">{{ editing ? \'Save\' : \'Create\' }}</button>' +
    '</template></pb-modal>' +
    '<pb-confirm :open="confirm.open" title="Delete property?" message="This removes the custom property. Existing customer data for it will no longer be shown." @close="confirm.open=false" @confirm="doDelete"/>' +
    '</div>'
});
