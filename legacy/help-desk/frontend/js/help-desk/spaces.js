/* Help Desk › Spaces (Workspace & Inbox Assignment requirements §8, §12, §19).
   ------------------------------------------------------------------
   A space is a separate support operation inside one Help Desk — a brand, a business unit, a
   region. This is §8's table: what each one holds, and the actions on it.

   Two things this screen goes out of its way to say:

   - UNASSIGNED INBOXES are listed at the top, not left to be discovered. An inbox in no space
     still receives customer mail, and mail arriving somewhere nobody is looking at is the
     failure this whole section exists to prevent;
   - ARCHIVING is refused while a space still holds inboxes, and the error says so. The
     alternative is orphaning five hundred conversations to make a row disappear from a list.
   ------------------------------------------------------------------ */
PB.boot('help-desk-spaces', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      spaces: b.spaces || [],
      unassigned: b.unassigned || [],
      typeSuggestions: b.type_suggestions || [],
      canManage: !!b.can_manage,
      showArchived: !!b.show_archived,
      endpoints: b.endpoints || {},
      edit: { open: false, space: null, saving: false, errors: {}, model: { name: '', description: '', types: [] } },
      archiving: null,
      busy: false
    };
  },
  methods: {
    avatarStyle: function (space) {
      return { backgroundColor: space.color || '#64748B' };
    },
    openEdit: function (space) {
      this.edit = {
        open: true, space: space, saving: false, errors: {},
        // A copy, not the row's own array — closing the dialog must not leave edits behind.
        model: { name: space.name || '', description: space.description || '', types: (space.types || []).slice() }
      };
    },
    save: async function () {
      if (!String(this.edit.model.name || '').trim() || this.edit.saving) return;
      this.edit.saving = true; this.edit.errors = {};

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.update, this.edit.space.id), {
          method: 'PATCH',
          body: {
            name: String(this.edit.model.name).trim(),
            description: this.edit.model.description || null,
            types: this.edit.model.types
          }
        });
        this.spaces = resp.spaces || this.spaces;
        this.edit.open = false;
        this.$pb.toast('Space saved.');
      } catch (e) {
        this.edit.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.edit.saving = false;
    },
    archive: async function () {
      var space = this.archiving;
      this.archiving = null;
      if (!space || this.busy) return;
      this.busy = true;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.archive, space.id), { method: 'POST' });
        this.spaces = resp.spaces || this.spaces;
        this.$pb.toast('Space archived.');
      } catch (e) {
        // The refusal that matters: "move its inboxes first". Shown as it came back, because it
        // names the number and tells the reader what to do about it.
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.busy = false;
    },
    restore: async function (space) {
      if (this.busy) return;
      this.busy = true;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.restore, space.id), { method: 'POST' });
        this.spaces = resp.spaces || this.spaces;
        this.$pb.toast('Space restored.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.busy = false;
    },
    toggleArchived: function () {
      window.location = window.location.pathname + (this.showArchived ? '' : '?archived=1');
    }
  },
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    '<div class="flex items-start justify-between gap-4">' +
    '<pb-section-head title="Spaces" ' +
    'desc="A space is a separate support operation — a brand, a business unit, a region or a team. Each one has its own inboxes and its own conversations, run independently under this Help Desk."/>' +
    '<a v-if="canManage" :href="endpoints.create" class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 shrink-0 flex items-center">New space</a>' +
    '</div>' +

    // ---- unassigned inboxes (§12) ----
    '<div v-if="unassigned.length" class="_moretogether-notice px-4 py-3 mb-5">' +
    '<div class="text-[13px] font-semibold text-ink">' +
    '{{ unassigned.length }} inbox<span v-if="unassigned.length !== 1">es</span> not in a space</div>' +
    '<p class="text-[12px] text-sub mt-0.5">They still receive mail. Assign them so their conversations show up where somebody is looking.</p>' +
    '<div class="mt-2 flex flex-wrap gap-2">' +
    '<span v-for="i in unassigned" :key="i.id" class="px-2 h-6 rounded-md bg-white border border-line text-[12px] text-ink flex items-center">{{ i.name }}</span>' +
    '</div></div>' +

    // ---- §19's empty state ----
    '<pb-empty v-if="!spaces.length" title="Create your first space" ' +
    'subtitle="Spaces help you organize support teams, inboxes, customers and conversations by business, department or support function.">' +
    '<a v-if="canManage" :href="endpoints.create" class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 flex items-center">Create space</a>' +
    '</pb-empty>' +

    // ---- §8's list ----
    '<div v-else class="space-y-3">' +
    '<div v-for="s in spaces" :key="s.id" class="border border-line rounded-xl px-4 py-3" :class="{\'opacity-60\': s.archived}">' +
    '<div class="flex items-start gap-3">' +
    '<div class="h-9 w-9 shrink-0 rounded-lg grid place-items-center text-white text-[14px] font-semibold" :style="avatarStyle(s)">{{ s.initial }}</div>' +

    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2 flex-wrap">' +
    '<a :href="s.url" class="text-[14px] font-medium text-ink hover:text-brand">{{ s.name }}</a>' +
    '<span v-for="t in s.types" :key="t" class="_moretogether-tag _moretogether-tag--static">{{ t }}</span>' +
    '<span v-if="s.archived" class="_moretogether-badge _moretogether-badge--off">Archived</span>' +
    // The Setup flow's status column, on the row rather than in a table of its own.
    '<span v-else class="_moretogether-badge" ' +
    ':class="s.inbox_status === \'Connected\' ? \'_moretogether-badge--ok\' : (s.setup_complete ? \'_moretogether-badge--wait\' : \'\')">' +
    '{{ s.inbox_status }}</span>' +
    '</div>' +
    '<p v-if="s.description" class="text-[12px] text-sub mt-0.5">{{ s.description }}</p>' +

    '<div v-if="s.inbox_name" class="text-[12px] text-sub mt-0.5">Inbox: {{ s.inbox_name }}</div>' +

    '<div class="mt-2 flex items-center gap-3 text-[12px] text-faint">' +
    '<span>{{ s.inboxes_count }} inbox<span v-if="s.inboxes_count !== 1">es</span></span>' +
    '<span>·</span>' +
    '<span>{{ s.members_count }} member<span v-if="s.members_count !== 1">s</span></span>' +
    '<span>·</span>' +
    '<span>{{ s.open_conversations }} open conversation<span v-if="s.open_conversations !== 1">s</span></span>' +
    '</div></div>' +

    // Icons rather than words, each with a tooltip for the pointer and an aria-label for
    // everything else — an icon with neither is a button whose meaning only its author knows.
    '<div class="flex items-center gap-1 shrink-0">' +
    // One primary action per row, and which one it is says where the space has got to.
    '<a v-if="canManage && !s.archived && !s.setup_complete" :href="s.setup_url" ' +
    'class="h-8 px-3 rounded-md bg-brand text-white text-[12px] font-semibold hover:opacity-90 flex items-center mr-1">' +
    'Continue to Setup Inbox</a>' +
    '<a v-else-if="s.inbox_url && !s.archived" :href="s.inbox_url" ' +
    'class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover flex items-center mr-1">' +
    'Open Inbox</a>' +
    '<a :href="s.url" class="text-[12px] text-sub hover:text-ink mr-2">Open</a>' +
    '<button v-if="canManage && !s.archived" class="_moretogether-iconbtn" ' +
    'data-tip="Edit" aria-label="Edit space" @click="openEdit(s)">' + wiIcon('pen', 15) + '</button>' +
    '<button v-if="canManage && !s.archived" class="_moretogether-iconbtn _moretogether-iconbtn--danger" ' +
    'data-tip="Archive" aria-label="Archive space" @click="archiving = s">' + wiIcon('trash-can', 15) + '</button>' +
    '<button v-if="canManage && s.archived" class="_moretogether-iconbtn" ' +
    'data-tip="Restore" aria-label="Restore space" :disabled="busy" @click="restore(s)">' + wiIcon('rotate-left', 15) + '</button>' +
    '</div>' +
    '</div></div></div>' +

    '<button class="mt-4 text-[12px] text-sub hover:text-ink" @click="toggleArchived">' +
    '{{ showArchived ? \'Hide archived spaces\' : \'Show archived spaces\' }}</button>' +

    // ---- edit ----
    '<pb-modal :open="edit.open" title="Edit space" @close="edit.open = false">' +
    '<div class="space-y-4">' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Name</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!edit.errors.name}" v-model="edit.model.name"/>' +
    '<p v-if="edit.errors.name" class="text-[12px] text-danger mt-1">{{ edit.errors.name[0] }}</p></div>' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Description</label>' +
    '<input class="pb-input" v-model="edit.model.description" placeholder="Support workspace for partners and resellers."/></div>' +
    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Type</label>' +
    '<pb-tags v-model="edit.model.types" :suggestions="typeSuggestions" placeholder="Type a value and press Enter"/>' +
    '<p class="text-[12px] text-sub mt-1.5">Add as many as fit — a space is often more than one thing. Press Enter or comma after each.</p>' +
    '<p v-if="edit.errors.types" class="text-[12px] text-danger mt-1">{{ edit.errors.types[0] }}</p></div>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="edit.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!edit.model.name || edit.saving" @click="save">Save space</button>' +
    '</template></pb-modal>' +

    '<pb-confirm :open="!!archiving" title="Archive this space?" ' +
    'message="It disappears from the switcher and the list. Nothing is deleted, and you can restore it later — but a space still holding inboxes has to have them moved first." ' +
    'confirm-label="Archive" @close="archiving = null" @confirm="archive"/>' +

    '</div>'
}, { root: 'help-desk-spaces-root' });
