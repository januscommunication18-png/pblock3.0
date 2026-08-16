/* Settings › Work Capacity — the workspace's working week and thresholds.
   (docs/features/work-capacity.md §12–§15, §29) */
PB.boot('work-capacity', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return {
      enabled: b.enabled,
      hoursPerDay: String(b.hoursPerDay),
      workingDays: b.workingDays.slice(),
      thresholds: {
        near: String(b.thresholds.near),
        over: String(b.thresholds.over),
        high: String(b.thresholds.high)
      },
      saved: JSON.stringify([
        parseFloat(b.hoursPerDay),
        b.workingDays.slice().sort(function (a, c) { return a - c; }),
        Number(b.thresholds.near), Number(b.thresholds.over), Number(b.thresholds.high)
      ]),
      weekdays: b.weekdays,
      limits: b.limits,
      endpoints: b.endpoints,
      errors: {},
      saving: false,
      toggling: false
    };
  },
  computed: {
    /* §15 — shown live as either input changes, because "8 × 5 = 40" is the whole point of
       the two fields above it and making people save to find out is a needless round trip. */
    weeklyHours: function () {
      var h = parseFloat(this.hoursPerDay);
      if (isNaN(h) || !this.workingDays.length) return null;
      return Math.round(h * this.workingDays.length * 100) / 100;
    },
    /* Compared as a normalized signature rather than field by field: the day list is a set,
       so reordering it is not a change, and the numbers arrive from inputs as strings. */
    signature: function () {
      return JSON.stringify([
        parseFloat(this.hoursPerDay),
        this.workingDays.slice().sort(function (a, b) { return a - b; }),
        Number(this.thresholds.near), Number(this.thresholds.over), Number(this.thresholds.high)
      ]);
    },
    dirty: function () { return this.signature !== this.saved; },
    canSave: function () {
      return this.dirty && this.workingDays.length > 0 && !this.saving;
    }
  },
  methods: {
    toggleDay: function (value) {
      var i = this.workingDays.indexOf(value);
      if (i === -1) this.workingDays.push(value); else this.workingDays.splice(i, 1);
    },
    isWorkingDay: function (value) { return this.workingDays.indexOf(value) !== -1; },

    toggleFeature: async function (next) {
      if (this.toggling) return;
      this.toggling = true;
      try {
        var resp = await this.$pb.api(this.endpoints.toggle, { method: 'POST', body: { enabled: next } });
        this.enabled = resp.enabled;
        this.$pb.toast(resp.enabled ? 'Work capacity tracking is on.' : 'Work capacity tracking is off.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.toggling = false;
    },

    save: async function () {
      if (!this.canSave) return;
      this.saving = true;
      this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.update, {
          method: 'PATCH',
          body: {
            hours_per_day: Number(this.hoursPerDay),
            working_days: this.workingDays,
            near_threshold: Number(this.thresholds.near),
            over_threshold: Number(this.thresholds.over),
            high_threshold: Number(this.thresholds.high)
          }
        });
        this.hoursPerDay = String(resp.hoursPerDay);
        this.workingDays = resp.workingDays.slice();
        this.saved = this.signature;
        this.$pb.toast('Work capacity updated.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    }
  },

  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Work Capacity" ' +
    'desc="Compare your team\'s available working hours with estimated, planned and logged work."/>' +

    // ---- enable (§12) ----
    '<div class="border border-line rounded-xl p-5 flex items-start gap-4">' +
    '<div class="flex-1">' +
    '<div class="text-[14px] font-medium text-ink">Enable work capacity tracking</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-[560px]">' +
    'Turns on the Team Capacity report and lets estimates be compared against the hours people ' +
    'actually have. Switching it off hides the settings below but keeps them — turn it back on ' +
    'and your working week and thresholds are as you left them.</p>' +
    '</div>' +
    '<pb-toggle :model-value="enabled" :disabled="toggling" @update:model-value="toggleFeature" />' +
    '</div>' +

    // ---- working week (§13, §14, §15) ----
    // Everything below only exists once tracking is on: configuring a working week for a report
    // nobody can open is a form with no consequence, and reads as though it did something.
    '<template v-if="enabled">' +
    '<div class="border border-line rounded-xl p-5 mt-4">' +
    '<div class="text-[14px] font-medium text-ink">Standard working week</div>' +
    '<p class="text-[13px] text-sub mt-1">The default for everyone. Individual people can be given their own hours in Members.</p>' +

    '<div class="mt-4 max-w-[220px]">' +
    '<label class="block text-[12px] font-medium text-sub mb-1.5">Working hours per day</label>' +
    '<input v-model="hoursPerDay" type="number" step="0.5" :min="limits.min" :max="limits.max" ' +
    'class="pb-input" :class="{\'is-error\': errors.hours_per_day}" />' +
    '<p v-if="errors.hours_per_day" class="text-[12px] text-danger mt-1">{{ errors.hours_per_day[0] }}</p>' +
    '<p v-else class="text-[12px] text-sub mt-1">Half-days are fine — 7.5 is a common working day.</p>' +
    '</div>' +

    '<label class="block text-[12px] font-medium text-sub mt-5 mb-2">Working days</label>' +
    '<div class="flex flex-wrap gap-2">' +
    '<button v-for="d in weekdays" :key="d.value" type="button" @click="toggleDay(d.value)" ' +
    ':aria-pressed="String(isWorkingDay(d.value))" ' +
    ':class="[\'h-9 px-3.5 rounded-md text-[13px] font-semibold border transition-colors\', ' +
    'isWorkingDay(d.value) ? \'bg-brand text-white border-brand\' : \'bg-white text-ink border-stroke hover:bg-hover\']">' +
    '{{ d.short }}</button>' +
    '</div>' +
    '<p v-if="errors.working_days" class="text-[12px] text-danger mt-2">{{ errors.working_days[0] }}</p>' +
    '<p v-else-if="!workingDays.length" class="text-[12px] text-danger mt-2">Choose at least one working day.</p>' +

    // The derived figure, never stored (CAP-D7).
    '<div class="mt-5 rounded-md border border-line bg-hover px-4 py-3 flex items-baseline gap-2">' +
    '<span class="text-[13px] text-sub">Weekly capacity</span>' +
    '<span class="text-[17px] font-bold text-head">{{ weeklyHours === null ? \'—\' : weeklyHours + \'h\' }}</span>' +
    '<span v-if="weeklyHours !== null" class="text-[12px] text-sub">' +
    '({{ hoursPerDay }}h × {{ workingDays.length }} day<span v-if="workingDays.length !== 1">s</span>)</span>' +
    '</div>' +
    '</div>' +

    // ---- thresholds (§29) ----
    '<div class="border border-line rounded-xl p-5 mt-4">' +
    '<div class="text-[14px] font-medium text-ink">Capacity thresholds</div>' +
    '<p class="text-[13px] text-sub mt-1">' +
    'When a member\'s planned or logged hours reach these percentages of their capacity. ' +
    'Each must be higher than the one before it.</p>' +

    '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">' +
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Near Capacity</label>' +
    '<div class="relative"><input v-model="thresholds.near" type="number" min="1" max="500" class="pb-input pr-7" ' +
    ':class="{\'is-error\': errors.near_threshold}" />' +
    '<span class="absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-faint">%</span></div></div>' +

    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Over Capacity</label>' +
    '<div class="relative"><input v-model="thresholds.over" type="number" min="1" max="500" class="pb-input pr-7" ' +
    ':class="{\'is-error\': errors.over_threshold}" />' +
    '<span class="absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-faint">%</span></div></div>' +

    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">High Workload</label>' +
    '<div class="relative"><input v-model="thresholds.high" type="number" min="1" max="500" class="pb-input pr-7" ' +
    ':class="{\'is-error\': errors.high_threshold}" />' +
    '<span class="absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-faint">%</span></div></div>' +
    '</div>' +
    '<p v-if="errors.near_threshold" class="text-[12px] text-danger mt-2">{{ errors.near_threshold[0] }}</p>' +
    '</div>' +

    '<div class="mt-5 flex items-center gap-3">' +
    '<button type="button" :disabled="!canSave" @click="save" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save changes\' }}</button>' +
    '<span v-if="dirty" class="text-[12px] text-sub">Unsaved changes</span>' +
    '</div>' +
    '</template>' +
    '</div>'
});
