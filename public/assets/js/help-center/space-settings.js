/* Help Center › Space › Settings (docs/features/help-center.md, P11).
   ------------------------------------------------------------------
   ONE app for all ten functional sections, not ten files. Which panel renders is decided by
   `kind` in the bootstrap, and six of the eleven nav items share the `metadata` kind — they
   are the same screen (a title, a sentence, one switch) pointed at a different toggle. Writing
   them out separately would be six copies of the same save-and-toast logic waiting to drift.

   Built to the shape of public/assets/js/projects/features.js: the same `pb-section-head`, the
   same `pb-toggle`, the same 820px content column, the same optimistic-write-then-revert on
   failure. Configuring a Space and configuring a project should not feel like two applications.

   Every control here is mirrored by a server-side check in SpaceSettingsController and
   UpdateSpaceSettingRequest. Hiding or disabling a control in the UI is not enforcing it.
   ------------------------------------------------------------------ */

PB.boot('help-center-space-settings', {
  props: { bootstrap: Object },

  /*
   * The same card the setup wizard's step 4 uses (P16). Shared rather than copied, because both
   * screens edit the same rows through the same server-side rules — two cards would drift apart
   * while the server went on treating their output as one thing.
   */
  /*
   * `pg-editor` is the project's rich-text editor (P41), for the Email Template panel (P48).
   *
   * Reached directly rather than as a global, and guarded: a checkout without the licensed Jodit
   * package has no `PgEditor`, and a component registered as `undefined` is a Vue warning on
   * every page in Settings rather than only on the one that uses it. See `useEditor`.
   */
  components: {
    'hc-status-card': window.HC_STATUS_CARD,
    'pg-editor': typeof PgEditor !== 'undefined' ? PgEditor : null,
  },
  data: function () {
    var b = this.bootstrap || {};

    return {
      kind: b.kind || '',
      title: b.title || 'Settings',
      description: b.description || '',
      toggleLabel: b.toggleLabel || '',
      note: b.note || '',
      canManage: !!b.canManage,
      endpoint: b.endpoint || '',

      // --- metadata / auto_follow: one switch -------------------------------------------
      enabled: !!b.enabled,
      available: b.available !== false,

      // --- tags (the Tag section manages a list as well as its switch) --------------------
      manages: b.manages || '',
      tags: Array.isArray(b.tags) ? b.tags : [],
      tagForm: { open: false, name: '', error: '' },
      tagConfirm: { open: false, tag: null },
      tagSaving: false,

      // --- Company & Customer (P18 for the fields, P75 for everything around them) ----------
      // What a workspace wants to record about a customer or their company is its own question,
      // so the FIELDS are data here and only their types are code.
      //
      // TWO lists now, not one. They are the same eight types over two record kinds, so the
      // modal, the validation and the save are shared and `fieldForm.kind` is the only thing
      // that says which list a field belongs to.
      companyFields: Array.isArray(b.companyFields) ? b.companyFields : [],
      customerFields: Array.isArray(b.customerFields) ? b.customerFields : [],
      fieldTypes: Array.isArray(b.fieldTypes) ? b.fieldTypes : [],
      optionMax: b.optionMax || 50,
      optionMaxLength: b.optionMaxLength || 100,
      // One modal for create and edit: a field is the same thing either way, and `editing`
      // decides only the title, the button and where the save goes.
      fieldForm: {
        open: false, kind: 'customer', editing: null, saving: false, error: '',
        name: '', type: 'input', is_required: false, is_active: true, options: [], optionErrors: {}
      },
      fieldConfirm: { open: false, field: null, kind: 'customer' },

      // The five feature switches (P75 §2). A LIST from the server rather than five named
      // booleans: the page draws them in the order it is given, and adding a sixth is a config
      // entry rather than an edit here.
      features: Array.isArray(b.features) ? b.features.map(function (f) { return Object.assign({}, f); }) : [],
      featureSaving: '',

      // --- Ticket Metadata Mapping (P75 §3-§4) ----------------------------------------------
      mappings: Array.isArray(b.mappings) ? b.mappings : [],
      mappingSources: Array.isArray(b.mappingSources) ? b.mappingSources : [],
      mappingRecordTypes: Array.isArray(b.mappingRecordTypes) ? b.mappingRecordTypes : [],
      mappingMax: b.mappingMax || 60,
      // One modal for add and edit, for the same reason the field modal is one.
      mapForm: {
        open: false, editing: null, saving: false, error: '',
        source: '', source_key: '', record_type: 'customer', destination: '', custom_field_id: null
      },
      mapConfirm: { open: false, mapping: null },
      // Reprocess is the one destructive-ish action on this page, so it is behind a typed
      // confirmation rather than a plain OK (§14).
      reprocess: { open: false, running: false },
      seeding: false,

      // --- auto_bcc ---------------------------------------------------------------------
      // A LIST now (P13): a compliance archive and a shared mailbox are two different reasons
      // to want a blind copy, and one field made a Space choose between them.
      bccEnabled: !!b.enabled,
      bccEmails: Array.isArray(b.emails) ? b.emails.slice() : [],
      bccMax: b.max || 10,
      bccForm: { open: false, email: '', error: '' },
      bccConfirm: { open: false, email: '' },

      // --- reassignment -----------------------------------------------------------------
      reEnabled: !!b.enabled,
      reHours: (b.after && b.after.hours) || 0,
      reMinutes: (b.after && b.after.minutes) || 0,
      reDestination: b.destination || 'unassigned',
      destinations: Array.isArray(b.destinations) ? b.destinations : [],

      // --- workflow -----------------------------------------------------------------------
      // The page READS the workflow; Edit opens the editor over it. Two states of one panel
      // rather than two screens, so leaving the editor puts you back exactly where you were.
      statuses: Array.isArray(b.statuses) ? b.statuses : [],
      responsibilities: Array.isArray(b.responsibilities) ? b.responsibilities : [],
      waitingOptions: Array.isArray(b.waitingOptions) ? b.waitingOptions : [],
      // The five fixed System Categories, for the per-card picker (P54).
      categoryOptions: Array.isArray(b.categoryOptions) ? b.categoryOptions : [],
      statusColors: Array.isArray(b.colors) ? b.colors : [],
      statusMax: b.statusMax || 20,
      nameMax: b.nameMax || 60,
      assigneeOptions: Array.isArray(b.assigneeOptions) ? b.assigneeOptions : [],
      // The editor works on a COPY. Cancel has to leave the page showing what is stored, and
      // an editor bound straight to `statuses` would have rewritten it on the way in.
      editor: { open: false, rows: [], error: '', uid: 0 },
      editorConfirm: { open: false, row: null },

      // --- channels (read-only in this phase) ---------------------------------------------
      channels: Array.isArray(b.channels) ? b.channels : [],

      // --- inbox ------------------------------------------------------------------------
      // Everything the Inboxes screen does, done here: the inbound address is copied, and the
      // customer-facing addresses are added and removed without a page load. It used to be a
      // read-only list with a link out, which meant leaving the Space to finish configuring it.
      inboxes: Array.isArray(b.inboxes) ? b.inboxes : [],

      /* The Space's Inbound Email Display Name (P65).
         `senderDisplayName` is the RAW value — empty when nobody has set one — so the input
         renders empty and the placeholder can show the default instead of pretending somebody
         typed it. `senderSaved` is what the server currently holds, which is how the Save
         button knows whether there is anything to save. */
      senderDisplayName: b.displayName || '',
      senderSaved: b.displayName || '',
      senderInbound: b.inboundAddress || '',
      savingSender: false,
      endpointTemplates: b.endpointTemplates || {},

      // --- CSAT (P56) --------------------------------------------------------------------
      /*
       * A working COPY of the settings, not the payload.
       *
       * Twenty fields with a Save button: the draft is what the form binds to and what the live
       * preview renders, so the preview shows what is about to be saved rather than what was
       * saved last time.
       */
      rating: b.settings ? Object.assign({}, b.settings) : null,
      ratingTypes: Array.isArray(b.types) ? b.types : [],
      ratingTriggers: Array.isArray(b.triggers) ? b.triggers : [],
      ratingDelays: Array.isArray(b.delays) ? b.delays : [],
      ratingRequirements: Array.isArray(b.requirements) ? b.requirements : [],
      ratingStatuses: Array.isArray(b.statuses) && b.kind === 'rating' ? b.statuses : [],
      ratingTags: Array.isArray(b.tags) && b.kind === 'rating' ? b.tags : [],
      ratingTemplateUrl: b.templateUrl || '',
      // Distinct from `endpoint`, which is the shared settings PATCH — see the controller.
      ratingEndpoint: b.ratingEndpoint || '',
      // The rating email modal (P60). Opened from the Email card; edits the SAME template row the
      // Email Template page edits, through the same methods.
      ratingEmailOpen: false,
      // The preview's own state — which option a reader has hovered/clicked to see it work.
      previewScore: 0,

      // --- workflow tabs (P53) -----------------------------------------------------------
      systemCategories: Array.isArray(b.systemCategories) ? b.systemCategories : [],
      /*
       * Which tab of the Workflow page is open: `workflow` or `categories`.
       *
       * Workflow leads, because it is the Space's own and the reason anybody opens this page.
       * The categories are reference — a vocabulary ProjectBlock owns, which somebody consults
       * rather than works in.
       */
      workflowTab: 'workflow',

      // --- Space configuration + delete (P52), on the Inbox settings page ---------------
      configuration: Array.isArray(b.configuration) ? b.configuration : [],
      spaceName: b.spaceName || '',
      canDelete: !!b.canDelete,
      deleteEndpoint: b.deleteEndpoint || '',
      spacesUrl: b.spacesUrl || '',
      /*
       * The delete dialog, and the name typed into it.
       *
       * `null` when closed rather than a boolean beside a string, so closing cannot leave a
       * half-typed Space name behind for the next time it opens.
       */
      deleteForm: null,
      deleting: false,
      // Which Inbox's "add address" form is open, by id. One at a time: two open forms is two
      // places to type the same thing into.
      openForm: null,
      addressForm: { email: '', name: '' },
      addressError: '',
      copiedId: null,
      // Removing an address is confirmed first — for a verified address it stops mail that is
      // demonstrably arriving today, and for an unverified one it throws away setup work that
      // has already been half done. Neither is worth an undo people have to ask for.
      removeConfirm: { open: false, inbox: null, address: null },
      // ONE test at a time, and the row it belongs to. Not a map keyed by address: two probes
      // in flight is two countdowns competing for the same explanation area, and the server
      // refuses a second probe per address anyway.
      test: { addressId: null, data: null, error: '', starting: false },
      testTimer: null,

      // --- email templates (P48) ---------------------------------------------------------
      /*
       * The panel's OWN endpoints, plural.
       *
       * The screen already has `endpoint` (singular) — the one settings PATCH every other panel
       * writes through. This section has six of its own, because it is not a settings switch.
       * The near-identical names are a hazard worth naming: `this.endpoint` and
       * `this.endpoints` are different things, and reading the wrong one is a silent no-op —
       * which is exactly how this first shipped, with Preview firing no request at all.
       */
      endpoints: b.endpoints || {},
      templates: Array.isArray(b.templates) ? b.templates : [],
      variables: Array.isArray(b.variables) ? b.variables : [],
      // Keyed by user id, with the Space default under `0` — see the controller for why 0.
      signatures: b.signatures || {},
      agents: Array.isArray(b.agents) ? b.agents : [],
      me: b.me || null,
      /*
       * Which drawer is open, if any: `template`, `signature`, or null (P49).
       *
       * The page is a LIST now, not a set of tabs. Tabs put four editors behind four clicks and
       * showed none of them at rest, so the page could not answer the question an administrator
       * arrives with — "which of these is switched on, and which have we changed?" A list answers
       * it before anything is opened, and editing moves into a drawer where there is room for a
       * body field, a preview and four actions without any of them fighting the page's width.
       */
      drawer: null,
      // The editor's working copy. Never the row in `templates` — an unsaved edit that mutated
      // the list would make Cancel impossible and would leave the list disagreeing with the
      // server the moment somebody typed.
      draft: null,
      preview: null,
      previewBusy: false,
      testBusy: false,
      resetConfirm: false,
      /*
       * Whose signature is being edited: a user id, or 0 for the Space default.
       *
       * Defaults to the signed-in user, because the overwhelmingly common case is an agent
       * setting up their own — the administrator configuring the fallback is the rarer visit.
       */
      sigFor: 0,
      sigDraft: null,
      sigSaving: false,

      useEditor: typeof PgEditor !== 'undefined' && typeof window.Jodit !== 'undefined',
      /*
       * The same toolbar the reply composer carries (P41), character for character.
       *
       * An email template is a field, not a document: paragraphs, emphasis, lists and links. Set
       * for every breakpoint because Jodit otherwise falls back to its full set as the viewport
       * narrows, which grows the toolbar exactly when there is least room for it.
       */
      editorButtons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,|,ul,ol,|,link,|,eraser',
      editorLicense: (b.editorLicense || ''),

      saving: false,
      errors: {}
    };
  },
  computed: {
    /**
     * The merge tags as combo options (P61).
     *
     * `pb-combo` wants `{value,label}`; the variable list is `{tag,label}`. Mapped once here
     * rather than inline at both call sites, which would be the same map written twice.
     */
    variableOptions: function () {
      return (this.availableVariables || []).map(function (v) {
        return { value: v.tag, label: v.label };
      });
    },

    /**
     * The delay options, including a "Custom" entry for a value the presets do not cover.
     *
     * VALUES ARE STRINGS. `pb-combo` types `modelValue` as String|Array, so every numeric setting
     * that goes through one is carried as a string and converted back on the way in — see
     * `setRatingNumber`. Binding a number would set the model to a value the component's own
     * comparison never matches, and the field would render as though nothing were selected.
     */
    ratingDelayOptions: function () {
      var list = (this.ratingDelays || []).map(function (d) {
        return { value: String(d.value), label: d.label };
      });
      var current = String((this.rating || {}).delay_minutes);

      if (!list.some(function (d) { return d.value === current; })) {
        list.push({ value: current, label: 'Custom — ' + current + ' min' });
      }

      return list;
    },

    ratingExpiryOptions: function () {
      return [
        { value: 'never', label: 'Never' },
        { value: '7', label: '7 days' },
        { value: '14', label: '14 days' },
        { value: '30', label: '30 days' },
      ];
    },

    ratingThresholdOptions: function () {
      var labels = (this.rating || {}).labels || {};

      return [1, 2, 3, 4].map(function (n) {
        return { value: String(n), label: n + ' — ' + (labels[n] || '') };
      });
    },

    ratingReminderMaxOptions: function () {
      return [1, 2, 3].map(function (n) { return { value: String(n), label: String(n) }; });
    },

    ratingStatusOptions: function () {
      return (this.ratingStatuses || []).map(function (s) {
        return { value: String(s.value), label: s.label };
      });
    },

    ratingTagOptions: function () {
      return [{ value: '', label: 'No tag' }].concat(
        (this.ratingTags || []).map(function (t) { return { value: String(t.value), label: t.label }; }),
      );
    },

    /**
     * How many options the customer preview draws (P56).
     *
     * COMPUTED, not a method: the template writes `v-for="i in ratingPoints"` without
     * parentheses, and a method read as a property yields the function — `v-for` over which
     * iterates nothing. The preview rendered a heading and a button and no rating at all, which
     * is exactly the silent failure P48 hit with three of its own.
     */
    ratingPoints: function () {
      var type = (this.rating || {}).rating_type;
      var hit = (this.ratingTypes || []).filter(function (t) { return t.value === type; })[0];

      return hit ? hit.points : 5;
    },

    // ---- Email Template (P48) ----
    //
    // Computed, not methods: the template names all four without parentheses, and a method read
    // as a property yields the function itself — `v-for` over one iterates nothing and `v-if`
    // on one is always true. Silent in both cases, which is what makes it worth stating.

    /** The variables offered for the type in the editor — some belong to one template only. */
    availableVariables: function () {
      var type = this.draft ? this.draft.type : null;

      return this.variables.filter(function (v) {
        // `types` absent means "everywhere". Present means only there — `{{reply_content}}` in
        // an auto-response would render empty, so it is not offered there.
        return !v.types || v.types.indexOf(type) !== -1;
      });
    },

    /**
     * Whose signatures this person may choose between.
     *
     * A computed rather than `v-for` + `v-if` on the same `<option>`: in Vue 3 `v-if` is
     * evaluated FIRST, so `a` does not exist yet and the condition throws or silently drops
     * every row. Filtering here is the fix and the clearer statement of the rule.
     */
    signatureAgents: function () {
      var self = this;

      return this.agents.filter(function (a) { return self.canManage || a.id === self.me; });
    },

    /** May the signed-in user edit the signature currently open? */
    canEditSignature: function () {
      // The Space default is administration. An agent's own is theirs. Somebody else's is
      // administration again — the same split the server enforces.
      return this.sigFor === 0 ? this.canManage : (this.canManage || this.sigFor === this.me);
    },

    /** The signature as it would appear — the requirement's Preview, from the SAVED row. */
    signaturePreview: function () {
      var row = this.signatures[this.sigFor];

      return row ? row.html : '';
    },

    /* A single switch is saved the moment it is flipped — there is nothing to review, and a
       Save button under one toggle is a second click for no decision. Everything with a text
       field or a number in it gets an explicit Save, because a half-typed address should not
       be sent on every keystroke. */
    /* What the customer actually sees (P65): the typed name, or the Space name as the
       fallback — the same one HelpCenterSpace::senderName() applies on the server, so the
       preview under the field is a promise rather than a guess. */
    senderPreviewName: function () {
      return String(this.senderDisplayName || '').trim() || String(this.spaceName || '').trim();
    },

    senderDirty: function () {
      return String(this.senderDisplayName || '').trim() !== String(this.senderSaved || '').trim();
    },

    isInstant: function () { return this.kind === 'metadata' || this.kind === 'auto_follow'; },

    /* ----- Company & Customer (P75 §2) ---------------------------------------------------- */

    /* The master switch. Everything else on the page is drawn under it and disabled while it is
       off — the module either exists for this Space or it does not. */
    companyMaster: function () {
      return this.features.filter(function (f) { return f.master; })[0] || null;
    },

    companyOn: function () {
      var master = this.companyMaster;

      return !!(master && master.enabled);
    },

    subFeatures: function () {
      return this.features.filter(function (f) { return !f.master; });
    },

    featureOn: function () {
      var map = {};
      this.features.forEach(function (f) { map[f.key] = f.enabled; });

      return map;
    },

    /* The two custom-field lists, as one thing the template loops over.
       Drawn from a computed rather than written twice, so the table, its empty state and its
       row actions exist once — the difference between them is a kind, a title and a list. */
    fieldSections: function () {
      return [
        {
          kind: 'customer',
          title: 'Customer Custom Fields',
          help: 'Record your own fields against the person who wrote in.',
          on: !!this.featureOn.customer_custom_fields,
          fields: this.customerFields
        },
        {
          kind: 'company',
          title: 'Company Custom Fields',
          help: 'Record your own fields against the company they belong to.',
          on: !!this.featureOn.company_custom_fields,
          fields: this.companyFields
        }
      ];
    },

    /* The <select>'s current value, encoded the way `destinationValue` encodes the options. */
    mapDestinationValue: function () {
      if (!this.mapForm.destination) return '';

      return this.mapForm.destination + '|' +
        (this.mapForm.custom_field_id === null || this.mapForm.custom_field_id === undefined
          ? '' : this.mapForm.custom_field_id);
    },

    mapDestinations: function () {
      return this.destinationsFor(this.mapForm.record_type);
    },

    mappingsFull: function () {
      return this.mappings.length >= this.mappingMax;
    },
    /* Disabled for the same two reasons the server refuses: no permission, or the feature is
       Coming Soon. Both are re-checked on the way in — see UpdateSpaceSettingRequest. */
    locked: function () { return !this.canManage || !this.available; },
    /* Which types need choices authored before the field means anything. Read from the type
       list the server sent, which is the same list its validator uses — a second copy here
       would be a modal that hides an Options section the save then demands. */
    fieldTypeNeedsOptions: function () {
      var t = this.fieldTypes.filter(function (x) { return x.value === this.fieldForm.type; }.bind(this))[0];

      return !!(t && t.options);
    },

    /* The probe is measured in tens of seconds, so the row shows how long is left rather than
       an indeterminate spinner that says nothing about whether to keep waiting. */
    testRunning: function () { return !!(this.test.data && this.test.data.running); },
    testCountdown: function () {
      if (!this.testRunning) return '';
      var s = this.test.data.seconds_remaining || 0;

      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    },
    thresholdSummary: function () {
      if (!this.reEnabled) return '';
      var h = Number(this.reHours) || 0, m = Number(this.reMinutes) || 0;
      if (h === 0 && m === 0) return 'Set how long to wait.';

      return 'After ' + (h ? h + 'h ' : '') + (m ? m + 'm' : '') + ' without a reply from the agent.';
    }
  },
  beforeUnmount: function () { this.stopWatching(); },

  /*
   * Seed the Email Template panel's working copies (P48).
   *
   * `created`, not `mounted`: the template references `draft.body` and `sigDraft.name`, and a
   * first render against nulls is a render that throws before anything is on screen.
   */
  created: function () {
    if (this.kind !== 'email_templates') return;

    // Nothing is opened at load — the page is a list, and a drawer that is already up when you
    // arrive is a page you have to dismiss before you can read it (P49).
    this.sigFor = this.me || 0;
  },
  methods: {
    // ===== CSAT (P56) ======================================================================

    /**
     * The glyph for one option of the current scale.
     *
     * Mirrors the Blade the customer actually sees, so the preview is a preview rather than an
     * impression. The two are separate renderings of one vocabulary; if they ever disagree, this
     * is the copy to delete.
     */
    ratingGlyph: function (i) {
      var type = (this.rating || {}).rating_type;

      if (type === 'thumbs') return i === 2 ? '👍' : '👎';
      if (type === 'emoji5') return ['😠', '🙁', '😐', '🙂', '😍'][Math.min(4, i - 1)];
      if (type === 'scale10') return String(i);

      return '★';
    },

    /** A raw choice as the normalised 1–5 score, matching the server's map. */
    ratingNormalise: function (i) {
      var type = (this.rating || {}).rating_type;

      if (type === 'thumbs') return i === 2 ? 5 : 1;
      if (type === 'scale10') return Math.ceil(i / 2);

      return i;
    },

    ratingLabelFor: function (i) {
      var labels = (this.rating || {}).labels || {};

      return labels[this.ratingNormalise(i)] || '';
    },

    /** Would the customer be forced to write something for this score? Drives the preview. */
    ratingCommentRequired: function (score) {
      var r = this.rating || {};

      if (!r.allow_comment) return false;
      if (r.comment_requirement === 'always') return true;
      if (r.comment_requirement === 'low_only') return score > 0 && score <= r.low_threshold;

      return false;
    },

    /**
     * Write a combo's string value back onto a numeric setting (P61).
     *
     * `pb-combo` deals in strings; these columns are integers, and `''`/`'never'` mean null on
     * the two that are nullable. One bridge rather than a cast at each of six call sites.
     */
    setRatingNumber: function (field, value, nullable) {
      if (!this.rating) return;

      var empty = value === '' || value === 'never' || value === null || value === undefined;

      this.rating[field] = (nullable && empty) ? null : Number(value);
    },

    // ===== The rating email, edited in place (P60) =========================================

    /**
     * Open the rating request template in a modal, without leaving this page.
     *
     * Reuses `editTemplate`, which seeds `draft` from `templates` — the same working copy the
     * Email Template page edits. That is what makes "must edit the same underlying record" true
     * by construction rather than by two code paths agreeing to.
     *
     * `drawer` is reset afterwards because `editTemplate` sets it for the other page's shell,
     * and this page has no drawer to open.
     */
    openRatingEmail: function () {
      this.editTemplate('rating_request');
      this.drawer = null;
      this.ratingEmailOpen = true;
    },

    closeRatingEmail: function () {
      this.ratingEmailOpen = false;
      this.draft = null;
      this.preview = null;
    },

    /**
     * Save and close.
     *
     * `saveTemplate` already folds the server's answer back into `templates`, which is what the
     * Email card on this page reads — so the card updates the moment this returns, with no second
     * fetch and nothing to keep in step.
     */
    saveRatingEmail: function () {
      if (!this.draft || this.saving) return;

      var self = this;

      /*
       * Closed only when the save actually LANDED.
       *
       * A failed save leaves the modal open with the edits still in it, which is the whole point
       * of not closing optimistically — losing somebody's email copy because the server said no
       * would be the worst possible response to the server saying no.
       */
      var result = this.saveTemplate();

      if (result && typeof result.then === 'function') {
        result.then(function (ok) { if (ok) self.closeRatingEmail(); });
      }
    },

    /** The rating email's current state, for the Email card. */
    ratingEmailTemplate: function () {
      return this.templateFor('rating_request');
    },

    /**
     * The enable switch, saved on the spot (P57).
     *
     * Every other single toggle on this screen behaves this way (`isInstant`), and here it is not
     * a nicety: the Save button lives in the second card, which disappears the moment this goes
     * off. A switch whose only Save vanishes when you use it is a switch that silently does
     * nothing.
     *
     * The whole draft goes with it, which is correct rather than incidental — a half-edited form
     * is still the settings somebody wants, and saving them alongside the flag is what makes
     * turning Rating on with its defaults a single click.
     */
    setRatingEnabled: function (value) {
      if (!this.rating) return;

      this.rating.enabled = !!value;
      this.saveRating();
    },

    saveRating: function () {
      if (!this.rating || this.saving) return;

      var self = this;
      this.saving = true;

      this.$pb.api(this.ratingEndpoint, { method: 'PUT', body: this.rating })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save the rating settings.', 'error');

            return;
          }

          self.rating = Object.assign({}, resp.settings);
          self.$pb.toast(resp.message || 'Rating settings saved.');
        })
        .catch(function () { self.$pb.toast('Could not save the rating settings.', 'error'); })
        .finally(function () { self.saving = false; });
    },

    // ===== System Category (P54) ===========================================================

    /** A category key as its label — the read-only table shows words, not keys. */
    categoryLabelFor: function (key) {
      var hit = (this.categoryOptions || []).filter(function (c) { return c.value === key; })[0];

      return hit ? hit.label : (key || '—');
    },

    /**
     * The dot colour beside it.
     *
     * Read off `systemCategories`, which carries the colours, rather than `categoryOptions`,
     * which is only value/label — the two lists come from the same config entry and are shaped
     * for their two different jobs.
     */
    categoryColor: function (key) {
      var hit = (this.systemCategories || []).filter(function (c) { return c.key === key; })[0];

      return hit ? hit.color : '#9ca3af';
    },

    // ===== Delete Space (P52) ==============================================================

    openDeleteSpace: function () {
      this.deleteForm = { confirm: '', error: '' };
    },

    /**
     * The typed name has to match, exactly as the server demands (SpaceController::destroy).
     *
     * Checked here as well so the button is disabled rather than the request refused: a form
     * whose only feedback is a 422 after you press the button is a form that teaches you the
     * rule by failing at you.
     */
    canConfirmDelete: function () {
      if (!this.deleteForm) return false;

      return this.deleteForm.confirm.trim().toLowerCase() === (this.spaceName || '').toLowerCase();
    },

    deleteSpace: function () {
      if (!this.canConfirmDelete() || this.deleting) return;

      var self = this;
      this.deleting = true;
      this.deleteForm.error = '';

      this.$pb.api(this.deleteEndpoint, { method: 'DELETE', body: { confirm: this.deleteForm.confirm } })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.deleteForm.error = self.$pb.firstError(resp) || 'The Space could not be deleted.';

            return;
          }

          /*
           * Navigate AWAY, and not with a toast on this page.
           *
           * The Space this screen is about no longer exists; staying here would leave every
           * other control pointing at a 404. The Spaces listing is where somebody who just
           * deleted one belongs.
           */
          window.location.href = self.spacesUrl || '/help-center/spaces';
        })
        .catch(function () {
          self.deleteForm.error = 'The Space could not be deleted.';
        })
        .finally(function () { self.deleting = false; });
    },

    // ===== Email templates (P48) ===========================================================

    /**
     * Shut the drawer (P49).
     *
     * The working copies are dropped with it: a draft that survived closing would reappear the
     * next time the drawer opened, showing edits somebody had already walked away from as though
     * they were the saved template.
     */
    closeEmailDrawer: function () {
      this.drawer = null;
      this.draft = null;
      this.sigDraft = null;
      this.preview = null;
      this.resetConfirm = false;
    },

    /** The signature row for a list entry — `0` is the Space default. */
    signatureRow: function (id) {
      return this.signatures[id] || null;
    },

    /**
     * What the list says about one signature's state.
     *
     * Three answers, not two. "Not set" and "Off" are different situations with different
     * fixes — one has never been written, the other exists and was switched off — and a list
     * that collapses them into "inactive" hides which.
     */
    signatureStatus: function (id) {
      var row = this.signatureRow(id);

      if (!row) return 'Not set';

      return row.enabled ? 'Active' : 'Off';
    },

    /**
     * `{{tag}}` as a displayable string.
     *
     * A method rather than an inline expression, because a mustache CANNOT contain the closing
     * braces of a merge tag: Vue's parser ends the interpolation at the first `}}` it meets, so
     * `{{ '{{' + v.tag + '}}' }}` fails to compile and takes the whole screen down with it. The
     * error is a bare "missing ) after argument list" from the template compiler, which points
     * nowhere near the line that caused it.
     */
    tagToken: function (tag) {
      return '{' + '{' + tag + '}' + '}';
    },

    /** The stored (or default) row for a type. */
    templateFor: function (type) {
      return this.templates.filter(function (t) { return t.type === type; })[0] || null;
    },

    /** Open a type in the editor — a COPY, so Cancel and Reset both remain possible. */
    editTemplate: function (type) {
      var t = this.templateFor(type);
      if (!t) return;

      this.drawer = 'template';
      this.preview = null;
      this.resetConfirm = false;

      this.draft = {
        type: t.type,
        name: t.name,
        subject: t.subject === null ? '' : t.subject,
        body: t.body,
        enabled: t.enabled,
      };
    },

    /**
     * Insert a merge tag AT THE CURSOR — the requirement's own words.
     *
     * The subject is a plain input, so the caret is read off the element. The body is Jodit,
     * which owns its own selection; `pg-editor` exposes no insert API, so the tag is appended
     * to the body instead and the field is told so. Appending is the honest fallback: silently
     * dropping it at the start would be worse, and pretending to honour the caret in a rich
     * editor we do not control would be a lie the first time somebody clicked mid-paragraph.
     */
    insertVariable: function (tag, target) {
      var token = '{{' + tag + '}}';

      if (!this.draft) return;

      if (target === 'subject') {
        var el = this.$refs.subjectInput;

        if (el && typeof el.selectionStart === 'number') {
          var at = el.selectionStart;
          var end = el.selectionEnd;
          this.draft.subject = this.draft.subject.slice(0, at) + token + this.draft.subject.slice(end);

          var self = this;
          this.$nextTick(function () {
            el.focus();
            el.setSelectionRange(at + token.length, at + token.length);
          });

          return;
        }

        this.draft.subject += token;

        return;
      }

      this.draft.body += '<p>' + token + '</p>';
      this.$pb.toast('Added ' + token + ' to the end of the body.');
    },

    /** Save the working copy. */
    saveTemplate: function () {
      if (!this.draft || this.saving) return;

      var self = this;
      var url = (this.endpoints.save || '').replace('__TYPE__', this.draft.type);

      this.saving = true;

      /*
       * RETURNS the promise, resolving to whether the save landed.
       *
       * The drawer on the Email Template page ignores it; the rating modal (P60) chains on it to
       * close only on success. Returning it is what lets one save path serve both without the
       * caller having to watch `saving` and guess.
       */
      return this.$pb.api(url, { method: 'PUT', body: this.draft })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save the template.', 'error');

            return false;
          }

          self.mergeTemplate(resp.template);
          self.$pb.toast(resp.message || 'Template saved.');

          return true;
        })
        .catch(function () {
          self.$pb.toast('Could not save the template.', 'error');

          return false;
        })
        .finally(function () { self.saving = false; });
    },

    /** Restore Default Template — a DELETE, which is what "default" means here. */
    resetTemplate: function () {
      if (!this.draft || this.saving) return;

      var self = this;
      var type = this.draft.type;
      var url = (this.endpoints.reset || '').replace('__TYPE__', type);

      this.saving = true;
      this.resetConfirm = false;

      this.$pb.api(url, { method: 'DELETE' })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not restore the default.', 'error');

            return;
          }

          self.mergeTemplate(resp.template);
          // Re-seed the editor: the point of restoring is to SEE the default in front of you.
          self.editTemplate(type);
          self.$pb.toast(resp.message || 'Default template restored.');
        })
        .catch(function () { self.$pb.toast('Could not restore the default.', 'error'); })
        .finally(function () { self.saving = false; });
    },

    /** Fold the server's answer back into the list without disturbing the other types. */
    mergeTemplate: function (fresh) {
      if (!fresh) return;

      var at = this.templates.findIndex(function (t) { return t.type === fresh.type; });
      if (at === -1) return;

      this.templates.splice(at, 1, Object.assign({}, this.templates[at], fresh));
    },

    /** Preview what is IN THE EDITOR — not what is saved. */
    previewTemplate: function () {
      if (!this.draft || this.previewBusy) return;

      var self = this;
      var url = (this.endpoints.preview || '').replace('__TYPE__', this.draft.type);

      this.previewBusy = true;

      this.$pb.api(url, { method: 'POST', body: { subject: this.draft.subject, body: this.draft.body } })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not build the preview.', 'error');

            return;
          }

          self.preview = { subject: resp.subject, html: resp.html };
        })
        .catch(function () { self.$pb.toast('Could not build the preview.', 'error'); })
        .finally(function () { self.previewBusy = false; });
    },

    /** Send Test Email — always to the signed-in user; see the controller for why. */
    sendTest: function () {
      if (!this.draft || this.testBusy) return;

      var self = this;
      var url = (this.endpoints.test || '').replace('__TYPE__', this.draft.type);

      this.testBusy = true;

      this.$pb.api(url, { method: 'POST', body: { subject: this.draft.subject, body: this.draft.body } })
        .then(function (resp) {
          self.$pb.toast(
            (resp && resp.message) || 'Could not send the test email.',
            resp && resp.ok ? 'success' : 'error',
          );
        })
        .catch(function () { self.$pb.toast('Could not send the test email.', 'error'); })
        .finally(function () { self.testBusy = false; });
    },

    // ===== Signatures (P48) ================================================================

    /** Open one signature in the editor — `0` is the Space default. */
    editSignature: function (id) {
      var existing = this.signatures[id] || null;

      this.drawer = 'signature';
      this.sigFor = id;
      this.sigDraft = {
        enabled: existing ? existing.enabled : true,
        name: existing ? (existing.name || '') : this.suggestedName(id),
        job_title: existing ? (existing.job_title || '') : '',
        company: existing ? (existing.company || '') : '',
        avatar_url: existing ? (existing.avatar_url || '') : '',
        content: existing ? (existing.content || '') : '',
      };
    },

    /** A new signature starts with the agent's own name filled in — nobody signs as nobody. */
    suggestedName: function (id) {
      if (!id) return '';

      var agent = this.agents.filter(function (a) { return a.id === id; })[0];

      return agent ? agent.name : '';
    },

    saveSignature: function () {
      if (!this.sigDraft || this.sigSaving) return;

      var self = this;
      var url = this.sigFor === 0
        ? this.endpoints.signatureDefault
        : (this.endpoints.signature || '').replace('__USER__', this.sigFor);

      this.sigSaving = true;

      this.$pb.api(url, { method: 'PUT', body: this.sigDraft })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save the signature.', 'error');

            return;
          }

          // Reassigned rather than mutated: Vue 3 tracks added keys, but replacing the object
          // keeps the preview below — which reads `signatures[sigFor]` — in step in one step.
          self.signatures = Object.assign({}, self.signatures);
          self.signatures[self.sigFor] = resp.signature;
          self.$pb.toast(resp.message || 'Signature saved.');
        })
        .catch(function () { self.$pb.toast('Could not save the signature.', 'error'); })
        .finally(function () { self.sigSaving = false; });
    },

    fieldError: function (key) { return this.errors[key] || ''; },

    /* One save for every panel. The body is whatever this section owns — the server decides
       which keys it will accept from the section in the URL, so sending more would not help
       and sending the wrong ones is refused rather than silently ignored. */
    save: async function (body, revert) {
      if (this.saving || !this.canManage) return;
      this.saving = true;
      this.errors = {};

      try {
        var resp = await this.$pb.api(this.endpoint, { method: 'PATCH', body: body });
        this.$pb.toast((resp && resp.message) || 'Saved.');
      } catch (e) {
        // Put the control back where it was. An optimistic switch that stays flipped after a
        // refusal is a screen telling the user something the database does not agree with.
        if (typeof revert === 'function') revert();
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not save.'), 'error');
      } finally {
        this.saving = false;
      }
    },

    toggleOne: function (v) {
      if (this.locked) return;
      var was = this.enabled;
      this.enabled = v;
      this.save({ enabled: v }, function () { this.enabled = was; }.bind(this));
    },

    /* One write for the whole setting, sent by every action on this panel. The switch, an
       added address and a removed one are all "this is what Auto BCC is now" — three endpoints
       would be three places that have to agree about what an empty list means. */
    /* Saved on a button rather than on blur (P65).
       Every other control on this panel is a switch or a row action that writes immediately;
       a free-text field is the one thing somebody is halfway through typing, and saving it on
       every keystroke would send the customer's sender name a character at a time. */
    saveSenderName: async function () {
      if (this.savingSender || !this.canManage || !this.senderDirty) return;

      var value = String(this.senderDisplayName || '').trim();
      this.savingSender = true;
      this.errors = {};

      try {
        var resp = await this.$pb.api(this.endpoint, {
          method: 'PATCH',
          body: { inbound_display_name: value }
        });

        // The server is the authority on the resolved name: it applies the fallback, and
        // clearing the field must leave the preview showing the Space name rather than blank.
        this.senderDisplayName = value;
        this.senderSaved = value;
        this.$pb.toast((resp && resp.message) || 'Sender name saved.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not save the sender name.'), 'error');
      } finally {
        this.savingSender = false;
      }
    },

    saveBcc: function (revert) {
      return this.save({
        auto_bcc_enabled: this.bccEnabled,
        auto_bcc_emails: this.bccEmails
      }, revert);
    },

    // The switch saves the moment it is flipped, like the single-toggle panels: with add and
    // remove writing immediately, a Save button underneath would be the only unsaved control
    // on the page and the easiest thing to walk away from.
    toggleBcc: function (v) {
      if (!this.canManage) return;
      var was = this.bccEnabled;
      this.bccEnabled = v;
      this.saveBcc(function () { this.bccEnabled = was; }.bind(this));
    },

    showBccForm: function () {
      this.bccForm = { open: !this.bccForm.open, email: '', error: '' };
    },

    addBcc: async function () {
      var email = String(this.bccForm.email || '').trim().toLowerCase();
      this.bccForm.error = '';

      if (!email) return;

      // Caught here as well as by the server, because the answer is already on this screen —
      // asking the server to tell us what the list in front of us says would be a round trip
      // to learn nothing new.
      if (this.bccEmails.indexOf(email) !== -1) {
        this.bccForm.error = email + ' is already on the list.';

        return;
      }

      if (this.bccEmails.length >= this.bccMax) {
        this.bccForm.error = 'Auto BCC can hold at most ' + this.bccMax + ' addresses.';

        return;
      }

      var was = this.bccEmails.slice();
      this.bccEmails.push(email);

      await this.saveBcc(function () { this.bccEmails = was; }.bind(this));

      // Only cleared when the write stood. A refused address left in the box is an address
      // somebody can correct; one that vanished is one they have to remember and retype.
      if (this.bccEmails.indexOf(email) !== -1) this.bccForm = { open: false, email: '', error: '' };
      else this.bccForm.error = this.fieldError('auto_bcc_emails') || 'Could not add that address.';
    },

    // Removing an address silently stops copies that something downstream — an archive, an
    // audit, somebody's filter — may be relying on, and nothing on this page would show it had
    // happened. So it is confirmed, like deleting an Inbox address.
    confirmRemoveBcc: function (email) {
      this.bccConfirm = { open: true, email: email };
    },

    removeBcc: function () {
      var email = this.bccConfirm.email;
      this.bccConfirm = { open: false, email: '' };

      if (!email) return;

      var was = this.bccEmails.slice();
      this.bccEmails = this.bccEmails.filter(function (e) { return e !== email; });
      this.saveBcc(function () { this.bccEmails = was; }.bind(this));
    },

    // ----- inbox ------------------------------------------------------------------------
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    addressesUrl: function (inboxId) {
      return String(this.endpointTemplates.addresses || '').replace('__ID__', encodeURIComponent(inboxId));
    },

    addressUrl: function (inboxId, addressId) {
      return String(this.endpointTemplates.address || '')
        .replace('__ID__', encodeURIComponent(inboxId))
        .replace('__ADDRESS__', encodeURIComponent(addressId));
    },

    showAddressForm: function (inbox) {
      this.openForm = this.openForm === inbox.id ? null : inbox.id;
      this.addressForm = { email: '', name: '' };
      this.addressError = '';
    },

    addAddress: async function (inbox) {
      if (this.saving) return;
      this.saving = true;
      this.addressError = '';

      try {
        var res = await this.$pb.api(this.addressesUrl(inbox.id), { method: 'POST', body: this.addressForm });
        inbox.addresses.push(res.address);
        this.addressForm = { email: '', name: '' };
        this.openForm = null;
        this.$pb.toast(res.message);
      } catch (e) {
        // §7's "already connected elsewhere" arrives as a field error; showing it beside the
        // input is the point of asking the server rather than guessing here.
        this.addressError = this.$pb.firstError(e, 'Could not add that address.');
      } finally {
        this.saving = false;
      }
    },

    // Verified or not, removing an address goes through the same confirmation. The status
    // changes what the sentence says, not whether there is one: "are you sure?" for one and a
    // silent delete for the other would mean the safer-looking row is the easier one to lose.
    confirmRemove: function (inbox, address) {
      this.removeConfirm = { open: true, inbox: inbox, address: address };
    },

    removeMessage: function () {
      var a = this.removeConfirm.address;
      if (!a) return '';

      return a.status === 'verified'
        ? a.email + ' is verified and receiving email. Removing it stops messages forwarded from '
          + 'it reaching this Space. This cannot be undone.'
        : a.email + ' will no longer be connected to this Inbox, and its setup will have to be '
          + 'done again to reconnect it. This cannot be undone.';
    },

    removeAddress: async function () {
      var inbox = this.removeConfirm.inbox;
      var address = this.removeConfirm.address;
      this.removeConfirm = { open: false, inbox: null, address: null };

      if (!inbox || !address) return;

      try {
        var res = await this.$pb.api(this.addressUrl(inbox.id, address.id), { method: 'DELETE' });
        inbox.addresses = inbox.addresses.filter(function (a) { return a.id !== address.id; });
        // A result about an address that no longer exists is a result about nothing.
        if (this.test.addressId === address.id) this.clearTest();
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not remove that address.'), 'error');
      }
    },

    /* ----- the workflow editor (P16) -----------------------------------------------------
       Full screen over the settings page, 1000px centred. A workflow is six fields per row plus
       an order, and the 820px settings column cannot hold that without the cards folding into
       something you cannot compare across — which is the one thing reading a workflow is for. */
    openEditor: function () {
      if (!this.canManage) return;

      var self = this;
      // A deep copy, and a local id per row: `key` needs something stable, and a row that has
      // not been saved yet has no id to be stable about.
      this.editor = {
        open: true,
        error: '',
        uid: 0,
        rows: this.statuses.map(function (s) {
          return {
            _uid: ++self.editor.uid,
            id: s.id,
            name: s.name,
            color: s.color,
            responsibility: s.responsibility,
            waiting_on: s.waiting_on,
            system_category: s.system_category,
            is_active: s.is_active,
            is_default: s.is_default,
            system_key: s.system_key,
            default_assignees: (s.default_assignees || []).slice()
          };
        })
      };

      // The page behind must not scroll while a full-screen layer is open, or a scroll gesture
      // over the overlay moves the wrong document.
      document.body.style.overflow = 'hidden';
    },

    closeEditor: function () {
      this.editor.open = false;
      document.body.style.overflow = '';
    },

    openStatus: function () {
      return this.editor.rows.filter(function (s) { return s.system_key === 'open'; })[0];
    },

    closedStatus: function () {
      return this.editor.rows.filter(function (s) { return s.system_key === 'closed'; })[0];
    },

    customStatuses: function () {
      return this.editor.rows.filter(function (s) { return !s.system_key; });
    },

    addStatus: function () {
      if (this.customStatuses().length >= this.statusMax) return;

      var closedAt = this.editor.rows.findIndex(function (s) { return s.system_key === 'closed'; });
      var row = {
        _uid: ++this.editor.uid,
        id: null,
        name: '',
        color: this.statusColors[0] || '#3b82f6',
        responsibility: 'assignee',
        waiting_on: 'agent',
        /*
         * A new status starts as `active` (P54).
         *
         * The category is NOT NULL, so a new row has to start somewhere, and "an agent owes
         * something" is what a state somebody is adding to the middle of a workflow almost
         * always means. It is the same default the server falls back to, so a card the user
         * never touches saves as what it displayed.
         */
        system_category: 'active',
        is_active: true,
        is_default: false,
        system_key: null,
        default_assignees: []
      };

      // Always directly above Closed, because Closed is always last (P2 §16).
      if (closedAt === -1) this.editor.rows.push(row);
      else this.editor.rows.splice(closedAt, 0, row);
    },

    // Confirmed, like every other delete on these pages — and this one takes a status Requests
    // may already be sitting in.
    confirmRemoveStatus: function (row) {
      this.editorConfirm = { open: true, row: row };
    },

    removeStatus: function () {
      var row = this.editorConfirm.row;
      this.editorConfirm = { open: false, row: null };

      if (!row) return;

      var i = this.editor.rows.indexOf(row);
      if (i > -1) this.editor.rows.splice(i, 1);
    },

    /* Move a custom status among the other custom ones. The system rows are never operands:
       Open is always first and Closed always last, so moving past either is not a move. */
    moveStatus: function (row, delta) {
      var custom = this.customStatuses();
      var from = custom.indexOf(row);
      var to = from + delta;

      if (from === -1 || to < 0 || to >= custom.length) return;

      var a = this.editor.rows.indexOf(custom[from]);
      var b = this.editor.rows.indexOf(custom[to]);
      this.editor.rows.splice(b, 0, this.editor.rows.splice(a, 1)[0]);
    },

    saveWorkflow: async function () {
      if (this.saving) return;
      this.saving = true;
      this.editor.error = '';
      this.errors = {};

      try {
        var res = await this.$pb.api(this.endpoint, {
          method: 'PATCH',
          body: { statuses: this.editor.rows }
        });
        // Replaced from the RESPONSE, not from the rows just sent: the server normalizes order,
        // the two system rows and which status is the starting one, so the screen should show
        // what was stored rather than what was submitted.
        this.statuses = res.statuses || this.statuses;
        this.closeEditor();
        this.$pb.toast((res && res.message) || 'Workflow saved.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.editor.error = this.$pb.firstError(e, 'Could not save the workflow.');
      } finally {
        this.saving = false;
      }
    },

    /* ----- custom fields, Customer and Company (P18, P75 §2) ------------------------------
       Every method here takes a KIND. The two lists are the same eight types over two tables,
       so one set of methods with a parameter beats two sets that have to be kept identical. */
    customFieldsUrl: function (kind) {
      return String(this.endpointTemplates.customFields || '').replace('__KIND__', encodeURIComponent(kind));
    },

    customFieldUrl: function (kind, id) {
      return String(this.endpointTemplates.customField || '')
        .replace('__KIND__', encodeURIComponent(kind))
        .replace('__FIELD__', encodeURIComponent(id));
    },

    /* The list a kind writes into, by reference — so a push or a filter lands on the real
       array rather than on a copy nothing is bound to. */
    fieldListFor: function (kind) {
      return kind === 'company' ? this.companyFields : this.customerFields;
    },

    newField: function (kind) {
      this.fieldForm = {
        open: true, kind: kind || 'customer', editing: null, saving: false, error: '',
        name: '', type: 'input', is_required: false, is_active: true, options: [], optionErrors: {}
      };
    },

    editField: function (kind, field) {
      this.fieldForm = {
        open: true,
        kind: kind,
        editing: field,
        saving: false,
        error: '',
        name: field.name,
        type: field.type,
        is_required: field.is_required,
        is_active: field.is_active,
        // A COPY of the options. Cancel has to leave the list as it was stored, and an array
        // bound straight to the row would have been rewritten on the way in.
        options: (field.options || []).slice(),
        optionErrors: {}
      };
    },

    closeFieldForm: function () { this.fieldForm.open = false; },

    /* The Options section appears the moment the type changes, and the choices survive a move
       between two types that both take them — somebody switching Dropdown to Radio has not
       asked to retype three answers. The server drops them for a type that takes none, so a
       switch to Input stores nothing. */
    addOption: function () {
      if (this.fieldForm.options.length >= this.optionMax) return;
      this.fieldForm.options.push('');
    },

    removeOption: function (i) {
      this.fieldForm.options.splice(i, 1);
      this.fieldForm.optionErrors = {};
    },

    moveOption: function (i, delta) {
      var to = i + delta;
      if (to < 0 || to >= this.fieldForm.options.length) return;

      var rows = this.fieldForm.options;
      rows.splice(to, 0, rows.splice(i, 1)[0]);
      this.fieldForm.optionErrors = {};
    },

    /* Checked here as well as on the server, because both answers are already on this screen:
       an empty box and a repeated word are things the modal can see without asking. The server
       checks them again — a client is not an authority on what it may store. */
    validateOptions: function () {
      var errors = {};
      var seen = {};

      if (!this.fieldTypeNeedsOptions) return errors;

      this.fieldForm.options.forEach(function (option, i) {
        var value = String(option || '').trim();

        if (value === '') {
          errors[i] = 'Enter a value or remove this option.';

          return;
        }

        var key = value.toLowerCase();

        if (seen[key]) errors[i] = 'This option already exists.';
        else seen[key] = true;
      });

      return errors;
    },

    saveField: async function () {
      if (this.fieldForm.saving) return;

      this.fieldForm.error = '';
      this.fieldForm.optionErrors = {};

      if (!String(this.fieldForm.name || '').trim()) {
        this.fieldForm.error = 'Field name is required.';

        return;
      }

      if (this.fieldTypeNeedsOptions && !this.fieldForm.options.length) {
        this.fieldForm.error = 'Add at least one option before creating this field.';

        return;
      }

      var optionErrors = this.validateOptions();

      if (Object.keys(optionErrors).length) {
        this.fieldForm.optionErrors = optionErrors;

        return;
      }

      this.fieldForm.saving = true;

      var body = {
        name: String(this.fieldForm.name).trim(),
        type: this.fieldForm.type,
        is_required: this.fieldForm.is_required,
        is_active: this.fieldForm.is_active,
        options: this.fieldTypeNeedsOptions ? this.fieldForm.options : []
      };

      var editing = this.fieldForm.editing;

      var kind = this.fieldForm.kind;

      try {
        var res = editing
          ? await this.$pb.api(this.customFieldUrl(kind, editing.id), { method: 'PATCH', body: body })
          : await this.$pb.api(this.customFieldsUrl(kind), { method: 'POST', body: body });

        if (editing) this.replaceField(kind, res.field);
        else this.fieldListFor(kind).push(res.field);

        this.fieldForm.open = false;
        this.$pb.toast(res.message);
      } catch (e) {
        this.fieldForm.error = this.$pb.firstError(e, 'Could not save the field.');
      } finally {
        this.fieldForm.saving = false;
      }
    },

    /* Disable rather than delete: a field somebody stopped asking is not a field that never
       existed. It sends the whole record, like Save Changes does, because both are "this field
       is now that". */
    toggleField: async function (kind, field) {
      try {
        var res = await this.$pb.api(this.customFieldUrl(kind, field.id), {
          method: 'PATCH',
          body: {
            name: field.name,
            type: field.type,
            is_required: field.is_required,
            is_active: !field.is_active,
            options: field.options || []
          }
        });
        this.replaceField(kind, res.field);
        this.$pb.toast(res.field.is_active ? 'Field set to Active.' : 'Field set to Inactive.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not change that field.'), 'error');
      }
    },

    confirmRemoveField: function (kind, field) {
      this.fieldConfirm = { open: true, field: field, kind: kind };
    },

    removeField: async function () {
      var field = this.fieldConfirm.field;
      var kind = this.fieldConfirm.kind;
      this.fieldConfirm = { open: false, field: null, kind: kind };

      if (!field) return;

      try {
        var res = await this.$pb.api(this.customFieldUrl(kind, field.id), { method: 'DELETE' });

        if (kind === 'company') {
          this.companyFields = this.companyFields.filter(function (f) { return f.id !== field.id; });
        } else {
          this.customerFields = this.customerFields.filter(function (f) { return f.id !== field.id; });
        }

        /* A mapping that pointed at it is left in the list, greyed, saying the field is gone —
           the server keeps the row for the same reason (see CustomFieldController). Repointing
           it is a decision, not a side effect of tidying a form. */
        this.mappings.forEach(function (m) {
          if (m.custom_field_id === field.id) m.destination_label = 'Custom Field (deleted)';
        });

        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not delete that field.'), 'error');
      }
    },

    replaceField: function (kind, payload) {
      if (!payload) return;

      var row = this.fieldListFor(kind).filter(function (f) { return f.id === payload.id; })[0];
      if (row) Object.assign(row, payload);
    },

    /* ----- Company & Customer feature switches (P75 §2) -----------------------------------
       Optimistic, like every other switch in this file: the UI flips, the write goes, and a
       failure puts it back. A switch that waits for a round trip feels broken on a slow link.

       The WHOLE map is sent, not the one key that changed. The server merges it over the
       stored map either way, and sending one key would mean the master and its sub-switches
       could be half-saved if two clicks raced. */
    featureByKey: function (key) {
      return this.features.filter(function (f) { return f.key === key; })[0] || null;
    },

    toggleFeature: async function (feature) {
      if (!this.canManage || !feature.available || this.featureSaving) return;

      var before = feature.enabled;
      feature.enabled = !before;
      this.featureSaving = feature.key;

      var body = { features: {} };
      this.features.forEach(function (f) { body.features[f.key] = f.enabled; });

      try {
        var res = await this.$pb.api(this.endpoint, { method: 'PATCH', body: body });
        this.$pb.toast((res && res.message) || 'Setting saved.');
      } catch (e) {
        feature.enabled = before;
        this.$pb.toast(this.$pb.firstError(e, 'Could not save that setting.'), 'error');
      } finally {
        this.featureSaving = '';
      }
    },

    /* ----- Ticket Metadata Mapping (P75 §3-§4) --------------------------------------------- */
    mappingsUrl: function () { return String(this.endpointTemplates.mappings || ''); },

    mappingUrl: function (id) {
      return String(this.endpointTemplates.mapping || '').replace('__MAPPING__', encodeURIComponent(id));
    },

    /* The destination list for the record type currently chosen in the modal. Computed from a
       method rather than a `computed`, because the mapping ROWS need the same lookup for a type
       that is not the one being edited. */
    destinationsFor: function (recordType) {
      var found = this.mappingRecordTypes.filter(function (t) { return t.key === recordType; })[0];

      return found ? found.fields : [];
    },

    /* A destination is (key + custom_field_id), because every custom field shares the key
       `custom_field`. Encoded into one string for the <select>, which can only hold one. */
    destinationValue: function (field) {
      return field.key + '|' + (field.custom_field_id === null || field.custom_field_id === undefined ? '' : field.custom_field_id);
    },

    sourceIsNamed: function (key) {
      var found = this.mappingSources.filter(function (s) { return s.key === key; })[0];

      return !!(found && found.named);
    },

    newMapping: function () {
      this.mapForm = {
        open: true, editing: null, saving: false, error: '',
        source: '', source_key: '', record_type: 'customer', destination: '', custom_field_id: null
      };
    },

    editMapping: function (mapping) {
      this.mapForm = {
        open: true,
        editing: mapping,
        saving: false,
        error: '',
        source: mapping.source,
        source_key: mapping.source_key || '',
        record_type: mapping.record_type,
        destination: mapping.destination,
        custom_field_id: mapping.custom_field_id
      };
    },

    /* Changing the record type clears the destination.
       A Customer destination is not a Company destination, and leaving the old one selected
       would submit a pair the server refuses — with an error about a field the person cannot
       see, because the dropdown no longer offers it. */
    onRecordTypeChange: function () {
      this.mapForm.destination = '';
      this.mapForm.custom_field_id = null;
    },

    onDestinationChange: function (value) {
      var parts = String(value).split('|');
      this.mapForm.destination = parts[0] || '';
      this.mapForm.custom_field_id = parts[1] ? Number(parts[1]) : null;
    },

    saveMapping: async function () {
      if (!this.mapForm.source || !this.mapForm.destination) {
        this.mapForm.error = 'Choose a source field and a destination field.';

        return;
      }

      if (this.sourceIsNamed(this.mapForm.source) && !String(this.mapForm.source_key).trim()) {
        this.mapForm.error = 'Name the field this mapping reads.';

        return;
      }

      this.mapForm.saving = true;

      var body = {
        source: this.mapForm.source,
        source_key: this.mapForm.source_key,
        record_type: this.mapForm.record_type,
        destination: this.mapForm.destination,
        custom_field_id: this.mapForm.custom_field_id,
        is_active: this.mapForm.editing ? this.mapForm.editing.is_active : true
      };

      var editing = this.mapForm.editing;

      try {
        var res = editing
          ? await this.$pb.api(this.mappingUrl(editing.id), { method: 'PATCH', body: body })
          : await this.$pb.api(this.mappingsUrl(), { method: 'POST', body: body });

        if (editing) Object.assign(editing, res.mapping);
        else this.mappings.push(res.mapping);

        this.mapForm.open = false;
        this.$pb.toast(res.message);
      } catch (e) {
        this.mapForm.error = this.$pb.firstError(e, 'Could not save that mapping.');
      } finally {
        this.mapForm.saving = false;
      }
    },

    /* Enable/Disable one mapping (§4). Sends the whole row, like the field switch above does. */
    toggleMapping: async function (mapping) {
      var before = mapping.is_active;
      mapping.is_active = !before;

      try {
        var res = await this.$pb.api(this.mappingUrl(mapping.id), {
          method: 'PATCH',
          body: {
            source: mapping.source,
            source_key: mapping.source_key,
            record_type: mapping.record_type,
            destination: mapping.destination,
            custom_field_id: mapping.custom_field_id,
            is_active: mapping.is_active
          }
        });
        Object.assign(mapping, res.mapping);
      } catch (e) {
        mapping.is_active = before;
        this.$pb.toast(this.$pb.firstError(e, 'Could not change that mapping.'), 'error');
      }
    },

    confirmRemoveMapping: function (mapping) {
      this.mapConfirm = { open: true, mapping: mapping };
    },

    removeMapping: async function () {
      var mapping = this.mapConfirm.mapping;
      this.mapConfirm = { open: false, mapping: null };

      if (!mapping) return;

      try {
        var res = await this.$pb.api(this.mappingUrl(mapping.id), { method: 'DELETE' });
        this.mappings = this.mappings.filter(function (m) { return m.id !== mapping.id; });
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not delete that mapping.'), 'error');
      }
    },

    /* The packaged defaults, for a Space that has none. Offered rather than applied at Space
       creation: a Space that never uses the feature should not carry seven rows it never asked
       for. */
    seedMappings: async function () {
      if (this.seeding) return;

      this.seeding = true;

      try {
        var res = await this.$pb.api(String(this.endpointTemplates.seed || ''), { method: 'POST' });
        this.mappings = res.mappings || [];
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not add the default mappings.'), 'error');
      } finally {
        this.seeding = false;
      }
    },

    /* Reprocess Existing Records (§14) — behind a confirmation, because it runs over every
       ticket in the Space. */
    runReprocess: async function () {
      this.reprocess.running = true;

      try {
        var res = await this.$pb.api(String(this.endpointTemplates.reprocess || ''), { method: 'POST' });
        this.reprocess.open = false;
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not start reprocessing.'), 'error');
      } finally {
        this.reprocess.running = false;
      }
    },

    /* ----- tags -------------------------------------------------------------------------
       A Space's own vocabulary. Rows, not a field, so they are created and deleted through
       their own endpoints rather than folded into the settings PATCH — which is also why the
       switch above them keeps saving exactly as the other five metadata switches do. */
    tagsUrl: function () { return String(this.endpointTemplates.tags || ''); },

    tagUrl: function (id) {
      return String(this.endpointTemplates.tag || '').replace('__TAG__', encodeURIComponent(id));
    },

    showTagForm: function () {
      this.tagForm = { open: !this.tagForm.open, name: '', error: '' };
    },

    addTag: async function () {
      var name = String(this.tagForm.name || '').trim();

      if (this.tagSaving || !name) return;

      // The list is already on screen, so the duplicate answer is too — asking the server would
      // be a round trip to learn what this page can already see. It is checked there as well,
      // because two open tabs can each be right about a list that is no longer current.
      var clash = this.tags.some(function (t) { return t.name.toLowerCase() === name.toLowerCase(); });

      if (clash) {
        this.tagForm.error = 'This Space already has a tag called that.';

        return;
      }

      this.tagSaving = true;
      this.tagForm.error = '';

      try {
        var res = await this.$pb.api(this.tagsUrl(), { method: 'POST', body: { name: name } });
        this.tags.push(res.tag);
        // Kept alphabetical, so a tag lands where somebody would look for it rather than at the
        // bottom until the next reload moves it.
        this.tags.sort(function (a, b) { return a.name.localeCompare(b.name); });
        this.tagForm = { open: false, name: '', error: '' };
        this.$pb.toast(res.message);
      } catch (e) {
        this.tagForm.error = this.$pb.firstError(e, 'Could not add that tag.');
      } finally {
        this.tagSaving = false;
      }
    },

    // Confirmed, like every other delete on these pages: a tag is a word a team agreed on, and
    // nothing on this screen would show that somebody had removed it.
    confirmRemoveTag: function (tag) {
      this.tagConfirm = { open: true, tag: tag };
    },

    removeTag: async function () {
      var tag = this.tagConfirm.tag;
      this.tagConfirm = { open: false, tag: null };

      if (!tag) return;

      try {
        var res = await this.$pb.api(this.tagUrl(tag.id), { method: 'DELETE' });
        this.tags = this.tags.filter(function (t) { return t.id !== tag.id; });
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not remove that tag.'), 'error');
      }
    },

    /* ----- the per-address test ---------------------------------------------------------
       Offered only while an address is unverified, because verification is what it is FOR: an
       address becomes Verified when a message forwarded from it reaches us, and a passing test
       is that message. Once a row is verified the button would prove something the badge
       already says.

       It sends the same probe as the Space Overview's card, aimed at one address, and polls the
       same way — the answer comes back through the customer's own mail provider and takes tens
       of seconds, which is too slow to hold a request open and too infrequent to justify a
       websocket. */
    testUrl: function (inboxId, addressId) {
      return String(this.endpointTemplates.addressTest || '')
        .replace('__ID__', encodeURIComponent(inboxId))
        .replace('__ADDRESS__', encodeURIComponent(addressId));
    },

    sendTest: async function (inbox, address) {
      if (this.test.starting || (this.testRunning && this.test.addressId === address.id)) return;

      this.stopWatching();
      this.test = { addressId: address.id, data: null, error: '', starting: true };

      try {
        var res = await this.$pb.api(this.testUrl(inbox.id, address.id), { method: 'POST' });
        this.test.data = res.test;
        this.applyAddress(inbox, res.address);
        if (this.testRunning) this.watchTest(inbox, address);
      } catch (e) {
        this.test.error = this.$pb.firstError(e, 'Could not send the test email.');
      } finally {
        this.test.starting = false;
      }
    },

    // Every 5s, matching the Overview card: often enough to feel live, rarely enough not to
    // hammer the server for the two minutes a probe is allowed to take.
    watchTest: function (inbox, address) {
      var self = this;
      this.stopWatching();
      this.testTimer = window.setInterval(function () { self.pollTest(inbox, address); }, 5000);
    },

    stopWatching: function () {
      if (this.testTimer) { window.clearInterval(this.testTimer); this.testTimer = null; }
    },

    pollTest: async function (inbox, address) {
      try {
        var res = await this.$pb.api(this.testUrl(inbox.id, address.id), { method: 'GET' });
        this.test.data = res.test;
        // A pass verifies the address server-side, so the row's badge is refreshed from the
        // response rather than left saying "Setup Required" beside a result that says it works.
        this.applyAddress(inbox, res.address);
        if (!this.testRunning) this.stopWatching();
      } catch (e) {
        this.stopWatching();
      }
    },

    /* Which Inbox's card shows the result: the one holding the address under test. An Inbox
       with no part in the current test renders nothing, so a Space with two Inboxes cannot show
       the same countdown twice. */
    testFor: function (inbox) {
      var id = this.test.addressId;
      if (!id) return false;

      return inbox.addresses.some(function (a) { return a.id === id; });
    },

    applyAddress: function (inbox, payload) {
      if (!payload) return;

      var row = inbox.addresses.filter(function (a) { return a.id === payload.id; })[0];
      if (row) Object.assign(row, payload);
    },

    clearTest: function () {
      this.stopWatching();
      this.test = { addressId: null, data: null, error: '', starting: false };
    },

    copyInbound: function (inbox) {
      var self = this;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inbox.inbound_address).then(function () {
          self.copiedId = inbox.id;
          window.setTimeout(function () { self.copiedId = null; }, 2000);
        }, function () {
          self.$pb.toast('Could not copy — select the address and copy it manually.', 'error');
        });
        return;
      }

      this.$pb.toast('Select the address and copy it manually.', 'error');
    },

    saveReassignment: function () {
      this.save({
        reassign_enabled: this.reEnabled,
        reassign_hours: Number(this.reHours) || 0,
        reassign_minutes: Number(this.reMinutes) || 0,
        reassign_destination: this.reDestination
      });
    }
  },

  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head :title="title" :desc="description"/>' +

    // ===== One switch: the six metadata sections, and Auto Follow on Mention ==============
    '<div v-if="isInstant">' +
    '<div class="border border-line rounded-xl">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink flex items-center gap-2">{{ toggleLabel }}' +
    '<span v-if="!available" class="text-[10px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5">Soon</span>' +
    '</div>' +
    // No description repeated here: pb-section-head above already carries it, and the same
    // sentence twice on one short page reads as a rendering mistake.
    '<p v-if="!available" class="text-[12px] text-faint mt-1">This setting is coming soon and cannot be enabled yet.</p>' +
    '<p v-else-if="!canManage" class="text-[12px] text-faint mt-1">You do not have permission to change this Space.</p>' +
    '</div>' +
    '<pb-toggle :model-value="enabled" :disabled="locked || saving" @update:model-value="toggleOne"/>' +
    '</div>' +

    // The Space's tag vocabulary, under the switch that governs it (P14). Only the Tag section
    // carries this; every other metadata page is the switch and nothing else.
    '<div v-if="manages === \'tags\' && enabled" class="border-t border-line px-4 py-3">' +
    '<div class="text-[12px] font-medium text-ink mb-1.5">Tags in this Space</div>' +

    // A table, the same one the Inbox panel's addresses use — a Space's tags are a list somebody
    // audits, and chips give a row nowhere to say anything about itself.
    '<table v-if="tags.length" class="w-full text-[13px]">' +
    '<thead><tr class="text-left text-[12px] text-faint border-b border-line">' +
    '<th class="py-2 font-medium">Tag Name</th>' +
    '<th class="py-2 font-medium">Created</th>' +
    '<th v-if="canManage" class="py-2 font-medium text-right">Action</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="t in tags" :key="t.id" class="border-b border-line last:border-0">' +
    '<td class="py-2 text-ink">{{ t.name }}</td>' +
    '<td class="py-2 text-sub">{{ t.created_at || \'—\' }}</td>' +
    '<td v-if="canManage" class="py-2 text-right">' +
    '<button type="button" @click="confirmRemoveTag(t)" ' +
    'class="_moretogether-iconbtn _moretogether-iconbtn--danger" data-tip="Delete tag" ' +
    ':aria-label="\'Delete \' + t.name" v-html="icon(\'trash-can\', 14)"></button>' +
    '</td>' +
    '</tr></tbody></table>' +
    '<p v-else class="text-[12px] text-faint">No tags yet. Add the words your team uses to sort conversations.</p>' +

    '<div v-if="canManage" class="mt-3">' +
    '<button v-if="!tagForm.open" type="button" @click="showTagForm" ' +
    'class="inline-flex items-center gap-1 text-[12px] font-semibold text-brand hover:underline">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add tag</button>' +
    '<div v-else class="flex flex-wrap items-start gap-2">' +
    '<input v-model="tagForm.name" maxlength="60" placeholder="Tag name" ' +
    'class="flex-1 min-w-[200px] max-w-[320px] h-9 px-3 rounded-md border border-stroke text-[13px] text-ink placeholder:text-faint" ' +
    '@keydown.enter.prevent="addTag"/>' +
    '<button type="button" @click="addTag" :disabled="tagSaving || !tagForm.name" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Add</button>' +
    '<button type="button" @click="showTagForm" ' +
    'class="inline-flex items-center h-9 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Cancel</button>' +
    '</div>' +
    '<p v-if="tagForm.error" class="mt-2 text-[12px] text-danger">{{ tagForm.error }}</p>' +
    '</div>' +
    '</div>' +

    /* The Company custom-field table that used to live here is GONE (P75).

       Settings → Company became Company & Customer — its own `kind`, with TWO field lists and
       a mapping table underneath, which is more than a metadata panel built around a single
       `enabled` boolean can carry. See the `company_customer` block further down. */

    '</div>' +
    '<p v-if="note" class="text-[12px] text-sub mt-3">{{ note }}</p>' +

    '<pb-confirm v-if="manages === \'tags\'" :open="tagConfirm.open" title="Delete this tag?" ' +
    ':message="(tagConfirm.tag ? tagConfirm.tag.name : \'\') + \' will no longer be available in this Space. This cannot be undone.\'" ' +
    'confirm-label="Delete tag" @close="tagConfirm.open = false" @confirm="removeTag"/>' +
    '</div>' +

    // ===== Company & Customer (P75 §2-§4) =================================================
    //
    // THREE stacked cards, in the order the requirement introduces them: the switches that say
    // what this Space tracks, the custom fields those switches turn on, then the mapping table
    // that fills both from an incoming request.
    //
    // Everything below the first card is drawn only while the master switch is on. Not merely
    // disabled — a page of greyed controls for a module that is off asks the reader to work out
    // which of them would matter if it were.
    '<div v-else-if="kind === \'company_customer\'">' +

    // ---- card one: what this Space tracks ----
    '<div class="border border-line rounded-xl">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-4" v-if="companyMaster">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-semibold text-head">{{ companyMaster.label }}</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ companyMaster.help }}</p>' +
    '</div>' +
    '<pb-toggle :model-value="companyMaster.enabled" :disabled="!canManage || featureSaving === companyMaster.key" ' +
    '@update:model-value="toggleFeature(companyMaster)" />' +
    '</div>' +

    // The four sub-switches, under the master and only while it is on.
    '<div v-if="companyOn" class="border-t border-line divide-y divide-line">' +
    '<div v-for="f in subFeatures" :key="f.key" class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[13px] font-medium text-ink">{{ f.label }}</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ f.help }}</p>' +
    '</div>' +
    '<pb-toggle :model-value="f.enabled" :disabled="!canManage || !f.available || featureSaving === f.key" ' +
    '@update:model-value="toggleFeature(f)" />' +
    '</div>' +
    '</div>' +
    '</div>' +

    // ---- card two: the two custom-field lists ----
    //
    // One markup block, looped. The Customer list and the Company list are the same table over
    // two arrays, and writing them out twice would be two places to fix the next thing either
    // of them gets wrong.
    '<template v-if="companyOn">' +
    '<div v-for="sec in fieldSections" :key="sec.kind" v-show="sec.on" class="border border-line rounded-xl mt-4 px-4 py-4">' +
    '<div class="flex items-start justify-between gap-4">' +
    '<div class="min-w-0">' +
    '<div class="text-[13px] font-medium text-ink">{{ sec.title }}</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ sec.help }}</p>' +
    '</div>' +
    '<button v-if="canManage" type="button" @click="newField(sec.kind)" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 shrink-0 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add New Field</button>' +
    '</div>' +

    '<table v-if="sec.fields.length" class="w-full text-[13px] mt-3">' +
    '<thead><tr class="text-left text-[12px] text-faint border-b border-line">' +
    '<th class="py-2 font-medium">Field Name</th>' +
    '<th class="py-2 font-medium">Field Type</th>' +
    '<th class="py-2 font-medium">Required</th>' +
    '<th class="py-2 font-medium">Status</th>' +
    '<th v-if="canManage" class="py-2 font-medium text-right">Actions</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="f in sec.fields" :key="f.id" class="border-b border-line last:border-0">' +
    '<td class="py-2 text-ink">{{ f.name }}</td>' +
    '<td class="py-2 text-sub">{{ f.type_label }}</td>' +
    '<td class="py-2 text-sub">{{ f.is_required ? \'Yes\' : \'No\' }}</td>' +
    '<td class="py-2">' +
    '<span :class="[\'_moretogether-badge\', f.is_active ? \'_moretogether-badge--ok\' : \'_moretogether-badge--off\']">' +
    // The STATE reads Active / Inactive, matching the modal's field. The row's action still
    // says Disable / Enable, because that is the verb — the two are not the same word.
    '{{ f.is_active ? \'Active\' : \'Inactive\' }}</span></td>' +
    '<td v-if="canManage" class="py-2">' +
    '<div class="flex items-center justify-end gap-1">' +
    '<button type="button" @click="editField(sec.kind, f)" class="_moretogether-iconbtn" data-tip="Edit field" ' +
    ':aria-label="\'Edit \' + f.name" v-html="icon(\'pen-line\', 13)"></button>' +
    // Disable, not delete: the question stops being asked, the answers already given stay.
    '<button type="button" @click="toggleField(sec.kind, f)" class="_moretogether-iconbtn" ' +
    ':data-tip="f.is_active ? \'Disable field\' : \'Enable field\'" ' +
    ':aria-label="(f.is_active ? \'Disable \' : \'Enable \') + f.name" ' +
    'v-html="icon(f.is_active ? \'circle-slash\' : \'check\', 13)"></button>' +
    '<button type="button" @click="confirmRemoveField(sec.kind, f)" class="_moretogether-iconbtn _moretogether-iconbtn--danger" ' +
    'data-tip="Delete field" :aria-label="\'Delete \' + f.name" v-html="icon(\'trash-can\', 13)"></button>' +
    '</div></td>' +
    '</tr></tbody></table>' +
    '<p v-else class="text-[12px] text-faint mt-3">No custom fields yet.</p>' +
    '</div>' +
    '</template>' +

    // ---- the Add / Edit Field modal ----
    '<pb-modal v-if="kind === \'company_customer\'" :open="fieldForm.open" ' +
    ':title="fieldForm.editing ? \'Edit field\' : \'Add new field\'" width="max-w-[560px]" @close="closeFieldForm">' +
    '<div class="space-y-4">' +

    '<div>' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5" for="hc-field-name">Field Name</label>' +
    '<input id="hc-field-name" v-model="fieldForm.name" maxlength="60" placeholder="Industry" ' +
    'class="pb-input !h-9 w-full" @keydown.enter.prevent="saveField" />' +
    '</div>' +

    // Two radios rather than a switch: "Required" is a question with two named answers on a
    // form, and the spec names them Yes and No.
    '<div>' +
    '<span class="block text-[12px] font-medium text-ink mb-1.5">Required</span>' +
    '<div class="flex items-center gap-4">' +
    '<label class="inline-flex items-center gap-2 text-[13px] text-ink cursor-pointer">' +
    '<input type="radio" :checked="fieldForm.is_required === true" @change="fieldForm.is_required = true" ' +
    'name="hc-field-required" class="accent-brand" /> Yes</label>' +
    '<label class="inline-flex items-center gap-2 text-[13px] text-ink cursor-pointer">' +
    '<input type="radio" :checked="fieldForm.is_required === false" @change="fieldForm.is_required = false" ' +
    'name="hc-field-required" class="accent-brand" /> No</label>' +
    '</div></div>' +

    // The same switch the row's Disable flips, so a field can be created inactive and its
    // status changed without leaving the modal you are already editing it in.
    '<div>' +
    '<span class="block text-[12px] font-medium text-ink mb-1.5">Status</span>' +
    '<div class="flex items-center gap-4">' +
    '<label class="inline-flex items-center gap-2 text-[13px] text-ink cursor-pointer">' +
    '<input type="radio" :checked="fieldForm.is_active === true" @change="fieldForm.is_active = true" ' +
    'name="hc-field-status" class="accent-brand" /> Active</label>' +
    '<label class="inline-flex items-center gap-2 text-[13px] text-ink cursor-pointer">' +
    '<input type="radio" :checked="fieldForm.is_active === false" @change="fieldForm.is_active = false" ' +
    'name="hc-field-status" class="accent-brand" /> Inactive</label>' +
    '</div>' +
    '<p v-if="!fieldForm.is_active" class="text-[12px] text-faint mt-1">An inactive field is not asked on Company forms. Values already recorded against it are kept.</p>' +
    '</div>' +

    '<div>' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5">Field Type</label>' +
    '<pb-combo v-model="fieldForm.type" :options="fieldTypes" :searchable="false" :dense="true" />' +
    '</div>' +

    /* Only the four choice types (P18). The section appears the moment the type changes, and
       the values survive a move between two types that both take them — switching Dropdown to
       Radio should not mean retyping three answers. */
    '<div v-if="fieldTypeNeedsOptions">' +
    '<span class="block text-[12px] font-medium text-ink mb-1.5">Options</span>' +
    '<div v-if="fieldForm.options.length" class="space-y-2">' +
    '<div v-for="(o, i) in fieldForm.options" :key="i">' +
    '<div class="flex items-center gap-1.5">' +
    '<input v-model="fieldForm.options[i]" :maxlength="optionMaxLength" placeholder="Technology" ' +
    'class="pb-input !h-9 flex-1" />' +
    '<button type="button" @click="moveOption(i, -1)" :disabled="i === 0" aria-label="Move option up" ' +
    'class="_moretogether-iconbtn disabled:opacity-40 disabled:cursor-not-allowed" v-html="icon(\'arrow-up\', 12)"></button>' +
    '<button type="button" @click="moveOption(i, 1)" :disabled="i === fieldForm.options.length - 1" ' +
    'aria-label="Move option down" ' +
    'class="_moretogether-iconbtn disabled:opacity-40 disabled:cursor-not-allowed" v-html="icon(\'arrow-down\', 12)"></button>' +
    '<button type="button" @click="removeOption(i)" aria-label="Remove option" ' +
    'class="_moretogether-iconbtn _moretogether-iconbtn--danger" v-html="icon(\'xmark\', 12)"></button>' +
    '</div>' +
    '<p v-if="fieldForm.optionErrors[i]" class="mt-1 text-[12px] text-danger">{{ fieldForm.optionErrors[i] }}</p>' +
    '</div></div>' +
    '<p v-else class="text-[12px] text-faint">No options yet.</p>' +
    '<button type="button" @click="addOption" :disabled="fieldForm.options.length >= optionMax" ' +
    'class="mt-2 inline-flex items-center gap-1 text-[12px] font-semibold text-brand hover:underline disabled:opacity-50 disabled:no-underline">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add Option</button>' +
    '</div>' +

    '<p v-if="fieldForm.error" class="text-[12px] text-danger">{{ fieldForm.error }}</p>' +
    '</div>' +
    '<template #footer>' +
    '<button type="button" @click="closeFieldForm" ' +
    'class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveField" :disabled="fieldForm.saving" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ fieldForm.saving ? \'Saving…\' : (fieldForm.editing ? \'Save Changes\' : \'Create Field\') }}</button>' +
    '</template></pb-modal>' +

    // Deleting the QUESTION and its answers.
    //
    // P18's version deliberately left the answers alone, because nothing stored them yet. P75
    // gave them a table, and leaving them now would mean rows keyed to a field that resolves to
    // nothing. The message says so rather than implying the field goes quietly.
    '<pb-confirm v-if="kind === \'company_customer\'" :open="fieldConfirm.open" title="Delete custom field?" ' +
    ':message="\'Deleting this field removes it from every form and profile, and deletes the values already recorded against it. Mappings that write to it are kept so you can point them somewhere else.\'" ' +
    'confirm-label="Delete Field" @close="fieldConfirm.open = false" @confirm="removeField"/>' +

    // ---- card three: Ticket Metadata Mapping (§3-§4) ----
    '<div v-if="companyOn && featureOn.ticket_metadata_mapping" class="border border-line rounded-xl mt-4 px-4 py-4">' +
    '<div class="flex items-start justify-between gap-4">' +
    '<div class="min-w-0">' +
    '<div class="text-[13px] font-medium text-ink">Ticket Metadata Mapping</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Decide which field on an incoming request fills which field on the customer and their company.</p>' +
    '</div>' +
    '<div class="flex items-center gap-2 shrink-0">' +
    // Reprocess is offered only once there is something to reprocess WITH — a run against an
    // empty mapping table is a queued job that does nothing.
    '<button v-if="canManage && mappings.length" type="button" @click="reprocess.open = true" ' +
    'class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover">' +
    'Reprocess Existing Records</button>' +
    '<button v-if="canManage" type="button" @click="newMapping" :disabled="mappingsFull" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add Mapping</button>' +
    '</div>' +
    '</div>' +

    '<table v-if="mappings.length" class="w-full text-[13px] mt-3">' +
    '<thead><tr class="text-left text-[12px] text-faint border-b border-line">' +
    '<th class="py-2 font-medium">Incoming Field</th>' +
    '<th class="py-2 font-medium">Map To</th>' +
    '<th class="py-2 font-medium">Destination Field</th>' +
    '<th class="py-2 font-medium">Status</th>' +
    '<th v-if="canManage" class="py-2 font-medium text-right">Actions</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="m in mappings" :key="m.id" class="border-b border-line last:border-0">' +
    '<td class="py-2 text-ink">{{ m.source_label }}</td>' +
    '<td class="py-2 text-sub">{{ m.record_type_label }}</td>' +
    // A destination whose custom field has been deleted reads as deleted rather than as blank:
    // the row is still a rule, and the reader needs to see that it points at nothing.
    '<td class="py-2" :class="m.destination_label === \'Custom Field (deleted)\' ? \'text-danger\' : \'text-sub\'">{{ m.destination_label }}</td>' +
    '<td class="py-2">' +
    '<span :class="[\'_moretogether-badge\', m.is_active ? \'_moretogether-badge--ok\' : \'_moretogether-badge--off\']">' +
    '{{ m.is_active ? \'Active\' : \'Inactive\' }}</span></td>' +
    '<td v-if="canManage" class="py-2">' +
    '<div class="flex items-center justify-end gap-1">' +
    '<button type="button" @click="editMapping(m)" class="_moretogether-iconbtn" data-tip="Edit mapping" ' +
    ':aria-label="\'Edit \' + m.source_label" v-html="icon(\'pen-line\', 13)"></button>' +
    '<button type="button" @click="toggleMapping(m)" class="_moretogether-iconbtn" ' +
    ':data-tip="m.is_active ? \'Disable mapping\' : \'Enable mapping\'" ' +
    ':aria-label="(m.is_active ? \'Disable \' : \'Enable \') + m.source_label" ' +
    'v-html="icon(m.is_active ? \'circle-slash\' : \'check\', 13)"></button>' +
    '<button type="button" @click="confirmRemoveMapping(m)" class="_moretogether-iconbtn _moretogether-iconbtn--danger" ' +
    'data-tip="Delete mapping" :aria-label="\'Delete \' + m.source_label" v-html="icon(\'trash-can\', 13)"></button>' +
    '</div></td>' +
    '</tr></tbody></table>' +

    // The empty state offers the packaged defaults rather than only an Add button: everything in
    // that list is something an email already carries, and asking somebody to re-derive it from
    // scratch is asking them to guess what this product calls a sender address.
    '<div v-else class="mt-3 rounded-lg border border-dashed border-stroke px-4 py-6 text-center">' +
    '<p class="text-[13px] text-ink font-medium">No mappings yet</p>' +
    '<p class="text-[12px] text-sub mt-1 max-w-[420px] mx-auto">Until a mapping exists, this Space still reads the sender address and name from an incoming email. Add the recommended set to map the rest.</p>' +
    '<button v-if="canManage" type="button" @click="seedMappings" :disabled="seeding" ' +
    'class="inline-flex items-center h-9 px-4 mt-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ seeding ? \'Adding…\' : \'Add Recommended Mappings\' }}</button>' +
    '</div>' +

    '<p v-if="mappingsFull" class="text-[12px] text-danger mt-2">This Space has reached the maximum of {{ mappingMax }} mappings.</p>' +
    '</div>' +

    // ---- the Add / Edit Mapping modal (§4) ----
    //
    // The requirement's three dropdowns, in its own order: Source Field, Record Type,
    // Destination Field. Record Type is in the middle because it is what decides the third.
    '<pb-modal :open="mapForm.open" :title="mapForm.editing ? \'Edit mapping\' : \'Add mapping\'" ' +
    'width="max-w-[560px]" @close="mapForm.open = false">' +
    '<div class="space-y-4">' +

    '<div>' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5" for="hc-map-source">Source Field</label>' +
    '<select id="hc-map-source" v-model="mapForm.source" class="pb-input !h-9 w-full">' +
    '<option value="">Choose a field…</option>' +
    '<option v-for="s in mappingSources" :key="s.key" :value="s.key">{{ s.label }}</option>' +
    '</select>' +
    '</div>' +

    // Only for Custom / Integration Field, which is the one source whose key this product
    // cannot know — it is whatever the sending system calls it.
    '<div v-if="sourceIsNamed(mapForm.source)">' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5" for="hc-map-key">Field name in the incoming payload</label>' +
    '<input id="hc-map-key" v-model="mapForm.source_key" maxlength="80" placeholder="order_number" ' +
    'class="pb-input !h-9 w-full" />' +
    '<p class="text-[12px] text-faint mt-1">Case and separators are ignored, so <span class="font-mono">Order Number</span> and <span class="font-mono">order_number</span> are the same field.</p>' +
    '</div>' +

    '<div>' +
    '<span class="block text-[12px] font-medium text-ink mb-1.5">Record Type</span>' +
    '<div class="flex items-center gap-4">' +
    '<label v-for="t in mappingRecordTypes" :key="t.key" class="inline-flex items-center gap-2 text-[13px] text-ink cursor-pointer">' +
    '<input type="radio" name="hc-map-type" class="accent-brand" :checked="mapForm.record_type === t.key" ' +
    '@change="mapForm.record_type = t.key; onRecordTypeChange()" /> {{ t.label }}</label>' +
    '</div></div>' +

    '<div>' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5" for="hc-map-dest">Destination Field</label>' +
    '<select id="hc-map-dest" :value="mapDestinationValue" @change="onDestinationChange($event.target.value)" ' +
    'class="pb-input !h-9 w-full">' +
    '<option value="">Choose a field…</option>' +
    '<option v-for="d in mapDestinations" :key="destinationValue(d)" :value="destinationValue(d)">{{ d.label }}</option>' +
    '</select>' +
    '<p class="text-[12px] text-faint mt-1">Custom fields appear here once they are added above.</p>' +
    '</div>' +

    '<p v-if="mapForm.error" class="text-[12px] text-danger">{{ mapForm.error }}</p>' +
    '</div>' +
    '<template #footer>' +
    '<button type="button" @click="mapForm.open = false" ' +
    'class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveMapping" :disabled="mapForm.saving" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ mapForm.saving ? \'Saving…\' : (mapForm.editing ? \'Save Changes\' : \'Add Mapping\') }}</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="mapConfirm.open" title="Delete this mapping?" ' +
    'message="New requests will stop filling that field. Customer and company records already written are not changed." ' +
    'confirm-label="Delete mapping" @close="mapConfirm.open = false" @confirm="removeMapping"/>' +

    // Reprocess (§14). Its own modal rather than a pb-confirm, because the requirement asks for
    // a confirmation that explains what a bulk update will and will not do — and that is more
    // than one sentence.
    '<pb-modal :open="reprocess.open" title="Reprocess existing records?" width="max-w-[520px]" ' +
    '@close="reprocess.open = false">' +
    '<p class="text-[13px] text-ink">Every request in this Space will be run through the current mappings again.</p>' +
    '<ul class="mt-3 space-y-1.5 text-[13px] text-sub list-disc pl-5">' +
    '<li>Customers and companies that were never linked will be matched or created.</li>' +
    '<li>Fields that are still empty will be filled.</li>' +
    '<li><span class="font-semibold text-ink">Nothing already filled in is overwritten</span> — including anything typed by hand.</li>' +
    '</ul>' +
    '<p class="mt-3 text-[12px] text-faint">This runs in the background and may take a few minutes on a busy Space.</p>' +
    '<template #footer>' +
    '<button type="button" @click="reprocess.open = false" ' +
    'class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="runReprocess" :disabled="reprocess.running" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ reprocess.running ? \'Starting…\' : \'Reprocess Records\' }}</button>' +
    '</template></pb-modal>' +

    '</div>' +

    // ===== Rating / CSAT (P56, laid out per P57) ===========================================
    //
    // TWO cards. The first is only ever the switch; the second is everything the switch turns on,
    // in two columns with a live preview beside it.
    //
    // The split is the point: "is this on?" and "how should it behave?" are different questions,
    // and putting the answer to the first inside a column of twenty answers to the second made
    // the one that matters most the easiest to miss.
    '<div v-else-if="kind === \'rating\'">' +
    '<div v-if="rating">' +

    // ---- card one: the switch, and nothing else ----
    '<div class="flex items-center justify-between gap-4 border border-line rounded-xl px-4 py-4">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink">Enable Customer Satisfaction</div>' +
    '<p class="text-[12px] text-sub mt-0.5">When on, customers are emailed a rating request once a ticket reaches the trigger below.</p>' +
    '</div>' +
    /* Saved the MOMENT it is flipped, like every other single switch on this screen (see
       `isInstant`). It has to be: the Save button lives in the second card, and that card is gone
       the instant this goes off — a toggle whose only Save disappears when you use it is a toggle
       that silently does nothing. */
    '<pb-toggle :model-value="rating.enabled" :disabled="!canManage || saving" @update:model-value="setRatingEnabled($event)"/>' +
    '</div>' +

    // ---- the configuration: one card per section, stacked (P59) ----
    //
    // NOT one card with everything in it. Five separate cards, in the order the requirement
    // lists them, because they are five separate decisions — "how does it look", "when does it
    // go", "how long does it live", "what happens on a bad one", "what does it say". A single
    // card made them read as one long form whose end you had to scroll to find.
    //
    // The preview stays beside them rather than joining the stack: it is not a sixth decision,
    // it is what the other five look like, and it has to be on screen while they are changed.
    '<div v-if="rating.enabled" class="mt-5 flex flex-col lg:flex-row gap-5">' +

    // left: the five cards
    '<div class="flex-1 min-w-0 space-y-5">' +

    // ---- Rating Experience ----
    '<section class="border border-line rounded-xl overflow-hidden">' +
    '<div class="px-4 py-3 border-b border-line">' +
    '<h3 class="text-[13px] font-semibold text-head">Rating experience</h3></div>' +
    '<div class="divide-y divide-line">' +

    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Rating type</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    '<pb-combo v-model="rating.rating_type" :options="ratingTypes" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    // The normalisation rule, said out loud. Somebody switching a Space from stars to thumbs
    // deserves to know their historical averages still mean something.
    '<p class="text-[12px] text-faint mt-1">Whatever the customer sees, every answer is stored as a score out of 5 so reporting stays comparable.</p>' +
    '</div>' +

    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Rating labels</label>' +
    '<p class="text-[12px] text-faint mb-2">Renaming a label does not change the score it sits on.</p>' +
    // ONE ROW PER SCORE, full width (P58).
    //
    // Two columns paired 1 with 2 and 3 with 4, which read as a mapping between them rather than
    // as one ordered scale — and left 5 alone on its own row, which made the odd one out look
    // like a different kind of thing. A single column is the scale, in the order it is scored.
    '<div class="space-y-2">' +
    '<div v-for="n in [1,2,3,4,5]" :key="n" class="flex items-center gap-2">' +
    '<span class="w-4 shrink-0 text-[12px] text-faint tabular-nums">{{ n }}</span>' +
    '<input v-model="rating.labels[n]" maxlength="60" :disabled="!canManage" class="pb-input !h-8 flex-1" />' +
    '</div>' +
    '</div>' +
    '</div>' +

    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<span class="text-[13px] text-ink">Allow customer comment</span>' +
    '<pb-toggle v-model="rating.allow_comment" :disabled="!canManage"/>' +
    '</div>' +

    '<div v-if="rating.allow_comment" class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Comment requirement</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    '<pb-combo v-model="rating.comment_requirement" :options="ratingRequirements" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '</div>' +
    '</section>' +

    // ---- Trigger ----
    '<section class="border border-line rounded-xl overflow-hidden">' +
    '<div class="px-4 py-3 border-b border-line">' +
    '<h3 class="text-[13px] font-semibold text-head">Trigger</h3></div>' +
    '<div class="divide-y divide-line">' +
    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Send rating request when</label>' +
    '<div class="w-full sm:max-w-[320px]">' +
    '<pb-combo v-model="rating.trigger" :options="ratingTriggers" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '<div v-if="rating.trigger === \'status\'" class="mt-2">' +
    '<div class="w-full sm:max-w-[320px]">' +
    '<pb-combo :model-value="rating.trigger_status_id === null ? \'\' : String(rating.trigger_status_id)" ' +
    '@update:model-value="setRatingNumber(\'trigger_status_id\', $event, true)" ' +
    ':options="ratingStatusOptions" placeholder="Choose a status…" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '<p v-if="rating.trigger === \'manual\'" class="text-[12px] text-faint mt-1">Nothing is sent automatically; an agent asks from the ticket.</p>' +
    '</div>' +

    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Send after</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    // Custom is the same field, so it is an option rather than a second control — built by
    // `ratingDelayOptions`, which appends one when the number does not match a preset.
    '<pb-combo :model-value="String(rating.delay_minutes)" ' +
    '@update:model-value="setRatingNumber(\'delay_minutes\', $event)" ' +
    ':options="ratingDelayOptions" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '<div class="flex items-center gap-2 mt-2">' +
    '<input type="number" min="0" max="525600" :value="rating.delay_minutes" ' +
    '@input="rating.delay_minutes = Math.max(0, Number($event.target.value) || 0)" ' +
    ':disabled="!canManage" class="pb-input !h-8 w-28" />' +
    '<span class="text-[12px] text-faint">minutes</span>' +
    '</div>' +
    '<p class="text-[12px] text-faint mt-1">If the ticket reopens before this elapses, the pending request is cancelled.</p>' +
    '</div>' +
    '</div>' +
    '</section>' +

    // ---- Reminder & expiration ----
    '<section class="border border-line rounded-xl overflow-hidden">' +
    '<div class="px-4 py-3 border-b border-line">' +
    '<h3 class="text-[13px] font-semibold text-head">Reminder &amp; expiration</h3></div>' +
    '<div class="divide-y divide-line">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<span class="text-[13px] text-ink">Send a reminder if there is no answer</span>' +
    '<pb-toggle v-model="rating.reminder_enabled" :disabled="!canManage"/>' +
    '</div>' +
    '<div v-if="rating.reminder_enabled" class="px-4 py-3 grid gap-3 sm:grid-cols-2">' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Remind after (days)</label>' +
    '<input type="number" min="1" max="30" v-model.number="rating.reminder_after_days" ' +
    ':disabled="!canManage" class="pb-input !h-8 w-full" /></div>' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Maximum reminders</label>' +
    '<pb-combo :model-value="String(rating.reminder_max)" ' +
    '@update:model-value="setRatingNumber(\'reminder_max\', $event)" ' +
    ':options="ratingReminderMaxOptions" :searchable="false" :dense="true" :disabled="!canManage" /></div>' +
    '</div>' +
    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Rating link expires after</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    // `never` is the empty case here, and `setRatingNumber` reads it as null.
    '<pb-combo :model-value="rating.expires_days === null ? \'never\' : String(rating.expires_days)" ' +
    '@update:model-value="setRatingNumber(\'expires_days\', $event, true)" ' +
    ':options="ratingExpiryOptions" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0"><span class="text-[13px] text-ink">Allow the customer to change their rating</span>' +
    '<p class="text-[12px] text-faint">Off means the first answer is final.</p></div>' +
    '<pb-toggle v-model="rating.allow_change" :disabled="!canManage"/>' +
    '</div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0"><span class="text-[13px] text-ink">Ask again after a ticket reopens</span>' +
    '<p class="text-[12px] text-faint">Off means one survey per ticket, however many times it is resolved.</p></div>' +
    '<pb-toggle v-model="rating.rerequest_after_reopen" :disabled="!canManage"/>' +
    '</div>' +
    '</div>' +
    '</section>' +

    // ---- Negative feedback ----
    '<section class="border border-line rounded-xl overflow-hidden">' +
    '<div class="px-4 py-3 border-b border-line">' +
    '<h3 class="text-[13px] font-semibold text-head">Negative feedback</h3></div>' +
    '<div class="divide-y divide-line">' +
    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">A rating is low at or below</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    '<pb-combo :model-value="String(rating.low_threshold)" ' +
    '@update:model-value="setRatingNumber(\'low_threshold\', $event)" ' +
    ':options="ratingThresholdOptions" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<span class="text-[13px] text-ink">Notify the assigned agent</span>' +
    '<pb-toggle v-model="rating.low_notify_agent" :disabled="!canManage"/></div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<span class="text-[13px] text-ink">Notify the Space lead</span>' +
    '<pb-toggle v-model="rating.low_notify_admin" :disabled="!canManage"/></div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<span class="text-[13px] text-ink">Add an internal note to the ticket</span>' +
    '<pb-toggle v-model="rating.low_internal_note" :disabled="!canManage"/></div>' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0"><span class="text-[13px] text-ink">Reopen the ticket</span>' +
    '<p class="text-[12px] text-faint">Off by default — a low score is not always a request to continue.</p></div>' +
    '<pb-toggle v-model="rating.low_reopen" :disabled="!canManage"/></div>' +
    '<div class="px-4 py-3">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Add tag</label>' +
    '<div class="w-full sm:max-w-[280px]">' +
    '<pb-combo :model-value="rating.low_tag_id === null ? \'\' : String(rating.low_tag_id)" ' +
    '@update:model-value="setRatingNumber(\'low_tag_id\', $event, true)" ' +
    ':options="ratingTagOptions" :searchable="false" :disabled="!canManage" />' +
    '</div>' +
    '<p v-if="!ratingTags.length" class="text-[12px] text-faint mt-1">This Space has no tags yet — add them on Settings › Tag.</p>' +
    '</div>' +
    '</div>' +
    '</section>' +

    // ---- Email template ----
    '<section class="border border-line rounded-xl overflow-hidden">' +
    '<div class="px-4 py-3 border-b border-line">' +
    '<h3 class="text-[13px] font-semibold text-head">Email</h3></div>' +
    '<div class="px-4 py-3">' +
    // The template's own state, on the card — the requirement asks this to update the moment the
    // modal saves, and it does because both read the one `templates` entry.
    '<div v-if="ratingEmailTemplate()" class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[13px] font-medium text-ink">{{ ratingEmailTemplate().name }}</span>' +
    '<span :class="[\'inline-flex items-center h-5 px-2 rounded-full text-[11px] font-semibold\', ratingEmailTemplate().enabled ? \'bg-brand/10 text-brand\' : \'bg-hover text-faint\']">' +
    '{{ ratingEmailTemplate().enabled ? \'Enabled\' : \'Disabled\' }}</span>' +
    '<span class="text-[11px] text-faint">{{ ratingEmailTemplate().custom ? \'Customised\' : \'Default\' }}</span>' +
    '</div>' +
    '<p v-if="ratingEmailTemplate() && !ratingEmailTemplate().enabled" class="text-[12px] text-sub mt-1">' +
    'While this is off, no rating request email is sent — requests are still raised and will go out if it is switched back on.</p>' +
    '<p v-else class="text-[12px] text-sub mt-1">The wording of the request customers receive.</p>' +
    '<div class="flex flex-wrap items-center gap-2 mt-2">' +
    '<button type="button" @click="openRatingEmail" ' +
    'class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    'Edit rating email</button>' +
    // Kept as a link out, for somebody who wants the other three templates too.
    '<a :href="ratingTemplateUrl" class="text-[12px] text-sub hover:underline">All email templates</a>' +
    '</div>' +
    '</div>' +
    '</section>' +

    // ---- the rating email modal (P60) ----
    //
    // `pb-modal` at 720px, wide enough for a rich-text editor to be usable — the same editor the
    // Email Template page mounts, on the same draft, saving through the same endpoint. It is one
    // template record with two doors onto it, never a copy.
    '<pb-modal :open="ratingEmailOpen" title="Rating request email" width="max-w-[720px]" @close="closeRatingEmail">' +
    '<div v-if="draft" class="space-y-4">' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-lg px-3 py-2.5">' +
    '<div class="min-w-0">' +
    '<span class="text-[13px] text-ink">Enable rating email</span>' +
    '<p class="text-[12px] text-faint">Off means no rating request is emailed.</p></div>' +
    '<pb-toggle v-model="draft.enabled" :disabled="!canManage"/>' +
    '</div>' +

    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Template name</label>' +
    '<input v-model="draft.name" maxlength="120" :disabled="!canManage" class="pb-input w-full" />' +
    '</div>' +

    '<div>' +
    '<div class="flex items-center justify-between gap-2 mb-1">' +
    '<label class="text-[12px] font-semibold text-ink">Email subject</label>' +
    // An ACTION menu, not a value picker: bound to a permanently empty value so the button keeps
    // reading "Insert Variable" rather than adopting whatever was inserted last.
    '<div class="w-[180px]">' +
    '<pb-combo :model-value="\'\'" @update:model-value="insertVariable($event, \'subject\')" ' +
    ':options="variableOptions" placeholder="Insert Variable" :searchable="false" :dense="true" ' +
    ':disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '<input ref="subjectInput" v-model="draft.subject" maxlength="255" :disabled="!canManage" class="pb-input w-full" />' +
    '</div>' +

    '<div>' +
    '<div class="flex items-center justify-between gap-2 mb-1">' +
    '<label class="text-[12px] font-semibold text-ink">Email content</label>' +
    // An ACTION menu, not a value picker: bound to a permanently empty value so the button keeps
    // reading "Insert Variable" rather than adopting whatever was inserted last.
    '<div class="w-[180px]">' +
    '<pb-combo :model-value="\'\'" @update:model-value="insertVariable($event, \'body\')" ' +
    ':options="variableOptions" placeholder="Insert Variable" :searchable="false" :dense="true" ' +
    ':disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    // `:key` on the open flag, not the type: this editor mounts inside a modal that is destroyed
    // and rebuilt, and Jodit does not swap its document when the model changes underneath it.
    '<pg-editor v-if="useEditor" :key="\'rating-email\'" v-model="draft.body" min-height="200px" ' +
    ':document-view="false" :buttons="editorButtons" :license="editorLicense" :disabled="!canManage" />' +
    '<textarea v-else v-model="draft.body" rows="10" :disabled="!canManage" ' +
    'class="pb-input w-full !h-auto font-mono text-[12px]"></textarea>' +
    '</div>' +

    '<div class="rounded-lg border border-line bg-hover px-3 py-2.5">' +
    '<div class="text-[12px] font-semibold text-ink mb-1.5">Available variables</div>' +
    '<div class="flex flex-wrap gap-x-4 gap-y-1">' +
    '<span v-for="v in availableVariables" :key="v.tag" class="text-[12px] text-sub">' +
    '<code class="text-ink" v-text="tagToken(v.tag)"></code> — {{ v.label }}</span>' +
    '</div>' +
    '</div>' +

    '<div v-if="preview" class="rounded-lg border border-line overflow-hidden">' +
    '<div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-line bg-hover">' +
    '<div class="min-w-0">' +
    '<div class="text-[11px] text-faint">Subject</div>' +
    '<div class="text-[13px] text-ink truncate">{{ preview.subject || \'(no subject)\' }}</div></div>' +
    '<button type="button" @click="preview = null" class="text-[12px] text-sub hover:underline shrink-0">Close</button>' +
    '</div>' +
    '<p class="px-3 pt-2 text-[11px] text-faint">Rendered with sample ticket details and your own signature.</p>' +
    '<div class="px-3 py-3 max-h-[300px] overflow-y-auto text-[13px] wi-rich" v-html="preview.html"></div>' +
    '</div>' +
    '</div>' +

    '<template #footer>' +
    '<button type="button" @click="closeRatingEmail" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="previewTemplate" :disabled="previewBusy" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    '{{ previewBusy ? \'Building…\' : \'Preview Email\' }}</button>' +
    '<button type="button" @click="saveRatingEmail" :disabled="!canManage || saving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save Changes\' }}</button>' +
    '</template>' +
    '</pb-modal>' +


    '<div v-if="canManage" class="pt-1">' +
    '<button type="button" @click="saveRating" :disabled="saving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save changes\' }}</button>' +
    '</div>' +
    '</div>' +

    // right: the live preview, its own column beside the stack.
    //
    // `lg:w-[340px]`, not `lg:w-[360px]` — the built stylesheet holds only the arbitrary values
    // Tailwind found while scanning, and this file is not scanned.
    //
    // `sticky top-4` unprefixed: `lg:sticky` is not in the stylesheet either, and sticky costs
    // nothing on a narrow screen where this column is stacked underneath anyway. It matters more
    // now than it did — with five cards to scroll through, a preview pinned in view is the only
    // way a change to the last one can still be seen taking effect.
    '<div class="lg:w-[340px] shrink-0">' +
    '<div class="sticky top-4">' +
    '<h3 class="text-[13px] font-semibold text-head mb-2">Customer preview</h3>' +

    '<div class="rounded-xl border border-line bg-white px-4 py-5">' +
    '<p class="text-[15px] font-semibold text-head">How was your support experience?</p>' +
    '<div class="mt-4 flex flex-wrap items-end gap-1.5">' +
    '<button v-for="i in ratingPoints" :key="i" type="button" @click="previewScore = i"' +
    ' class="text-center rounded-lg border px-2.5 py-1.5"' +
    ' :class="previewScore === i ? \'border-brand bg-brand/5\' : \'border-stroke hover:bg-hover\'">' +
    '<span class="block leading-none" :class="rating.rating_type === \'scale10\' ? \'text-[13px] font-semibold\' : \'text-[20px]\'">' +
    '{{ ratingGlyph(i) }}</span>' +
    '<span v-if="ratingPoints <= 5" class="block text-[10px] text-sub mt-0.5" style="max-width:60px">{{ ratingLabelFor(i) }}</span>' +
    '</button>' +
    '</div>' +
    '<div v-if="rating.allow_comment" class="mt-4">' +
    '<p class="text-[12px] font-semibold text-ink">Tell us more about your experience' +
    '<span v-if="ratingCommentRequired(ratingNormalise(previewScore))" class="text-danger"> *</span>' +
    '<span v-else class="font-normal text-faint"> (optional)</span></p>' +
    '<div class="mt-1 h-16 rounded-md border border-stroke bg-hover"></div>' +
    '</div>' +
    '<div class="mt-4 inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold">Submit Feedback</div>' +
    '</div>' +
    '<p class="text-[11px] text-faint mt-2">Click an option to see what the customer would be asked next.</p>' +
    '</div>' +   // sticky
    '</div>' +   // right column

    '</div>' +   // the two-column row (v-if="rating.enabled")

    '</div>' +   // v-if="rating"
    '</div>' +   // the panel

    // ===== Email Template (P48/P49) ========================================================
    //
    // A LIST, and editing happens in a drawer.
    //
    // It was four tabs, which showed one editor at a time and none of them at rest — so the page
    // could not answer the question an administrator actually arrives with: which of these is
    // switched on, and which have we changed? The list answers that before anything is opened.
    '<div v-else-if="kind === \'email_templates\'">' +

    // ---- templates ----
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="t in templates" :key="t.type" class="flex items-center gap-4 px-4 py-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-medium text-ink">{{ t.name }}</span>' +

    // The requirement's own status vocabulary, on the row: "New Ticket Auto Response — Enabled".
    // Only where the type CAN be disabled — a badge that can never say anything else is noise.
    '<span v-if="t.can_disable" ' +
    ':class="[\'inline-flex items-center h-5 px-2 rounded-full text-[11px] font-semibold\', t.enabled ? \'bg-brand/10 text-brand\' : \'bg-hover text-faint\']">' +
    '{{ t.enabled ? \'Enabled\' : \'Disabled\' }}</span>' +

    // "Customised" vs nothing. The absence of a row IS the default (P48), so this is the one
    // place an administrator can see at a glance whether this Space has diverged.
    '<span v-if="t.custom" class="text-[11px] text-faint">Customised</span>' +
    '<span v-else class="text-[11px] text-faint">Default</span>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ t.description }}</p>' +
    '</div>' +
    '<button type="button" @click="editTemplate(t.type)" ' +
    'class="shrink-0 inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '{{ canManage ? \'Edit\' : \'View\' }}</button>' +
    '</div>' +
    '</div>' +

    // ---- signatures ----
    '<div class="mt-7">' +
    '<h3 class="text-[14px] font-semibold text-head">Agent Signature</h3>' +
    '<p class="text-[13px] text-sub mt-0.5 mb-3">' +
    'Added to replies automatically. An agent’s own is used first; the Space default is used when ' +
    'they have none.</p>' +

    '<div class="border border-line rounded-xl divide-y divide-line">' +

    // The Space default leads, because it is the one that applies to everybody who has not set
    // their own — which is the row an administrator came here to fill in.
    '<div class="flex items-center gap-4 px-4 py-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-medium text-ink">Space default signature</span>' +
    '<span :class="[\'inline-flex items-center h-5 px-2 rounded-full text-[11px] font-semibold\', signatureRow(0) && signatureRow(0).enabled ? \'bg-brand/10 text-brand\' : \'bg-hover text-faint\']">' +
    '{{ signatureStatus(0) }}</span>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-0.5">Used when an agent has no signature of their own.</p>' +
    '</div>' +
    '<button v-if="canManage" type="button" @click="editSignature(0)" ' +
    'class="shrink-0 inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Edit</button>' +
    '</div>' +

    // Then the agents. `signatureAgents` is already the permission-filtered list: an agent who
    // cannot manage the Space sees only themselves, so this is never a list of rows they would
    // be refused on opening.
    '<div v-for="a in signatureAgents" :key="a.id" class="flex items-center gap-4 px-4 py-3">' +
    '<span v-if="a.avatar_url" class="h-7 w-7 rounded-full overflow-hidden shrink-0">' +
    '<img :src="a.avatar_url" alt="" class="h-full w-full object-cover" /></span>' +
    '<span v-else class="h-7 w-7 rounded-full text-white grid place-items-center text-[11px] font-bold shrink-0" ' +
    ':style="{ background: $pb.avatarColor(a) }">{{ a.initial }}</span>' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<span class="text-[14px] font-medium text-ink">{{ a.name }}</span>' +
    '<span v-if="a.id === me" class="text-[11px] text-faint">You</span>' +
    '<span :class="[\'inline-flex items-center h-5 px-2 rounded-full text-[11px] font-semibold\', signatureRow(a.id) && signatureRow(a.id).enabled ? \'bg-brand/10 text-brand\' : \'bg-hover text-faint\']">' +
    '{{ signatureStatus(a.id) }}</span>' +
    '</div>' +
    '<p v-if="!signatureRow(a.id)" class="text-[12px] text-sub mt-0.5">Falls back to the Space default.</p>' +
    '</div>' +
    '<button type="button" @click="editSignature(a.id)" ' +
    'class="shrink-0 inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Edit</button>' +
    '</div>' +

    '</div>' +
    '</div>' +

    // ===== the drawer ======================================================================
    //
    // The module's own slide-over, class for class: `fixed inset-0 z-[85]`, a 20%-black backdrop
    // and a panel pinned right. Narrower than the Request drawer's 80% — this is one form, not a
    // ticket with a properties rail beside it — but the same shell, so the two do not read as
    // two different mechanisms.
    '<teleport to="body">' +
    '<div v-if="drawer" class="fixed inset-0 z-[85]">' +
    '<div class="absolute inset-0 bg-black/20" @click="closeEmailDrawer"></div>' +
    // `sm:w-[80%]` with an inline cap, NOT `sm:w-[46rem]`: the built stylesheet contains only the
    // arbitrary widths Tailwind found while scanning its sources, and this file is not one of
    // them (P28). 80% is the Request drawer's width and 736px is where a single-column form stops
    // benefiting from more — an inline style is the honest way to say a number Tailwind has not
    // been told about.
    '<aside class="absolute right-0 top-0 h-full w-full sm:w-[80%] bg-white shadow-2xl flex flex-col" ' +
    'style="max-width:736px" role="dialog" aria-label="Edit email template">' +

    '<div class="flex items-center gap-2 px-4 h-14 border-b border-line shrink-0">' +
    '<button type="button" @click="closeEmailDrawer" data-tip="Close" aria-label="Close" ' +
    'class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" ' +
    'v-html="icon(\'arrow-right-long\', 18)"></button>' +
    '<span class="text-[14px] font-medium text-ink truncate">' +
    '{{ drawer === \'template\' ? (draft ? draft.name : \'\') : (sigFor === 0 ? \'Space default signature\' : suggestedName(sigFor) || \'Signature\') }}' +
    '</span>' +
    '</div>' +

    '<div class="flex-1 min-h-0 overflow-y-auto px-6 py-5">' +

    // ---- template form ----
    '<div v-if="drawer === \'template\' && draft" class="space-y-4">' +
    '<p v-if="templateFor(draft.type)" class="text-[13px] text-sub">{{ templateFor(draft.type).description }}</p>' +

    '<div class="grid gap-4 sm:grid-cols-2">' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Template Name</label>' +
    '<input v-model="draft.name" maxlength="120" :disabled="!canManage" class="pb-input w-full" />' +
    '</div>' +
    '<div v-if="templateFor(draft.type) && templateFor(draft.type).can_disable" class="flex items-end">' +
    '<div class="flex items-center justify-between gap-4 w-full border border-line rounded-lg px-3 h-[38px]">' +
    '<span class="text-[13px] text-ink">Enabled</span>' +
    '<pb-toggle v-model="draft.enabled" :disabled="!canManage"/>' +
    '</div>' +
    '</div>' +
    '</div>' +

    '<div v-if="draft.type !== \'ticket_layout\'">' +
    '<div class="flex items-center justify-between gap-2 mb-1">' +
    '<label class="text-[12px] font-semibold text-ink">Email Subject</label>' +
    // An ACTION menu, not a value picker: bound to a permanently empty value so the button keeps
    // reading "Insert Variable" rather than adopting whatever was inserted last.
    '<div class="w-[180px]">' +
    '<pb-combo :model-value="\'\'" @update:model-value="insertVariable($event, \'subject\')" ' +
    ':options="variableOptions" placeholder="Insert Variable" :searchable="false" :dense="true" ' +
    ':disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    '<input ref="subjectInput" v-model="draft.subject" maxlength="255" :disabled="!canManage" class="pb-input w-full" />' +
    '</div>' +

    '<div>' +
    '<div class="flex items-center justify-between gap-2 mb-1">' +
    '<label class="text-[12px] font-semibold text-ink">Email Body</label>' +
    // An ACTION menu, not a value picker: bound to a permanently empty value so the button keeps
    // reading "Insert Variable" rather than adopting whatever was inserted last.
    '<div class="w-[180px]">' +
    '<pb-combo :model-value="\'\'" @update:model-value="insertVariable($event, \'body\')" ' +
    ':options="variableOptions" placeholder="Insert Variable" :searchable="false" :dense="true" ' +
    ':disabled="!canManage" />' +
    '</div>' +
    '</div>' +
    // `:key` on the type: Jodit does not swap its document when the model changes underneath it,
    // so opening a second template into a live editor would show the first one's body.
    '<pg-editor v-if="useEditor" :key="draft.type" v-model="draft.body" min-height="220px" ' +
    ':document-view="false" :buttons="editorButtons" :license="editorLicense" :disabled="!canManage" />' +
    '<textarea v-else v-model="draft.body" rows="12" :disabled="!canManage" ' +
    'class="pb-input w-full !h-auto font-mono text-[12px]"></textarea>' +
    '</div>' +

    '<div class="rounded-lg border border-line bg-hover px-3 py-2.5">' +
    '<div class="text-[12px] font-semibold text-ink mb-1.5">Available variables</div>' +
    '<div class="flex flex-wrap gap-x-4 gap-y-1">' +
    '<span v-for="v in availableVariables" :key="v.tag" class="text-[12px] text-sub">' +
    '<code class="text-ink" v-text="tagToken(v.tag)"></code> — {{ v.label }}</span>' +
    '</div>' +
    '</div>' +

    '<div v-if="preview" class="rounded-lg border border-line overflow-hidden">' +
    '<div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-line bg-hover">' +
    '<div class="min-w-0">' +
    '<div class="text-[11px] text-faint">Subject</div>' +
    '<div class="text-[13px] text-ink truncate">{{ preview.subject || \'(no subject)\' }}</div>' +
    '</div>' +
    '<button type="button" @click="preview = null" class="text-[12px] text-sub hover:underline shrink-0">Close</button>' +
    '</div>' +
    '<p class="px-3 pt-2 text-[11px] text-faint">Rendered with sample ticket details and your own signature.</p>' +
    '<div class="px-3 py-3 max-h-[320px] overflow-y-auto text-[13px] wi-rich" v-html="preview.html"></div>' +
    '</div>' +
    '</div>' +

    // ---- signature form ----
    '<div v-else-if="drawer === \'signature\' && sigDraft" class="space-y-4">' +
    '<p v-if="!canEditSignature" class="text-[12px] text-faint">You can only edit your own signature in this Space.</p>' +

    '<div class="flex items-center justify-between gap-4 border border-line rounded-lg px-3 py-2.5">' +
    '<span class="text-[13px] text-ink">Enable this signature</span>' +
    '<pb-toggle v-model="sigDraft.enabled" :disabled="!canEditSignature"/>' +
    '</div>' +

    '<div class="grid gap-4 sm:grid-cols-2">' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Name</label>' +
    '<input v-model="sigDraft.name" maxlength="120" :disabled="!canEditSignature" class="pb-input w-full" placeholder="Rohit Philip" />' +
    '</div>' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Job Title</label>' +
    '<input v-model="sigDraft.job_title" maxlength="120" :disabled="!canEditSignature" class="pb-input w-full" placeholder="Customer Success" />' +
    '</div>' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Company / Team <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="sigDraft.company" maxlength="120" :disabled="!canEditSignature" class="pb-input w-full" placeholder="ProjectBlock" />' +
    '</div>' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Image URL <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="sigDraft.avatar_url" maxlength="2048" :disabled="!canEditSignature" class="pb-input w-full" placeholder="https://…" />' +
    '<p class="mt-1 text-[12px] text-faint">A public image address. Mail clients cannot read files from this app.</p>' +
    '</div>' +
    '</div>' +

    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Signature Content <span class="text-faint font-normal">(optional)</span></label>' +
    '<pg-editor v-if="useEditor" :key="\'sig-\' + sigFor" v-model="sigDraft.content" min-height="140px" ' +
    ':document-view="false" :buttons="editorButtons" :license="editorLicense" :disabled="!canEditSignature" />' +
    '<textarea v-else v-model="sigDraft.content" rows="5" :disabled="!canEditSignature" class="pb-input w-full !h-auto"></textarea>' +
    '</div>' +

    '<div v-if="signaturePreview" class="rounded-lg border border-line px-3 py-3">' +
    '<div class="text-[11px] text-faint mb-1.5">Preview &mdash; as saved</div>' +
    '<div class="text-[13px]" v-html="signaturePreview"></div>' +
    '</div>' +
    '</div>' +

    '</div>' +

    // ---- the drawer's own footer ----
    //
    // Pinned, not scrolled with the form: the body is long enough that Save would otherwise be
    // below the fold every time somebody opened a template.
    '<div class="border-t border-line px-6 py-3 shrink-0 flex flex-wrap items-center gap-2">' +
    '<template v-if="drawer === \'template\'">' +
    '<button type="button" @click="saveTemplate" :disabled="!canManage || saving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save\' }}</button>' +
    '<button type="button" @click="previewTemplate" :disabled="previewBusy" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    '{{ previewBusy ? \'Building…\' : \'Preview\' }}</button>' +
    '<button type="button" @click="sendTest" :disabled="!canManage || testBusy" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    '{{ testBusy ? \'Sending…\' : \'Send Test Email\' }}</button>' +
    '<template v-if="canManage && draft && templateFor(draft.type) && templateFor(draft.type).custom">' +
    '<span class="ml-auto"></span>' +
    '<span v-if="resetConfirm" class="text-[12px] text-sub">Discard this Space’s version?</span>' +
    '<button type="button" @click="resetConfirm ? resetTemplate() : (resetConfirm = true)" :disabled="saving" ' +
    'class="inline-flex items-center h-9 px-3 rounded-md text-[13px] font-semibold text-danger hover:bg-hover disabled:opacity-50">' +
    '{{ resetConfirm ? \'Yes, restore default\' : \'Restore Default\' }}</button>' +
    '<button v-if="resetConfirm" type="button" @click="resetConfirm = false" ' +
    'class="text-[12px] text-sub hover:underline">Cancel</button>' +
    '</template>' +
    '<span v-else class="ml-auto text-[12px] text-faint">Using the default template</span>' +
    '</template>' +

    '<template v-else>' +
    '<button type="button" @click="saveSignature" :disabled="!canEditSignature || sigSaving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ sigSaving ? \'Saving…\' : \'Save signature\' }}</button>' +
    '<button type="button" @click="closeEmailDrawer" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '</template>' +
    '</div>' +

    '</aside>' +
    '</div>' +
    '</teleport>' +

    '</div>' +

    // ===== Auto BCC ======================================================================
    // The switch, then the list it governs. Add and remove write immediately; there is no Save
    // button, because every control here is already a completed action.
    '<div v-else-if="kind === \'auto_bcc\'">' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink">Auto BCC</div>' +
    '<p v-if="!canManage" class="text-[12px] text-faint mt-1">You do not have permission to change this Space.</p>' +
    '</div>' +
    '<pb-toggle :model-value="bccEnabled" :disabled="!canManage || saving" @update:model-value="toggleBcc"/>' +
    '</div>' +

    // The list only exists while the toggle is on: addresses behind a disabled feature are
    // addresses whose meaning nobody can state.
    '<div v-if="bccEnabled" class="px-4 py-3">' +
    '<div class="text-[12px] font-medium text-ink mb-1.5">BCC addresses</div>' +

    '<table v-if="bccEmails.length" class="w-full text-[13px]">' +
    '<tbody>' +
    '<tr v-for="email in bccEmails" :key="email" class="border-b border-line last:border-0">' +
    '<td class="py-2 text-ink _moretogether-break">{{ email }}</td>' +
    '<td v-if="canManage" class="py-2 w-10 text-right">' +
    '<button type="button" @click="confirmRemoveBcc(email)" ' +
    'class="_moretogether-iconbtn _moretogether-iconbtn--danger" data-tip="Delete address" ' +
    ':aria-label="\'Delete \' + email" v-html="icon(\'trash-can\', 14)"></button>' +
    '</td>' +
    '</tr>' +
    '</tbody></table>' +
    // Enabled with nothing to send to is a real state — the switch is flipped before the list
    // is filled in — so it is described rather than treated as an error.
    '<p v-else class="text-[12px] text-faint">No addresses yet. Auto BCC is on, but nothing is being copied until you add one.</p>' +

    '<p v-if="fieldError(\'auto_bcc_emails\')" class="text-[12px] text-danger mt-2">{{ fieldError(\'auto_bcc_emails\') }}</p>' +

    '<div v-if="canManage" class="mt-3">' +
    '<button v-if="!bccForm.open" type="button" @click="showBccForm" :disabled="bccEmails.length >= bccMax" ' +
    'class="inline-flex items-center gap-1 text-[12px] font-semibold text-brand hover:underline disabled:opacity-50 disabled:no-underline">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add BCC address</button>' +
    '<div v-else class="flex flex-wrap items-start gap-2">' +
    '<input v-model="bccForm.email" type="email" placeholder="archive@company.com" ' +
    'class="flex-1 min-w-[220px] max-w-[380px] h-9 px-3 rounded-md border border-stroke text-[13px] text-ink placeholder:text-faint" ' +
    '@keydown.enter.prevent="addBcc"/>' +
    '<button type="button" @click="addBcc" :disabled="saving || !bccForm.email" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Add</button>' +
    '<button type="button" @click="showBccForm" ' +
    'class="inline-flex items-center h-9 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Cancel</button>' +
    '</div>' +
    '<p v-if="bccForm.error" class="mt-2 text-[12px] text-danger">{{ bccForm.error }}</p>' +
    '<p v-else-if="bccEmails.length >= bccMax" class="mt-2 text-[12px] text-faint">Auto BCC holds at most {{ bccMax }} addresses.</p>' +
    '</div>' +
    '</div></div>' +

    '<pb-confirm :open="bccConfirm.open" title="Delete this BCC address?" ' +
    ':message="bccConfirm.email + \' will stop receiving blind copies of messages sent from this Space. This cannot be undone.\'" ' +
    'confirm-label="Delete address" @close="bccConfirm.open = false" @confirm="removeBcc"/>' +
    '</div>' +

    // ===== Reassignment ==================================================================
    '<div v-else-if="kind === \'reassignment\'">' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink">Reassign stalled conversations</div>' +
    '<p v-if="!canManage" class="text-[12px] text-faint mt-1">You do not have permission to change this Space.</p>' +
    '</div>' +
    '<pb-toggle v-model="reEnabled" :disabled="!canManage || saving"/>' +
    '</div>' +
    '<div v-if="reEnabled" class="px-4 py-3">' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5">Wait for</label>' +
    '<div class="flex items-end gap-2">' +
    '<div><input type="number" min="0" max="720" v-model.number="reHours" :disabled="!canManage" ' +
    ':class="[\'w-20 h-9 px-3 rounded-md border text-[13px] text-ink\', fieldError(\'reassign_hours\') ? \'border-danger\' : \'border-stroke\']"/>' +
    '<div class="text-[11px] text-faint mt-1">Hours</div></div>' +
    '<div><input type="number" min="0" max="59" v-model.number="reMinutes" :disabled="!canManage" ' +
    ':class="[\'w-20 h-9 px-3 rounded-md border text-[13px] text-ink\', fieldError(\'reassign_minutes\') ? \'border-danger\' : \'border-stroke\']"/>' +
    '<div class="text-[11px] text-faint mt-1">Minutes</div></div>' +
    '</div>' +
    '<p v-if="fieldError(\'reassign_hours\')" class="text-[12px] text-danger mt-1.5">{{ fieldError(\'reassign_hours\') }}</p>' +
    '<p v-else-if="fieldError(\'reassign_minutes\')" class="text-[12px] text-danger mt-1.5">{{ fieldError(\'reassign_minutes\') }}</p>' +
    '<p v-else class="text-[12px] text-sub mt-1.5">{{ thresholdSummary }}</p>' +
    '</div>' +
    '<div v-if="reEnabled" class="px-4 py-3">' +
    '<label class="block text-[12px] font-medium text-ink mb-1.5">Then</label>' +
    '<div class="max-w-[380px]"><pb-combo v-model="reDestination" :options="destinations" :searchable="false"/></div>' +
    '<p v-if="fieldError(\'reassign_destination\')" class="text-[12px] text-danger mt-1">{{ fieldError(\'reassign_destination\') }}</p>' +
    '</div></div>' +
    '<p v-if="note" class="text-[12px] text-sub mt-3">{{ note }}</p>' +
    '<div v-if="canManage" class="flex justify-end mt-4">' +
    '<button type="button" @click="saveReassignment" :disabled="saving" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save changes\' }}</button></div>' +
    '</div>' +

    // ===== Workflow ======================================================================
    // The Space's status list, moved here from the Space's tab bar (P15). The same two views
    // the old panel had — the chain across the top, the detail underneath — because a workflow
    // is read as an order first and as a table second.
    '<div v-else-if="kind === \'workflow\'">' +

    // ---- the two tabs (P53) ----
    '<div class="flex items-center gap-1 border-b border-line mb-5">' +
    '<button type="button" @click="workflowTab = \'workflow\'" ' +
    ':class="[\'h-9 px-3 text-[13px] border-b-2 -mb-px\', workflowTab === \'workflow\' ? \'border-brand text-brand font-semibold\' : \'border-transparent text-sub hover:text-ink\']">' +
    'Workflow</button>' +
    '<button type="button" @click="workflowTab = \'categories\'" ' +
    ':class="[\'h-9 px-3 text-[13px] border-b-2 -mb-px\', workflowTab === \'categories\' ? \'border-brand text-brand font-semibold\' : \'border-transparent text-sub hover:text-ink\']">' +
    'System Category</button>' +
    '</div>' +

    // ===== System Category ===============================================================
    //
    // READ-ONLY, and not because the controls were left off — there is no endpoint to write to.
    // These five are `config('help-center.system_categories')`; the requirement is that
    // ProjectBlock owns them and they cannot be renamed, deleted or customised, and config is
    // the honest expression of that. Anything editable would need a table, and a table is a
    // thing somebody eventually gets an update endpoint for.
    '<div v-if="workflowTab === \'categories\'">' +
    '<p class="text-[13px] text-sub mb-4 max-w-[640px]">' +
    'System Categories are the fixed set of meanings a ticket state can carry. They are managed by ' +
    'ProjectBlock and cannot be renamed, deleted or added to. A Space\u2019s own workflow statuses ' +
    'are on the Workflow tab.</p>' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="c in systemCategories" :key="c.key" class="flex items-start gap-3 px-4 py-3">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0 mt-1.5" :style="{ background: c.color }"></span>' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[14px] font-medium text-ink">{{ c.label }}</span>' +
    // The lock is the same marker a protected status carries in the table below, so "you cannot
    // change this" reads the same way in both places on one page.
    '<span class="text-faint" title="Managed by ProjectBlock" v-html="icon(\'lock\', 11)"></span>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-0.5">{{ c.description }}</p>' +
    '</div>' +
    '<span class="shrink-0 inline-flex items-center h-5 px-2 rounded-full bg-hover text-faint text-[11px] font-semibold">System</span>' +
    '</div>' +
    '</div>' +
    '</div>' +

    // ===== Workflow ======================================================================
    '<template v-else>' +
    '<pb-empty v-if="!statuses.length" title="No workflow configured" ' +
    'subtitle="A workflow is set up when the Space is created."/>' +
    '<div v-else>' +
    '<div class="flex flex-wrap items-center gap-2 mb-4">' +
    '<template v-for="(s, i) in statuses" :key="s.id">' +
    '<span class="inline-flex items-center h-6 px-2 rounded-full text-[11px] font-semibold text-white" ' +
    ':style="{ background: s.color }">{{ s.name }}</span>' +
    '<span v-if="s.is_default" class="text-[11px] text-sub" title="New Requests open in this status">Start</span>' +
    '<span v-if="i < statuses.length - 1" class="text-faint">&rarr;</span>' +
    '</template>' +
    '</div>' +

    '<div class="border border-line rounded-xl overflow-x-auto">' +
    '<table class="w-full text-[13px]">' +
    '<thead><tr class="text-left text-[12px] text-faint border-b border-line">' +
    '<th class="py-2 pl-4 font-medium">Status</th>' +
    '<th class="py-2 font-medium">Responsibility</th>' +
    // Whose clock runs in this status — what makes the Inbox's Waiting column mean something
    // other than the age of the ticket (P9).
    '<th class="py-2 font-medium">Waiting on</th>' +
    // The System Category (P54), beside Waiting for the reason the editor puts them together:
    // what a state MEANS and whose clock runs in it are neighbouring questions.
    '<th class="py-2 font-medium">System Category</th>' +
    '<th class="py-2 font-medium">State</th>' +
    '<th class="py-2 pr-4 font-medium">Default assignees</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="s in statuses" :key="s.id" class="border-b border-line last:border-0">' +
    '<td class="py-2 pl-4 text-ink">' +
    '<span class="inline-flex items-center gap-1.5">{{ s.name }}' +
    '<span v-if="s.is_system" class="text-faint" title="Protected" v-html="icon(\'lock\', 11)"></span>' +
    '<span v-if="s.is_default" class="_moretogether-badge _moretogether-badge--ok">Start</span>' +
    '</span></td>' +
    // The LABEL, not the stored value — the editor's pickers bind to the raw one.
    '<td class="py-2 text-sub">{{ s.responsibility_label }}</td>' +
    '<td class="py-2 text-sub">{{ s.waiting_on_label }}</td>' +
    '<td class="py-2">' +
    '<span class="inline-flex items-center gap-1.5 text-sub">' +
    '<span class="h-2 w-2 rounded-full shrink-0" :style="{ background: categoryColor(s.system_category) }"></span>' +
    '{{ categoryLabelFor(s.system_category) }}</span></td>' +
    '<td class="py-2 text-sub">{{ s.is_active ? \'Active\' : \'Inactive\' }}</td>' +
    '<td class="py-2 pr-4 text-sub">{{ s.assignees.length ? s.assignees.join(\', \') : \'—\' }}</td>' +
    '</tr></tbody></table>' +
    '</div>' +
    '<p v-if="note" class="text-[12px] text-sub mt-3">{{ note }}</p>' +
    '</div>' +

    '<div v-if="canManage" class="mt-4">' +
    '<button type="button" @click="openEditor" ' +
    'class="inline-flex items-center gap-1.5 h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '<span v-html="icon(\'pen-line\', 13)"></span> Edit workflow</button>' +
    '</div>' +
    '</template>' +

    // ---- the editor, full screen over the page ----
    // 1000px centred, because a workflow row carries six fields and an order, and the settings
    // column is 820px — narrow enough that the cards fold into something you cannot compare
    // across, which is the one thing reading a workflow is for.
    '<teleport to="body">' +
    '<div v-if="editor.open" class="fixed inset-0 z-50 bg-[#f9fafb] overflow-y-auto" role="dialog" aria-modal="true" aria-label="Edit workflow">' +
    // The bar stays put while the list scrolls: Save and Cancel are the two things somebody
    // reaches for, and a long workflow would otherwise scroll them off the top.
    '<div class="sticky top-0 z-10 border-b border-line bg-white">' +
    '<div class="max-w-[1000px] mx-auto px-6 h-14 flex items-center gap-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[15px] font-semibold text-head truncate">Edit workflow</div>' +
    '</div>' +
    '<div class="ml-auto flex items-center gap-2">' +
    '<button type="button" @click="closeEditor" :disabled="saving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">Cancel</button>' +
    '<button type="button" @click="saveWorkflow" :disabled="saving" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Saving…\' : \'Save workflow\' }}</button>' +
    '</div></div></div>' +

    '<div class="max-w-[1000px] mx-auto px-6 py-6">' +
    '<p class="text-[13px] text-sub">Open is always first and Closed always last. New statuses are added just above Closed; use the arrows to reorder.</p>' +

    // Open, then Add, then the custom rows, then Closed — the order a workflow is read in.
    '<div class="mt-4 space-y-3">' +
    '<hc-status-card v-if="openStatus()" :status="openStatus()" :colors="statusColors" ' +
    ':responsibilities="responsibilities" :waiting-options="waitingOptions" :category-options="categoryOptions" :assignees="assigneeOptions" ' +
    ':advanced="true" :name-max="nameMax"/>' +

    '<hc-status-card v-for="s in customStatuses()" :key="s._uid" :status="s" :colors="statusColors" ' +
    ':responsibilities="responsibilities" :waiting-options="waitingOptions" :category-options="categoryOptions" :assignees="assigneeOptions" ' +
    ':advanced="true" :name-max="nameMax" ' +
    '@move="moveStatus(s, $event)" @remove="confirmRemoveStatus(s)"/>' +

    '<div class="flex justify-center py-1">' +
    '<button type="button" @click="addStatus" :disabled="customStatuses().length >= statusMax" ' +
    'class="inline-flex items-center gap-1.5 h-9 px-4 rounded-md border border-dashed border-stroke text-[13px] font-semibold text-ink hover:bg-hover disabled:opacity-50">' +
    '<span v-html="icon(\'plus\', 13)"></span> Add status</button>' +
    '</div>' +
    '<p v-if="customStatuses().length >= statusMax" class="text-center text-[12px] text-faint">That is the most statuses a workflow can hold.</p>' +

    '<hc-status-card v-if="closedStatus()" :status="closedStatus()" :colors="statusColors" ' +
    ':responsibilities="responsibilities" :waiting-options="waitingOptions" :category-options="categoryOptions" :assignees="assigneeOptions" ' +
    ':advanced="true" :name-max="nameMax"/>' +
    '</div>' +

    '<p v-if="editor.error" class="mt-4 text-[13px] text-danger">{{ editor.error }}</p>' +
    '</div>' +

    '<pb-confirm :open="editorConfirm.open" title="Delete this status?" ' +
    ':message="(editorConfirm.row ? editorConfirm.row.name || \'This status\' : \'\') + \' will be removed when you save. Requests sitting in it will need a new status.\'" ' +
    'confirm-label="Delete status" @close="editorConfirm.open = false" @confirm="removeStatus"/>' +
    '</div>' +
    '</teleport>' +

    '</div>' +

    // ===== Channels ======================================================================
    // A LIST, not a set of switches. The toggles are rendered because the row reads as a
    // setting either way, and a disabled one states the position without inviting a click that
    // would do nothing: Email is on and cannot be turned off — a Space with no channel could
    // not be reached at all — and the other two are off because they do not exist yet.
    '<div v-else-if="kind === \'channels\'">' +
    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="c in channels" :key="c.key" class="flex items-center justify-between gap-4 px-4 py-3">' +
    '<div class="min-w-0">' +
    '<div class="text-[14px] font-medium text-ink flex items-center gap-2">{{ c.label }}' +
    '<span v-if="c.status === \'soon\'" class="text-[10px] font-semibold uppercase tracking-wide text-sub bg-hover rounded px-1.5 py-0.5">Coming Soon</span>' +
    // Said in words as well as by the switch. All three toggles are disabled, so a dimmed ON
    // beside two dimmed OFFs would leave "is email actually available?" to be read off an
    // opacity — which is exactly the question this page exists to answer.
    '<span v-else class="_moretogether-badge _moretogether-badge--ok">Enabled</span>' +
    '</div>' +
    '<p class="text-[12px] text-sub mt-1">{{ c.help }}</p>' +
    '</div>' +
    '<pb-toggle :model-value="c.enabled" :disabled="true"/>' +
    '</div>' +
    '</div>' +
    '<p v-if="note" class="text-[12px] text-sub mt-3">{{ note }}</p>' +
    '</div>' +

    // ===== Inbox — the addresses, managed here ===========================================
    // The same actions the Inboxes screen offers, against the same endpoints: copy the inbound
    // address, add a customer-facing address, remove one. Both screens post to
    // EmailAddressController, so the verification and the "already connected elsewhere" rule
    // stay in one place — this is a second door onto them, not a second copy of them.
    '<div v-else-if="kind === \'inbox\'">' +
    '<pb-empty v-if="!inboxes.length" title="This Space has no Inbox" ' +
    'subtitle="An Inbox is created with the Space during setup."/>' +

    // ---- the Space's sender identity (P65) -------------------------------------------
    // ABOVE the Inbox list and outside its v-for, because it is one fact about the SPACE.
    // Rendering it per Inbox would put the same field on screen twice and invite somebody to
    // give one Space two names — the thing this field exists to prevent.
    '<div v-if="inboxes.length" class="border border-line rounded-xl mb-4">' +
    '<div class="px-4 py-3 border-b border-line text-[14px] font-medium text-head">Sender identity</div>' +
    '<div class="px-4 py-3">' +

    '<div class="text-[12px] text-sub mb-1.5">Inbound Email Address</div>' +
    // READ-ONLY, as the requirement states: it is generated with the Inbox, and every ticket
    // already routed to it would be orphaned by a change. Rendered as a code block rather than
    // a disabled input so it does not look like a field somebody failed to enable.
    '<code class="_moretogether-break block rounded-md border border-line bg-[#f9fafb] px-2 py-1.5 text-[12px] text-ink">{{ senderInbound || \'—\' }}</code>' +
    '<p class="text-[12px] text-faint mt-1.5">Generated by the system and cannot be changed.</p>' +

    '<div class="mt-4">' +
    '<label class="block text-[12px] text-sub mb-1.5">Inbound Email Display Name</label>' +
    '<input v-model="senderDisplayName" maxlength="100" :disabled="!canManage" class="pb-input w-full" ' +
    ':placeholder="spaceName || \'eBay Support\'" />' +
    '<p class="text-[12px] text-faint mt-1.5">This name will be shown to customers as the sender name when ' +
    'emails are sent from this Space.</p>' +
    // The preview the requirement asks for, built from the SAME fallback the server applies —
    // so an empty field shows what will actually be sent rather than an empty name.
    '<p v-if="senderInbound" class="text-[12px] text-sub mt-1.5 _moretogether-break">' +
    'For example: <span class="font-semibold text-ink">{{ senderPreviewName }}</span> ' +
    '<span class="text-faint">&lt;{{ senderInbound }}&gt;</span></p>' +
    '<p v-if="errors.inbound_display_name" class="mt-1 text-[12px] text-danger">{{ errors.inbound_display_name[0] }}</p>' +

    '<div v-if="canManage" class="mt-3">' +
    '<button type="button" @click="saveSenderName" :disabled="!senderDirty || savingSender" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ savingSender ? \'Saving…\' : \'Save\' }}</button>' +
    '</div>' +

    '</div>' +
    '</div>' +
    '</div>' +
    '<div v-for="ib in inboxes" :key="ib.id" class="border border-line rounded-xl mb-4">' +
    '<div class="px-4 py-3 border-b border-line text-[14px] font-medium text-head">{{ ib.name }}</div>' +
    '<div class="px-4 py-3">' +
    '<div class="text-[12px] text-sub mb-1.5">Inbound address</div>' +
    '<div class="flex flex-wrap items-center gap-2">' +
    '<code class="_moretogether-break flex-1 min-w-[220px] rounded-md border border-line bg-[#f9fafb] px-2 py-1.5 text-[12px] text-ink">{{ ib.inbound_address }}</code>' +
    '<button type="button" @click="copyInbound(ib)" class="_moretogether-iconbtn" :aria-label="\'Copy \' + ib.inbound_address" ' +
    ':title="copiedId === ib.id ? \'Copied\' : \'Copy address\'" v-html="icon(\'copy\', 14)"></button>' +
    '</div>' +
    '<p class="text-[12px] text-faint mt-1.5">Generated for this Inbox. Forward your support address here.</p>' +

    '<div class="mt-4">' +
    '<div class="text-[12px] text-sub mb-1.5">Customer-facing addresses</div>' +
    '<table v-if="ib.addresses.length" class="w-full text-[13px]">' +
    '<thead><tr class="text-left text-[12px] text-faint border-b border-line">' +
    '<th class="py-2 font-medium">Name</th><th class="py-2 font-medium">Email Address</th><th class="py-2 font-medium">Status</th>' +
    '<th v-if="ib.manageable" class="py-2 font-medium text-right">Action</th>' +
    '</tr></thead><tbody>' +
    '<tr v-for="a in ib.addresses" :key="a.id" class="border-b border-line last:border-0">' +
    '<td class="py-2 text-ink">{{ a.name || \'—\' }}</td>' +
    '<td class="py-2 text-ink _moretogether-break">{{ a.email }}</td>' +
    '<td class="py-2"><span :class="\'_moretogether-badge _moretogether-badge--\' + a.status_tone">{{ a.status_label }}</span></td>' +
    '<td v-if="ib.manageable" class="py-2">' +
    '<div class="flex items-center justify-end gap-1">' +
    // Offered only while the address is unverified: a passing test IS verification, so on a
    // verified row it would prove what the badge already says.
    '<button v-if="a.status !== \'verified\'" type="button" @click="sendTest(ib, a)" ' +
    ':disabled="test.starting || (testRunning && test.addressId === a.id)" ' +
    'class="_moretogether-iconbtn" data-tip="Send test email" aria-label="Send test email" ' +
    'v-html="icon(\'play\', 14)"></button>' +
    '<button type="button" @click="confirmRemove(ib, a)" ' +
    'class="_moretogether-iconbtn _moretogether-iconbtn--danger" data-tip="Delete address" ' +
    ':aria-label="\'Delete \' + a.email" v-html="icon(\'trash-can\', 14)"></button>' +
    '</div>' +
    '</td>' +
    '</tr></tbody></table>' +
    '<p v-else class="text-[12px] text-faint">No customer-facing addresses connected yet.</p>' +

    // The test's result, under the table it belongs to. One area, because one probe runs at a
    // time; it names the address so it cannot be read against the wrong row.
    '<div v-if="testFor(ib)" class="mt-3">' +
    '<div v-if="testRunning" class="rounded-md border border-line bg-[#f9fafb] px-3 py-3">' +
    '<div class="flex items-center gap-2">' +
    '<span class="_moretogether-spinner" aria-hidden="true"></span>' +
    '<span class="text-[13px] font-semibold text-ink">Testing {{ test.data.test_email_address }}…</span>' +
    '<span class="ml-auto text-[12px] text-sub">{{ testCountdown }}</span>' +
    '</div>' +
    '<p class="mt-1.5 text-[12px] text-sub">We sent a test email to this address. We&rsquo;re waiting for your forwarding rule to send it back to ProjectBlock.</p>' +
    '</div>' +
    '<div v-else-if="test.data && test.data.passed" class="rounded-md border border-line bg-[#f9fafb] px-3 py-3">' +
    '<div class="text-[13px] font-semibold text-ink"><span v-html="icon(\'check\', 14)"></span> {{ test.data.test_email_address }} is working</div>' +
    '<p class="mt-1.5 text-[12px] text-sub">The forwarded message arrived and was parsed, so this address is now verified.</p>' +
    '</div>' +
    // Names WHICH leg broke, because the remedy differs completely — the same distinction the
    // Space Overview's card makes, from the same `explanation`.
    '<div v-else-if="test.data && test.data.explanation" class="_moretogether-notice px-3 py-3">' +
    '<div class="text-[13px] font-semibold text-ink">We couldn&rsquo;t verify {{ test.data.test_email_address }}</div>' +
    '<p class="mt-1.5 text-[12px] text-sub">{{ test.data.explanation }}</p>' +
    '<p v-if="test.data.failure_reason" class="mt-1 text-[12px] text-sub _moretogether-break">{{ test.data.failure_reason }}</p>' +
    '</div>' +
    '<p v-if="test.error" class="text-[12px] text-danger">{{ test.error }}</p>' +
    '</div>' +

    // Only somebody who may manage the Space is offered the form. The endpoint enforces it too
    // (§19) — this is presentation, and the server is the authority.
    '<div v-if="ib.manageable" class="mt-3">' +
    '<button v-if="openForm !== ib.id" type="button" @click="showAddressForm(ib)" ' +
    'class="inline-flex items-center gap-1 text-[12px] font-semibold text-brand hover:underline">' +
    '<span v-html="icon(\'plus\', 12)"></span> Add email address</button>' +
    '<div v-else class="mt-2">' +
    '<div class="flex flex-wrap items-start gap-2">' +
    '<input v-model="addressForm.email" type="email" placeholder="support@company.com" ' +
    'class="flex-1 min-w-[200px] h-9 px-3 rounded-md border border-stroke text-[13px] text-ink placeholder:text-faint" ' +
    '@keydown.enter.prevent="addAddress(ib)"/>' +
    '<input v-model="addressForm.name" maxlength="100" placeholder="Company Support" ' +
    'class="flex-1 min-w-[160px] h-9 px-3 rounded-md border border-stroke text-[13px] text-ink placeholder:text-faint" ' +
    '@keydown.enter.prevent="addAddress(ib)"/>' +
    '<button type="button" @click="addAddress(ib)" :disabled="saving || !addressForm.email" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Add</button>' +
    '<button type="button" @click="showAddressForm(ib)" ' +
    'class="inline-flex items-center h-9 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Cancel</button>' +
    '</div>' +
    '<p v-if="addressError" class="mt-2 text-[12px] text-danger">{{ addressError }}</p>' +
    '</div></div>' +
    '</div>' +
    '</div></div>' +

    '<pb-confirm :open="removeConfirm.open" title="Delete this email address?" ' +
    ':message="removeMessage()" confirm-label="Delete address" ' +
    '@close="removeConfirm.open = false" @confirm="removeAddress"/>' +

    // ===== Space configuration (P52) =====================================================
    //
    // On this page because it is where somebody already comes to answer "how is this Space set
    // up?" — the inbound address above is the first thing they check (P8), and the lead, the
    // type and the workflow are the rest of the same question.
    //
    // Read-only. Every value is edited somewhere that owns it (Edit Space, Settings › Workflow,
    // the addresses above), and a second place to change them is a second place for them to
    // disagree. The same rows the Overview's dialog shows, from the same builder.
    '<div v-if="configuration.length" class="mt-8">' +
    '<h3 class="text-[14px] font-semibold text-head">Space configuration</h3>' +
    '<dl class="mt-3 rounded-xl border border-line divide-y divide-line">' +
    '<div v-for="row in configuration" :key="row.label" class="flex gap-4 px-4 py-3">' +
    '<dt class="w-40 shrink-0 text-[12px] text-sub">{{ row.label }}</dt>' +
    '<dd class="text-[13px] text-ink min-w-0" :class="row.break ? \'_moretogether-break\' : \'\'">{{ row.value }}</dd>' +
    '</div>' +
    '</dl>' +
    '</div>' +

    // ===== Delete Space ==================================================================
    //
    // Last on the page and visually separated, which is where a destructive action belongs: it
    // should be findable on purpose and never on the way to something else.
    '<div v-if="canDelete" class="mt-8 rounded-xl border border-danger/30 px-4 py-4">' +
    '<div class="flex flex-wrap items-start justify-between gap-3">' +
    '<div class="min-w-0">' +
    '<h3 class="text-[14px] font-semibold text-ink">Delete this Space</h3>' +
    '<p class="text-[12px] text-sub mt-1 max-w-[560px]">' +
    'Its conversations, workflow, tags and inbound address go with it. Mail already forwarded to ' +
    'the inbound address will stop arriving, and the address cannot be recovered.</p>' +
    '</div>' +
    '<button type="button" @click="openDeleteSpace" ' +
    'class="shrink-0 inline-flex items-center h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold hover:opacity-90">' +
    'Delete Space</button>' +
    '</div>' +
    '</div>' +

    // The confirmation. NOT `pb-confirm`: that is a yes/no, and this needs the Space name typed —
    // the bar the server already sets (SpaceController::destroy), because a confirm dialog is one
    // careless click and typing the name is a decision.
    '<pb-modal :open="!!deleteForm" title="Delete this Space?" @close="deleteForm = null">' +
    '<div v-if="deleteForm" class="space-y-3">' +
    '<p class="text-[13px] text-sub leading-relaxed">' +
    'This cannot be undone. Every conversation in this Space, its workflow, its tags and its ' +
    'inbound address will be deleted.</p>' +
    '<div>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">' +
    'Type <span class="font-mono text-ink">{{ spaceName }}</span> to confirm</label>' +
    '<input v-model="deleteForm.confirm" class="pb-input w-full" :placeholder="spaceName" ' +
    '@keydown.enter.prevent="deleteSpace" />' +
    '<p v-if="deleteForm.error" class="mt-1 text-[12px] text-danger">{{ deleteForm.error }}</p>' +
    '</div>' +
    '</div>' +
    '<template #footer>' +
    '<button type="button" @click="deleteForm = null" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="deleteSpace" :disabled="!canConfirmDelete() || deleting" ' +
    'class="inline-flex items-center h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ deleting ? \'Deleting…\' : \'Delete Space\' }}</button>' +
    '</template>' +
    '</pb-modal>' +
    '</div>' +

    '</div>'
}, { root: 'help-center-space-settings' });
