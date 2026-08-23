/* Help Center — the Spaces listing (docs/features/help-center.md, P3 §20).
   ------------------------------------------------------------------
   The module's management screen, modelled on the Projects index: a header, one primary action,
   and a card per Space. It replaced "Inboxes" in the navigation, because a Space is the thing
   you manage and its Inbox is one of the things a Space HAS.

   Every Space is independent (P3 §2), and that is visible here: archiving or deleting one card
   changes only that card. Each action posts to a route scoped by that Space's id and the server
   re-checks the permission — the menu is presentation.
   ------------------------------------------------------------------ */
PB.boot('help-center-spaces', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      spaces: b.spaces || [],
      canCreate: !!b.canCreate,
      urls: b.urls || {},
      endpointTemplates: b.endpointTemplates || {},

      // Which row's "…" menu is open, by id. One at a time.
      openMenu: null,
      // The Space queued for deletion, and the name the user has typed to confirm it.
      pendingDelete: null,
      confirmName: '',
      deleteError: '',
      busy: false
    };
  },

  computed: {
    /* Active Spaces first, archived after — one grid, so an archived Space is still findable
       without a second list, but never sits above a live one. */
    visible: function () {
      return this.spaces.slice().sort(function (a, b) {
        return (a.archived === b.archived) ? 0 : (a.archived ? 1 : -1);
      });
    },

    /* Typing the exact name is what arms the button (see SpaceController::destroy). */
    canConfirmDelete: function () {
      if (!this.pendingDelete) return false;
      return this.confirmName.trim().toLowerCase() === String(this.pendingDelete.name).toLowerCase();
    }
  },

  mounted: function () {
    var self = this;
    // A click anywhere else closes the open row menu.
    document.addEventListener('click', function () { self.openMenu = null; });
  },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    url: function (template, space) {
      return String(this.endpointTemplates[template] || '').replace('__ID__', encodeURIComponent(space.id));
    },

    spaceUrl: function (space) { return this.url('show', space); },

    initial: function (space) { return String(space.name || '?').trim().charAt(0).toUpperCase(); },

    /*
     * The card's cover band.
     *
     * A Project has an uploaded image or a preset gradient; a Space has neither, so the band is
     * tinted from the Space's OWN colour — `$pb.avatarColor` keyed on its id, the same function
     * that colours a person's initial. Two Spaces are then reliably different colours, and a
     * given Space is the same colour every time you look at it.
     *
     * Soft: the band sits behind a white initial tile and a status chip, and a saturated fill
     * would fight both.
     */
    coverStyle: function (space) {
      var c = this.$pb.avatarColor({ id: space.id });
      return { background: 'linear-gradient(135deg, ' + c + '26 0%, ' + c + '4d 100%)' };
    },

    toggleMenu: function (space) {
      this.openMenu = this.openMenu === space.id ? null : space.id;
    },

    archive: async function (space, archived) {
      this.openMenu = null;
      this.busy = true;

      try {
        var res = await this.$pb.api(this.url('archive', space), {
          method: 'PATCH', body: { archived: archived }
        });
        // Replace the card in place — nothing else on the page is affected (P3 §2).
        var i = this.spaces.findIndex(function (s) { return s.id === space.id; });
        if (i > -1) this.spaces.splice(i, 1, res.space);
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not update that Space.'), 'error');
      } finally {
        this.busy = false;
      }
    },

    askDelete: function (space) {
      this.openMenu = null;
      this.pendingDelete = space;
      this.confirmName = '';
      this.deleteError = '';
    },

    cancelDelete: function () { if (!this.busy) this.pendingDelete = null; },

    destroy: async function () {
      if (!this.canConfirmDelete || this.busy) return;

      this.busy = true;
      this.deleteError = '';
      var space = this.pendingDelete;

      try {
        var res = await this.$pb.api(this.url('destroy', space), {
          method: 'DELETE', body: { confirm: this.confirmName }
        });
        this.spaces = this.spaces.filter(function (s) { return s.id !== space.id; });
        this.pendingDelete = null;
        this.$pb.toast(res.message);
      } catch (e) {
        this.deleteError = (e.data && e.data.message) || 'Could not delete that Space.';
      } finally {
        this.busy = false;
      }
    }
  },

  template: [
    '<div>',

    /* ===== Toolbar — the Projects index's header, adapted (P5).
       Same h-12 bordered bar, same leading sidebar-expand control, same 14px medium title with
       an icon, same right-aligned action group. ===== */
    '  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">',
    /* Brings the collapsed Help Center panel back. Hidden by CSS until there is something to
       expand, and handled by the delegated listener in partials/help-center-nav — which is why
       a Vue-rendered toolbar can carry it without any wiring here. */
    '    <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" aria-controls="sidebar" aria-expanded="false" class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" v-html="icon(\'sidebar\', 16)"></button>',
    '    <span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>',
    '    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">',
    '      <span v-html="icon(\'rectangles-pair\', 16, \'text-sub\')"></span>Spaces',
    '    </span>',
    '    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">',
    '      <a v-if="canCreate" :href="urls.setup" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap">',
    '        <span v-html="icon(\'plus\', 14)"></span>Add Space',
    '      </a>',
    '    </div>',
    '  </div>',

    /* Supporting description. The Projects toolbar carries none, so this sits just below it
       rather than being crammed into a 48px bar. */
    '  <p class="px-5 sm:px-8 pt-5 text-[13px] text-sub max-w-[720px]">Manage the Help Desk spaces used by your teams to organize conversations, inboxes, workflows, and support members.</p>',

    /* ===== Empty state =====
       The module's page-level empty state, not a card: Inbox (`mt-10 mx-auto max-w-[440px]
       text-center`) and Overview (`mt-12 mx-auto max-w-[460px]`) both draw a centred,
       BORDERLESS block — 48px tinted glyph, 15px heading, 13px body, then the action.

       Spaces used to be the exception: a bordered `max-w-[720px]` card pinned to the left of
       an otherwise empty screen, which read as a widget that had failed to fill its row rather
       than as "there is nothing here yet". Same content, same wording — only the frame goes. */
    '  <div v-if="!spaces.length" class="mt-10 mx-auto max-w-[440px] text-center px-5 sm:px-8">',
    '    <span class="mx-auto h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub" v-html="icon(\'rectangles-pair\', 22)"></span>',
    '    <h2 class="mt-4 text-[15px] font-semibold text-head">No spaces yet</h2>',
    '    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">Create a Help Desk space to organize your support team, conversations, inbox, and workflow.</p>',
    '    <a v-if="canCreate" :href="urls.setup" class="inline-flex items-center h-9 px-4 mt-5 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Create Space</a>',
    '  </div>',

    /* ===== Card grid — the Projects grid, one card per Space ===== */
    '  <div v-else class="px-5 sm:px-8 py-6 grid gap-5 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">',
    '    <a v-for="s in visible" :key="s.id" :href="spaceUrl(s)" class="group block border border-line rounded-xl overflow-hidden hover:shadow-md transition-shadow">',

    /* Cover band. Projects use an uploaded cover or a preset gradient; a Space has no image,
       so the band is tinted from its own colour and carries its initial — enough to tell three
       cards apart at a glance, which is what the cover is for. */
    '      <div class="relative h-24" :style="coverStyle(s)">',
    '        <span class="absolute top-2.5 left-2.5 h-7 w-7 rounded-md bg-white/90 grid place-items-center text-[13px] font-semibold text-ink shadow-sm">{{ initial(s) }}</span>',
    '        <div class="absolute top-2.5 right-2.5 flex items-center gap-1.5">',
    '          <span class="text-[11px] bg-white/90 rounded px-1.5 py-0.5 text-sub">{{ s.status_label }}</span>',
    /* The card is a link, so the kebab stops the click. */
    '          <button v-if="s.manageable || s.deletable" type="button" @click.stop.prevent="toggleMenu(s)" :aria-expanded="openMenu === s.id ? \'true\' : \'false\'" aria-haspopup="menu" title="Space actions" class="h-6 w-6 grid place-items-center rounded bg-white/90 text-sub hover:bg-white hover:text-ink shadow-sm" v-html="icon(\'ellipsis-vertical\', 15)"></button>',
    '        </div>',
    '        <div v-if="openMenu === s.id" @click.stop.prevent class="absolute top-10 right-2.5 z-20 w-44 rounded-md border border-line bg-white shadow-lg py-1 text-left">',
    '          <a :href="spaceUrl(s)" class="block px-3 py-1.5 text-[13px] text-ink hover:bg-hover">Open Space</a>',
    '          <button v-if="s.manageable && !s.archived" type="button" @click="archive(s, true)" :disabled="busy" class="w-full text-left px-3 py-1.5 text-[13px] text-ink hover:bg-hover disabled:opacity-50">Archive Space</button>',
    '          <button v-if="s.manageable && s.archived" type="button" @click="archive(s, false)" :disabled="busy" class="w-full text-left px-3 py-1.5 text-[13px] text-ink hover:bg-hover disabled:opacity-50">Restore Space</button>',
    '          <button v-if="s.deletable" type="button" @click="askDelete(s)" class="w-full text-left px-3 py-1.5 text-[13px] text-danger hover:bg-hover">Delete Space</button>',
    '        </div>',
    '      </div>',

    /* Body — name, then the types line where a Project shows its @identifier, then the Lead
       chip in the same position and the same shape. */
    '      <div class="p-4">',
    '        <div class="text-[15px] font-semibold text-head truncate">{{ s.name }}</div>',
    '        <div class="text-[12px] text-brand mt-0.5 font-medium truncate">{{ s.types.length ? s.types.join(\' · \') : \'No type\' }}</div>',
    '        <p v-if="s.description" class="mt-1.5 text-[12px] text-sub line-clamp-2">{{ s.description }}</p>',
    '        <div class="mt-2.5">',
    '          <span class="inline-flex items-center gap-1.5 h-8 pl-1.5 pr-2 rounded-md border border-stroke text-[12px] text-ink max-w-full min-w-0">',
    '            <template v-if="s.lead">',
    '              <span :style="{ background: $pb.avatarColor(s.lead) }" class="h-5 w-5 rounded-full text-white grid place-items-center text-[9px] font-bold shrink-0">{{ s.lead.initial }}</span>',
    '              <span class="truncate">{{ s.lead.name }}</span>',
    '            </template>',
    '            <template v-else>',
    '              <span v-html="icon(\'user\', 15, \'text-faint shrink-0\')"></span><span class="text-sub">No lead</span>',
    '            </template>',
    '          </span>',
    '        </div>',
    '      </div>',

    /* Footer chips, in the Projects footer's shape. */
    '      <div class="px-4 py-3 border-t border-line flex flex-wrap items-center gap-2 min-w-0">',
    '        <span class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink shrink-0">',
    '          <span v-html="icon(\'users\', 14, \'text-faint\')"></span>{{ s.members_count }} {{ s.members_count === 1 ? \'member\' : \'members\' }}',
    '        </span>',
    '        <span v-if="s.department_groups.length" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink shrink-0 max-w-full min-w-0">',
    '          <span v-html="icon(\'tag\', 14, \'text-faint shrink-0\')"></span><span class="truncate">{{ s.department_groups.join(\', \') }}</span>',
    '        </span>',
    /* Conversations do not exist yet, so the chip is absent rather than showing a zero. */
    '        <span v-if="s.conversations_count !== null" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[12px] text-ink shrink-0">',
    '          <span v-html="icon(\'inbox\', 14, \'text-faint\')"></span>{{ s.conversations_count }}',
    '        </span>',
    '        <span v-if="s.updated_at" class="text-[12px] text-sub shrink-0 ml-auto">{{ s.updated_at }}</span>',
    '      </div>',

    '    </a>',
    '  </div>',

    /* ===== Delete confirmation ===== */
    '  <pb-modal :open="!!pendingDelete" title="Delete Space" @close="cancelDelete">',
    '    <div v-if="pendingDelete">',
    '      <p class="text-[13px] text-ink">This deletes <span class="font-semibold">{{ pendingDelete.name }}</span> and everything in it — its Inbox, its inbound address, its workflow, its members and its settings.</p>',
    '      <p class="mt-2 text-[13px] text-sub">Other Spaces are not affected. This cannot be undone; archive it instead if you may want it back.</p>',
    '      <label class="block mt-4 text-[12px] font-semibold text-ink mb-1">Type <span class="font-mono">{{ pendingDelete.name }}</span> to confirm</label>',
    '      <input v-model="confirmName" class="pb-input w-full" :placeholder="pendingDelete.name" @keydown.enter.prevent="destroy" />',
    '      <p v-if="deleteError" class="mt-2 text-[12px] text-danger">{{ deleteError }}</p>',
    '    </div>',
    '    <template #footer>',
    '      <button type="button" @click="cancelDelete" class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '      <button type="button" @click="destroy" :disabled="!canConfirmDelete || busy" class="inline-flex items-center h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50">{{ busy ? \'Deleting…\' : \'Delete Space\' }}</button>',
    '    </template>',
    '  </pb-modal>',

    '</div>'
  ].join('\n')
}, { root: 'help-center-spaces' });
