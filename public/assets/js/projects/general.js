/* Project Settings › General (PRJ-040) + lifecycle danger zone. */
PB.boot('project-general', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap; var p = b.project;
    return {
      project: Object.assign({}, p),
      form: {
        name: p.name, description: p.description || '',
        visibility: p.visibility, lead_user_id: p.lead_user_id ? String(p.lead_user_id) : '',
        timezone: p.timezone || '',
        // §10/§12/§13
        work_item_view: p.work_item_view || 'all',
        default_assignee_id: p.default_assignee_id ? String(p.default_assignee_id) : '',
        subscriber_ids: (p.subscriber_ids || []).map(String)
      },
      members: b.members, visibilities: b.visibilities, timezones: b.timezones, endpoints: b.endpoints,
      workItemViews: b.workItemViews || [],
      saving: false, uploading: false, errors: {}, confirmOpen: false, confirmText: '',
      // §16: a snapshot of what was loaded, so "has anything changed?" is answerable.
      saved: ''
    };
  },
  computed: {

    /**
     * `projects.visibilities` is a key => label MAP on the server, so mapping over it as an
     * array threw and took the whole page down with it — the settings shell empties the
     * container before mounting, so a failed mount renders blank rather than half a form.
     * Both shapes are accepted, since the config could reasonably be either.
     */
    visibilityOptions: function () {
      var v = this.visibilities || {};
      if (Array.isArray(v)) {
        return v.map(function (key) { return { value: key, label: key.charAt(0).toUpperCase() + key.slice(1) }; });
      }
      return Object.keys(v).map(function (key) { return { value: key, label: v[key] }; });
    },
    tzOptions: function () { return this.timezones; },
    canDelete: function () { return this.confirmText.trim().toLowerCase() === (this.project.identifier || '').toLowerCase(); },
    /** People, with their face — the combo renders `avatar`/`initial` when present. */
    memberOptions: function () {
      return this.members.map(function (m) {
        return { value: String(m.id), label: m.name, desc: m.email, avatar: m.avatar_url, initial: m.initial };
      });
    },
    assigneeOptions: function () {
      return [{ value: '', label: 'No default assignee' }].concat(this.memberOptions);
    },
    leadOptions: function () {
      return [{ value: '', label: 'No lead' }].concat(this.memberOptions);
    },
    /** §16: compared against the snapshot taken at load and after each save. */
    dirty: function () { return JSON.stringify(this.form) !== this.saved; },
    subscriberChips: function () {
      var ids = this.form.subscriber_ids.map(String);
      return this.members.filter(function (m) { return ids.indexOf(String(m.id)) > -1; });
    }
  },
  mounted: function () {
    this.saved = JSON.stringify(this.form);
    // §16: the browser's own prompt is the only one that can stop a navigation.
    var self = this;
    this._guard = function (e) {
      if (!self.dirty) return;
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', this._guard);
  },
  beforeUnmount: function () {
    if (this._guard) { window.removeEventListener('beforeunload', this._guard); this._guard = null; }
  },
  methods: {
    toggleSubscriber: function (member) {
      var ids = this.form.subscriber_ids.map(String);
      var at = ids.indexOf(String(member.id));
      if (at > -1) this.form.subscriber_ids.splice(at, 1);
      else this.form.subscriber_ids.push(String(member.id));
    },
    isSubscribed: function (member) { return this.form.subscriber_ids.map(String).indexOf(String(member.id)) > -1; },
    save: async function () {
      if (this.saving) return;
      // §15: nothing to send is worth saying, rather than a save that appears to do nothing.
      if (!this.dirty) { this.$pb.toast('No changes to save.'); return; }

      this.saving = true; this.errors = {};
      var body = {
        name: this.form.name, description: this.form.description,
        visibility: this.form.visibility, lead_user_id: this.form.lead_user_id || null,
        timezone: this.form.timezone || null,
        work_item_view: this.form.work_item_view,
        default_assignee_id: this.form.default_assignee_id || null,
        subscriber_ids: this.form.subscriber_ids.map(Number)
      };
      try {
        var resp = await this.$pb.api(this.endpoints.update, { method: 'PATCH', body: body });
        this.saved = JSON.stringify(this.form);
        this.$pb.toast((resp && resp.message) || 'Project settings updated successfully.');
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

    // §4: the project's identity area — a full-width cover banner with Change cover, the
    // same shape as the Add Project modal's header rather than a thumbnail beside a link.
    '<div class="relative h-32 rounded-xl mb-6 bg-center bg-cover overflow-hidden" ' +
    ':style="project.cover_url ? {backgroundImage: \'url(\' + project.cover_url + \')\'} : {background: \'linear-gradient(120deg,#f6d5b8 0%,#eaa987 55%,#d98a68 100%)\'}">' +
    '<button class="absolute top-3 left-3 h-8 px-3 rounded-md bg-white/85 text-[12px] font-medium text-ink hover:bg-white shadow-sm disabled:opacity-60" ' +
    ':disabled="uploading" @click="pickCover">{{ uploading ? \'Uploading…\' : \'Change cover\' }}</button>' +
    '<input ref="cover" type="file" accept="image/*" class="hidden" @change="uploadCover"/>' +
    // The project it belongs to, over the image, so the page always says what is being edited.
    '<div class="absolute left-3 bottom-3 flex items-center gap-2">' +
    '<span class="h-9 w-9 rounded-md bg-white/90 grid place-items-center text-[18px] shadow-sm">{{ project.emoji || \'📁\' }}</span>' +
    '<span class="px-2 py-1 rounded-md bg-white/85 text-[13px] font-semibold text-ink shadow-sm">{{ form.name }}</span>' +
    '<span class="px-2 py-1 rounded-md bg-white/70 text-[11px] font-semibold text-sub shadow-sm lowercase">{{ project.identifier }}</span>' +
    '<span class="px-2 py-1 rounded-md bg-white/70 text-[11px] text-sub shadow-sm capitalize">{{ form.visibility }}</span>' +
    '</div></div>' +

    '<div class="space-y-5">' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': errors.name}" v-model="form.name"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p></div>' +
    // Read-only: the ID is fixed at creation because it is baked into every work item
    // identifier and every link already shared.
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Identifier</label>' +
    '<input class="pb-input lowercase bg-hover text-sub cursor-not-allowed" :value="project.identifier" readonly disabled ' +
    'data-tip="The identifier cannot be changed after creation" aria-label="Identifier (read-only)"/>' +
    '<p class="text-[12px] text-sub mt-1">Used to build work item links. This cannot be changed after the project is created.</p>' +
    '<p v-if="errors.identifier" class="text-[12px] text-danger mt-1">{{ errors.identifier[0] }}</p></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Description</label>' +
    '<textarea class="pb-textarea" rows="2" v-model="form.description"></textarea></div>' +
    '<div class="grid grid-cols-2 gap-3">' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Access</label><pb-combo v-model="form.visibility" :options="visibilityOptions" :searchable="false"/></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Lead</label><pb-combo v-model="form.lead_user_id" :options="leadOptions" placeholder="Search members…"/></div>' +
    '</div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Timezone</label><pb-combo v-model="form.timezone" :options="tzOptions" placeholder="Search timezone…"/></div>' +

    // ---- Work item access (§10) — the two options, described as the spec words them, as
    // radio cards rather than a dropdown: the choice changes who can see what, so the
    // consequence should be readable without opening a menu. ----
    '<div class="pt-2">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Work item view</label>' +
    '<div class="space-y-2">' +
    '<label v-for="v in workItemViews" :key="v.value" class="flex items-start gap-2.5 rounded-lg border p-3 cursor-pointer" ' +
    ':class="form.work_item_view === v.value ? \'border-brand bg-sel/30\' : \'border-line hover:bg-hover\'">' +
    '<input type="radio" class="mt-0.5" :value="v.value" v-model="form.work_item_view" />' +
    '<span class="min-w-0"><span class="block text-[13px] font-medium text-ink">{{ v.label }}</span>' +
    '<span class="block text-[12px] text-sub mt-0.5">{{ v.hint }}</span></span></label>' +
    '</div></div>' +

    // ---- Ownership (§11-§13) ----
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Default assignee</label>' +
    '<pb-combo v-model="form.default_assignee_id" :options="assigneeOptions" placeholder="Search members…"/>' +
    '<p class="text-[12px] text-sub mt-1">Used when a work item is created without an assignee.</p></div>' +

    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Project subscribers</label>' +
    '<p class="text-[12px] text-sub mb-2">These members receive notifications for this project.</p>' +
    '<pb-combo multiple v-model="form.subscriber_ids" :options="memberOptions" placeholder="Select members…"/>' +
    // The combo summarises the choice; the chips name everyone in it and can drop one.
    '<div v-if="subscriberChips.length" class="flex flex-wrap gap-1.5 mt-2">' +
    '<span v-for="m in subscriberChips" :key="m.id" class="inline-flex items-center gap-1.5 h-7 pl-1 pr-1.5 rounded-full border border-line text-[12px] text-ink">' +
    '<img v-if="m.avatar_url" :src="m.avatar_url" alt="" class="h-5 w-5 rounded-full object-cover" />' +
    '<span v-else class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold">{{ m.initial }}</span>' +
    '{{ m.name }}' +
    '<button type="button" @click="toggleSubscriber(m)" :data-tip="\'Remove \' + m.name" :aria-label="\'Remove \' + m.name" ' +
    'class="h-5 w-5 grid place-items-center rounded-full text-faint hover:text-danger hover:bg-hover">' +
    '' + wiIcon('xmark', 12) + '</button>' +
    '</span></div></div>' +

    // 20px of air between the last field and the primary action (and before the card).
    '<div class="flex items-center gap-3 pt-5">' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="saving" @click="save">' +
    '{{ saving ? \'Updating…\' : \'Update project\' }}</button>' +
    '<span v-if="dirty" class="text-[12px] text-sub">Unsaved changes</span>' +
    // §17: read-only, straight from the creation timestamp.
    '<span v-if="project.created_on" class="ml-auto text-[12px] text-faint">Created on {{ project.created_on }}</span>' +
    '</div>' +
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

    // The destructive card should not sit flush against the bottom of the scroll area.
    '<div class="h-[175px]" aria-hidden="true"></div>' +

    '<pb-modal :open="confirmOpen" title="Delete project?" @close="confirmOpen=false">' +
    '<p class="text-[13px] text-sub">This permanently deletes <b class="text-ink">{{ project.name }}</b> and all of its data. This cannot be undone.</p>' +
    '<label class="block text-[13px] font-medium text-ink mt-4 mb-1.5">Type <b>{{ project.identifier }}</b> to confirm</label>' +
    '<input class="pb-input lowercase" v-model="confirmText"/>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="confirmOpen=false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!canDelete" @click="doDelete">Delete project</button>' +
    '</template></pb-modal>' +
    '</div>'
});
