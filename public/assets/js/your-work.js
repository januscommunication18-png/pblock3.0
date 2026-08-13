/* Your Work — one person's work items across every project they can see.
   ------------------------------------------------------------------
   Five tabs. Three of them ARE the work items screen: <work-items-screen> is mounted with a
   multi-project payload, so a row here has the same chips, opens the same drawer and edits
   the same way as it does on its own project's list — because it is the same component.

   What makes that possible is the payload's `projects` map: the screen resolves each row's
   states, labels, members, cycles and endpoints from the project that row belongs to, rather
   than from one flat set that would only ever be right for one of them. Everything on this
   screen is the server's answer to "what may this person see" — see YourWorkController.

   The tab is a path segment, not client state: each tab is somewhere you can send a link to,
   and the back button should move between them. So switching tabs is a navigation, and the
   only thing this component does with the others is render their counts.
   ------------------------------------------------------------------ */
PB.boot('your-work', {
  props: { bootstrap: Object },
  components: { 'work-items-screen': WorkItemsScreen },
  data: function () {
    var b = this.bootstrap || {};
    return {
      tab: b.tab || 'summary',
      tabs: Array.isArray(b.tabs) ? b.tabs : [],
      // The list payload for THIS tab, or null on Summary and Activity.
      workItems: b.workItems || null,
      activity: Array.isArray(b.activity) ? b.activity : [],
      counts: b.counts || {}
    };
  },
  computed: {
    isList: function () { return !!this.workItems; },
    /** Nothing to show is a different thing from a tab that lists nothing — say which. */
    isEmpty: function () {
      return this.isList && !(this.workItems.items || []).length;
    },
    emptyText: function () {
      var text = {
        assigned: 'Nothing is assigned to you right now. Work items assigned to you in any project you can see will appear here.',
        created: 'You have not created any work items yet. Anything you open in any project you can see will appear here.',
        subscribed: 'You are not subscribed to any projects yet. Subscribe from a project’s settings to follow its work here.'
      };

      return text[this.tab] || '';
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },
    count: function (key) { return this.counts[key]; },

    when: function (iso) {
      if (!iso) return '';
      var mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + 'm ago';
      if (mins < 1440) return Math.floor(mins / 60) + 'h ago';
      return Math.floor(mins / 1440) + 'd ago';
    },

    /**
     * One activity line in words.
     *
     * Deliberately plain: the feed is a record of what you did, and "changed state from Todo
     * to In Progress" reads better in a list of a hundred than a chip cluster repeating the
     * row above it.
     */
    activityText: function (a) {
      if (a.event === 'created') return 'created';

      var field = String(a.field || '').replace(/_/g, ' ');
      if (a.old_value && a.new_value) return 'changed ' + field + ' from ' + a.old_value + ' to ' + a.new_value;
      if (a.new_value) return 'set ' + field + ' to ' + a.new_value;
      if (a.old_value) return 'cleared ' + field;

      return 'updated ' + (field || 'this item');
    }
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ============ header ============
    '<div class="h-12 shrink-0 px-4 sm:px-6 flex items-center gap-2 border-b border-line">' +
    '<button data-sidebar-expand class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover" ' +
    'title="Expand sidebar" v-html="icon(\'sidebar\', 15)"></button>' +
    '<span class="grid place-items-center text-sub" v-html="icon(\'user\', 16)"></span>' +
    '<h1 class="text-[14px] font-semibold text-head">Your work</h1>' +
    '</div>' +

    // ============ tabs ============
    // Anchors, not buttons: each tab is a URL, so middle-click and "open in new tab" work.
    '<nav class="h-10 shrink-0 px-4 sm:px-6 flex items-end gap-1 border-b border-line overflow-x-auto">' +
    '<a v-for="t in tabs" :key="t.key" :href="t.url" ' +
    'class="inline-flex items-center gap-1.5 h-9 px-3 text-[13px] whitespace-nowrap border-b-2 -mb-px" ' +
    ':class="t.key === tab ? \'border-brand text-brand font-semibold\' : \'border-transparent text-sub hover:text-ink\'">' +
    '{{ t.label }}' +
    '<span v-if="count(t.key) !== undefined" class="text-[11px] font-semibold rounded-full px-1.5 py-0.5 bg-hover text-sub">{{ count(t.key) }}</span>' +
    '</a>' +
    '</nav>' +

    // ============ Summary — not built yet, and says so rather than showing an empty panel ==
    '<div v-if="tab === \'summary\'" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-sm">' +
    '<div class="text-[14px] font-medium text-head">Summary is coming soon</div>' +
    '<p class="text-[13px] text-sub mt-1">It will collect what you are working on across every project — assigned, created, due and completed — in one view. The other tabs work now.</p>' +
    '</div></div>' +

    // ============ Activity — what you did, newest first ============
    '<div v-else-if="tab === \'activity\'" class="flex-1 min-h-0 overflow-y-auto">' +
    '<div class="px-4 sm:px-6 py-5 max-w-[820px] mx-auto w-full">' +
    '<p v-if="!activity.length" class="text-[13px] text-sub text-center py-10">' +
    'Nothing yet. Anything you create or change on a work item shows up here.</p>' +
    '<div v-for="a in activity" :key="a.id" class="flex items-start gap-3 py-2.5 border-b border-line last:border-0">' +
    '<span class="h-6 w-6 rounded-full overflow-hidden grid place-items-center bg-slate-600 text-white text-[10px] font-bold shrink-0 mt-0.5">' +
    '<img v-if="a.actor && a.actor.avatar_url" :src="a.actor.avatar_url" alt="" class="h-full w-full object-cover" />' +
    '<span v-else>{{ a.actor ? a.actor.initial : \'?\' }}</span></span>' +
    '<div class="min-w-0 flex-1">' +
    '<p class="text-[13px] text-ink">' +
    '<span class="font-medium">You</span> {{ activityText(a) }}' +
    '<template v-if="a.item"> on <a :href="a.item.url" class="text-brand hover:underline">{{ a.item.identifier }}</a> ' +
    '<span class="text-sub">{{ a.item.title }}</span></template>' +
    '</p>' +
    '<p class="text-[12px] text-faint mt-0.5">{{ when(a.created_at) }}</p>' +
    '</div></div>' +
    '</div></div>' +

    // ============ Assigned / Created / Subscribed — the work items screen itself ============
    '<div v-else-if="isEmpty" class="flex-1 grid place-items-center px-6">' +
    '<div class="text-center max-w-md">' +
    '<div class="text-[14px] font-medium text-head">Nothing here yet</div>' +
    '<p class="text-[13px] text-sub mt-1">{{ emptyText }}</p>' +
    '</div></div>' +
    // Editable chips, the real drawer, the same everything — because it IS the same component.
    '<work-items-screen v-else-if="isList" :bootstrap="workItems" />' +

    '</div>'
});
