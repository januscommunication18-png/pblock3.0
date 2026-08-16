/* Project Settings › Estimation (Estimation §3-§10, §20-§24).
   ------------------------------------------------------------------
   One screen, one switch, and a body that follows it — the switch reads exactly like the
   Cycle and Module ones, because it is the same control doing the same job:

     off, no system      → §3's toggle alone
     off, system kept    → §27: the system is named, and said to be preserved
     on, nothing chosen  → §5's chooser, because a system is required before estimating
     on, configured      → §10's value editor

   The toggle goes through the SAME feature endpoint as Epics, Modules and Cycles, so §29's
   disable pattern — the confirmation, the preserved data, the restore on re-enable — is the
   one already built rather than a second implementation of it.
   ------------------------------------------------------------------ */
PB.boot('project-estimation', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      enabled: !!b.enabled,
      estimation: b.estimation || null,
      types: Array.isArray(b.types) ? b.types : [],
      labelMax: b.labelMax || 40,
      valuesMax: b.valuesMax || 20,
      endpoints: b.endpoints || {},
      busy: false,
      // The chooser, shown when enabling and when changing type (§22).
      chooser: { open: false, type: 'points', template: 'fibonacci' },
      // §22's warning, held until the user answers it.
      typeConfirm: { open: false, type: '', template: '' },
      // §24's warning, answered before the feature goes off.
      disableConfirm: { open: false, dialog: null, busy: false },
      // §21's warning, which has to name the number of work items first.
      removeConfirm: { open: false, value: null },
      adding: { label: '', minutes: '', hours: '', busy: false },
      editing: { id: null, label: '' },
      // Work Capacity context: the workspace's working week, and whether mapping is wanted.
      capacity: b.capacity || { enabled: false },
      // Which value's hours field is mid-save, so one row's spinner is not every row's.
      savingHours: null,
      multiplier: ''
    };
  },
  computed: {
    chooserType: function () {
      var self = this;
      return this.types.filter(function (t) { return t.key === self.chooser.type; })[0] || null;
    },
    /** §10: the editor lists active values; archived ones are shown apart, as history. */
    activeValues: function () {
      return this.estimation ? this.estimation.values.filter(function (v) { return v.active; }) : [];
    },
    archivedValues: function () {
      return this.estimation ? this.estimation.values.filter(function (v) { return !v.active; }) : [];
    },
    /** Time systems ask for minutes as well as a label; nothing else does. */
    isTime: function () { return !!this.estimation && this.estimation.type === 'time'; },
    isPoints: function () { return !!this.estimation && this.estimation.type === 'points'; },
    atLimit: function () { return this.activeValues.length >= this.valuesMax; },

    /* Mapping is only asked for when there is something to map INTO. With capacity tracking
       off, an hours column collects a number that feeds nothing. */
    showsCapacity: function () { return !!this.capacity.enabled && !!this.estimation; },

    /* A time estimate already IS hours, so its figure is derived and read-only — asking for
       it twice invites two answers that disagree. */
    capacityIsDerived: function () { return this.isTime; },

    /* §41's warning, at the point somebody can act on it: an unmapped value contributes
       nothing to planned capacity, and silence would read as "nothing assigned". */
    unmappedCount: function () {
      if (!this.showsCapacity || this.capacityIsDerived) return 0;
      return this.activeValues.filter(function (v) { return v.hours === null; }).length;
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    // ---------- enable / disable (§4, §5, §24) ----------
    /**
     * The toggle, reading exactly like the Cycle and Module ones.
     *
     * Switching ON is not one action, though: §5 requires a system before the property means
     * anything. With one already configured the flag is all that changes (§28 — the previous
     * system comes straight back); with none, the chooser opens and the flag is set when it
     * is saved, so a project is never left enabled with nothing to estimate with.
     */
    onToggle: function (v) {
      if (!v) { this.disable(); return; }
      // §28: a system already configured comes straight back — nothing to choose again.
      if (this.estimation) { this.reEnable(); return; }
      this.openChooser();
    },
    openChooser: function () {
      var first = this.types[0];
      this.chooser = {
        open: true,
        type: this.estimation ? this.estimation.type : (first ? first.key : 'points'),
        template: this.estimation ? this.estimation.template : (first && first.templates[0] ? first.templates[0].key : '')
      };
    },
    pickType: function (t) {
      this.chooser.type = t.key;
      this.chooser.template = t.templates[0] ? t.templates[0].key : '';
    },
    /**
     * §5: enabling and configuring are one step.
     *
     * The feature flag goes on FIRST, then the system is saved — the other order would leave a
     * project with a configured system it is not allowed to use if the second call failed.
     */
    enableAndConfigure: async function () {
      if (this.busy) return;
      this.busy = true;
      try {
        // §22: replacing an existing system warns first, and this path is also "change type".
        if (this.estimation && (this.estimation.type !== this.chooser.type || this.estimation.template !== this.chooser.template)) {
          this.typeConfirm = { open: true, type: this.chooser.type, template: this.chooser.template };
          this.busy = false;
          return;
        }
        await this.applyConfiguration(this.chooser.type, this.chooser.template);
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.busy = false;
    },
    confirmTypeChange: async function () {
      var t = this.typeConfirm;
      this.typeConfirm = { open: false, type: '', template: '' };
      await this.applyConfiguration(t.type, t.template);
    },
    /** Cancelling leaves the feature exactly as it was — the flag is only set on save. */
    closeChooser: function () { this.chooser.open = false; },
    applyConfiguration: async function (type, template) {
      if (!this.enabled) {
        await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { feature: 'estimates', enabled: true } });
        this.enabled = true;
      }
      var resp = await this.$pb.api(this.endpoints.configure, { method: 'POST', body: { type: type, template: template } });
      this.estimation = resp.estimation;
      this.chooser.open = false;
      this.$pb.toast(resp.message || 'Saved.');
    },
    /** §24: switching off asks first — the server answers 409 with the dialog to show. */
    disable: async function (confirmed) {
      try {
        var body = { feature: 'estimates', enabled: false };
        if (confirmed) body.confirm = true;
        await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: body });
        this.enabled = false;
        this.disableConfirm = { open: false, dialog: null, busy: false };
        // §25: the system and every assigned estimate stay exactly where they are.
        this.$pb.toast('Estimation disabled. Existing estimates are kept.');
      } catch (e) {
        if (e && e.status === 409 && e.data && e.data.confirm) {
          this.disableConfirm = { open: true, dialog: e.data, busy: false };
          return;
        }
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },
    /** §28: re-enabling restores the system that was already there. */
    reEnable: async function () {
      try {
        await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { feature: 'estimates', enabled: true } });
        this.enabled = true;
        this.$pb.toast('Estimation enabled.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- values (§10, §20, §21) ----------
    addValue: async function () {
      var label = (this.adding.label || '').trim();
      if (!label || this.adding.busy || this.atLimit) return;
      this.adding.busy = true;
      try {
        var body = { label: label };
        if (this.isTime && this.adding.minutes) body.duration_minutes = Number(this.adding.minutes);
        if (this.isPoints && !isNaN(Number(label))) body.numeric_value = Number(label);
        if (this.adding.hours !== '') body.capacity_hours = Number(this.adding.hours);
        var resp = await this.$pb.api(this.endpoints.values, { method: 'POST', body: body });
        this.estimation = resp.estimation;
        this.adding = { label: '', minutes: '', hours: '', busy: false };
        this.$pb.toast(resp.message || 'Added.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.adding.busy = false;
    },
    /* Hours for one value. Saved on blur rather than per keystroke: every save re-plans the
       open work items carrying this value, which is not something to do on the way from 1 to
       16. */
    saveHours: async function (v, raw) {
      var next = String(raw).trim() === '' ? null : Number(raw);

      if (next !== null && (isNaN(next) || next < 0)) return;
      if (next === v.capacity_hours) return;

      this.savingHours = v.id;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.value, v.id), {
          method: 'PATCH', body: { capacity_hours: next }
        });
        this.estimation = resp.estimation;
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.savingHours = null;
    },

    /* §5's shorthand — "1 point = 2 hours" — as a one-click fill of the column §8 actually
       shows as a table. The multiplier is never stored: it cannot express a team deciding
       8 points is worth 16 hours rather than 16, which is exactly what estimation scales do. */
    applyMultiplier: async function () {
      var factor = Number(this.multiplier);
      if (!factor || factor <= 0) return;

      for (var i = 0; i < this.activeValues.length; i++) {
        var v = this.activeValues[i];
        var base = v.numeric_value !== null ? v.numeric_value : Number(v.label);
        if (isNaN(base)) continue;
        await this.saveHours(v, Math.round(base * factor * 100) / 100);
      }
      this.$pb.toast('Capacity hours filled from the multiplier.');
    },

    startEdit: function (v) { this.editing = { id: v.id, label: v.label }; },
    cancelEdit: function () { this.editing = { id: null, label: '' }; },
    saveEdit: async function () {
      var label = (this.editing.label || '').trim();
      if (!label || !this.editing.id) return this.cancelEdit();
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.value, this.editing.id), {
          method: 'PATCH', body: { label: label }
        });
        this.estimation = resp.estimation;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.cancelEdit();
    },
    /** §21: a value in use needs the warning, one that is not can just go. */
    askRemove: function (v) {
      if (v.in_use > 0) { this.removeConfirm = { open: true, value: v }; return; }
      this.removeValue(v);
    },
    removeMessage: function () {
      var v = this.removeConfirm.value;
      if (!v) return '';
      return 'Estimate "' + v.label + '" is currently assigned to ' + v.in_use +
        (v.in_use === 1 ? ' work item.' : ' work items.') +
        ' Removing this value will prevent it from being assigned to new work items. Existing work items will retain the historical value.';
    },
    removeValue: async function (v) {
      var value = v || this.removeConfirm.value;
      this.removeConfirm = { open: false, value: null };
      if (!value) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.value, value.id), { method: 'DELETE' });
        this.estimation = resp.estimation;
        this.$pb.toast(resp.message || 'Removed.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    restoreValue: async function (v) {
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.restore, v.id), { method: 'POST' });
        this.estimation = resp.estimation;
        this.$pb.toast(resp.message || 'Restored.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    move: async function (v, delta) {
      var ids = this.activeValues.map(function (x) { return x.id; });
      var at = ids.indexOf(v.id);
      var to = at + delta;
      if (at < 0 || to < 0 || to >= ids.length) return;
      ids.splice(to, 0, ids.splice(at, 1)[0]);
      try {
        var resp = await this.$pb.api(this.endpoints.reorder, { method: 'POST', body: { ids: ids } });
        this.estimation = resp.estimation;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    }
  },

  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Estimation" ' +
    'desc="Use estimates to measure the relative effort, complexity, or expected time required to complete work items in this project."/>' +

    // ===== §3: the switch, in the same card the Cycle and Module sections use =====
    '<div class="border border-line rounded-xl">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink">Enable Estimation</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Estimate the effort required to complete work items in this project. ' +
    'Turning estimation off hides it from work items without deleting the system or a single estimate.</p>' +
    '</div>' +
    '<pb-toggle :model-value="enabled" @update:model-value="onToggle"/>' +
    '</div></div>' +

    // ===== §27: switched off with a system kept — say so, rather than showing nothing =====
    '<div v-if="!enabled && estimation" class="mt-4 border border-line rounded-xl p-5">' +
    '<div class="flex items-start gap-2.5 rounded-md bg-amber-50 px-3 py-2.5">' +
    '<span class="text-amber-700 shrink-0 mt-0.5" v-html="icon(\'circle-info\', 15)"></span>' +
    '<p class="text-[12px] text-amber-900">Estimation is disabled. <strong>{{ estimation.label }}</strong> and every ' +
    'estimate already assigned to a work item are kept — turning it back on restores them exactly as they are.</p></div>' +
    '</div>' +

    // ===== §10: enabled and configured — the value editor =====
    '<div v-if="enabled && estimation" class="mt-4 border border-line rounded-xl">' +
    '<div class="flex items-center gap-3 px-5 py-4 border-b border-line">' +
    '<div class="min-w-0"><div class="text-[13px] font-medium text-ink">{{ estimation.label }}</div>' +
    '<p class="text-[12px] text-sub mt-0.5">One estimation system is active per project.</p></div>' +
    '<button type="button" @click="openChooser" ' +
    'class="ml-auto h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover whitespace-nowrap">Change type</button>' +
    '</div>' +

    // ===== Work Capacity mapping (work-capacity §7) =====
    // The working week is shown here, not just linked to, because the numbers typed below are
    // meaningless without it: "16h" is a heavy item against a 40h week and an impossible one
    // against 20h.
    '<div v-if="showsCapacity" class="px-5 py-4 border-b border-line bg-hover/40">' +
    '<div class="flex flex-wrap items-center gap-x-6 gap-y-2">' +
    '<div><div class="text-[11px] font-semibold text-sub uppercase tracking-wide">Working day</div>' +
    '<div class="text-[15px] font-bold text-head">{{ capacity.hoursPerDay }}h</div></div>' +
    '<div><div class="text-[11px] font-semibold text-sub uppercase tracking-wide">Working week</div>' +
    '<div class="text-[15px] font-bold text-head">{{ capacity.weeklyHours }}h</div></div>' +
    '<div class="text-[12px] text-sub">' +
    '{{ capacity.hoursPerDay }}h × {{ capacity.workingDays }} day<span v-if="capacity.workingDays !== 1">s</span>' +
    '</div>' +
    '<a :href="capacity.settingsUrl" class="ml-auto text-[12px] font-semibold text-brand hover:underline">Change</a>' +
    '</div>' +

    '<p class="text-[12px] text-sub mt-3 max-w-[620px]">' +
    '<template v-if="capacityIsDerived">' +
    'This project estimates in time, so each value is already worth its own duration — nothing to map.' +
    '</template>' +
    '<template v-else>' +
    'Give each value the working hours it represents, so estimates can be compared with the hours ' +
    'people actually have.' +
    '</template></p>' +

    // The multiplier — a shortcut into the column, never a stored rule.
    '<div v-if="!capacityIsDerived && isPoints" class="flex flex-wrap items-center gap-2 mt-3">' +
    '<span class="text-[12px] text-sub whitespace-nowrap">1 point =</span>' +
    '<input v-model="multiplier" type="number" step="0.5" min="0" placeholder="2" class="pb-input is-compact" />' +
    '<span class="text-[12px] text-sub whitespace-nowrap">hours</span>' +
    '<button type="button" @click="applyMultiplier" :disabled="!multiplier" ' +
    'class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover ' +
    'disabled:opacity-40 whitespace-nowrap">Fill all</button>' +
    '</div>' +

    '<p v-if="unmappedCount" class="text-[12px] text-amber-700 mt-3">' +
    '{{ unmappedCount }} value<span v-if="unmappedCount !== 1">s</span> ' +
    '<span v-if="unmappedCount === 1">has</span><span v-else>have</span> no hours yet. ' +
    'Work items using <span v-if="unmappedCount === 1">it</span><span v-else>them</span> are counted as ' +
    'unestimated rather than as no work.</p>' +
    '</div>' +

    '<div class="divide-y divide-line">' +
    '<div v-for="(v, i) in activeValues" :key="v.id" class="flex items-center gap-2 px-5 h-12">' +
    '<span class="text-[12px] text-faint w-5 shrink-0">{{ i + 1 }}</span>' +
    '<template v-if="editing.id === v.id">' +
    '<input v-model="editing.label" :maxlength="labelMax" @keyup.enter="saveEdit" @keyup.esc="cancelEdit" ' +
    'class="pb-input flex-1" />' +
    '<button type="button" @click="saveEdit" class="h-8 px-3 rounded-md bg-brand text-white text-[13px] font-semibold">Save</button>' +
    '<button type="button" @click="cancelEdit" class="h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Cancel</button>' +
    '</template>' +
    '<template v-else>' +
    '<span class="text-[13px] text-ink flex-1 truncate">{{ v.label }}</span>' +

    // One hours field per value (work-capacity CAP-D1). Editable for points and sizes;
    // read-only for time, which already carries its own duration.
    '<div v-if="showsCapacity" class="flex items-center gap-1.5 shrink-0">' +
    '<span v-if="capacityIsDerived" class="text-[12px] text-sub tabular-nums w-16 text-right">' +
    '{{ v.hours === null ? \'—\' : v.hours + \'h\' }}</span>' +
    '<template v-else>' +
    '<input :value="v.capacity_hours" @change="saveHours(v, $event.target.value)" ' +
    ':disabled="savingHours === v.id" type="number" step="0.5" min="0" placeholder="—" ' +
    'class="pb-input is-compact" :aria-label="\'Capacity hours for \' + v.label" />' +
    '<span class="text-[12px] text-sub">h</span>' +
    '</template>' +
    '</div>' +

    '<span v-if="v.in_use" class="text-[11px] text-faint whitespace-nowrap">{{ v.in_use }} work items</span>' +
    '<button type="button" @click="move(v, -1)" :disabled="i === 0" data-tip="Move up" aria-label="Move up" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover disabled:opacity-30" v-html="icon(\'chevron-down\', 14, \'rotate-180\')"></button>' +
    '<button type="button" @click="move(v, 1)" :disabled="i === activeValues.length - 1" data-tip="Move down" aria-label="Move down" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover disabled:opacity-30" v-html="icon(\'chevron-down\', 14)"></button>' +
    '<button type="button" @click="startEdit(v)" data-tip="Rename" aria-label="Rename" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" v-html="icon(\'pen\', 14)"></button>' +
    '<button type="button" @click="askRemove(v)" data-tip="Remove" aria-label="Remove" ' +
    'class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover hover:text-danger" v-html="icon(\'trash\', 14)"></button>' +
    '</template>' +
    '</div>' +

    // §10: add a value
    '<div class="flex items-center gap-2 px-5 py-3">' +
    '<input v-model="adding.label" :maxlength="labelMax" :disabled="atLimit" ' +
    ':placeholder="atLimit ? \'Maximum values reached\' : \'Add a value…\'" @keyup.enter="addValue" class="pb-input flex-1" />' +
    '<input v-if="isTime" v-model="adding.minutes" type="number" min="1" placeholder="Min" class="pb-input is-compact" />' +
    '<input v-if="showsCapacity && !capacityIsDerived" v-model="adding.hours" type="number" step="0.5" min="0" ' +
    'placeholder="Hours" class="pb-input is-compact" />' +
    '<button type="button" @click="addValue" :disabled="!adding.label.trim() || adding.busy || atLimit" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Add</button>' +
    '</div></div>' +

    // §21: archived values, kept because work items still read them
    '<div v-if="archivedValues.length" class="border-t border-line px-5 py-4">' +
    '<h4 class="text-[12px] font-semibold text-sub uppercase tracking-wide">Removed values</h4>' +
    '<p class="text-[12px] text-faint mt-1">Kept because work items still use them. They cannot be assigned to new work items.</p>' +
    '<div v-for="v in archivedValues" :key="v.id" class="flex items-center gap-2 mt-2.5">' +
    '<span class="text-[13px] text-sub flex-1 truncate">{{ v.label }}</span>' +
    '<span v-if="v.in_use" class="text-[11px] text-faint">{{ v.in_use }} work items</span>' +
    '<button type="button" @click="restoreValue(v)" class="h-7 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">Restore</button>' +
    '</div></div>' +
    '</div>' +

    // §5: switched on but nothing chosen yet — the one case where the toggle alone is not
    // enough, so the page says what is still missing instead of looking broken.
    '<div v-if="enabled && !estimation" class="mt-4 border border-line rounded-xl px-6 py-10 text-center">' +
    '<h3 class="text-[14px] font-semibold text-head">Choose an estimation system</h3>' +
    '<p class="text-[13px] text-sub mt-1">Points, categories or time — work items can be estimated once one is set.</p>' +
    '<button type="button" @click="openChooser" ' +
    'class="mt-4 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<span v-html="icon(\'plus\', 14)"></span>Select a system</button>' +
    '</div>' +

    // ===== §5: the type chooser =====
    '<pb-modal :open="chooser.open" width="max-w-[640px]" title="Estimation system" @close="closeChooser">' +
    '<div class="space-y-2">' +
    '<button v-for="t in types" :key="t.key" type="button" @click="pickType(t)" ' +
    'class="w-full text-left rounded-lg border px-4 py-3" :class="chooser.type === t.key ? \'border-brand bg-sel/40\' : \'border-line hover:bg-hover\'">' +
    '<div class="text-[13px] font-medium text-ink">{{ t.label }}</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ t.description }}</p></button>' +
    '</div>' +
    '<div v-if="chooserType" class="mt-4">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Values</label>' +
    '<div class="space-y-2">' +
    '<button v-for="tpl in chooserType.templates" :key="tpl.key" type="button" @click="chooser.template = tpl.key" ' +
    'class="w-full text-left rounded-lg border px-4 py-2.5" :class="chooser.template === tpl.key ? \'border-brand bg-sel/40\' : \'border-line hover:bg-hover\'">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[13px] text-ink">{{ tpl.label }}</span>' +
    '<span class="ml-auto text-[12px] text-sub truncate">{{ tpl.preview.join("  ·  ") }}</span></div></button>' +
    '</div>' +
    '<p class="text-[12px] text-faint mt-2">You can add, rename, reorder and remove these afterwards.</p>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="closeChooser">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" ' +
    ':disabled="busy || !chooser.template" @click="enableAndConfigure">{{ estimation ? \'Save\' : \'Enable Estimation\' }}</button>' +
    '</template></pb-modal>' +

    // ===== §22: changing the type =====
    '<pb-confirm :open="typeConfirm.open" title="Change estimate type?" ' +
    'message="Changing the estimation system may affect existing work item estimates. Existing estimate information will be retained for historical purposes." ' +
    'confirm-label="Change Estimate Type" @close="typeConfirm.open = false" @confirm="confirmTypeChange" />' +

    // ===== §21: removing a value that is in use =====
    '<pb-confirm :open="removeConfirm.open" title="Remove estimate value?" :message="removeMessage()" ' +
    'confirm-label="Remove Estimate Value" @close="removeConfirm.open = false" @confirm="removeValue(null)" />' +

    // ===== §24: disabling =====
    '<div v-if="disableConfirm.open && disableConfirm.dialog" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-28">' +
    '<div class="absolute inset-0 bg-black/40" @click="disableConfirm.open = false"></div>' +
    '<div class="relative w-full max-w-[460px] bg-white rounded-xl shadow-xl p-5">' +
    '<h3 class="text-[15px] font-semibold text-head">Disable Estimation?</h3>' +
    '<p class="text-[13px] text-sub mt-2">Estimation will no longer be available when creating or editing work items. ' +
    'Existing estimate information will be retained and can be restored if Estimation is enabled again.</p>' +
    '<div class="flex items-center justify-end gap-2 mt-5">' +
    '<button type="button" @click="disableConfirm.open = false" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="disable(true)" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Disable Estimation</button>' +
    '</div></div></div>' +

    '</div>'
});
