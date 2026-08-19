/* Help Desk — conversations (docs/features/help-desk.md — Phase 2, FR-2.8 routing, FR-2.9's
   visible half).
   ------------------------------------------------------------------
   A list and a move. Reading and replying are Phase 3's conversation workspace; what is here is
   what Phase 2 has to be able to demonstrate — that a conversation can be refiled into another
   inbox you are authorized for, and that a reply which never reached the customer says so on
   the row rather than sitting there looking handled.

   The inbox filter is a LINK, not a client-side filter: the list is paginated on the server, so
   filtering in the browser would show "25 of the ones on this page" and call it a filter.
   ------------------------------------------------------------------ */
PB.boot('help-desk-conversations', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      conversations: b.conversations || [],
      inboxes: b.inboxes || [],
      selected: b.selected || 0,
      canMove: !!b.canMove,
      endpoints: b.endpoints || {},
      move: { open: false, conversation: null, inboxId: '', saving: false }
    };
  },
  computed: {
    // Where a conversation can go: every inbox this person may open, except the one it is
    // already in. Offering its current inbox would be offering to do nothing.
    moveOptions: function () {
      var current = this.move.conversation ? this.move.conversation.inbox_id : 0;
      return this.inboxes
        .filter(function (i) { return i.id !== current; })
        .map(function (i) { return { value: String(i.id), label: i.name }; });
    },
    failing: function () {
      return this.conversations.filter(function (c) { return c.delivery_failed; }).length;
    }
  },
  methods: {
    inboxUrl: function (id) {
      var url = new URL(window.location.href);
      if (id) { url.searchParams.set('inbox', id); } else { url.searchParams.delete('inbox'); }
      url.searchParams.delete('page');
      return url.toString();
    },
    openMove: function (conversation) {
      this.move = { open: true, conversation: conversation, inboxId: '', saving: false };
    },
    submitMove: async function () {
      if (!this.move.inboxId || this.move.saving) return;
      this.move.saving = true;
      var conversation = this.move.conversation;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.move, conversation.id), {
          method: 'PATCH', body: { inbox_id: this.move.inboxId }
        });
        conversation.inbox_id = resp.inbox_id;
        conversation.inbox = resp.inbox;
        // The assignee can change with the move — somebody who cannot open the destination
        // does not keep a case they can no longer see — so the row is told, not guessed at.
        conversation.assignee = resp.assignee;
        this.move.open = false;
        this.$pb.toast('Moved to ' + resp.inbox + '.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.move.saving = false;
    }
  },
  template:
    '<div class="max-w-[1080px] mx-auto px-5 sm:px-8 py-6">' +

    // ---- inbox filter ----
    '<div v-if="inboxes.length" class="flex items-center gap-1 flex-wrap">' +
    '<a :href="inboxUrl(0)" :class="[\'h-8 px-3 grid place-items-center rounded-md text-[12px]\', !selected ? \'bg-sel text-brand font-semibold\' : \'text-sub hover:bg-hover\']">All inboxes</a>' +
    '<a v-for="i in inboxes" :key="i.id" :href="inboxUrl(i.id)" ' +
    ':class="[\'h-8 px-3 grid place-items-center rounded-md text-[12px]\', selected === i.id ? \'bg-sel text-brand font-semibold\' : \'text-sub hover:bg-hover\']">{{ i.name }}</a>' +
    '</div>' +

    '<p v-if="failing" class="mt-3 text-[12px] text-danger">' +
    '{{ failing }} conversation<span v-if="failing !== 1">s</span> on this page had a reply that never reached the customer.</p>' +

    // ---- the list ----
    '<div v-if="conversations.length" class="mt-4 border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="c in conversations" :key="c.id" class="flex items-start gap-3 px-4 py-3">' +

    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[12px] text-faint shrink-0">{{ c.reference }}</span>' +
    '<span class="text-[13px] text-ink truncate">{{ c.subject }}</span>' +
    // Said on the row, because a bounce nobody sees is the worst failure a help desk has.
    '<span v-if="c.delivery_failed" class="shrink-0 text-[11px] font-semibold text-white bg-danger rounded px-1.5 py-0.5">Not delivered</span>' +
    '</div>' +
    '<div class="mt-0.5 text-[12px] text-sub truncate">{{ c.customer }} · {{ c.customer_email }}</div>' +
    '<div class="mt-0.5 text-[12px] text-faint">' +
    '{{ c.inbox }} · {{ c.messages }} message<span v-if="c.messages !== 1">s</span>' +
    '<span v-if="c.assignee"> · {{ c.assignee }}</span>' +
    '<span v-else> · Unassigned</span>' +
    '<span v-if="c.last_message"> · {{ c.last_message }}</span>' +
    '</div></div>' +

    '<button v-if="canMove && inboxes.length > 1" class="text-[12px] text-sub hover:text-ink shrink-0" @click="openMove(c)">Move</button>' +
    '</div></div>' +

    // ---- empty states, which are three different facts ----
    '<div v-else class="mt-4 border border-dashed border-stroke rounded-xl px-6 py-10 text-center">' +
    '<div v-if="!inboxes.length">' +
    '<div class="text-[14px] font-medium text-head">No inboxes are open to you</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Conversations live in inboxes. A Help Desk admin can give you access to one — ' +
    'administering the workspace is not the same as working in the Help Desk.</p></div>' +
    '<div v-else>' +
    '<div class="text-[14px] font-medium text-head">Nothing here yet</div>' +
    '<p class="text-[13px] text-sub mt-1 max-w-md mx-auto">Conversations appear when customers email an inbox\'s address. ' +
    'Check that the inbox has one in Settings → Inboxes.</p></div>' +
    '</div>' +

    // ---- move dialog ----
    '<pb-modal :open="move.open" title="Move conversation" @close="move.open = false">' +
    '<div v-if="move.conversation">' +
    '<p class="text-[13px] text-sub mb-3">{{ move.conversation.reference }} “{{ move.conversation.subject }}” is in {{ move.conversation.inbox }}.</p>' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Move to</label>' +
    '<pb-combo v-model="move.inboxId" :options="moveOptions" placeholder="Choose an inbox…"/>' +
    '<p class="text-[12px] text-sub mt-2">The conversation keeps its number and its history. ' +
    'If the person it is assigned to cannot open the destination, it becomes unassigned.</p>' +
    '</div>' +
    '<template #footer>' +
    '<button class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="move.open = false">Cancel</button>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!move.inboxId || move.saving" @click="submitMove">Move</button>' +
    '</template></pb-modal>' +

    '</div>'
}, { root: 'help-desk-conversations-root' });
