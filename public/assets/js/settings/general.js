/* Settings › General — workspace identity, logo, danger-zone delete (spec §4). */
PB.boot('general', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      ws: Object.assign({}, b.workspace),
      form: {
        name: b.workspace.name, company_size: b.workspace.company_size,
        slug: b.workspace.slug, timezone: b.workspace.timezone
      },
      urlPrefix: b.urlPrefix, teamSizes: b.teamSizes, timezones: b.timezones,
      canDelete: b.canDelete, endpoints: b.endpoints,
      editing: false, saving: false, uploading: false, errors: {},
      confirmOpen: false, confirmText: ''
    };
  },
  computed: {
    logoStyle: function () { return this.ws.logo_url ? { backgroundImage: 'url(' + this.ws.logo_url + ')' } : {}; },
    canConfirmDelete: function () { return this.confirmText.trim() === this.ws.name; }
  },
  methods: {
    startEdit: function () {
      this.errors = {};
      this.form = { name: this.ws.name, company_size: this.ws.company_size, slug: this.ws.slug, timezone: this.ws.timezone };
      this.editing = true;
    },
    cancel: function () { this.editing = false; this.errors = {}; },
    save: async function () {
      if (this.saving) return; this.saving = true; this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.update, { method: 'PATCH', body: this.form });
        this.ws = Object.assign(this.ws, resp.workspace);
        this.editing = false;
        this.$pb.toast('Workspace updated.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    pickLogo: function () { this.$refs.logo.click(); },
    uploadLogo: async function (e) {
      var file = e.target.files[0]; if (!file) return;
      var fd = new FormData(); fd.append('logo', file);
      this.uploading = true;
      try {
        var resp = await this.$pb.api(this.endpoints.logo, { method: 'POST', body: fd });
        this.ws.logo_url = resp.logo_url;
        this.$pb.toast('Logo updated.');
      } catch (err) { this.$pb.toast(this.$pb.firstError(err), 'error'); }
      this.uploading = false; e.target.value = '';
    },
    doDelete: function () {
      if (!this.canConfirmDelete) return;
      var f = document.createElement('form');
      f.method = 'POST'; f.action = this.endpoints.delete;
      f.innerHTML =
        '<input type="hidden" name="_method" value="DELETE"/>' +
        '<input type="hidden" name="_token" value="' + (document.querySelector('meta[name=csrf-token]').content) + '"/>';
      document.body.appendChild(f); f.submit();
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="General" desc="Manage your workspace identity and defaults."/>' +

    // Identity
    '<div class="flex items-center gap-4 mb-6">' +
    '<span class="h-14 w-14 rounded-md bg-slate-700 text-white grid place-items-center text-[22px] font-semibold bg-cover bg-center shrink-0" :style="logoStyle"><template v-if="!ws.logo_url">{{ ws.initial }}</template></span>' +
    '<div class="min-w-0">' +
    '<div class="text-[18px] font-semibold text-head truncate">{{ ws.name }}</div>' +
    '<div class="text-[13px] text-sub">{{ urlPrefix }}{{ ws.slug }}</div>' +
    '<button class="text-[13px] text-link font-medium mt-1 disabled:opacity-50" :disabled="uploading" @click="pickLogo">{{ uploading ? \'Uploading…\' : \'Upload logo\' }}</button>' +
    '<input ref="logo" type="file" accept="image/*" class="hidden" @change="uploadLogo"/>' +
    '</div></div>' +

    // Details card
    '<div class="border border-line rounded-xl p-5">' +
    '<div class="flex items-center justify-between mb-4">' +
    '<h2 class="text-[15px] font-semibold text-head">Workspace details</h2>' +
    '<button v-if="!editing" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="startEdit">Edit</button>' +
    '</div>' +

    '<div class="space-y-4">' +
    // Name
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Workspace name</label>' +
    '<input v-if="editing" class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name" maxlength="80"/>' +
    '<p v-if="editing && errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<div v-else-if="!editing" class="text-[14px] text-ink">{{ ws.name }}</div></div>' +
    // Company size (searchable combobox)
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Company size</label>' +
    '<pb-combo v-if="editing" v-model="form.company_size" :options="teamSizes" :invalid="!!errors.company_size" placeholder="Select company size"/>' +
    '<p v-if="editing && errors.company_size" class="text-[12px] text-danger mt-1">{{ errors.company_size[0] }}</p>' +
    '<div v-else-if="!editing" class="text-[14px] text-ink">{{ ws.company_size }}</div></div>' +
    // URL (read-only)
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Workspace URL</label>' +
    '<div v-if="editing" class="pb-group is-readonly" style="background-color:#f3f4f6">' +
    '<span class="pb-group__prefix">{{ urlPrefix }}</span>' +
    '<input class="pb-group__field" :value="ws.slug" readonly style="background:transparent;color:#6b7280;cursor:default"/></div>' +
    '<div v-else class="text-[14px] text-ink">{{ urlPrefix }}{{ ws.slug }}</div>' +
    '<p v-if="editing" class="text-[12px] text-faint mt-1">The workspace URL is read-only and can\'t be changed here.</p></div>' +
    // Timezone (searchable combobox)
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Workspace Timezone</label>' +
    '<pb-combo v-if="editing" v-model="form.timezone" :options="timezones" :invalid="!!errors.timezone" placeholder="Search timezone…"/>' +
    '<p v-if="editing && errors.timezone" class="text-[12px] text-danger mt-1">{{ errors.timezone[0] }}</p>' +
    '<div v-else-if="!editing" class="text-[14px] text-ink">{{ ws.timezone || \'—\' }}</div></div>' +
    '</div>' +

    '<div v-if="editing" class="flex gap-2 mt-5">' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving" @click="save">Update workspace</button>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="cancel">Cancel</button>' +
    '</div></div>' +

    // Danger zone (owner only)
    '<div v-if="canDelete" class="border border-danger/40 rounded-xl p-5 mt-6">' +
    '<h2 class="text-[15px] font-semibold text-danger">Delete this workspace</h2>' +
    '<p class="text-[13px] text-sub mt-1 max-w-2xl">Deleting this workspace permanently erases all projects, pages, and data for every member. Nothing can be recovered — not even by us. Continue only if you\'re certain.</p>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold mt-3 hover:opacity-90" @click="confirmOpen = true">Delete</button>' +
    '</div>' +

    '<pb-modal :open="confirmOpen" title="Delete workspace?" @close="confirmOpen=false">' +
    '<p class="text-[13px] text-sub">This permanently deletes <b class="text-ink">{{ ws.name }}</b> and all of its data. This action cannot be undone.</p>' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Type <b>{{ ws.name }}</b> to confirm</label>' +
    '<input class="pb-input" v-model="confirmText"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="confirmOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!canConfirmDelete" @click="doDelete">Delete workspace</button>' +
    '</template></pb-modal>' +
    '</div>'
});
