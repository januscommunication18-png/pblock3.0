/* Project Settings › General (PRJ-040) + lifecycle danger zone. */
PB.boot('project-general', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap; var p = b.project;
    return {
      project: Object.assign({}, p),
      form: { name: p.name, identifier: p.identifier, description: p.description || '', visibility: p.visibility, lead_user_id: p.lead_user_id ? String(p.lead_user_id) : '', timezone: p.timezone || '' },
      members: b.members, visibilities: b.visibilities, timezones: b.timezones, endpoints: b.endpoints,
      saving: false, uploading: false, errors: {}, confirmOpen: false, confirmText: ''
    };
  },
  computed: {
    leadOptions: function () {
      return [{ value: '', label: 'No lead' }].concat(this.members.map(function (m) { return { value: String(m.id), label: m.name + ' · ' + m.email }; }));
    },
    visibilityOptions: function () { return this.visibilities.map(function (v) { return { value: v, label: v.charAt(0).toUpperCase() + v.slice(1) }; }); },
    tzOptions: function () { return this.timezones; },
    canDelete: function () { return this.confirmText.trim().toUpperCase() === (this.project.identifier || '').toUpperCase(); }
  },
  methods: {
    save: async function () {
      if (this.saving) return; this.saving = true; this.errors = {};
      var body = { name: this.form.name, identifier: this.form.identifier, description: this.form.description, visibility: this.form.visibility, lead_user_id: this.form.lead_user_id || null, timezone: this.form.timezone || null };
      try {
        await this.$pb.api(this.endpoints.update, { method: 'PATCH', body: body });
        this.project.identifier = this.form.identifier.toUpperCase();
        this.$pb.toast('Project updated.');
      } catch (e) { this.errors = this.$pb.fieldErrors(e); this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.saving = false;
    },
    pickCover: function () { this.$refs.cover.click(); },
    uploadCover: async function (e) {
      var file = e.target.files[0]; if (!file) return;
      var fd = new FormData(); fd.append('cover', file); this.uploading = true;
      try { var resp = await this.$pb.api(this.endpoints.cover, { method: 'POST', body: fd }); this.project.cover_url = resp.cover_url; this.$pb.toast('Cover updated.'); }
      catch (err) { this.$pb.toast(this.$pb.firstError(err), 'error'); }
      this.uploading = false; e.target.value = '';
    },
    archive: async function () {
      try { await this.$pb.api(this.endpoints.archive, { method: 'POST' }); this.project.status = 'archived'; this.$pb.toast('Project archived.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    restore: async function () {
      try { await this.$pb.api(this.endpoints.restore, { method: 'POST' }); this.project.status = 'active'; this.$pb.toast('Project restored.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    doDelete: async function () {
      if (!this.canDelete) return;
      try { var resp = await this.$pb.api(this.endpoints.delete, { method: 'DELETE', body: { confirm: this.confirmText } }); window.location = resp.redirect; }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="General" desc="Manage this project\'s identity and defaults."/>' +

    '<div class="flex items-center gap-4 mb-6">' +
    '<span class="h-14 w-14 rounded-md grid place-items-center text-[22px] bg-cover bg-center" :style="project.cover_url ? {backgroundImage:\'url(\'+project.cover_url+\')\'} : {background:\'#334155\'}"></span>' +
    '<div><button class="text-[13px] text-link font-medium disabled:opacity-50" :disabled="uploading" @click="pickCover">{{ uploading ? \'Uploading…\' : \'Change cover\' }}</button>' +
    '<input ref="cover" type="file" accept="image/*" class="hidden" @change="uploadCover"/></div></div>' +

    '<div class="space-y-4">' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Identifier</label>' +
    '<input class="pb-input uppercase" :class="{\'is-error\': errors.identifier}" v-model="form.identifier" maxlength="10"/>' +
    '<p v-if="errors.identifier" class="text-[12px] text-danger mt-1">{{ errors.identifier[0] }}</p></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Description</label>' +
    '<textarea class="pb-textarea" rows="2" v-model="form.description"></textarea></div>' +
    '<div class="grid grid-cols-2 gap-3">' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Access</label><pb-combo v-model="form.visibility" :options="visibilityOptions" :searchable="false"/></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Lead</label><pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="Search members…"/></div>' +
    '</div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Timezone</label><pb-combo v-model="form.timezone" :options="tzOptions" placeholder="Search timezone…"/></div>' +
    '<div><button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving" @click="save">Update project</button></div>' +
    '</div>' +

    // Danger zone
    '<div class="border border-danger/40 rounded-xl p-5 mt-8">' +
    '<h2 class="text-[15px] font-semibold text-danger">Danger zone</h2>' +
    '<div class="flex items-center gap-2 mt-3">' +
    '<button v-if="project.status !== \'archived\'" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="archive">Archive project</button>' +
    '<button v-else class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="restore">Restore project</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold hover:opacity-90" @click="confirmOpen=true">Delete project</button>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-2">Archiving keeps all data and can be undone. Deleting is permanent.</p></div>' +

    '<pb-modal :open="confirmOpen" title="Delete project?" @close="confirmOpen=false">' +
    '<p class="text-[13px] text-sub">This permanently deletes <b class="text-ink">{{ project.name }}</b> and all of its data. This cannot be undone.</p>' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Type <b>{{ project.identifier }}</b> to confirm</label>' +
    '<input class="pb-input uppercase" v-model="confirmText"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="confirmOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!canDelete" @click="doDelete">Delete project</button>' +
    '</template></pb-modal>' +
    '</div>'
});
