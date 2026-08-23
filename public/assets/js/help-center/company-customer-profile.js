/* Help Center — a Customer or Company profile (docs/features/help-center.md, P75 §12–§13).
   ------------------------------------------------------------------
   ONE screen for both records. They differ in four field labels and in whether there is a
   Customers list beside the Tickets list; the header, the editable details, the custom fields
   grouped by Space and the ticket table are identical. Two files would be two places to fix the
   next thing either of them gets wrong, so `kind` is the only branch.

   The details are edited IN PLACE rather than in a modal: a profile is a page somebody opens to
   read, and the correction they make while reading is usually one field.

   Custom fields are shown but not edited here. Their values are written by Ticket Metadata
   Mapping, and an editor for them is a second answer to "what does this field hold" that the
   requirement does not ask for — see the note beside `fields` below.
   ------------------------------------------------------------------ */
PB.boot('help-center-cc-profile', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};
    var isCompany = b.kind === 'company';
    var record = (isCompany ? b.company : b.customer) || {};

    return {
      kind: isCompany ? 'company' : 'customer',
      record: record,
      tags: Array.isArray(b.tags) ? b.tags.slice() : [],
      created: b.created || null,
      fields: Array.isArray(b.fields) ? b.fields : [],
      tickets: Array.isArray(b.tickets) ? b.tickets : [],
      customers: Array.isArray(b.customers) ? b.customers : [],
      canManage: !!b.canManage,
      endpoint: b.endpoint || '',
      backUrl: b.backUrl || '',
      companyUrl: b.companyUrl || null,
      customerUrl: b.customerUrl || null,

      editing: false,
      saving: false,
      error: '',
      // A COPY. Cancel has to leave the record as it was loaded, and a form bound straight to
      // it would have rewritten it on the way in.
      form: {}
    };
  },

  computed: {
    isCompany: function () { return this.kind === 'company'; },

    title: function () { return this.record.name || '—'; },

    /* The detail rows, as data. The two records ask for different fields under different
       labels, and a template with eight `v-if`s on `isCompany` is a template nobody can read. */
    details: function () {
      if (this.isCompany) {
        return [
          { key: 'name', label: 'Company Name', value: this.record.name, editable: true },
          { key: 'domain', label: 'Domain', value: this.record.domain, editable: true },
          { key: 'phone', label: 'Phone', value: this.record.phone, editable: true },
          { key: 'external_id', label: 'External Company ID', value: this.record.external_id, editable: true }
        ];
      }

      return [
        { key: 'name', label: 'Name', value: this.record.name, editable: true },
        // The address is the identity every Request was matched on. Changing it would silently
        // re-point a history at somebody else, which is why the server refuses it too.
        { key: 'email', label: 'Email', value: this.record.email, editable: false },
        { key: 'phone', label: 'Phone', value: this.record.phone, editable: true },
        { key: 'company', label: 'Company', value: this.record.company_name || this.record.company, editable: false },
        { key: 'external_id', label: 'External Customer ID', value: this.record.external_id, editable: true }
      ];
    },

    editable: function () {
      return this.details.filter(function (d) { return d.editable; });
    },

    /* Every custom field across every Space that asks one, flattened for the "has any" test.
       The GROUPS are what render, because a field means "this Space's question". */
    hasFields: function () {
      return this.fields.some(function (g) { return g.fields.length > 0; });
    }
  },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    customerLink: function (customer) {
      return String(this.customerUrl || '').replace(/0$/, String(customer.id));
    },

    /* A stored value is TEXT whatever the field's type is (see the values migration). A Multiple
       Select holds a JSON array, so it is unpacked for display rather than printed as `["a"]`. */
    fieldValue: function (field) {
      var raw = field.value;

      if (raw === null || raw === undefined || raw === '') return '—';

      if (field.type === 'multi_select' || field.type === 'checkbox') {
        try {
          var parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) return parsed.join(', ');
        } catch (e) { /* not JSON — it is a plain answer, printed as-is */ }
      }

      return String(raw);
    },

    startEdit: function () {
      var form = {};
      this.editable.forEach(function (d) { form[d.key] = d.value || ''; });

      this.form = form;
      this.error = '';
      this.editing = true;
    },

    save: async function () {
      this.saving = true;
      this.error = '';

      try {
        var res = await this.$pb.api(this.endpoint, { method: 'PATCH', body: this.form });

        // The server sends the record back rather than the client patching what it typed: the
        // panel shows counts and a display name the server derives, and echoing the form would
        // leave those stale until a refresh.
        this.record = (this.isCompany ? res.company : res.customer) || this.record;
        this.editing = false;
        this.$pb.toast(res.message);
      } catch (e) {
        this.error = this.$pb.firstError(e, 'Could not save.');
      } finally {
        this.saving = false;
      }
    }
  },

  template: [
    '<div>',

    /* Toolbar — the module's h-12 bar, with the way back to the list. */
    '  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">',
    '    <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" aria-controls="sidebar" aria-expanded="false" class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" v-html="icon(\'sidebar\', 16)"></button>',
    '    <span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>',
    '    <a :href="backUrl" class="flex items-center gap-2 text-[14px] font-medium text-ink hover:opacity-80">',
    '      <span v-html="icon(\'users\', 16, \'text-sub\')"></span>Company &amp; Customer',
    '    </a>',
    '    <span class="text-faint shrink-0">/</span>',
    '    <span class="text-[13px] text-sub truncate">{{ title }}</span>',
    '  </div>',

    '  <div class="px-5 sm:px-8 py-6 max-w-[1100px]">',

    /* ---- header ---- */
    '    <div class="flex items-start gap-3">',
    '      <span :class="[\'h-11 w-11 shrink-0 grid place-items-center bg-hover text-[15px] font-semibold text-sub\', isCompany ? \'rounded-lg\' : \'rounded-full\']">{{ record.initial }}</span>',
    '      <div class="min-w-0 flex-1">',
    '        <h1 class="text-[18px] font-semibold text-head truncate">{{ title }}</h1>',
    '        <p class="text-[13px] text-sub _moretogether-break">{{ isCompany ? (record.domain || \'No domain recorded\') : record.email }}</p>',
    '        <div v-if="tags.length" class="flex flex-wrap gap-1.5 mt-2">',
    '          <span v-for="t in tags" :key="t" class="inline-flex items-center h-6 px-2 rounded-md bg-hover text-[12px] text-sub">{{ t }}</span>',
    '        </div>',
    '      </div>',
    '      <button v-if="canManage && !editing" type="button" @click="startEdit"',
    '              class="inline-flex items-center h-8 px-3 shrink-0 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">Edit</button>',
    '    </div>',

    /* ---- counts. The three numbers a profile is opened to see. ---- */
    '    <div class="grid gap-3 mt-5 grid-cols-2 sm:grid-cols-4">',
    '      <div class="rounded-xl border border-line px-4 py-3">',
    '        <div class="text-[20px] font-semibold text-head tabular-nums">{{ record.open_tickets }}</div>',
    '        <div class="text-[12px] text-sub mt-0.5">Open Tickets</div></div>',
    '      <div class="rounded-xl border border-line px-4 py-3">',
    '        <div class="text-[20px] font-semibold text-head tabular-nums">{{ record.total_tickets }}</div>',
    '        <div class="text-[12px] text-sub mt-0.5">Total Tickets</div></div>',
    '      <div v-if="isCompany" class="rounded-xl border border-line px-4 py-3">',
    '        <div class="text-[20px] font-semibold text-head tabular-nums">{{ record.customers }}</div>',
    '        <div class="text-[12px] text-sub mt-0.5">Customers</div></div>',
    '      <div class="rounded-xl border border-line px-4 py-3">',
    '        <div class="text-[13px] font-semibold text-head">{{ (isCompany ? record.last_activity : record.last_contact) || \'—\' }}</div>',
    '        <div class="text-[12px] text-sub mt-0.5">Last Activity</div></div>',
    '      <div class="rounded-xl border border-line px-4 py-3">',
    '        <div class="text-[13px] font-semibold text-head">{{ created || (isCompany ? record.first_seen : record.first_contact) || \'—\' }}</div>',
    '        <div class="text-[12px] text-sub mt-0.5">Created</div></div>',
    '    </div>',

    /* ---- details, read or edit ---- */
    '    <div class="rounded-xl border border-line mt-4">',
    '      <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Details</div>',

    '      <dl v-if="!editing" class="px-4 py-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">',
    '        <div v-for="d in details" :key="d.key">',
    '          <dt class="text-[12px] text-faint">{{ d.label }}</dt>',
    // The Company row on a Customer links through to the company, which is the requirement's
    // "Clicking a Company opens the Company profile" reached from the other direction.
    '          <dd v-if="d.key === \'company\' && companyUrl" class="text-[13px] mt-0.5">',
    '            <a :href="companyUrl" class="text-brand hover:underline">{{ d.value || \'—\' }}</a></dd>',
    '          <dd v-else class="text-[13px] text-ink mt-0.5 _moretogether-break">{{ d.value || \'—\' }}</dd>',
    '        </div>',
    '      </dl>',

    '      <div v-else class="px-4 py-3 space-y-3">',
    '        <div v-for="d in editable" :key="d.key">',
    '          <label class="block text-[12px] font-medium text-ink mb-1" :for="\'cc-\' + d.key">{{ d.label }}</label>',
    '          <input :id="\'cc-\' + d.key" v-model="form[d.key]" class="pb-input !h-9 w-full" />',
    '        </div>',
    '        <p v-if="error" class="text-[12px] text-danger">{{ error }}</p>',
    '        <div class="flex items-center gap-2 pt-1">',
    '          <button type="button" @click="save" :disabled="saving"',
    '                  class="inline-flex items-center h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">',
    '            {{ saving ? \'Saving…\' : \'Save Changes\' }}</button>',
    '          <button type="button" @click="editing = false"',
    '                  class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* ---- custom fields, grouped by the Space that asks them ----
       Read-only. Their values come from Ticket Metadata Mapping, and an editor here would be a
       second writer for the same column with no rule about which one wins. */
    '    <div v-if="hasFields" class="rounded-xl border border-line mt-4">',
    '      <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Custom Fields</div>',
    '      <div v-for="g in fields" :key="g.space_id" class="px-4 py-3 border-b border-line last:border-0">',
    '        <div class="text-[12px] font-semibold text-faint uppercase tracking-wide">{{ g.space }}</div>',
    '        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 mt-2">',
    '          <div v-for="f in g.fields" :key="f.id">',
    '            <dt class="text-[12px] text-faint">{{ f.name }}</dt>',
    '            <dd class="text-[13px] text-ink mt-0.5 _moretogether-break">{{ fieldValue(f) }}</dd>',
    '          </div>',
    '        </dl>',
    '      </div>',
    '    </div>',

    /* ---- the company's customers (§13) ---- */
    '    <div v-if="isCompany" class="rounded-xl border border-line mt-4">',
    '      <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Customers</div>',
    '      <div v-if="customers.length" class="px-4 py-2">',
    '        <a v-for="c in customers" :key="c.id" :href="customerLink(c)"',
    '           class="flex items-center gap-2 py-2 border-b border-line last:border-0 hover:bg-hover -mx-2 px-2 rounded">',
    '          <span class="h-6 w-6 shrink-0 rounded-full bg-hover grid place-items-center text-[11px] font-semibold text-sub">{{ c.initial }}</span>',
    '          <span class="text-[13px] text-ink font-medium truncate">{{ c.name }}</span>',
    '          <span class="text-[12px] text-sub truncate ml-auto _moretogether-break">{{ c.email }}</span>',
    '        </a>',
    '      </div>',
    '      <p v-else class="px-4 py-4 text-[12px] text-faint">Nobody has been linked to this company yet.</p>',
    '    </div>',

    /* ---- tickets (§12, §13) ---- */
    '    <div class="rounded-xl border border-line mt-4">',
    '      <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Tickets</div>',
    '      <div v-if="tickets.length" class="overflow-x-auto">',
    '        <table class="w-full text-[13px] min-w-[640px]">',
    '          <thead><tr class="text-left text-[12px] text-faint border-b border-line">',
    '            <th class="py-2 pl-4 font-medium">Ticket</th>',
    '            <th class="py-2 font-medium">Subject</th>',
    '            <th class="py-2 font-medium">Space</th>',
    '            <th class="py-2 font-medium">Status</th>',
    '            <th class="py-2 pr-4 font-medium">Last Message</th>',
    '          </tr></thead>',
    '          <tbody>',
    '            <tr v-for="t in tickets" :key="t.id" class="border-b border-line last:border-0 hover:bg-hover">',
    '              <td class="py-2 pl-4"><a :href="t.url" class="text-brand hover:underline tabular-nums">{{ t.number }}</a></td>',
    '              <td class="py-2 text-ink"><a :href="t.url" class="hover:underline">{{ t.subject }}</a></td>',
    '              <td class="py-2 text-sub">{{ t.space || \'—\' }}</td>',
    '              <td class="py-2">',
    '                <span v-if="t.status" class="inline-flex items-center gap-1.5 text-[12px] text-ink">',
    '                  <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: t.status_color || \'#6b7280\' }"></span>{{ t.status }}',
    '                </span>',
    '                <span v-else class="text-faint">—</span>',
    '              </td>',
    '              <td class="py-2 pr-4 text-sub">{{ t.last_message || \'—\' }}</td>',
    '            </tr>',
    '          </tbody>',
    '        </table>',
    '      </div>',
    '      <p v-else class="px-4 py-4 text-[12px] text-faint">No tickets yet.</p>',
    '    </div>',

    '  </div>',
    '</div>'
  ].join('\n')
}, { root: 'help-center-cc-profile' });
