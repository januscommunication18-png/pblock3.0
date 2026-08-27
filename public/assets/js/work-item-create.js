/* "Create Work Item", from anywhere (docs/features/quick-create.md).
   ------------------------------------------------------------------
   The sidebar button used to NAVIGATE to a project's Work Items and auto-open the modal
   there. That answered a question nobody asked — "which project?" — by taking you off
   whatever you were doing. This opens the modal in place, and asks for the project inside it.

   Mounted on its own root in the sidebar partial, so it exists on every authenticated screen
   without any of them knowing about it.

   IT STANDS DOWN on the Work Items screen. That screen's own create modal is richer — parent
   search, cycles, modules, epics, estimates — and it binds the same button on mount. It marks
   the button when it does; this checks for that mark at click time, which is reliable because
   binding happens on mount and clicks happen after.
   ------------------------------------------------------------------ */
PB.boot('work-item-create', {
  props: { bootstrap: Object },
  data: function () {
    return {
      open: false,
      // Loaded when the modal first opens, not on every page render — see the controller.
      projects: [],
      projectId: '',
      options: null,
      storeUrl: '',
      loading: false,
      saving: false,
      errors: {},
      form: { title: '', description: '', state_id: '', priority: 'none', assignee_id: '' },
      created: null
    };
  },
  computed: {
    projectOptions: function () {
      return this.projects.map(function (p) {
        return { value: String(p.id), label: p.name, desc: p.identifier };
      });
    },
    /*
     * Deliberately no wiStateIcon / WI_PRI here.
     *
     * Those live in work-item-ui.js, which is loaded by the work item screens and NOT by
     * Home, Projects or Settings — where this modal also has to open. A coloured dot built
     * from the state's own colour costs nothing and works everywhere; reaching for the shared
     * glyphs would mean loading the whole row vocabulary (and Tabulator's stylesheet with it)
     * onto every page in the application to decorate one dropdown.
     */
    stateOptions: function () {
      return ((this.options && this.options.states) || []).map(function (s) {
        // The colour goes into markup, so it is matched against a hex literal rather than
        // trusted — a state's colour is author-supplied.
        var hex = /^#[0-9a-f]{3,8}$/i.test(String(s.color || '')) ? s.color : '#9ca3af';

        return {
          value: String(s.id),
          label: s.name,
          icon: '<span class="h-2.5 w-2.5 rounded-full inline-block" style="background:' + hex + '"></span>'
        };
      });
    },
    priorityOptions: function () {
      return ((this.options && this.options.priorities) || []).map(function (p) {
        return { value: p.key, label: p.label };
      });
    },
    memberOptions: function () {
      return ((this.options && this.options.members) || []).map(function (m) {
        return { value: String(m.id), label: m.name, desc: m.email, initial: m.initial, avatar: m.avatar_url };
      });
    },
    canSubmit: function () {
      return !!(this.projectId && String(this.form.title || '').trim() && !this.saving);
    }
  },
  mounted: function () {
    var self = this;
    var el = document.getElementById('new-work-item-btn');
    if (!el) return;

    el.addEventListener('click', function (e) {
      // The Work Items screen got there first and has a better modal — let it through.
      if (el.getAttribute('data-create-bound') === '1') return;

      e.preventDefault();
      self.show();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.open && !self.saving) self.open = false;
    });
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    show: async function () {
      this.open = true;
      this.created = null;
      this.errors = {};

      if (this.projects.length) return;

      this.loading = true;
      try {
        var resp = await this.$pb.api('/work-items/create-options');
        this.projects = resp.projects || [];
        // One project to choose from is not a choice — pick it and get out of the way.
        if (this.projects.length === 1) await this.pickProject(String(this.projects[0].id));
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not load your projects.'), 'error');
      }
      this.loading = false;
    },

    /**
     * Load the chosen project's vocabulary.
     *
     * Everything below the project picker is that project's own — its states, its labels, its
     * members — so changing the project has to reset them rather than carry a state id that
     * would be refused on submit.
     */
    pickProject: async function (value) {
      this.projectId = value;
      this.options = null;
      this.storeUrl = '';
      this.form.state_id = '';
      this.form.assignee_id = '';
      this.errors = {};

      if (!value) return;

      this.loading = true;
      try {
        var resp = await this.$pb.api('/projects/' + encodeURIComponent(value) + '/work-items/options');
        this.options = resp.options || null;
        this.storeUrl = resp.store || '';
        this.form.state_id = this.options && this.options.defaultStateId
          ? String(this.options.defaultStateId) : '';
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not load that project.'), 'error');
      }
      this.loading = false;
    },

    save: async function () {
      if (!this.canSubmit) return;
      this.saving = true;
      this.errors = {};

      try {
        var resp = await this.$pb.api(this.storeUrl, {
          method: 'POST',
          body: {
            title: this.form.title,
            description: this.form.description || '',
            state_id: this.form.state_id || '',
            priority: this.form.priority || 'none',
            // One assignee per work item (§4.3): sent as a list of one, or none.
            assignee_ids: this.form.assignee_id ? [Number(this.form.assignee_id)] : []
          }
        });

        // Not closed on success: the useful next move is usually to open what you just made,
        // and a modal that vanishes leaves you hunting for it in a project you are not on.
        this.created = resp.item || null;
        this.form = { title: '', description: '', state_id: this.form.state_id, priority: 'none', assignee_id: '' };
        this.$pb.toast('Work item created.');
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e, 'Could not create the work item.'), 'error');
      }
      this.saving = false;
    },

    openCreated: function () {
      if (this.created) window.location.href = '/projects/' + this.projectId + '/work-items/' + this.created.id;
    }
  },

  template:
    '<pb-modal :open="open" title="Create Work Item" width="max-w-[620px]" @close="open = false">' +

    '<div v-if="loading && !options" class="py-6 text-center text-[13px] text-sub">Loading…</div>' +

    '<template v-else>' +
    // Project first: everything below it belongs to whichever one is chosen.
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Project</label>' +
    '<pb-combo :model-value="projectId" @update:model-value="pickProject" :options="projectOptions" ' +
    'placeholder="Choose a project" />' +
    '<p v-if="!projects.length && !loading" class="text-[12px] text-sub mt-2">' +
    'You are not a member of any project that accepts new work items.</p>' +

    '<template v-if="projectId">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5 mt-4">Title</label>' +
    '<input v-model="form.title" class="pb-input" :class="{\'is-error\': errors.title}" ' +
    'placeholder="What needs doing?" @keyup.enter="save" />' +
    '<p v-if="errors.title" class="text-[12px] text-danger mt-1">{{ errors.title[0] }}</p>' +

    // A plain textarea, as the project's own create modal has: quick capture, with the full
    // editor waiting on the detail view where the description is actually written.
    '<textarea v-model="form.description" rows="3" class="pb-textarea mt-3" ' +
    'placeholder="Click to add description"></textarea>' +

    '<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">' +
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Status</label>' +
    '<pb-combo v-model="form.state_id" :options="stateOptions" :searchable="false" placeholder="Status" /></div>' +
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Priority</label>' +
    '<pb-combo v-model="form.priority" :options="priorityOptions" :searchable="false" placeholder="None" /></div>' +
    '<div><label class="block text-[12px] font-medium text-sub mb-1.5">Assignee</label>' +
    '<pb-combo v-model="form.assignee_id" :options="memberOptions" placeholder="Unassigned" /></div>' +
    '</div>' +

    '<div v-if="created" class="mt-4 rounded-md border border-line bg-hover px-3 py-2.5 flex items-center gap-2">' +
    '<span class="text-[13px] text-ink flex-1 truncate">' +
    '<span class="text-sub">{{ created.identifier }}</span> {{ created.title }}</span>' +
    '<button type="button" @click="openCreated" class="text-[13px] font-semibold text-brand hover:underline shrink-0">Open</button>' +
    '</div>' +
    '</template>' +
    '</template>' +

    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="open = false">{{ created ? \'Done\' : \'Cancel\' }}</button>' +
    '<button type="button" :disabled="!canSubmit" @click="save" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ saving ? \'Creating…\' : \'Create work item\' }}</button>' +
    '</template></pb-modal>'
}, { root: 'work-item-create-root' });
