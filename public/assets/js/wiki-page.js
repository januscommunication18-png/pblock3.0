/* Wiki — one page (docs/features/wiki.md).
   ------------------------------------------------------------------
   The same document experience Project Pages give: a title you type over, the shared Jodit
   editor below it, and an autosave that says when it last wrote. Nothing here is a second
   implementation — <pg-editor> comes from projects/page-editor.js, loaded by the rich-editor
   partial, so the two screens format, paste and sanitize identically.
   ------------------------------------------------------------------ */
PB.boot('wiki-page', {
  props: { bootstrap: Object },
  // <pg-editor> is a definition object from projects/page-editor.js, not a global component:
  // it has to be registered on THIS app, exactly as projects/pages.js registers it on its own.
  components: { 'pg-editor': PgEditor },
  data: function () {
    var b = this.bootstrap || {};
    return {
      page: b.page || {},
      collection: b.collection || {},
      canEdit: !!b.canEdit,
      titleMax: b.titleMax || 200,
      editorLicense: b.editorLicense || '',
      endpoints: b.endpoints || {},
      title: (b.page && b.page.title) || '',
      content: (b.page && b.page.content) || '',
      saving: false,
      savedAt: null,
      dirty: false,
      timer: null,
      rename: { open: false, title: '' }
    };
  },
  computed: {
    status: function () {
      if (this.saving) return 'Saving…';
      if (this.dirty) return 'Unsaved changes';

      return this.savedAt ? 'Saved' : '';
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    /* "Saved a moment ago" rather than a timestamp: the only question this answers is whether
       the last keystroke made it, and a clock time makes you do the subtraction. */
    fmtWhen: function (d) {
      if (!d) return '';
      var secs = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000));
      if (secs < 60) return 'a moment ago';
      var mins = Math.round(secs / 60);
      if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');

      return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },

    openRename: function () { this.rename = { open: true, title: this.title }; },
    applyRename: function () {
      var next = String(this.rename.title || '').trim();
      if (!next) return;
      this.title = next;
      this.rename.open = false;
      this.dirty = true;
      this.save();
    },

    onTitle: function () { this.dirty = true; this.queue(); },
    onContent: function (html) { this.content = html; this.dirty = true; this.queue(); },

    /* Debounced, like the project pages editor: a save per keystroke would be a request per
       keystroke, and the last one to arrive would not necessarily be the last one sent. */
    queue: function () {
      if (!this.canEdit) return;
      clearTimeout(this.timer);
      var self = this;
      this.timer = setTimeout(function () { self.save(); }, 900);
    },

    save: async function () {
      if (!this.canEdit || this.saving) return;

      var title = String(this.title || '').trim();
      if (!title) return;

      this.saving = true;
      try {
        await this.$pb.api(this.endpoints.update, {
          method: 'PATCH', body: { title: title, content: this.content }
        });
        this.dirty = false;
        this.savedAt = new Date();
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    }
  },

  mounted: function () {
    var self = this;
    // Nothing typed should be lost to a click on the back link.
    window.addEventListener('beforeunload', function (e) {
      if (!self.dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ---- header, the same bar the project page carries: where you are, then how it is doing.
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.collection" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink shrink-0">' +
    '<span v-html="icon(\'arrow-left\', 15)"></span>{{ collection.name }}</a>' +
    '<span class="text-faint shrink-0">/</span>' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ title }}</span>' +
    '<button v-if="canEdit" type="button" @click="openRename" data-tip="Rename page" aria-label="Rename page" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover shrink-0" ' +
    'v-html="icon(\'pen\', 14)"></button>' +

    '<div class="ml-auto flex items-center gap-2">' +
    // Stated honestly — a failed save keeps saying "Unsaved changes".
    '<span class="text-[12px] text-faint whitespace-nowrap">' +
    '<template v-if="saving">Saving…</template>' +
    '<template v-else-if="dirty">Unsaved changes</template>' +
    '<template v-else-if="savedAt">Saved {{ fmtWhen(savedAt) }}</template>' +
    '</span>' +
    '</div></div>' +

    // The toolbar's home. <pg-editor> BUILDS its toolbar and hands the finished element over,
    // so this stays empty in the template and Vue never patches anything inside it — the same
    // arrangement projects/pages.js uses, for the same reason.
    // INSIDE the scroller, so `position: sticky` has something to stick to. Outside it the bar
    // is fixed only because the layout happens to put it there — which stops being true the
    // moment anything above it grows.
    '<div class="pb-page flex-1 min-h-0 overflow-y-auto">' +
    '<div id="pb-page-toolbar" class="pb-page-toolbar" v-show="canEdit"></div>' +
    // Jodit sits OUTSIDE the centred column so its toolbar can span the screen; only the text
    // inside it is held to the document measure (.pb-page-jodit in pages.css).
    '<pg-editor v-if="canEdit" class="pb-page-jodit" ref="editor" :model-value="content" ' +
    '@update:model-value="onContent" @blur="save" ' +
    'toolbar-host="#pb-page-toolbar" min-height="420px" placeholder="Start writing…" ' +
    ':license="editorLicense" :media-upload="endpoints.mediaUpload" />' +

    // A read-only invitation gets the rendered document, not a disabled editor pretending.
    '<div v-else class="pb-page-doc px-6 pt-6">' +
    '<div class="wi-rich" v-html="content"></div>' +
    '<p v-if="!content" class="text-[13px] text-faint">This page is empty.</p>' +
    '</div>' +
    '</div>' +

    '<pb-modal :open="rename.open" title="Rename page" width="max-w-[460px]" @close="rename.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Page name</label>' +
    '<input v-model="rename.title" :maxlength="titleMax" class="pb-input" @keyup.enter="applyRename" />' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="rename.open = false">Cancel</button>' +
    '<button type="button" :disabled="!rename.title.trim()" @click="applyRename" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Save</button>' +
    '</template></pb-modal>' +
    '</div>'
}, { root: 'wiki-page-root' });
