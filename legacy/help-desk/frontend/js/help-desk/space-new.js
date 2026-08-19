/* Help Desk › New Space (Workspace & Inbox Assignment requirements §5, §6, §7).
   ------------------------------------------------------------------
   §5's dedicated creation page. Name, description, type, a colour for the avatar, and §6's inbox
   selector: search, multi-select, name, address, and — the one that matters — where each inbox
   is now.

   That last column is why the selector is not a plain checkbox list. §7's rule is one inbox to
   one space, so ticking an inbox that already belongs somewhere is a MOVE, and the page says so
   on the row and again in the summary before the save. Finding out afterwards that Partner
   Support lost its inbox is not a thing this screen should be able to do to somebody.
   ------------------------------------------------------------------ */
PB.boot('help-desk-space-new', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    return {
      inboxes: b.inboxes || [],
      typeSuggestions: b.type_suggestions || [],
      colors: b.colors || [],
      endpoints: b.endpoints || {},
      model: { name: '', description: '', types: [], color: (b.colors || [])[0] || '' },
      selected: [],
      query: '',
      saving: false,
      errors: {}
    };
  },
  computed: {
    // §6's search, over both the name and the address — people look for an inbox by whichever
    // of the two they happen to remember.
    filtered: function () {
      var q = String(this.query || '').trim().toLowerCase();
      if (!q) return this.inboxes;
      return this.inboxes.filter(function (i) {
        return String(i.name || '').toLowerCase().indexOf(q) !== -1
          || String(i.inbound_address || '').toLowerCase().indexOf(q) !== -1;
      });
    },
    // The ones that are being taken from another space. Counted so the button can warn before
    // it is pressed rather than after.
    moving: function () {
      var chosen = this.selected;
      return this.inboxes.filter(function (i) {
        return chosen.indexOf(i.id) !== -1 && i.space_id;
      });
    },
    initial: function () {
      var name = String(this.model.name || '').trim();
      return name ? name.charAt(0).toUpperCase() : '?';
    },
    canSave: function () { return !!String(this.model.name || '').trim() && !this.saving; }
  },
  methods: {
    toggle: function (inbox) {
      var at = this.selected.indexOf(inbox.id);
      if (at === -1) this.selected.push(inbox.id); else this.selected.splice(at, 1);
    },
    isSelected: function (inbox) { return this.selected.indexOf(inbox.id) !== -1; },
    save: async function () {
      if (!this.canSave) return;
      this.saving = true; this.errors = {};

      try {
        var resp = await this.$pb.api(this.endpoints.store, {
          method: 'POST',
          body: {
            name: String(this.model.name).trim(),
            description: this.model.description || null,
            types: this.model.types,
            color: this.model.color || null,
            inbox_ids: this.selected
          }
        });
        window.location = resp.redirect || this.endpoints.cancel;
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
        this.saving = false;
      }
    }
  },
  template:
    '<div class="max-w-[760px] mx-auto px-5 sm:px-8 py-8">' +

    '<pb-section-head title="New space" ' +
    'desc="A space is a separate support environment inside this Help Desk — a brand, a business unit, a region or a support team. It has its own inboxes, and through them its own conversations."/>' +

    '<div class="_moretogether-stack">' +

    // ---- name + avatar (§5) ----
    '<div class="flex items-start gap-4">' +
    '<div class="h-12 w-12 shrink-0 rounded-xl grid place-items-center text-white text-[18px] font-semibold" ' +
    ':style="{ backgroundColor: model.color || \'#64748B\' }">{{ initial }}</div>' +
    '<div class="flex-1 min-w-0">' +
    '<label class="block text-[12px] font-semibold text-ink mb-1">Space name</label>' +
    '<input class="pb-input" :class="{\'is-error\': !!errors.name}" v-model="model.name" placeholder="Partner Support" @keyup.enter="save"/>' +
    '<p v-if="errors.name" class="text-[12px] text-danger mt-1">{{ errors.name[0] }}</p>' +
    '<div class="mt-2 flex items-center gap-2">' +
    '<button v-for="c in colors" :key="c" class="h-5 w-5 rounded-full border-2" ' +
    ':style="{ backgroundColor: c, borderColor: model.color === c ? c : \'transparent\' }" ' +
    ':class="{\'ring-2 ring-offset-2 ring-stroke\': model.color === c}" @click="model.color = c"></button>' +
    '</div></div></div>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Description</label>' +
    '<input class="pb-input" v-model="model.description" placeholder="Support workspace for partners and resellers."/>' +
    '<p class="text-[12px] text-sub mt-1">Optional. What this space is for.</p></div>' +

    '<div><label class="block text-[12px] font-semibold text-ink mb-1">Type</label>' +
    '<pb-tags v-model="model.types" :suggestions="typeSuggestions" placeholder="Type a value and press Enter"/>' +
    '<p class="text-[12px] text-sub mt-1.5">Optional, and as many as you like — a space is often more than one thing. Press Enter or comma after each; anything you type is allowed.</p>' +
    '<p v-if="errors.types" class="text-[12px] text-danger mt-1">{{ errors.types[0] }}</p></div>' +

    // ---- §6's inbox selector ----
    '<div class="border border-line rounded-xl p-4">' +
    '<div class="text-[13px] font-semibold text-ink">Assigned inboxes</div>' +
    '<p class="text-[12px] text-sub mt-1">An inbox belongs to one space. Choosing one that is already in another space moves it — its conversations, contacts, routing and email configuration come with it.</p>' +

    '<input v-if="inboxes.length > 6" class="pb-input mt-3" v-model="query" placeholder="Search inboxes by name or address"/>' +

    '<div v-if="!inboxes.length" class="mt-3 text-[12px] text-sub">This Help Desk has no inboxes yet. You can create one inside the space afterwards.</div>' +

    '<div v-else class="mt-3 space-y-1 max-h-[280px] overflow-y-auto">' +
    '<label v-for="i in filtered" :key="i.id" class="flex items-start gap-3 px-2 py-2 rounded-md hover:bg-hover cursor-pointer">' +
    '<input type="checkbox" class="mt-0.5" :checked="isSelected(i)" @change="toggle(i)"/>' +
    '<span class="min-w-0 flex-1">' +
    '<span class="block text-[13px] text-ink">{{ i.name }}</span>' +
    '<span class="block text-[12px] text-sub _moretogether-break">{{ i.inbound_address }}</span>' +
    // §6's "current assignment".
    '<span v-if="i.space" class="block text-[12px] text-warning">Currently in {{ i.space }} — will be moved</span>' +
    '<span v-else class="block text-[12px] text-faint">Not in a space</span>' +
    '</span></label>' +
    '</div></div>' +

    // ---- footer ----
    '<div class="flex items-center justify-between gap-3 pt-2">' +
    '<p v-if="moving.length" class="text-[12px] text-warning">' +
    '{{ moving.length }} inbox<span v-if="moving.length !== 1">es</span> will be moved out of another space.</p>' +
    '<span v-else></span>' +
    '<div class="flex items-center gap-2">' +
    '<a :href="endpoints.cancel" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover flex items-center">Cancel</a>' +
    '<button class="h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50" ' +
    ':disabled="!canSave" @click="save">Create space</button>' +
    '</div></div>' +

    '</div></div>'
}, { root: 'help-desk-space-new-root' });
