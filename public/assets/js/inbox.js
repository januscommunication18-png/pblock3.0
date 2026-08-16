/* Inbox — the things that need your attention (docs/features/inbox.md).
   ------------------------------------------------------------------
   Two panes: the stream on the left, the work item it points at on the right, so a
   notification can be dealt with without leaving the Inbox (§3).

   The read rule is the one worth reading the code for (§6). A row is marked read only AFTER
   its detail has loaded — never on the click — so a failed fetch cannot silently consume a
   notification. And a read row STAYS in the list, greyed, until you select another one or
   leave; a list that deletes the row you just clicked, out from under the cursor, makes people
   distrust the whole panel.
   ------------------------------------------------------------------ */
PB.boot('inbox', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      tab: b.tab || 'all',
      items: (b.items || []).slice(),
      counts: b.counts || { all: 0, assignment: 0, mention: 0 },
      urls: b.urls || {},
      selected: null,
      // Rows read during THIS visit. They stay on screen (§6) but are drawn as dealt with;
      // the next load will not include them.
      readNow: [],
      search: '',
      loading: false,
      // The detail pane's own state — §39/§40 want a loading state and a real error, not an
      // empty panel that leaves you wondering.
      detail: { loading: false, error: '', url: '' }
    };
  },
  computed: {
    tabs: function () {
      return [
        { key: 'all', label: 'All', count: this.counts.all },
        { key: 'assignment', label: 'Assigned', count: this.counts.assignment },
        { key: 'mention', label: 'Mentions', count: this.counts.mention }
      ];
    },
    /** §23: the empty state names the stream you are looking at, not "nothing found". */
    emptyState: function () {
      if (this.tab === 'assignment') {
        return { title: 'You’re all caught up', body: 'New work items assigned to you will appear here.' };
      }
      if (this.tab === 'mention') {
        return { title: 'No new mentions', body: 'When someone @mentions you in a work item or comment, it will appear here.' };
      }

      return { title: 'You’re all caught up', body: 'Assignments and mentions that need your attention will appear here.' };
    }
  },
  mounted: function () {
    var self = this;

    // §27: a notification that arrives while the Inbox is open appears without a refresh.
    // Only ADDS — the socket is a shortcut past a reload, never the source of truth, so
    // everything still works when Reverb is not running (see realtime.js).
    if (window.PB && typeof window.PB.onInbox === 'function') {
      window.PB.onInbox(function (payload) { self.receive(payload); });
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    /**
     * A notification arrived over the wire.
     *
     * Prepended rather than merged blindly: the same row can arrive twice if a reload races
     * the socket, and a duplicate in an attention list is worse than a late one. Rows for a
     * stream you are not looking at only move the counts — swapping the list under somebody
     * mid-read is exactly what §6 is careful to avoid.
     */
    receive: function (payload) {
      if (!payload || !payload.item) return;

      if (payload.counts) this.counts = payload.counts;

      var item = payload.item;
      if (this.tab !== 'all' && this.tab !== item.type) return;
      if (this.items.some(function (i) { return i.id === item.id; })) return;
      // A search is a narrowed view; dropping an unrelated row into it would be a lie.
      if ((this.search || '').trim() !== '') return;

      this.items = [item].concat(this.items);
    },
    isRead: function (item) { return this.readNow.indexOf(item.id) > -1; },

    when: function (iso) {
      if (!iso) return '';
      var mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + 'm ago';
      if (mins < 1440) return Math.floor(mins / 60) + 'h ago';
      return Math.floor(mins / 1440) + 'd ago';
    },

    /** What the row says happened. The actor's name leads, because that is who wants you. */
    headline: function (item) {
      var who = item.actor ? item.actor.name : 'Someone';

      return item.type === 'mention' ? who + ' mentioned you' : who + ' assigned this to you';
    },

    // ---- the list ----------------------------------------------------------------------

    switchTab: function (key) {
      if (this.tab === key) return;
      this.tab = key;
      // Leaving a stream is one of the two moments read rows are allowed to vanish (§6).
      this.readNow = [];
      this.selected = null;
      this.reload();
    },

    reload: async function () {
      this.loading = true;
      try {
        var url = this.urls.list + '?tab=' + encodeURIComponent(this.tab)
          + '&search=' + encodeURIComponent(this.search || '');
        var resp = await this.$pb.api(url);
        this.items = resp.items || [];
        this.counts = resp.counts || this.counts;
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not load your inbox.'), 'error');
      }
      this.loading = false;
    },

    onSearch: function () {
      clearTimeout(this._searchTimer);
      var self = this;
      this._searchTimer = setTimeout(function () { self.reload(); }, 250);
    },

    // ---- opening a notification ---------------------------------------------------------

    /**
     * §6's sequence, in order: select → load → mark read.
     *
     * Nothing is marked read on the click itself. If the work item cannot be loaded — deleted,
     * or access withdrawn since the notification was written (§31) — the row keeps its unread
     * state and the panel says what went wrong.
     */
    open: async function (item) {
      this.selected = item.id;
      this.detail = { loading: true, error: '', url: '' };

      if (!item.readable) {
        // §31/§40: checked server-side on every list, because permissions change after a
        // notification is written.
        this.detail = { loading: false, url: '', error: 'You no longer have access to this work item.' };

        return;
      }

      var url = this.$pb.withId(this.urls.frame, item.work_item_id)
        .replace('__PROJECT__', item.project_id);
      // §13: a mention on a comment opens straight to it and highlights it.
      if (item.comment_id) url += '?tab=comments#comment-' + item.comment_id;

      this.detail = { loading: false, error: '', url: url };
    },

    /** The frame reported it is up — only now is the notification dealt with (§6). */
    onDetailLoaded: async function () {
      var id = this.selected;
      if (!id || this.isRead({ id: id })) return;

      try {
        var resp = await this.$pb.api(this.$pb.withId(this.urls.read, id), { method: 'POST' });
        this.counts = resp.counts || this.counts;
        // Kept on screen, marked dealt with — see the note at the top of this file.
        if (this.readNow.indexOf(id) === -1) this.readNow.push(id);
      } catch (e) { /* still unread; the next load will show it again */ }
    },

    onDetailError: function () {
      this.detail = {
        loading: false, url: '',
        error: 'We couldn’t load this work item. Try again or return to your inbox.'
      };
    },

    markAllRead: async function () {
      try {
        var resp = await this.$pb.api(this.urls.readAll, { method: 'POST', body: { tab: this.tab } });
        this.items = resp.items || [];
        this.counts = resp.counts || this.counts;
        this.readNow = [];
        this.selected = null;
        this.detail = { loading: false, error: '', url: '' };
        this.$pb.toast('Inbox cleared.');
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not clear your inbox.'), 'error');
      }
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex">' +

    // ============ left: the stream ============
    '<section class="w-[380px] shrink-0 border-r border-line flex flex-col min-h-0">' +
    '<div class="h-12 shrink-0 px-4 flex items-center gap-2 border-b border-line">' +
    '<button data-sidebar-expand class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'title="Expand sidebar" v-html="icon(\'sidebar\', 15)"></button>' +
    '<h1 class="text-[14px] font-semibold text-head">Inbox</h1>' +
    '<button class="ml-auto h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'data-tip="Mark all as read" aria-label="Mark all as read" @click="markAllRead" v-html="icon(\'check-large\', 16)"></button>' +
    '<button class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'data-tip="Refresh" aria-label="Refresh" @click="reload" v-html="icon(\'rotate\', 15)"></button>' +
    '</div>' +

    // Tabs + search
    '<div class="shrink-0 px-4 py-2.5 flex items-center gap-2 border-b border-line">' +
    '<div class="inline-flex items-center rounded-md border border-line overflow-hidden">' +
    '<button v-for="t in tabs" :key="t.key" @click="switchTab(t.key)" ' +
    'class="inline-flex items-center gap-1.5 h-7 px-2.5 text-[12px] border-r border-line last:border-r-0" ' +
    ':class="tab === t.key ? \'bg-sel text-brand font-semibold\' : \'text-sub hover:bg-hover\'">' +
    '{{ t.label }}<span v-if="t.count" class="text-[11px] font-semibold">{{ t.count }}</span>' +
    '</button></div>' +
    '<div class="relative flex-1 min-w-0">' +
    '<input v-model="search" @input="onSearch" placeholder="Search" ' +
    'class="w-full h-7 pl-7 pr-2 rounded-md bg-hover text-[12px] text-ink placeholder:text-faint outline-none focus:bg-white focus:outline focus:outline-1 focus:outline-stroke" />' +
    '<span class="absolute left-2 top-1/2 -translate-y-1/2 text-faint" v-html="icon(\'magnifying-glass\', 13)"></span>' +
    '</div></div>' +

    // §39: skeleton rows rather than a blank panel.
    '<div v-if="loading" class="p-4 space-y-3">' +
    '<div v-for="n in 4" :key="n" class="animate-pulse"><div class="h-3 w-2/3 rounded bg-hover"></div>' +
    '<div class="h-3 w-1/3 rounded bg-hover mt-2"></div></div>' +
    '</div>' +

    '<div v-else-if="!items.length" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-[260px]">' +
    '<div class="text-[14px] font-medium text-head">{{ emptyState.title }}</div>' +
    '<p class="text-[13px] text-sub mt-1">{{ emptyState.body }}</p>' +
    '</div></div>' +

    '<div v-else class="flex-1 min-h-0 overflow-y-auto">' +
    '<button v-for="i in items" :key="i.id" @click="open(i)" ' +
    'class="w-full text-left px-4 py-3 border-b border-line hover:bg-hover" ' +
    ':class="[selected === i.id ? \'bg-sel\' : \'\', isRead(i) ? \'opacity-55\' : \'\']">' +
    '<div class="flex items-start gap-2.5">' +
    // Unread dot — identity is never the row's colour alone.
    '<span class="mt-1.5 h-2 w-2 rounded-full shrink-0" :class="isRead(i) ? \'bg-transparent\' : \'bg-brand\'"></span>' +
    '<span class="h-6 w-6 rounded-full overflow-hidden grid place-items-center text-white text-[10px] font-bold shrink-0" ' +
    ':style="{ background: $pb.avatarColor(i.actor) }">' +
    '<img v-if="i.actor && i.actor.avatar_url" :src="i.actor.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ i.actor ? i.actor.initial : \'?\' }}</span></span>' +
    '<span class="min-w-0 flex-1">' +
    '<span class="block text-[13px] text-ink truncate"><span class="font-medium">{{ headline(i) }}</span></span>' +
    '<span class="block text-[12px] text-sub truncate mt-0.5">' +
    '<span v-if="i.identifier" class="text-faint">{{ i.identifier }}</span> {{ i.title }}</span>' +
    // Quoted only for a mention — that is somebody speaking. An assignment's excerpt is the
    // work item's description, and putting quotation marks round it would attribute the
    // product's own text to whoever did the assigning.
    '<span v-if="i.excerpt" class="block text-[12px] text-sub truncate mt-0.5" ' +
    ':class="i.type === \'mention\' ? \'italic\' : \'\'">' +
    '{{ i.type === \'mention\' ? \'“\' + i.excerpt + \'”\' : i.excerpt }}</span>' +
    '<span class="block text-[11px] text-faint mt-1">' +
    '<template v-if="i.project">{{ i.project.name }} · </template>{{ when(i.created_at) }}</span>' +
    '</span></div></button>' +
    '</div></section>' +

    // ============ right: the work item ============
    '<section class="flex-1 min-w-0 flex flex-col min-h-0">' +
    '<div v-if="detail.error" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-sm">' +
    '<div class="text-[14px] font-medium text-head">{{ detail.error }}</div>' +
    '<p class="text-[13px] text-sub mt-1">Pick another notification, or open the project directly.</p>' +
    '</div></div>' +
    '<div v-else-if="!detail.url" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-sm">' +
    '<div class="text-[14px] font-medium text-head">No notification selected</div>' +
    '<p class="text-[13px] text-sub mt-1">Select a notification to view its details.</p>' +
    '</div></div>' +
    // The chrome-less work item frame — the same detail the Views grid opens, so a work item
    // means the same thing here as everywhere else.
    '<iframe v-else :src="detail.url" class="flex-1 w-full border-0" title="Work item" ' +
    '@load="onDetailLoaded" @error="onDetailError"></iframe>' +
    '</section>' +

    '</div>'
});
