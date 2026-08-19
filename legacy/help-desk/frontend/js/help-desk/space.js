/* Help Desk › a space (Workspace & Inbox Assignment requirements §7, §9, §10, §11).
   ------------------------------------------------------------------
   §9's detail page: the space, its inboxes, and the ways out of it into everything scoped to it.

   §7's confirmation is the piece worth reading twice. Moving an inbox between spaces is the one
   action here that changes something for people who are not looking at this screen, so it is
   confirmed with the message §7 specifies — naming both ends, and stating plainly that the
   conversations, contacts, routing and email configuration stay attached to the inbox. That
   sentence is true by construction rather than by promise: a move writes one column.
   ------------------------------------------------------------------ */
PB.boot('help-desk-space', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      space: b.space || {},
      inboxes: b.inboxes || [],
      assignable: b.assignable || [],
      typeSuggestions: b.type_suggestions || [],
      canManage: !!b.can_manage,
      endpoints: b.endpoints || {},
      assign: { open: false, selected: [], query: '', saving: false },
      confirming: null,
      removing: null,
      busy: false
    };
  },
  computed: {
    filtered: function () {
      var q = String(this.assign.query || '').trim().toLowerCase();
      if (!q) return this.assignable;
      return this.assignable.filter(function (i) {
        return String(i.name || '').toLowerCase().indexOf(q) !== -1
          || String(i.inbound_address || '').toLowerCase().indexOf(q) !== -1;
      });
    },
    // The ones being taken from somewhere else — what §7's confirmation is about.
    moving: function () {
      var chosen = this.assign.selected;
      return this.assignable.filter(function (i) {
        return chosen.indexOf(i.id) !== -1 && i.space_id;
      });
    },
    // §7's message, with both ends named.
    moveMessage: function () {
      var names = this.moving.map(function (i) { return '"' + i.name + '" from ' + i.space; });
      return 'Move ' + names.join(', ') + ' to "' + this.space.name + '"?\n\n'
        + 'Existing conversations, contacts, routing rules and email configuration will remain attached to the inbox.';
    }
  },
  methods: {
    avatarStyle: function () { return { backgroundColor: this.space.color || '#64748B' }; },
    toggle: function (inbox) {
      var at = this.assign.selected.indexOf(inbox.id);
      if (at === -1) this.assign.selected.push(inbox.id); else this.assign.selected.splice(at, 1);
    },
    isSelected: function (inbox) { return this.assign.selected.indexOf(inbox.id) !== -1; },
    openAssign: function () {
      this.assign = { open: true, selected: [], query: '', saving: false };
    },
    // Nothing being taken from another space goes straight through; anything that is asks
    // first, in §7's words.
    submitAssign: function () {
      if (!this.assign.selected.length) return;
      if (this.moving.length) { this.confirming = true; return; }
      this.doAssign();
    },
    doAssign: async function () {
      this.confirming = null;
      if (this.assign.saving) return;
      this.assign.saving = true;

      try {
        var resp = await this.$pb.api(this.endpoints.assign, {
          method: 'POST',
          body: { inbox_ids: this.assign.selected }
        });
        this.apply(resp);
        this.assign.open = false;
        this.$pb.toast(resp.moved === 1 ? 'Inbox assigned.' : resp.moved + ' inboxes assigned.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.assign.saving = false;
    },
    remove: async function () {
      var inbox = this.removing;
      this.removing = null;
      if (!inbox || this.busy) return;
      this.busy = true;

      try {
        var resp = await this.$pb.api(inbox.unassign_url, { method: 'DELETE' });
        this.apply(resp);
        this.$pb.toast('Inbox removed from this space.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.busy = false;
    },
    apply: function (resp) {
      this.space = resp.space || this.space;
      this.inboxes = resp.inboxes || this.inboxes;
      this.assignable = resp.assignable || this.assignable;
    }
  },
  template:
    '<div class="max-w-[980px] mx-auto px-5 sm:px-8 py-8">' +

    // ---- §9's header ----
    '<div class="flex items-start gap-4">' +
    '<div class="h-11 w-11 shrink-0 rounded-xl grid place-items-center text-white text-[16px] font-semibold" :style="avatarStyle()">' +
    '{{ space.initial }}</div>' +
    '<div class="min-w-0 flex-1">' +
    '<h1 class="text-[20px] font-bold text-head">{{ space.name }}</h1>' +
    '<p v-if="space.description" class="text-[13px] text-sub mt-0.5">{{ space.description }}</p>' +
    '<div v-if="space.types && space.types.length" class="mt-1.5 flex items-center gap-1.5 flex-wrap">' +
    '<span v-for="t in space.types" :key="t" class="_moretogether-tag _moretogether-tag--static">{{ t }}</span>' +
    '</div>' +
    '<div class="mt-1 flex items-center gap-3 text-[12px] text-faint">' +
    '<span>{{ space.inboxes_count }} inbox<span v-if="space.inboxes_count !== 1">es</span></span>' +
    '<span>·</span>' +
    '<span>{{ space.open_conversations }} open conversation<span v-if="space.open_conversations !== 1">s</span></span>' +
    '</div></div></div>' +

    // ---- §9's secondary navigation, pointing at what this space scopes ----
    '<nav class="mt-5 flex items-center gap-1 border-b border-line pb-2">' +
    '<span class="h-7 px-3 grid place-items-center rounded-md bg-sel text-brand text-[12px] font-medium">Inboxes</span>' +
    '<a :href="endpoints.conversations" class="h-7 px-3 grid place-items-center rounded-md text-sub hover:bg-hover text-[12px]">Conversations</a>' +
    '<a v-if="canManage" :href="endpoints.members" class="h-7 px-3 grid place-items-center rounded-md text-sub hover:bg-hover text-[12px]">Team</a>' +
    '</nav>' +

    // ---- §10's inbox section ----
    '<div class="mt-5 flex items-center justify-between gap-4">' +
    '<h2 class="text-[14px] font-semibold text-ink">Inboxes</h2>' +
    '<div v-if="canManage" class="flex items-center gap-2">' +
    // §11: a new inbox created from here arrives already assigned to this space.
    '<a :href="endpoints.new_inbox" class="h-8 px-3 rounded-md border border-stroke text-[12px] font-semibold text-ink hover:bg-hover flex items-center">New inbox</a>' +
    '<button class="h-8 px-3 rounded-md bg-brand text-white text-[12px] font-semibold hover:opacity-90" @click="openAssign">Assign inbox</button>' +
    '</div></div>' +

    '<pb-empty v-if="!inboxes.length" title="No inboxes in this space" ' +
    'subtitle="Assign an existing inbox, or create one here — a new inbox created from this space is assigned to it automatically." class="mt-3"/>' +

    '<div v-else class="mt-3 space-y-3">' +
    '<div v-for="i in inboxes" :key="i.id" class="border border-line rounded-xl px-4 py-3">' +
    '<div class="flex items-start gap-3">' +
    '<div class="min-w-0 flex-1">' +
    '<div class="text-[14px] font-medium text-ink">{{ i.name }}</div>' +
    '<div class="text-[12px] text-sub _moretogether-break mt-0.5">{{ i.inbound_address }}</div>' +
    '<div class="mt-1 text-[12px] text-faint">{{ i.conversations_count }} conversation<span v-if="i.conversations_count !== 1">s</span></div>' +
    '</div>' +
    '<div class="flex items-center gap-3 shrink-0 text-[12px]">' +
    '<a :href="i.addresses_url" class="text-sub hover:text-ink">Email addresses</a>' +
    '<button v-if="canManage" class="text-sub hover:text-ink" @click="removing = i">Remove</button>' +
    '</div></div></div></div>' +

    // ---- assign dialog (§6) ----
    '<pb-modal :open="assign.open" title="Assign inboxes to this space" @close="assign.open = false" width="max-w-[560px]">' +
    '<div>' +
    '<p class="text-[12px] text-sub">An inbox belongs to one space. Anything already assigned elsewhere will be moved.</p>' +
    '<input v-if="assignable.length > 6" class="pb-input mt-3" v-model="assign.query" placeholder="Search inboxes by name or address"/>' +
    '<div v-if="!assignable.length" class="mt-3 text-[12px] text-sub">Every inbox in this Help Desk is already in this space.</div>' +
    '<div v-else class="mt-3 space-y-1 max-h-[320px] overflow-y-auto">' +
    '<label v-for="i in filtered" :key="i.id" class="flex items-start gap-3 px-2 py-2 rounded-md hover:bg-hover cursor-pointer">' +
    '<input type="checkbox" class="mt-0.5" :checked="isSelected(i)" @change="toggle(i)"/>' +
    '<span class="min-w-0 flex-1">' +
    '<span class="block text-[13px] text-ink">{{ i.name }}</span>' +
    '<span class="block text-[12px] text-sub _moretogether-break">{{ i.inbound_address }}</span>' +
    '<span v-if="i.space" class="block text-[12px] text-warning">Currently in {{ i.space }} — will be moved</span>' +
    '<span v-else class="block text-[12px] text-faint">Not in a space</span>' +
    '</span></label>' +
    '</div></div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="assign.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!assign.selected.length || assign.saving" @click="submitAssign">Assign</button>' +
    '</template></pb-modal>' +

    // §7's confirmation, word for word on the consequence.
    '<pb-confirm :open="!!confirming" title="Move inbox?" :message="moveMessage" ' +
    'confirm-label="Move inbox" @close="confirming = null" @confirm="doAssign"/>' +

    '<pb-confirm :open="!!removing" title="Remove this inbox from the space?" ' +
    'message="The inbox keeps its conversations, its email addresses and everything else. It simply belongs to no space until you assign it to one — and it goes on receiving mail in the meantime." ' +
    'confirm-label="Remove" @close="removing = null" @confirm="remove"/>' +

    '</div>'
}, { root: 'help-desk-space-root' });
