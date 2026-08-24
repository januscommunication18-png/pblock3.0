/* Help Center › Space › Settings › SLA (docs/features/helpdesk-sla.md, §2).
   ------------------------------------------------------------------
   FOUR resources on one screen — Policies, Business Hours, Holiday Calendar, Escalation Rules —
   as four tabs rather than four nav entries. §2 lists them as one section, and they are one
   question asked four ways: what do we promise, when does the clock run, when does it not, and
   who hears about it.

   Its own file rather than another `kind` inside space-settings.js. That file is one 3,500-line
   component over eleven panels that share a single PATCH endpoint; this screen has four
   endpoints of its own and would have doubled it.

   Every vocabulary here — priorities, units, triggers, actions, week days — arrives in the
   bootstrap. There is no list in this file that config does not own, so adding a unit is a
   config edit and not a second one here.

   Every control is mirrored by a server-side check in the SLA controllers and their form
   requests. Hiding a button is not enforcing a rule.
   ------------------------------------------------------------------ */

PB.boot('help-center-sla', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      title: b.title || 'SLA',
      description: b.description || '',
      note: b.note || '',
      canManage: !!b.canManage,
      endpoints: b.slaEndpoints || {},

      tab: 'policies',

      policies: Array.isArray(b.policies) ? b.policies : [],
      businessHours: Array.isArray(b.businessHours) ? b.businessHours : [],
      holidays: Array.isArray(b.holidays) ? b.holidays : [],
      escalations: Array.isArray(b.escalations) ? b.escalations : [],

      // Vocabularies (config-owned).
      priorities: Array.isArray(b.priorities) ? b.priorities : [],
      units: Array.isArray(b.units) ? b.units : [],
      timerKinds: Array.isArray(b.timerKinds) ? b.timerKinds : [],
      reopenBehaviors: Array.isArray(b.reopenBehaviors) ? b.reopenBehaviors : [],
      conditionFields: Array.isArray(b.conditionFields) ? b.conditionFields : [],
      conditionOperators: Array.isArray(b.conditionOperators) ? b.conditionOperators : [],
      escalationTriggers: Array.isArray(b.escalationTriggers) ? b.escalationTriggers : [],
      escalationActions: Array.isArray(b.escalationActions) ? b.escalationActions : [],
      weekDays: Array.isArray(b.weekDays) ? b.weekDays : [],
      defaultSchedule: b.defaultSchedule || {},
      timezones: Array.isArray(b.timezones) ? b.timezones : [],

      // What conditions and actions may point at.
      companies: Array.isArray(b.companies) ? b.companies : [],
      tags: Array.isArray(b.tags) ? b.tags : [],
      statuses: Array.isArray(b.statuses) ? b.statuses : [],
      members: Array.isArray(b.members) ? b.members : [],
      groups: Array.isArray(b.groups) ? b.groups : [],
      customerFields: Array.isArray(b.customerFields) ? b.customerFields : [],
      companyFields: Array.isArray(b.companyFields) ? b.companyFields : [],

      policyForm: this.blankPolicyForm(),
      hoursForm: { open: false, editing: null, saving: false, error: '', errors: {}, name: '', timezone: 'UTC', is_default: false, days: [] },
      holidayForm: { open: false, editing: null, saving: false, error: '', name: '', date: '', repeats_annually: false },
      escalationForm: { open: false, editing: null, saving: false, error: '', name: '', trigger: 'breached', kind: '', policy_id: '', is_active: true, actions: [] },

      confirm: { open: false, kind: '', row: null, title: '', message: '' },

      // Which policy is being dragged, for the reorder (§14). Null when nothing is.
      dragging: null,
    };
  },

  computed: {
    tabs: function () {
      return [
        { key: 'policies', label: 'SLA Policies', count: this.policies.length },
        { key: 'hours', label: 'Business Hours', count: this.businessHours.length },
        { key: 'holidays', label: 'Holiday Calendar', count: this.holidays.length },
        { key: 'escalations', label: 'Escalation Rules', count: this.escalations.length },
      ];
    },

    /** The business-hours picker, with the 24/7 option §D3 describes at the top. */
    hoursOptions: function () {
      return [{ value: '', label: '24/7 — no business hours' }].concat(
        this.businessHours.map(function (h) { return { value: String(h.id), label: h.name + ' · ' + h.timezone }; })
      );
    },

    policyOptions: function () {
      return [{ value: '', label: 'Any SLA policy' }].concat(
        this.policies.map(function (p) { return { value: String(p.id), label: p.name }; })
      );
    },

    kindOptions: function () {
      return [{ value: '', label: 'Any SLA target' }].concat(
        this.timerKinds.map(function (k) { return { value: k.value, label: k.label }; })
      );
    },

    /*
     * The plain {value,label} lists the pickers take.
     *
     * Computed rather than mapped inside the template: a `.map()` in a template expression
     * builds a NEW array on every render, and `pb-combo` receives a prop that is never twice
     * the same object — which is how a dropdown ends up closing itself as you type in it.
     */
    reopenOptions: function () {
      return this.reopenBehaviors.map(function (r) { return { value: r.value, label: r.label }; });
    },

    triggerOptions: function () {
      return this.escalationTriggers.map(function (t) { return { value: t.value, label: t.label }; });
    },
  },

  methods: {
    // ---- shared -----------------------------------------------------------------------

    /*
     * A blank policy form, targets included.
     *
     * Every priority gets a row up front, even the ones nobody will fill in. A table that grows
     * as you type makes "what have I promised Urgent tickets?" a question you answer by
     * scrolling; five rows with three blank cells each answers it at a glance, and blank means
     * "no promise" all the way through to the column (SLA-D7).
     */
    blankPolicyForm: function () {
      var b = this.bootstrap || {};
      var kinds = (b.timerKinds || []).map(function (k) { return k.value; });

      var targets = (b.priorities || []).map(function (p) {
        var row = { priority: p.value, priority_label: p.label };
        kinds.forEach(function (kind) {
          row[kind + '_value'] = '';
          row[kind + '_unit'] = 'hours';
        });
        return row;
      });

      return {
        open: false, editing: null, saving: false, error: '', errors: {},
        name: '', description: '', is_active: true, is_default: false,
        business_hours_id: '', warning_percent: 80, match_type: 'all',
        reopen_behavior: 'resume', conditions: [], targets: targets,
      };
    },

    /** The endpoint for a row, with `__ID__` filled in. */
    url: function (key, id) {
      var template = this.endpoints[key] || '';
      return id === undefined ? template : PB.withId(template, id);
    },

    fail: function (form, err, fallback) {
      form.error = PB.firstError(err, fallback);
      form.errors = PB.fieldErrors ? PB.fieldErrors(err) : {};
    },

    // ---- policies (§3, §4, §8, §12, §14) ------------------------------------------------

    openPolicy: function (policy) {
      var form = this.blankPolicyForm();

      if (policy) {
        form.editing = policy.id;
        form.name = policy.name;
        form.description = policy.description || '';
        form.is_active = !!policy.is_active;
        form.is_default = !!policy.is_default;
        form.business_hours_id = policy.business_hours_id ? String(policy.business_hours_id) : '';
        form.warning_percent = policy.warning_percent;
        form.match_type = policy.match_type;
        form.reopen_behavior = policy.reopen_behavior;
        form.conditions = (policy.conditions || []).map(function (c) {
          return { field: c.field, operator: c.operator, value: c.value, field_id: c.field_id || '' };
        });

        // Merge the saved targets ONTO the blank rows rather than replacing them: a policy
        // saved before a priority existed would otherwise open with that priority missing.
        (policy.targets || []).forEach(function (saved) {
          var row = form.targets.find(function (t) { return t.priority === saved.priority; });
          if (!row) return;

          ['first_response', 'next_response', 'resolution'].forEach(function (kind) {
            var d = saved[kind];
            row[kind + '_value'] = d ? d.value : '';
            row[kind + '_unit'] = d ? d.unit : 'hours';
          });
        });
      }

      form.open = true;
      this.policyForm = form;
    },

    addCondition: function () {
      this.policyForm.conditions.push({ field: 'company', operator: 'is', value: '', field_id: '' });
    },

    removeCondition: function (index) {
      this.policyForm.conditions.splice(index, 1);
    },

    /** What a condition's value picker offers — driven by the field's `source` in config. */
    conditionField: function (key) {
      return this.conditionFields.find(function (f) { return f.value === key; }) || {};
    },

    conditionValueOptions: function (condition) {
      var field = this.conditionField(condition.field);

      if (field.value === 'company') return this.companies.map(this.asOption);
      if (field.value === 'tag') return this.tags.map(this.asOption);
      if (field.value === 'priority') return this.priorities.map(function (p) { return { value: p.value, label: p.label }; });
      if (field.value === 'customer_field') return this.customerFields.map(this.asOption);
      if (field.value === 'company_field') return this.companyFields.map(this.asOption);

      return [];
    },

    asOption: function (o) { return { value: String(o.value), label: o.label }; },

    /** Does this operator take a value at all? `is set` / `is not set` do not. */
    operatorTakesValue: function (key) {
      var operator = this.conditionOperators.find(function (o) { return o.value === key; });
      return !(operator && operator.valueless);
    },

    operatorIsMultiple: function (key) {
      var operator = this.conditionOperators.find(function (o) { return o.value === key; });
      return !!(operator && operator.multiple);
    },

    /** A condition on a custom field needs to say WHICH field; the rest do not. */
    conditionNeedsFieldId: function (condition) {
      return this.conditionField(condition.field).source === 'custom_field';
    },

    conditionIsText: function (condition) {
      return this.conditionField(condition.field).source === 'text';
    },

    savePolicy: async function () {
      var form = this.policyForm;
      if (form.saving) return;

      form.saving = true;
      form.error = '';
      form.errors = {};

      var body = {
        name: form.name,
        description: form.description,
        is_active: form.is_active,
        is_default: form.is_default,
        business_hours_id: form.business_hours_id === '' ? null : Number(form.business_hours_id),
        warning_percent: Number(form.warning_percent),
        match_type: form.match_type,
        reopen_behavior: form.reopen_behavior,
        conditions: form.conditions.map(function (c) {
          return { field: c.field, operator: c.operator, value: c.value, field_id: c.field_id || null };
        }),
        // Only the rows that promise something travel. A row of three blanks is not a target,
        // and sending it would create an empty row per priority on every save.
        targets: form.targets.filter(function (t) {
          return t.first_response_value !== '' || t.next_response_value !== '' || t.resolution_value !== '';
        }).map(function (t) {
          var out = { priority: t.priority };
          ['first_response', 'next_response', 'resolution'].forEach(function (kind) {
            var value = t[kind + '_value'];
            out[kind + '_value'] = value === '' ? null : Number(value);
            out[kind + '_unit'] = value === '' ? null : t[kind + '_unit'];
          });
          return out;
        }),
      };

      // The server refuses a policy with no targets, and says so — but it says so after a round
      // trip, and this is the one mistake somebody makes on their first policy.
      if (body.targets.length === 0) {
        form.saving = false;
        form.error = 'Set at least one target before saving — a policy that promises nothing has nothing to measure.';
        return;
      }

      try {
        var res = form.editing
          ? await PB.api(this.url('policyUpdate', form.editing), { method: 'PATCH', body: body })
          : await PB.api(this.url('policyStore'), { method: 'POST', body: body });

        this.mergePolicy(res.policy);
        form.open = false;
        PB.toast(res.message || 'Saved.');
      } catch (err) {
        this.fail(form, err, 'The policy could not be saved.');
      } finally {
        form.saving = false;
      }
    },

    /*
     * Put a saved policy back into the list — and re-read the DEFAULT flag off it.
     *
     * Making one policy the default clears it on every other, server-side. Only patching the
     * row that came back would leave two rows wearing the Default badge until a refresh.
     */
    mergePolicy: function (policy) {
      if (!policy) return;

      var index = this.policies.findIndex(function (p) { return p.id === policy.id; });

      if (index === -1) this.policies.push(policy);
      else this.policies.splice(index, 1, policy);

      if (policy.is_default) {
        this.policies.forEach(function (p) {
          if (p.id !== policy.id) p.is_default = false;
        });
      }

      this.policies.sort(function (a, b) { return a.position - b.position; });
    },

    duplicatePolicy: async function (policy) {
      try {
        var res = await PB.api(this.url('policyDuplicate', policy.id), { method: 'POST' });
        this.mergePolicy(res.policy);
        PB.toast(res.message || 'Duplicated.');
      } catch (err) {
        PB.toast(PB.firstError(err, 'The policy could not be duplicated.'), 'error');
      }
    },

    togglePolicyActive: async function (policy) {
      // The whole policy goes back, because the update endpoint takes the whole policy — the
      // same rule the custom-field panel follows. Building a second, smaller shape for one
      // switch is a second place that has to agree about what a missing key means.
      var body = {
        name: policy.name,
        description: policy.description,
        is_active: !policy.is_active,
        is_default: policy.is_default,
        business_hours_id: policy.business_hours_id,
        warning_percent: policy.warning_percent,
        match_type: policy.match_type,
        reopen_behavior: policy.reopen_behavior,
        conditions: policy.conditions || [],
        targets: (policy.targets || []).map(function (t) {
          var out = { priority: t.priority };
          ['first_response', 'next_response', 'resolution'].forEach(function (kind) {
            out[kind + '_value'] = t[kind] ? t[kind].value : null;
            out[kind + '_unit'] = t[kind] ? t[kind].unit : null;
          });
          return out;
        }),
      };

      try {
        var res = await PB.api(this.url('policyUpdate', policy.id), { method: 'PATCH', body: body });
        this.mergePolicy(res.policy);
      } catch (err) {
        PB.toast(PB.firstError(err, 'The policy could not be updated.'), 'error');
      }
    },

    // Drag to reorder (§14). The order IS the rule — first match wins — so the list is
    // reordered locally and then sent whole, and the server's answer replaces it.
    onDragStart: function (index) { this.dragging = index; },

    onDragOver: function (index) {
      if (this.dragging === null || this.dragging === index) return;

      var moved = this.policies.splice(this.dragging, 1)[0];
      this.policies.splice(index, 0, moved);
      this.dragging = index;
    },

    onDrop: async function () {
      if (this.dragging === null) return;
      this.dragging = null;

      try {
        var res = await PB.api(this.url('policyOrder'), {
          method: 'PATCH',
          body: { ids: this.policies.map(function (p) { return p.id; }) },
        });
        this.policies = res.policies;
      } catch (err) {
        PB.toast(PB.firstError(err, 'The order could not be saved.'), 'error');
      }
    },

    // ---- business hours (§5, §6) --------------------------------------------------------

    openHours: function (hours) {
      var self = this;
      var days;

      if (hours) {
        days = this.weekDays.map(function (d) {
          var saved = (hours.days || []).find(function (x) { return x.key === d.value; });
          var open = saved && saved.hours;
          return { key: d.value, label: d.label, closed: !open, open: open ? open.open : '09:00', close: open ? open.close : '17:00' };
        });
      } else {
        // A new calendar opens on §5's example week rather than on seven blank rows — the
        // common answer, already filled in.
        days = this.weekDays.map(function (d) {
          var preset = self.defaultSchedule[d.value];
          return { key: d.value, label: d.label, closed: !preset, open: preset ? preset.open : '09:00', close: preset ? preset.close : '17:00' };
        });
      }

      this.hoursForm = {
        open: true,
        editing: hours ? hours.id : null,
        saving: false, error: '', errors: {},
        name: hours ? hours.name : '',
        timezone: hours ? hours.timezone : (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'),
        is_default: hours ? !!hours.is_default : this.businessHours.length === 0,
        days: days,
      };
    },

    saveHours: async function () {
      var form = this.hoursForm;
      if (form.saving) return;

      form.saving = true;
      form.error = '';
      form.errors = {};

      var days = {};
      form.days.forEach(function (d) {
        days[d.key] = { closed: d.closed, open: d.open, close: d.close };
      });

      var body = { name: form.name, timezone: form.timezone, is_default: form.is_default, days: days };

      try {
        var res = form.editing
          ? await PB.api(this.url('hoursUpdate', form.editing), { method: 'PATCH', body: body })
          : await PB.api(this.url('hoursStore'), { method: 'POST', body: body });

        this.mergeHours(res.businessHours);
        form.open = false;
        PB.toast(res.message || 'Saved.');
      } catch (err) {
        this.fail(form, err, 'The business hours could not be saved.');
      } finally {
        form.saving = false;
      }
    },

    mergeHours: function (hours) {
      if (!hours) return;

      var index = this.businessHours.findIndex(function (h) { return h.id === hours.id; });

      if (index === -1) this.businessHours.push(hours);
      else this.businessHours.splice(index, 1, hours);

      if (hours.is_default) {
        this.businessHours.forEach(function (h) { if (h.id !== hours.id) h.is_default = false; });
      }
    },

    /** "Mon–Fri 08:00–18:00 · Sat, Sun closed" — the week in one line, for the list. */
    hoursSummary: function (hours) {
      var open = (hours.days || []).filter(function (d) { return d.hours; });

      if (open.length === 0) return 'Closed all week';

      return open.map(function (d) {
        return d.label.slice(0, 3) + ' ' + d.hours.open + '–' + d.hours.close;
      }).join(' · ');
    },

    // ---- holidays (§7) ------------------------------------------------------------------

    openHoliday: function (holiday) {
      this.holidayForm = {
        open: true,
        editing: holiday ? holiday.id : null,
        saving: false, error: '',
        name: holiday ? holiday.name : '',
        date: holiday ? holiday.date : '',
        repeats_annually: holiday ? !!holiday.repeats_annually : false,
      };
    },

    saveHoliday: async function () {
      var form = this.holidayForm;
      if (form.saving) return;

      form.saving = true;
      form.error = '';

      var body = { name: form.name, date: form.date, repeats_annually: form.repeats_annually };

      try {
        var res = form.editing
          ? await PB.api(this.url('holidayUpdate', form.editing), { method: 'PATCH', body: body })
          : await PB.api(this.url('holidayStore'), { method: 'POST', body: body });

        var index = this.holidays.findIndex(function (h) { return h.id === res.holiday.id; });
        if (index === -1) this.holidays.push(res.holiday);
        else this.holidays.splice(index, 1, res.holiday);

        this.holidays.sort(function (a, b) { return a.date < b.date ? -1 : 1; });
        form.open = false;
        PB.toast(res.message || 'Saved.');
      } catch (err) {
        this.fail(form, err, 'The holiday could not be saved.');
      } finally {
        form.saving = false;
      }
    },

    // ---- escalation rules (§24, §25) ----------------------------------------------------

    openEscalation: function (rule) {
      this.escalationForm = {
        open: true,
        editing: rule ? rule.id : null,
        saving: false, error: '',
        name: rule ? rule.name : '',
        trigger: rule ? rule.trigger : 'breached',
        kind: rule && rule.kind ? rule.kind : '',
        policy_id: rule && rule.policy_id ? String(rule.policy_id) : '',
        is_active: rule ? !!rule.is_active : true,
        actions: rule ? (rule.actions || []).map(function (a) { return { action: a.action, value: a.value }; }) : [],
      };
    },

    addAction: function () {
      this.escalationForm.actions.push({ action: 'notify_assignee', value: '' });
    },

    removeAction: function (index) {
      this.escalationForm.actions.splice(index, 1);
    },

    /** What kind of value this action needs, from config — or null when it needs none. */
    actionValueKind: function (key) {
      var row = this.escalationActions.find(function (a) { return a.value === key; });
      return row ? (row.needs || null) : null;
    },

    /** What an action is called, for the summary line on a rule row. */
    actionLabel: function (key) {
      var row = this.escalationActions.find(function (a) { return a.value === key; });
      return row ? row.label : key;
    },

    actionOptions: function (kind) {
      if (kind === 'priority') return this.priorities.map(function (p) { return { value: p.value, label: p.label }; });
      if (kind === 'status') return this.statuses.map(this.asOption);
      if (kind === 'tag') return this.tags.map(this.asOption);
      if (kind === 'member') return this.members.map(this.asOption);
      if (kind === 'group') return this.groups.map(this.asOption);
      return [];
    },

    saveEscalation: async function () {
      var form = this.escalationForm;
      if (form.saving) return;

      form.saving = true;
      form.error = '';

      var body = {
        name: form.name,
        trigger: form.trigger,
        kind: form.kind || null,
        policy_id: form.policy_id === '' ? null : Number(form.policy_id),
        is_active: form.is_active,
        actions: form.actions,
      };

      try {
        var res = form.editing
          ? await PB.api(this.url('escalationUpdate', form.editing), { method: 'PATCH', body: body })
          : await PB.api(this.url('escalationStore'), { method: 'POST', body: body });

        var index = this.escalations.findIndex(function (e) { return e.id === res.escalation.id; });
        if (index === -1) this.escalations.push(res.escalation);
        else this.escalations.splice(index, 1, res.escalation);

        form.open = false;
        PB.toast(res.message || 'Saved.');
      } catch (err) {
        this.fail(form, err, 'The rule could not be saved.');
      } finally {
        form.saving = false;
      }
    },

    // ---- deletes ------------------------------------------------------------------------

    /*
     * One confirmation for all four resources.
     *
     * They differ only in the sentence and the endpoint, and four near-identical dialogs is
     * four places for the next copy change to be half-applied.
     */
    askDelete: function (kind, row) {
      var messages = {
        policy: 'Tickets already running under this policy keep their deadlines and their history. '
          + 'New tickets will fall through to the next matching policy, or to the default.',
        hours: 'Any SLA policy using this calendar falls back to counting round the clock, which '
          + 'makes its targets stricter, not looser.',
        holiday: 'The SLA clock will run on this day again.',
        escalation: 'Nothing will be escalated by this rule again.',
      };

      this.confirm = {
        open: true, kind: kind, row: row,
        title: 'Delete ' + (row.name || 'this') + '?',
        message: messages[kind] || '',
      };
    },

    doDelete: async function () {
      var kind = this.confirm.kind;
      var row = this.confirm.row;
      var map = {
        policy: { url: 'policyDestroy', list: 'policies' },
        hours: { url: 'hoursDestroy', list: 'businessHours' },
        holiday: { url: 'holidayDestroy', list: 'holidays' },
        escalation: { url: 'escalationDestroy', list: 'escalations' },
      };
      var target = map[kind];

      if (!target) return;

      try {
        var res = await PB.api(this.url(target.url, row.id), { method: 'DELETE' });
        this[target.list] = this[target.list].filter(function (r) { return r.id !== row.id; });
        this.confirm.open = false;
        PB.toast(res.message || 'Deleted.');
      } catch (err) {
        // A refusal here is a RULE, not a failure — "make another policy the default first" —
        // so it is shown as a message rather than swallowed, and the dialog stays open.
        this.confirm.open = false;
        PB.toast(PB.firstError(err, 'It could not be deleted.'), 'error');
      }
    },
  },

  /*
   * ONE template, four tabs.
   *
   * Written as a string like every other screen in this codebase — the project has no build
   * step for these files, so a component is a string and stays one.
   *
   * The 980px column is wider than the settings panels' 820px on purpose: the targets table is
   * four columns of number-and-unit pairs, and a reading measure squeezes it into something
   * nobody can scan.
   */
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    '<pb-section-head :title="title" :desc="description"/>' +

    // Honest about what is stored versus what runs, exactly as Reassignment is (HC-D17).
    '<div v-if="note" class="mb-6 rounded-lg border border-line bg-hover/40 px-4 py-3 text-[12px] text-sub">{{ note }}</div>' +

    // ---- tabs -------------------------------------------------------------------------
    '<div class="flex items-center gap-1 border-b border-line mb-6">' +
    '<button v-for="t in tabs" :key="t.key" type="button" @click="tab = t.key" ' +
    ':class="[\'relative h-9 px-3 text-[13px] font-medium -mb-px border-b-2\', tab === t.key ? \'border-brand text-brand\' : \'border-transparent text-sub hover:text-ink\']">' +
    '{{ t.label }}' +
    '<span v-if="t.count" class="ml-1.5 text-[11px] text-faint">{{ t.count }}</span>' +
    '</button></div>' +

    // ================= SLA POLICIES (§3, §4, §8, §12, §14) =============================
    '<div v-if="tab === \'policies\'">' +
    '<div class="flex items-center justify-between mb-4">' +
    '<p class="text-[13px] text-sub max-w-xl">Policies are evaluated top down and the first one whose ' +
    'conditions match is applied. Drag to change that order. A ticket that matches nothing gets the default.</p>' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 ml-4" ' +
    '@click="openPolicy(null)">Add SLA Policy</button>' +
    '</div>' +

    '<pb-empty v-if="!policies.length" title="No SLA policies yet" ' +
    'subtitle="An SLA policy says what this Space promises — how fast somebody answers, and how fast it gets resolved.">' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" ' +
    '@click="openPolicy(null)">Add SLA Policy</button></pb-empty>' +

    '<div v-else class="space-y-2">' +
    '<div v-for="(p, i) in policies" :key="p.id" :draggable="canManage" ' +
    '@dragstart="onDragStart(i)" @dragover.prevent="onDragOver(i)" @drop.prevent="onDrop" @dragend="onDrop" ' +
    ':class="[\'flex items-start gap-3 px-4 py-3 border rounded-lg bg-white\', dragging === i ? \'border-brand shadow-sm\' : \'border-line\']">' +

    '<span v-if="canManage" class="mt-0.5 text-faint cursor-grab select-none" title="Drag to reorder">&#8942;&#8942;</span>' +
    '<span class="mt-0.5 text-[12px] text-faint w-4 shrink-0">{{ i + 1 }}</span>' +

    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-semibold text-head truncate">{{ p.name }}</span>' +
    '<span v-if="p.is_default" class="text-[10px] uppercase tracking-wide bg-sel text-brand rounded px-1.5 py-0.5">Default</span>' +
    '<span v-if="!p.is_active" class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">Inactive</span>' +
    '</div>' +
    '<p v-if="p.description" class="text-[12px] text-sub mt-0.5 truncate">{{ p.description }}</p>' +
    '<div class="text-[12px] text-sub mt-1.5 flex items-center gap-3 flex-wrap">' +
    '<span>{{ p.business_hours_name || \'24/7\' }}</span>' +
    '<span class="text-faint">&middot;</span>' +
    '<span>Due Soon at {{ p.warning_percent }}%</span>' +
    '<span class="text-faint">&middot;</span>' +
    '<span>{{ (p.conditions || []).length ? (p.conditions.length + \' condition\' + (p.conditions.length === 1 ? \'\' : \'s\')) : \'No conditions\' }}</span>' +
    '</div>' +

    // The promise itself, one line per priority that carries one. Shown in the list rather
    // than only in the drawer: "what do we promise Urgent tickets?" is the question this page
    // exists to answer, and it should not need a click.
    '<div v-if="(p.targets || []).length" class="mt-2 grid gap-1">' +
    '<div v-for="t in p.targets" :key="t.priority" class="text-[12px] text-sub flex items-center gap-2 flex-wrap">' +
    '<span class="text-ink font-medium w-20 shrink-0">{{ t.priority_label }}</span>' +
    '<span v-for="k in timerKinds" :key="k.value" class="text-faint">' +
    '{{ k.label }} <span class="text-ink">{{ t[k.value + \'_label\'] }}</span></span>' +
    '</div></div>' +
    '</div>' +

    '<div v-if="canManage" class="flex items-center gap-1 shrink-0">' +
    '<pb-toggle :model-value="!!p.is_active" @update:model-value="togglePolicyActive(p)"/>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-sub hover:bg-hover" @click="openPolicy(p)">Edit</button>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-sub hover:bg-hover" @click="duplicatePolicy(p)">Duplicate</button>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-danger hover:bg-hover" @click="askDelete(\'policy\', p)">Delete</button>' +
    '</div></div></div></div>' +

    // ================= BUSINESS HOURS (§5, §6) =========================================
    '<div v-else-if="tab === \'hours\'">' +
    '<div class="flex items-center justify-between mb-4">' +
    '<p class="text-[13px] text-sub max-w-xl">The hours the SLA clock runs, in the timezone you keep them in. ' +
    'A policy with no calendar counts round the clock.</p>' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 ml-4" ' +
    '@click="openHours(null)">Add Business Hours</button></div>' +

    '<pb-empty v-if="!businessHours.length" title="No business hours yet" ' +
    'subtitle="Add the working week your team actually keeps, and SLA time stops counting outside it.">' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" ' +
    '@click="openHours(null)">Add Business Hours</button></pb-empty>' +

    '<div v-else class="space-y-2">' +
    '<div v-for="h in businessHours" :key="h.id" class="flex items-start gap-3 px-4 py-3 border border-line rounded-lg bg-white">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[14px] font-semibold text-head truncate">{{ h.name }}</span>' +
    '<span v-if="h.is_default" class="text-[10px] uppercase tracking-wide bg-sel text-brand rounded px-1.5 py-0.5">Default</span>' +
    '</div>' +
    '<div class="text-[12px] text-sub mt-0.5">{{ h.timezone }}</div>' +
    '<div class="text-[12px] text-sub mt-1">{{ hoursSummary(h) }}</div>' +
    '</div>' +
    '<div v-if="canManage" class="flex items-center gap-1 shrink-0">' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-sub hover:bg-hover" @click="openHours(h)">Edit</button>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-danger hover:bg-hover" @click="askDelete(\'hours\', h)">Delete</button>' +
    '</div></div></div></div>' +

    // ================= HOLIDAY CALENDAR (§7) ===========================================
    '<div v-else-if="tab === \'holidays\'">' +
    '<div class="flex items-center justify-between mb-4">' +
    '<p class="text-[13px] text-sub max-w-xl">Days the clock does not run at all. A holiday applies to every ' +
    'business-hours calendar in this Space.</p>' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 ml-4" ' +
    '@click="openHoliday(null)">Add Holiday</button></div>' +

    '<pb-empty v-if="!holidays.length" title="No holidays yet" ' +
    'subtitle="Add the days you are closed, and SLA time will not count against them.">' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" ' +
    '@click="openHoliday(null)">Add Holiday</button></pb-empty>' +

    '<div v-else class="space-y-2">' +
    '<div v-for="h in holidays" :key="h.id" class="flex items-center gap-3 px-4 py-3 border border-line rounded-lg bg-white">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[14px] font-medium text-head truncate">{{ h.name }}</div>' +
    '<div class="text-[12px] text-sub mt-0.5">{{ h.date_label }}</div>' +
    '</div>' +
    '<div v-if="canManage" class="flex items-center gap-1 shrink-0">' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-sub hover:bg-hover" @click="openHoliday(h)">Edit</button>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-danger hover:bg-hover" @click="askDelete(\'holiday\', h)">Delete</button>' +
    '</div></div></div></div>' +

    // ================= ESCALATION RULES (§24, §25) =====================================
    '<div v-else>' +
    '<div class="flex items-center justify-between mb-4">' +
    '<p class="text-[13px] text-sub max-w-xl">What happens as a clock runs down — who to tell, and what to change.</p>' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 ml-4" ' +
    '@click="openEscalation(null)">Add Escalation Rule</button></div>' +

    '<pb-empty v-if="!escalations.length" title="No escalation rules yet" ' +
    'subtitle="A rule can notify somebody, retag, reassign or re-prioritise a ticket before its SLA runs out.">' +
    '<button v-if="canManage" class="h-9 px-3 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90" ' +
    '@click="openEscalation(null)">Add Escalation Rule</button></pb-empty>' +

    '<div v-else class="space-y-2">' +
    '<div v-for="e in escalations" :key="e.id" class="flex items-start gap-3 px-4 py-3 border border-line rounded-lg bg-white">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-semibold text-head truncate">{{ e.name }}</span>' +
    '<span v-if="!e.is_active" class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">Inactive</span>' +
    '</div>' +
    '<div class="text-[12px] text-sub mt-0.5">When {{ e.trigger_label }}</div>' +
    '<div class="text-[12px] text-sub mt-1 flex flex-wrap gap-1.5">' +
    '<span v-for="(a, ai) in (e.actions || [])" :key="ai" class="bg-hover rounded px-1.5 py-0.5">' +
    '{{ actionLabel(a.action) }}' +
    '<span v-if="a.value" class="text-ink"> &rarr; {{ a.value }}</span></span>' +
    '</div></div>' +
    '<div v-if="canManage" class="flex items-center gap-1 shrink-0">' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-sub hover:bg-hover" @click="openEscalation(e)">Edit</button>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-danger hover:bg-hover" @click="askDelete(\'escalation\', e)">Delete</button>' +
    '</div></div></div></div>' +

    // ================= policy drawer ===================================================
    '<pb-modal :open="policyForm.open" :title="policyForm.editing ? \'Edit SLA Policy\' : \'Add SLA Policy\'" ' +
    'width="max-w-[860px]" @close="policyForm.open = false">' +

    '<div v-if="policyForm.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ policyForm.error }}</div>' +

    '<div class="grid gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">SLA Name</label>' +
    '<input v-model="policyForm.name" type="text" maxlength="120" placeholder="Enterprise Support SLA" ' +
    'class="w-full h-9 px-3 rounded-md border border-stroke text-[13px]"/></div>' +

    '<div><label class="block text-[12px] font-medium text-ink mb-1">Description</label>' +
    '<textarea v-model="policyForm.description" rows="2" placeholder="SLA policy for Enterprise Support customers." ' +
    'class="w-full px-3 py-2 rounded-md border border-stroke text-[13px]"></textarea></div>' +

    '<div class="grid grid-cols-2 gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Business Hours</label>' +
    '<pb-combo v-model="policyForm.business_hours_id" :options="hoursOptions"/></div>' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Due Soon at</label>' +
    '<div class="flex items-center gap-2">' +
    '<input v-model.number="policyForm.warning_percent" type="number" min="1" max="99" ' +
    'class="w-24 h-9 px-3 rounded-md border border-stroke text-[13px]"/>' +
    '<span class="text-[12px] text-sub">% of the target</span></div></div>' +
    '</div>' +

    '<div><label class="block text-[12px] font-medium text-ink mb-1">When a resolved ticket reopens</label>' +
    '<pb-combo v-model="policyForm.reopen_behavior" :options="reopenOptions"/></div>' +

    '<div class="flex items-center gap-6">' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><pb-toggle v-model="policyForm.is_active"/> Active</label>' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><pb-toggle v-model="policyForm.is_default"/> Default SLA</label>' +
    '</div>' +

    // ---- targets (§8) ----
    '<div>' +
    '<div class="text-[13px] font-semibold text-head mt-2 mb-1">SLA Targets</div>' +
    '<p class="text-[12px] text-sub mb-2">Leave a cell empty to promise nothing at that stage.</p>' +
    '<div class="overflow-x-auto"><table class="w-full text-[12px]">' +
    '<thead><tr class="text-faint text-left">' +
    '<th class="py-1 pr-2 font-medium">Priority</th>' +
    '<th v-for="k in timerKinds" :key="k.value" class="py-1 px-2 font-medium">{{ k.label }}</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="t in policyForm.targets" :key="t.priority" class="border-t border-line">' +
    '<td class="py-2 pr-2 text-ink whitespace-nowrap">{{ t.priority_label }}</td>' +
    '<td v-for="k in timerKinds" :key="k.value" class="py-2 px-2">' +
    '<div class="flex items-center gap-1">' +
    '<input v-model="t[k.value + \'_value\']" type="number" min="1" placeholder="—" ' +
    'class="w-16 h-8 px-2 rounded-md border border-stroke text-[12px]"/>' +
    '<select v-model="t[k.value + \'_unit\']" class="h-8 px-1 rounded-md border border-stroke text-[12px] bg-white">' +
    '<option v-for="u in units" :key="u.value" :value="u.value">{{ u.label }}</option>' +
    '</select></div></td></tr>' +
    '</tbody></table></div></div>' +

    // ---- conditions (§12) ----
    '<div>' +
    '<div class="flex items-center justify-between mt-2 mb-1">' +
    '<div class="text-[13px] font-semibold text-head">SLA Applies When</div>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-brand hover:bg-hover" @click="addCondition">+ Add condition</button>' +
    '</div>' +
    '<p v-if="!policyForm.conditions.length" class="text-[12px] text-sub">No conditions. Only the default SLA applies to tickets that match nothing.</p>' +
    '<div v-else class="grid gap-2">' +
    '<div class="flex items-center gap-2 text-[12px] text-sub">Match' +
    '<select v-model="policyForm.match_type" class="h-8 px-2 rounded-md border border-stroke text-[12px] bg-white">' +
    '<option value="all">all conditions</option><option value="any">any condition</option></select></div>' +
    '<div v-for="(c, ci) in policyForm.conditions" :key="ci" class="flex items-start gap-2">' +
    '<select v-model="c.field" class="h-9 px-2 rounded-md border border-stroke text-[12px] bg-white w-40 shrink-0">' +
    '<option v-for="f in conditionFields" :key="f.value" :value="f.value">{{ f.label }}</option></select>' +
    '<select v-if="conditionNeedsFieldId(c)" v-model="c.field_id" class="h-9 px-2 rounded-md border border-stroke text-[12px] bg-white w-40 shrink-0">' +
    '<option value="">Choose a field…</option>' +
    '<option v-for="o in conditionValueOptions(c)" :key="o.value" :value="o.value">{{ o.label }}</option></select>' +
    '<select v-model="c.operator" class="h-9 px-2 rounded-md border border-stroke text-[12px] bg-white w-32 shrink-0">' +
    '<option v-for="o in conditionOperators" :key="o.value" :value="o.value">{{ o.label }}</option></select>' +
    '<input v-if="operatorTakesValue(c.operator) && (conditionIsText(c) || conditionNeedsFieldId(c))" v-model="c.value" type="text" ' +
    'placeholder="Value" class="flex-1 h-9 px-3 rounded-md border border-stroke text-[12px]"/>' +
    '<select v-else-if="operatorTakesValue(c.operator)" v-model="c.value" ' +
    'class="flex-1 h-9 px-2 rounded-md border border-stroke text-[12px] bg-white">' +
    '<option value="">Choose…</option>' +
    '<option v-for="o in conditionValueOptions(c)" :key="o.value" :value="o.value">{{ o.label }}</option></select>' +
    '<button class="h-9 px-2 rounded-md text-[12px] text-danger hover:bg-hover shrink-0" @click="removeCondition(ci)">Remove</button>' +
    '</div></div></div>' +

    '</div>' +

    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="policyForm.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-60" ' +
    ':disabled="policyForm.saving || !policyForm.name" @click="savePolicy">{{ policyForm.saving ? \'Saving…\' : \'Save Policy\' }}</button>' +
    '</template></pb-modal>' +

    // ================= business hours modal ============================================
    '<pb-modal :open="hoursForm.open" :title="hoursForm.editing ? \'Edit Business Hours\' : \'Add Business Hours\'" ' +
    'width="max-w-[620px]" @close="hoursForm.open = false">' +
    '<div v-if="hoursForm.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ hoursForm.error }}</div>' +
    '<div class="grid gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Name</label>' +
    '<input v-model="hoursForm.name" type="text" maxlength="80" placeholder="US Support Hours" ' +
    'class="w-full h-9 px-3 rounded-md border border-stroke text-[13px]"/></div>' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Time Zone</label>' +
    '<pb-combo v-model="hoursForm.timezone" :options="timezones"/></div>' +
    '<div>' +
    '<div class="text-[12px] font-medium text-ink mb-1">Working week</div>' +
    '<div v-for="d in hoursForm.days" :key="d.key" class="flex items-center gap-3 py-1.5 border-t border-line first:border-t-0">' +
    '<span class="w-24 text-[13px] text-ink shrink-0">{{ d.label }}</span>' +
    '<pb-toggle :model-value="!d.closed" @update:model-value="d.closed = !d.closed"/>' +
    '<template v-if="!d.closed">' +
    '<input v-model="d.open" type="time" class="h-8 px-2 rounded-md border border-stroke text-[12px]"/>' +
    '<span class="text-faint text-[12px]">to</span>' +
    '<input v-model="d.close" type="time" class="h-8 px-2 rounded-md border border-stroke text-[12px]"/>' +
    '</template>' +
    '<span v-else class="text-[12px] text-sub">Closed</span>' +
    '</div></div>' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><pb-toggle v-model="hoursForm.is_default"/> Use as the default calendar</label>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="hoursForm.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-60" ' +
    ':disabled="hoursForm.saving || !hoursForm.name" @click="saveHours">{{ hoursForm.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ================= holiday modal ===================================================
    '<pb-modal :open="holidayForm.open" :title="holidayForm.editing ? \'Edit Holiday\' : \'Add Holiday\'" @close="holidayForm.open = false">' +
    '<div v-if="holidayForm.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ holidayForm.error }}</div>' +
    '<div class="grid gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Holiday Name</label>' +
    '<input v-model="holidayForm.name" type="text" maxlength="120" placeholder="Christmas Day" ' +
    'class="w-full h-9 px-3 rounded-md border border-stroke text-[13px]"/></div>' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Date</label>' +
    '<input v-model="holidayForm.date" type="date" class="w-full h-9 px-3 rounded-md border border-stroke text-[13px]"/></div>' +
    '<label class="flex items-center gap-2 text-[13px] text-ink"><pb-toggle v-model="holidayForm.repeats_annually"/> Repeats annually</label>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="holidayForm.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-60" ' +
    ':disabled="holidayForm.saving || !holidayForm.name || !holidayForm.date" @click="saveHoliday">{{ holidayForm.saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</template></pb-modal>' +

    // ================= escalation modal ================================================
    '<pb-modal :open="escalationForm.open" :title="escalationForm.editing ? \'Edit Escalation Rule\' : \'Add Escalation Rule\'" ' +
    'width="max-w-[620px]" @close="escalationForm.open = false">' +
    '<div v-if="escalationForm.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ escalationForm.error }}</div>' +
    '<div class="grid gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Rule Name</label>' +
    '<input v-model="escalationForm.name" type="text" maxlength="120" placeholder="Notify the team lead at 90%" ' +
    'class="w-full h-9 px-3 rounded-md border border-stroke text-[13px]"/></div>' +
    '<div class="grid grid-cols-2 gap-4">' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Trigger</label>' +
    '<pb-combo v-model="escalationForm.trigger" :options="triggerOptions"/></div>' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">On which target</label>' +
    '<pb-combo v-model="escalationForm.kind" :options="kindOptions"/></div>' +
    '</div>' +
    '<div><label class="block text-[12px] font-medium text-ink mb-1">Applies to</label>' +
    '<pb-combo v-model="escalationForm.policy_id" :options="policyOptions"/></div>' +

    '<div>' +
    '<div class="flex items-center justify-between mb-1">' +
    '<div class="text-[12px] font-medium text-ink">Actions</div>' +
    '<button class="h-8 px-2 rounded-md text-[12px] text-brand hover:bg-hover" @click="addAction">+ Add action</button>' +
    '</div>' +
    '<p v-if="!escalationForm.actions.length" class="text-[12px] text-sub">A rule needs at least one action.</p>' +
    '<div v-else class="grid gap-2">' +
    '<div v-for="(a, ai) in escalationForm.actions" :key="ai" class="flex items-center gap-2">' +
    '<select v-model="a.action" class="h-9 px-2 rounded-md border border-stroke text-[12px] bg-white w-44 shrink-0">' +
    '<option v-for="o in escalationActions" :key="o.value" :value="o.value">{{ o.label }}</option></select>' +
    '<input v-if="actionValueKind(a.action) === \'email\'" v-model="a.value" type="email" placeholder="somebody@example.com" ' +
    'class="flex-1 h-9 px-3 rounded-md border border-stroke text-[12px]"/>' +
    '<select v-else-if="actionValueKind(a.action)" v-model="a.value" ' +
    'class="flex-1 h-9 px-2 rounded-md border border-stroke text-[12px] bg-white">' +
    '<option value="">Choose…</option>' +
    '<option v-for="o in actionOptions(actionValueKind(a.action))" :key="o.value" :value="o.value">{{ o.label }}</option></select>' +
    '<span v-else class="flex-1 text-[12px] text-faint">No value needed</span>' +
    '<button class="h-9 px-2 rounded-md text-[12px] text-danger hover:bg-hover shrink-0" @click="removeAction(ai)">Remove</button>' +
    '</div></div></div>' +

    '<label class="flex items-center gap-2 text-[13px] text-ink"><pb-toggle v-model="escalationForm.is_active"/> Active</label>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="escalationForm.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-60" ' +
    ':disabled="escalationForm.saving || !escalationForm.name || !escalationForm.actions.length" @click="saveEscalation">' +
    '{{ escalationForm.saving ? \'Saving…\' : \'Save Rule\' }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="confirm.open" :title="confirm.title" :message="confirm.message" ' +
    '@close="confirm.open = false" @confirm="doDelete"/>' +

    '</div>',

  /*
   * The mount point, as PB.boot's THIRD argument.
   *
   * The first argument is only a label for the console message. Leaving this off does not throw
   * — boot falls back to `#settings-root`, which this page does not have, logs to the console
   * and returns — so the screen sits on "Loading SLA…" forever with nothing visibly wrong.
   */
}, { root: 'help-center-sla' });
