/* Help Center — one workflow status, as an editable card
   (docs/features/help-center.md, P2 §11–§16, P16).
   ------------------------------------------------------------------
   Used in two places: the setup wizard's step 4, and Settings → Workflow's editor. It lives in
   its own file rather than inside the wizard because those two screens edit the SAME rows
   through the same rules — a copy in each would be two cards drifting apart while the server
   went on treating their output as one thing.

   The `status` object is mutated IN PLACE. That is deliberate: it is the same object the parent
   holds in its list, so the fields somebody edits are already where the payload reads them.
   Ordering and deletion are emitted instead, because those are facts about the LIST, which a
   card does not own.

   `advanced` is what differs between the two callers, and it decides two things.

   WHICH FIELDS. The wizard asks for a name, a colour, a responsibility and assignees — a first
   run deliberately asks less. The editor also sets "Waiting on", because by then the Space is
   running and that is what people come back to change.

   NEITHER sets which status a Request opens in. The value round-trips untouched — the editor
   sends back the `is_default` it loaded — so a Space keeps the starting status the wizard gave
   it, and there is no screen that changes it. The read-only Workflow page still labels it.

   HOW THEY SIT. The editor lays its five fields out in ONE ROW — Status Name, Waiting On,
   Responsibility, Default Assignees, Active — because it has 1000px and because a workflow is
   read by scanning DOWN a column: with the fields stacked, "which statuses wait on the customer?"
   means reading every card instead of one column. The wizard's step 4 is a narrower page and
   keeps the stacked two-column card, where five columns would be five cramped ones.

   Two layouts in one file, not two files: the fields, their bindings and the rules about which
   rows may be renamed or moved are written once, and only the arrangement forks.

   Registered as a global so both apps can name it in `components:` without either owning it.
   Loaded BEFORE the app that uses it — see the layout's script order.
   ------------------------------------------------------------------ */
