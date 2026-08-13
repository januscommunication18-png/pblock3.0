/* The account modal's Preference tab — Language & Time (Account §2).
   ------------------------------------------------------------------
   The one Vue island in an otherwise plain-JS dialog, and it earns that: the tab is four
   pickers, two of them searchable over hundreds of options, and <pb-combo> — the searchable
   combobox the workspace timezone setting already uses — is registered on Vue apps by
   settings/app.js. Writing a second combobox in vanilla for this panel would be the same
   mistake the account menu had made three times over.

   Mounted through PB.boot with an explicit root, because this dialog lives in the topbar,
   outside #settings-root.

   These are WORKSPACE settings behind a personal-looking menu. The panel is only rendered for
   owners and admins; the request re-checks that, so the gate is not the markup.
   ------------------------------------------------------------------ */

var AccountPreference = {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      timezones: b.timezones || [],
      languages: b.languages || [],
      days: b.days || [],
      endpoint: b.endpoint || '',
      saving: false,
      errors: {},
      // The form is a copy, so Cancel is just "close" and an abandoned edit leaves nothing
      // behind. `first_day_of_week` and the weekend are strings here because <pb-combo> deals
      // in strings; they are cast back on save.
      form: {
        timezone: b.timezone || 'UTC',
        language: b.language || 'en',
        first_day_of_week: String(b.first_day_of_week || 7),
        weekend_days: (b.weekend_days || []).map(String)
      }
    };
  },

  computed: {
    /** "Saturday, Sunday" — the summary the row shows when the picker is closed. */
    weekendLabel: function () {
      var days = this.days;
      var chosen = this.form.weekend_days;

      if (!chosen.length) return 'None';

      // Ordered by the week, not by the order they were ticked.
      return days
        .filter(function (d) { return chosen.indexOf(d.value) > -1; })
        .map(function (d) { return d.label; })
        .join(', ');
    }
  },

  methods: {
    save: async function () {
      if (this.saving) return;
      this.saving = true;
      this.errors = {};

      try {
        var resp = await this.$pb.api(this.endpoint, {
          method: 'PATCH',
          body: {
            timezone: this.form.timezone,
            language: this.form.language,
            first_day_of_week: parseInt(this.form.first_day_of_week, 10),
            weekend_days: this.form.weekend_days.map(Number)
          }
        });

        this.$pb.toast(resp.message || 'Preferences updated.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e) || {};
        this.$pb.toast(this.$pb.firstError(e, 'Could not save your preferences.'));
      } finally {
        this.saving = false;
      }
    },

    error: function (field) {
      var list = this.errors[field];

      return list && list.length ? list[0] : '';
    }
  },

  // A labelled row per setting, control on the right — the shape in the reference screenshot
  // and the same one the workspace settings sections use.
  template:
    '<div class="px-6 py-5">' +
      '<h3 class="text-[14px] font-semibold text-head">Language &amp; Time</h3>' +

      '<div class="mt-4 divide-y divide-line">' +

        '<div class="py-4 flex items-start gap-4">' +
          '<div class="min-w-0 flex-1">' +
            '<div class="text-[13px] font-medium text-ink">Timezone</div>' +
            '<p class="text-[12px] text-sub mt-0.5">Current timezone setting.</p>' +
            '<p v-if="error(\'timezone\')" class="text-[12px] text-danger mt-1">{{ error(\'timezone\') }}</p>' +
          '</div>' +
          '<div class="w-56 shrink-0">' +
            '<pb-combo v-model="form.timezone" :options="timezones" :invalid="!!error(\'timezone\')" placeholder="Search timezone…" dense />' +
          '</div>' +
        '</div>' +

        '<div class="py-4 flex items-start gap-4">' +
          '<div class="min-w-0 flex-1">' +
            '<div class="text-[13px] font-medium text-ink">Language</div>' +
            '<p class="text-[12px] text-sub mt-0.5">Choose the language used in the user interface.</p>' +
            '<p v-if="error(\'language\')" class="text-[12px] text-danger mt-1">{{ error(\'language\') }}</p>' +
          '</div>' +
          '<div class="w-56 shrink-0">' +
            '<pb-combo v-model="form.language" :options="languages" :invalid="!!error(\'language\')" placeholder="Search language…" dense />' +
          '</div>' +
        '</div>' +

        '<div class="py-4 flex items-start gap-4">' +
          '<div class="min-w-0 flex-1">' +
            '<div class="text-[13px] font-medium text-ink">First day of the week</div>' +
            '<p class="text-[12px] text-sub mt-0.5">Choose which day your week starts on.</p>' +
            '<p v-if="error(\'first_day_of_week\')" class="text-[12px] text-danger mt-1">{{ error(\'first_day_of_week\') }}</p>' +
          '</div>' +
          '<div class="w-56 shrink-0">' +
            '<pb-combo v-model="form.first_day_of_week" :options="days" :searchable="false" dense />' +
          '</div>' +
        '</div>' +

        '<div class="py-4 flex items-start gap-4">' +
          '<div class="min-w-0 flex-1">' +
            '<div class="text-[13px] font-medium text-ink">Weekend days</div>' +
            '<p class="text-[12px] text-sub mt-0.5">Sets which days are treated as non-working time.</p>' +
            '<p class="text-[12px] text-faint mt-0.5">{{ weekendLabel }}</p>' +
            '<p v-if="error(\'weekend_days\')" class="text-[12px] text-danger mt-1">{{ error(\'weekend_days\') }}</p>' +
          '</div>' +
          '<div class="w-56 shrink-0">' +
            // multiple: a weekend is not always two days, and may be none at all.
            '<pb-combo v-model="form.weekend_days" :options="days" :searchable="false" multiple dense />' +
          '</div>' +
        '</div>' +

      '</div>' +

      '<div class="mt-5 flex justify-end">' +
        '<button type="button" @click="save" :disabled="saving"' +
          ' class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
          'Save changes</button>' +
      '</div>' +
    '</div>'
};

// Deferred like every other screen script, so PB.boot and the shared components exist by now.
if (document.getElementById('account-preference-root')) {
  PB.boot('account-preference', AccountPreference, { root: 'account-preference-root' });
}
