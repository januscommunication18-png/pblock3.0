/* Project Settings › Labels (Project-Level Labels §1-§14, §24).
   ------------------------------------------------------------------
   Its own screen rather than the shared <pb-label-manager>, which workspace settings also
   mounts: this one carries a description, a usage count, an archive lifecycle and the enable
   switch, and pushing all of that into the shared component would change a screen this spec
   says nothing about.

   The toggle goes through the SAME feature endpoint as Epics, Modules, Cycles and Estimation,
   so §3-§5's disable behaviour — the confirmation, the preserved labels and assignments, the
   restore on re-enable — is the one already built rather than a second implementation of it.
   ------------------------------------------------------------------ */
PB.boot('project-labels', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      enabled: b.enabled !== false,
      labels: (b.labels || []).slice(),
      presets: (b.color && b.color.presets) || [],
      nameMax: b.nameMax || 50,
      descriptionMax: b.descriptionMax || 255,
      endpoints: b.endpoints || {},
      query: '',
      form: { open: false, id: null, name: '', description: '', color: '', saving: false, errors: {} },
      removeConfirm: { open: false, label: null },
      disableConfirm: { open: false, dialog: null }
    };
  },
  computed: {
    /** §13: search matches the name AND the description — the description is where the
     *  "when do I use this one?" answer lives, so leaving it out would miss the point. */
    visible: function () {
      var q = (this.query || '').trim().toLowerCase();
      if (!q) return this.labels;
      return this.labels.filter(function (l) {
        return (l.name || '').toLowerCase().indexOf(q) > -1 ||
          (l.description || '').toLowerCase().indexOf(q) > -1;
      });
    },
    activeLabels: function () { return this.visible.filter(function (l) { return !l.archived; }); },
    archivedLabels: function () { return this.visible.filter(function (l) { return l.archived; }); },
    formValid: function () { return !!this.form.name.trim() && !!this.form.color; }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    // ---------- §2/§4/§5: the switch ----------
    onToggle: async function (v) {
      try {
        var body = { feature: 'labels', enabled: v };
        await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: body });
        this.enabled = v;
        this.$pb.toast(v ? 'Labels enabled.' : 'Labels disabled. Existing labels are kept.');
      } catch (e) {
        // §4: the server answers 409 with the dialog when labels already exist.
        if (e && e.status === 409 && e.data && e.data.confirm) {
          this.disableConfirm = { open: true, dialog: e.data };
          return;
        }
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },
    confirmDisable: async function () {
      this.disableConfirm = { open: false, dialog: null };
      try {
        await this.$pb.api(this.endpoints.toggle, {
          method: 'POST', body: { feature: 'labels', enabled: false, confirm: true }
        });
        this.enabled = false;
        this.$pb.toast('Labels disabled. Existing labels and assignments are kept.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- §7/§9: create and edit ----------
    openCreate: function () {
      this.form = {
        open: true, id: null, name: '', description: '',
        color: this.presets[this.labels.length % (this.presets.length || 1)] || '#F97316',
        saving: false, errors: {}
      };
    },
    openEdit: function (l) {
      this.form = {
        open: true, id: l.id, name: l.name, description: l.description || '',
        color: l.color, saving: false, errors: {}
      };
    },
    save: async function () {
      if (!this.formValid || this.form.saving) return;
      this.form.saving = true; this.form.errors = {};
      var f = this.form;
      try {
        var url = f.id ? this.$pb.withId(this.endpoints.label, f.id) : this.endpoints.store;
        var resp = await this.$pb.api(url, {
          method: f.id ? 'PATCH' : 'POST',
          body: { name: f.name.trim(), description: f.description || null, color: f.color }
        });
        this.labels = resp.labels || this.labels;
        this.form.open = false;
        this.$pb.toast('Saved.');
      } catch (e) {
        // §8: "A label named Bug already exists in this project" lands on the field.
        this.form.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.form.saving = false;
    },

    // ---------- §10/§11: archive and delete ----------
    toggleArchive: async function (l) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.archive, l.id), { method: 'POST' });
        this.labels = resp.labels || this.labels;
        this.$pb.toast(resp.message || 'Saved.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    askRemove: function (l) { this.removeConfirm = { open: true, label: l }; },
    /** §10: the warning names how many work items lose the label before anything happens. */
    removeMessage: function () {
      var l = this.removeConfirm.label;
      if (!l) return '';
      if (!l.usage) return 'This label will be deleted. This cannot be undone.';
      return 'This label is currently assigned to ' + l.usage + (l.usage === 1 ? ' work item' : ' work items') +
        '. Deleting it will remove the label from ' + (l.usage === 1 ? 'that work item' : 'those work items') +
        '. The work items themselves are not deleted. Archive it instead to keep the categorization.';
    },
    remove: async function () {
      var l = this.removeConfirm.label;
      this.removeConfirm = { open: false, label: null };
      if (!l) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.label, l.id), { method: 'DELETE' });
        this.labels = resp.labels || this.labels;
        this.$pb.toast(resp.message || 'Label deleted.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },

  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Labels" ' +
    'desc="Use labels to categorize and organize work items within this project."/>' +

    // ===== §2: the switch, reading like every other feature toggle =====
    '<div class="border border-line rounded-xl">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink">Enable Labels</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Turning labels off hides them from work items without deleting a ' +
    'single label or assignment. Enabling again restores everything exactly as it was.</p>' +
    '</div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="onToggle"/>' +
    '</div></div>' +

    // ===== §3: switched off — say what is kept rather than showing an empty page =====
    '<div v-if="!enabled" class="mt-4 flex items-start gap-2.5 rounded-xl border border-line bg-amber-50 px-4 py-3">' +
    '<span class="text-amber-700 shrink-0 mt-0.5" v-html="icon(\'circle-info\', 15)"></span>' +
    '<p class="text-[12px] text-amber-900">Labels are disabled. ' +
    '<strong v-if="labels.length">{{ labels.length }} label<span v-if="labels.length > 1">s</span></strong>' +
    '<span v-else>Your labels</span> and every assignment on a work item are kept — turning labels ' +
    'back on restores them exactly as they are.</p></div>' +

    // ===== §6/§12: label management =====
    '<div v-if="enabled" class="mt-4 border border-line rounded-xl">' +
    '<div class="flex items-center gap-2 px-4 py-3 border-b border-line">' +
    '<span class="text-[13px] font-medium text-ink shrink-0">Project labels</span>' +
    '<span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ activeLabels.length }}</span>' +
    // §13: search appears with the list; it matches name and description.
    '<input v-model="query" placeholder="Search labels…" ' +
    'class="ml-auto h-8 w-48 px-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<button type="button" @click="openCreate" ' +
    'class="h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap inline-flex items-center gap-1.5">' +
    '<span v-html="icon(\'plus\', 14)"></span>Add Label</button>' +
    '</div>' +

    '<div v-if="!activeLabels.length" class="px-6 py-10 text-center">' +
    '<p class="text-[13px] text-sub">{{ query ? \'No label matches your search.\' : \'No labels yet.\' }}</p>' +
    '<button v-if="!query" type="button" @click="openCreate" ' +
    'class="mt-3 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Add your first label</button>' +
    '</div>' +

    '<div v-else class="divide-y divide-line">' +
    '<div v-for="l in activeLabels" :key="l.id" class="flex items-center gap-3 px-4 py-3">' +
    '<span class="h-3 w-3 rounded-full shrink-0" :style="{background: l.color}"></span>' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[13px] text-ink truncate">{{ l.name }}</div>' +
    '<p v-if="l.description" class="text-[12px] text-sub truncate">{{ l.description }}</p></div>' +
    // §24: how often it is used, so unused labels are easy to spot.
    '<span class="text-[11px] text-faint whitespace-nowrap">{{ l.usage }} work item<span v-if="l.usage !== 1">s</span></span>' +
    '<button type="button" @click="openEdit(l)" data-tip="Edit" aria-label="Edit" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" v-html="icon(\'pen\', 14)"></button>' +
    '<button type="button" @click="toggleArchive(l)" data-tip="Archive" aria-label="Archive" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" v-html="icon(\'box-archive\', 14)"></button>' +
    '<button type="button" @click="askRemove(l)" data-tip="Delete" aria-label="Delete" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover hover:text-danger" v-html="icon(\'trash\', 14)"></button>' +
    '</div></div>' +

    // §11: archived labels, kept because historical work items still carry them
    '<div v-if="archivedLabels.length" class="border-t border-line px-4 py-4">' +
    '<h4 class="text-[12px] font-semibold text-sub uppercase tracking-wide">Archived</h4>' +
    '<p class="text-[12px] text-faint mt-1">Still shown on the work items using them, but not offered when labelling new work.</p>' +
    '<div v-for="l in archivedLabels" :key="l.id" class="flex items-center gap-3 mt-2.5">' +
    '<span class="h-3 w-3 rounded-full shrink-0 opacity-50" :style="{background: l.color}"></span>' +
    '<span class="text-[13px] text-sub flex-1 truncate">{{ l.name }}</span>' +
    '<span class="text-[11px] text-faint whitespace-nowrap">{{ l.usage }} work item<span v-if="l.usage !== 1">s</span></span>' +
    '<button type="button" @click="toggleArchive(l)" class="h-7 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">Restore</button>' +
    '<button type="button" @click="askRemove(l)" data-tip="Delete" aria-label="Delete" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover hover:text-danger" v-html="icon(\'trash\', 14)"></button>' +
    '</div></div>' +
    '</div>' +

    // ===== §7: create / edit =====
    '<pb-modal :open="form.open" :title="form.id ? \'Edit label\' : \'Add label\'" @close="form.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Name</label>' +
    '<input v-model="form.name" :maxlength="nameMax" placeholder="Bug" @keyup.enter="save" ' +
    'class="pb-input w-full" :class="{\'is-error\': form.errors.name}" />' +
    '<p v-if="form.errors.name" class="text-[12px] text-danger mt-1">{{ form.errors.name[0] }}</p>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Description <span class="text-faint font-normal">(optional)</span></label>' +
    '<textarea v-model="form.description" rows="2" :maxlength="descriptionMax" ' +
    'placeholder="Issues representing defects or unexpected product behaviour." ' +
    'class="pb-textarea w-full resize-none"></textarea>' +

    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Color</label>' +
    '<pb-color-picker v-model="form.color" :presets="presets" />' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="form.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="!formValid || form.saving" @click="save">{{ form.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ===== §10: delete, with the usage warning =====
    '<pb-confirm :open="removeConfirm.open" ' +
    ':title="removeConfirm.label ? \'Delete \\u201c\' + removeConfirm.label.name + \'\\u201d label?\' : \'Delete label?\'" ' +
    ':message="removeMessage()" confirm-label="Delete Label" ' +
    '@close="removeConfirm.open = false" @confirm="remove" />' +

    // ===== §4: disable confirmation =====
    '<div v-if="disableConfirm.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-28">' +
    '<div class="absolute inset-0 bg-black/40" @click="disableConfirm.open = false"></div>' +
    '<div class="relative w-full max-w-[460px] bg-white rounded-xl shadow-xl p-5">' +
    '<h3 class="text-[15px] font-semibold text-head">Disable Labels?</h3>' +
    '<p class="text-[13px] text-sub mt-2">Labels will no longer be available when creating or editing work items. ' +
    'Existing labels and label assignments will be retained and restored if Labels are enabled again.</p>' +
    '<div class="flex items-center justify-end gap-2 mt-5">' +
    '<button type="button" @click="disableConfirm.open = false" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="confirmDisable" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Disable Labels</button>' +
    '</div></div></div>' +

    '</div>'
});