window.HC_STATUS_CARD = {
  props: {
    status: { type: Object, required: true },
    colors: { type: Array, default: function () { return []; } },
    responsibilities: { type: Array, default: function () { return []; } },
    assignees: { type: Array, default: function () { return []; } },
    // The wizard's four fields, or the editor's six.
    advanced: { type: Boolean, default: false },
    waitingOptions: { type: Array, default: function () { return []; } },
    // The five fixed System Categories (P54). Passed in rather than read from a global, like
    // every other option list this card takes.
    categoryOptions: { type: Array, default: function () { return []; } },
    nameMax: { type: Number, default: 60 }
  },

  emits: ['move', 'remove'],

  computed: {
    /** The System Category's label, for the locked rows that show it as text (P54). */
    categoryLabel: function () {
      var key = this.status.system_category;
      var hit = (this.categoryOptions || []).filter(function (c) { return c.value === key; })[0];

      return hit ? hit.label : (key || '—');
    },

    system: function () { return !!this.status.system_key; },
    lockLabel: function () {
      return this.status.system_key === 'open' ? 'Always Active' : 'Always Inactive';
    },
    swatch: function () {
      var v = String(this.status.color || '').trim();

      return /^#[0-9A-Fa-f]{6}$/.test(v) ? v : '#000000';
    },
    /* Row two's placeholders. A list rather than two spans, so adding a third is a word here
       and not another copy of the badge markup. Nothing behind either of them yet — see the
       Not built note in docs/features/help-center.md, P16. */
    soon: function () { return ['Custom Rules', 'Email Configuration']; }
  },

  methods: {
    icon: function (name, size) { return window.wiIcon ? window.wiIcon(name, size || 16) : ''; },
    // The native input needs a valid #RRGGBB even while a stored value is malformed.
    onColor: function (e) { this.status.color = String(e.target.value || '').toUpperCase(); },
    // Typed hex. `#` is added rather than demanded: nobody types the hash on purpose twice.
    onHex: function (e) {
      var v = String(e.target.value || '').trim();
      if (v && v[0] !== '#') v = '#' + v;
      this.status.color = v.toUpperCase();
    },
    // pb-combo works in strings; the payload wants integers.
    assigneeValues: function () { return (this.status.default_assignees || []).map(String); },
    setAssignees: function (values) {
      this.status.default_assignees = (values || []).map(function (v) { return parseInt(v, 10); });
    }
  },

  template: [
    '<div :class="[\'rounded-lg border px-4 py-3\', system ? \'border-line bg-[#f9fafb]\' : \'border-line bg-white\']">',

    /* ---- the editor: two rows (P17) ----
       Row one is the status as a RECORD — the five fields somebody compares down the list.
       Row two is what you DO to that record: its colour, its position, the rules it will grow,
       and, on the right where destructive actions belong, delete. Splitting them means row one
       stays a table you can read down while row two can hold controls of different shapes
       without pushing the columns out of line. */
    '  <div v-if="advanced">',

    /* ---- row one: the record ----
       Every control here is h-9 — `:dense` on the combos, `!h-9` on the name. `pb-input`'s own
       height is h-11, which is right for a stacked form and wrong in a row: a 44px combo beside
       a 36px toggle is the only thing the eye picks up when it should be reading the values. */
    '    <div class="flex items-end gap-3">',
    '      <div class="flex-1 min-w-[180px]">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Status</label>',
    '        <input v-if="!system" v-model="status.name" :maxlength="nameMax" class="pb-input !h-9 w-full" placeholder="Waiting on Customer" />',
    '        <div v-else class="h-9 flex items-center gap-2 text-[13px] font-semibold text-ink">{{ status.name }}<span v-html="icon(\'lock\', 11)" class="text-faint"></span></div>',
    '      </div>',

    '      <div class="w-[150px] shrink-0">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Waiting</label>',
    '        <pb-combo v-model="status.waiting_on" :options="waitingOptions" :searchable="false" :dense="true" />',
    '      </div>',

    /* ---- System Category (P54) ----
       Beside Waiting, because the two answer neighbouring questions — "what does this state
       MEAN" and "whose clock is running in it" — and reading them together is how somebody
       checks that a status they invented is filed sensibly.

       Locked on Open and Closed: those two are the workflow's own start and end, and filing
       Closed under "Waiting" would break every consumer that trusts the vocabulary. The server
       pins them regardless (WorkflowStatusPayload::normalize); this is the same rule made
       visible rather than enforced twice by accident. */
    '      <div class="w-[150px] shrink-0">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">System Category</label>',
    '        <pb-combo v-if="!system" v-model="status.system_category" :options="categoryOptions" :searchable="false" :dense="true" />',
    '        <div v-else class="h-9 flex items-center gap-1.5 text-[13px] text-sub">',
    '          {{ categoryLabel }}<span v-html="icon(\'lock\', 11)" class="text-faint"></span></div>',
    '      </div>',

    '      <div class="w-[150px] shrink-0">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Responsibility</label>',
    '        <pb-combo v-model="status.responsibility" :options="responsibilities" :searchable="false" :dense="true" />',
    '      </div>',

    '      <div class="w-[190px] shrink-0">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Default Assigned</label>',
    '        <pb-combo :model-value="assigneeValues()" @update:model-value="setAssignees($event)" :options="assignees" :multiple="true" placeholder="Anyone" :dense="true" />',
    '      </div>',

    /* Open is always Active and Closed always Inactive (P2 §16), so neither gets a toggle —
       the cell still exists so the columns line up down the list. */
    '      <div class="w-[86px] shrink-0">',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Status</label>',
    '        <div class="h-9 flex items-center">',
    '          <pb-toggle v-if="!system" :model-value="status.is_active" @update:model-value="status.is_active = $event" />',
    '          <span v-else class="text-[11px] text-faint leading-tight">{{ lockLabel }}</span>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* ---- row two: what you do to it ---- */
    '    <div class="mt-3 pt-3 border-t border-line flex items-center gap-3">',
    '      <div class="flex items-center gap-3 flex-wrap min-w-0">',

    /* Only custom statuses can be reordered (P2 §16). The buttons are rendered disabled on the
       system rows rather than dropped, so row two keeps the same shape on every card. */
    '        <div class="flex items-center gap-1">',
    '          <button type="button" @click="$emit(\'move\', -1)" :disabled="system" aria-label="Move up" ',
    '                  class="_moretogether-iconbtn disabled:opacity-40 disabled:cursor-not-allowed" v-html="icon(\'arrow-up\', 12)"></button>',
    '          <button type="button" @click="$emit(\'move\', 1)" :disabled="system" aria-label="Move down" ',
    '                  class="_moretogether-iconbtn disabled:opacity-40 disabled:cursor-not-allowed" v-html="icon(\'arrow-down\', 12)"></button>',
    '        </div>',

    /* Which status a new Request opens in. Not in the requested list, and kept because it is
       the one control here that changes what the Space DOES — dropping it would mean the
       editor could no longer set something the read-only screen displays. */
    /* The hex, not the palette. The presets need eight swatches and this row has four other
       things in it; the swatch opens the native picker, which is the same value either way. */
    '        <div class="pb-group !h-9" style="width:9.5rem">',
    '          <span class="pb-group__prefix pl-1.5 pr-1 flex items-center">',
    '            <input type="color" class="pb-color-input" :value="swatch" @input="onColor" :title="\'Colour for \' + (status.name || \'this status\')" />',
    '          </span>',
    '          <input class="pb-group__field uppercase" :value="status.color" @input="onHex" maxlength="7" spellcheck="false" />',
    '        </div>',

    /* Named, badged, and not buttons. A Coming Soon control that can be pressed is a promise
       the screen then has to break — the same rule the settings nav follows for AI Tag. */
    '        <span v-for="s in soon" :key="s" ',
    '              class="inline-flex items-center gap-1.5 h-7 px-2 rounded-md text-[11px] text-faint border border-dashed border-stroke whitespace-nowrap cursor-not-allowed select-none" ',
    '              :title="s + \' is coming soon\'">',
    '          {{ s }}',
    '          <span class="text-[10px] font-semibold uppercase tracking-wide bg-hover text-sub rounded px-1 py-0.5">Soon</span>',
    '        </span>',
    '      </div>',

    /* Right, and only on the rows that can be deleted. Open and Closed are protected (P2 §16). */
    '      <div class="ml-auto shrink-0">',
    '        <button v-if="!system" type="button" @click="$emit(\'remove\')" aria-label="Delete status" ',
    '                class="_moretogether-iconbtn _moretogether-iconbtn--danger" v-html="icon(\'trash-can\', 13)"></button>',
    '      </div>',
    '    </div>',
    '  </div>',

    /* ---- the wizard: the stacked card ---- */
    '  <div v-else class="flex items-start gap-3">',
    '    <div class="pt-2 text-faint" :class="system ? \'opacity-40\' : \'cursor-move\'" v-html="icon(system ? \'lock\' : \'grip-vertical\', 14)"></div>',
    '    <div class="flex-1 min-w-0 grid gap-3 sm:grid-cols-2">',
    '      <div>',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Status Name</label>',
    '        <input v-if="!system" v-model="status.name" :maxlength="nameMax" class="pb-input w-full" placeholder="Waiting on Customer" />',
    '        <div v-else class="h-9 flex items-center gap-2 text-[13px] font-semibold text-ink">{{ status.name }}<span v-html="icon(\'lock\', 11)" class="text-faint"></span></div>',
    '      </div>',
    '      <div>',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Responsibility</label>',
    '        <pb-combo v-model="status.responsibility" :options="responsibilities" :searchable="false" />',
    '      </div>',
    '      <div>',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Colour</label>',
    '        <pb-color-picker v-model="status.color" :presets="colors" />',
    '      </div>',
    '      <div>',
    '        <label class="block text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Default Assignees</label>',
    '        <pb-combo :model-value="assigneeValues()" @update:model-value="setAssignees($event)" :options="assignees" :multiple="true" placeholder="Anyone" />',
    '      </div>',
    '    </div>',
    '    <div class="w-[136px] shrink-0 text-right">',
    '      <div class="text-[11px] font-semibold text-faint uppercase tracking-wide mb-1">Preview</div>',
    '      <span class="inline-flex items-center h-6 px-2 rounded-full text-[11px] font-semibold text-white" :style="{ background: status.color }">{{ status.name || \'Untitled\' }}</span>',
    '      <div class="mt-2 flex items-center justify-end">',
    '        <pb-toggle v-if="!system" :model-value="status.is_active" @update:model-value="status.is_active = $event" />',
    '        <span v-else class="text-[11px] text-faint">{{ lockLabel }}</span>',
    '      </div>',
    '      <div v-if="!system" class="mt-2 flex items-center justify-end gap-1">',
    '        <button type="button" @click="$emit(\'move\', -1)" aria-label="Move up" class="_moretogether-iconbtn" v-html="icon(\'arrow-up\', 12)"></button>',
    '        <button type="button" @click="$emit(\'move\', 1)" aria-label="Move down" class="_moretogether-iconbtn" v-html="icon(\'arrow-down\', 12)"></button>',
    '        <button type="button" @click="$emit(\'remove\')" aria-label="Delete status" class="_moretogether-iconbtn _moretogether-iconbtn--danger" v-html="icon(\'trash-can\', 12)"></button>',
    '      </div>',
    '    </div>',
    '  </div>',

    '</div>'
  ].join('\n')
};
