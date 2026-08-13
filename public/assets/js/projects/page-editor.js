/* Project Pages › the Jodit editor.
   ------------------------------------------------------------------
   A page is a document — tables, structure, long-form text — and Quill drops what it has no
   format for, tables included. Jodit keeps them, so Pages uses it while work items, comments
   and status updates stay on <wi-editor>: those are short-form fields where Quill is a fine
   fit, and moving them would touch the drawer and both comment composers for no gain.

   The contract here is deliberately IDENTICAL to <wi-editor> — same props, same events, same
   flush() — so the Pages screen swaps one tag for the other and nothing else changes. That is
   also what lets this file degrade: when the Jodit package is not installed, pages.js renders
   <wi-editor> instead and the screen keeps working.
   ------------------------------------------------------------------ */

/** Is the Jodit package actually loaded? The Pro build is licensed and not vendored by default. */
function pgJoditReady() {
  return typeof window !== 'undefined' && typeof window.Jodit !== 'undefined';
}

var PgEditor = {
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Write…' },
    minHeight: { type: String, default: '420px' },
    disabled: { type: Boolean, default: false },
    /** Where images go. Absent means the image button uploads nowhere, so it is hidden. */
    mediaUpload: { type: String, default: '' },
    /**
     * The Jodit Pro licence key.
     *
     * Without it the Pro plugins load but stay inert, which presents as "the Pro features are
     * missing" rather than as an error — so it is worth checking here first when they are.
     */
    license: { type: String, default: '' },
    /**
     * A CSS selector for an element to move the toolbar into.
     *
     * Jodit builds its toolbar at the top of its own container, which puts it below anything
     * the page renders above the editor. Moving the finished element lets the toolbar sit at
     * the top of the screen while the title and body scroll beneath it — the same relocation
     * <wi-editor> does, and for the same reason.
     *
     * The element is MOVED, never rebuilt: its buttons carry Jodit's own handlers, so the
     * host must be one Vue renders empty and never patches.
     */
    toolbarHost: { type: String, default: '' },
    /**
     * Render the editing area as a page (Jodit's document view).
     *
     * Turns on Jodit's `iframe` mode, which puts the document in its own frame with its own
     * stylesheet — so the text is laid out on a sheet with page margins rather than filling a
     * panel, and page breaks have something to break. It is also what isolates the document's
     * CSS from the app's: nothing in tailwind.css can reach inside and restyle a user's
     * document, and nothing the document carries can leak out onto the app.
     */
    documentView: { type: Boolean, default: true },
    /**
     * A trimmed toolbar, as Jodit's `buttons` string.
     *
     * Empty means Jodit's own full set, which is what Pages wants — a page is a document and
     * gets the document's tools. A host embedding this as a FIELD rather than a surface (the
     * draft description) passes a short list instead, so the control is the size of the job.
     * Set for every breakpoint, because Jodit otherwise falls back to the full set as the
     * viewport narrows and the toolbar grows rather than shrinks.
     */
    buttons: { type: String, default: '' }
  },
  emits: ['update:modelValue', 'blur'],
  data: function () {
    return { jodit: null };
  },
  mounted: function () {
    if (!pgJoditReady() || !this.$refs.area) return;
    var self = this;

    var options = {
      license: this.license,
      readonly: this.disabled === true,
      placeholder: this.placeholder,
      minHeight: parseInt(this.minHeight, 10) || 420,
      // The page's own chrome supplies the frame; the editor should look like the document.
      toolbarAdaptive: false,
      // Jodit's sticky toolbar positions itself against the WINDOW, so inside a scrolled
      // container it detaches and floats. The page pins it with CSS `position: sticky`
      // instead, which is relative to the scroller it actually lives in.
      toolbarSticky: false,
      statusbar: false,
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      // Pasting from Word is the case this editor is here for: keep the formatting, and ask
      // rather than guessing, so a document arrives looking like the document.
      askBeforePasteHTML: true,
      askBeforePasteFromWord: true,
      defaultActionOnPaste: 'insert_clear_html',
      uploader: this.uploaderConfig()
    };

    if (this.buttons) {
      options.buttons = options.buttonsMD = options.buttonsSM = options.buttonsXS = this.buttons;
    }

    if (this.documentView) {
      options.iframe = true;
      // APPENDED to Jodit's own iframe stylesheet, never replacing it: the defaults carry the
      // editor's base typography, and the page-break plugin appends its rules the same way.
      options.iframeStyle = (window.Jodit.defaultOptions.iframeStyle || '') + this.pageStyle();
    }

    this.jodit = window.Jodit.make(this.$refs.area, options);

    // Jodit exposes its event bus as both `events` and the shorthand `e`.
    var bus = this.jodit.events || this.jodit.e;

    // Debounced like <wi-editor>, and for the same reason: a save clicked inside the window
    // would otherwise read the previous value. flush() closes that window.
    this._syncTimer = null;
    bus.on('change', function () {
      clearTimeout(self._syncTimer);
      self._syncTimer = setTimeout(function () { self.emitValue(); }, 120);
    });

    bus.on('blur', function () {
      clearTimeout(self._syncTimer);
      self.emitValue();
      self.$emit('blur');
    });

    this.applyOurIcons();
    this.relocateToolbar();

    // Content LAST, after the listeners — the same ordering lesson <wi-editor> records: a
    // throw while parsing must not cost the editor its wiring.
    if (this.modelValue) this.setHtml(this.modelValue);
  },
  beforeUnmount: function () {
    clearTimeout(this._syncTimer);
    try {
      if (this.jodit && this.jodit.destruct) this.jodit.destruct();
    } catch (e) { /* already torn down */ }
    this.jodit = null;
  },
  watch: {
    // The page was replaced under us (a save refreshed it) — take the new value, but never
    // while the user is typing into it.
    modelValue: function (next) {
      if (!this.jodit || this.isFocused()) return;
      if (next !== this.html()) this.setHtml(next || '');
    },
    disabled: function (next) {
      // Archiving a page makes it read-only without remounting the editor.
      try {
        if (this.jodit && this.jodit.setReadOnly) this.jodit.setReadOnly(next === true);
      } catch (e) { /* older builds expose it as an option only */ }
    }
  },
  methods: {
    /**
     * Replace Jodit's toolbar icons with the app's own.
     *
     * Jodit ships a complete icon set; so does this app, and a toolbar drawn in someone
     * else's line weight sits in the page looking borrowed. `wiIcon` reads the same registry
     * every other icon in the app comes from and honours the same set switch, so the toolbar
     * follows a change to config/icons.php without anything here changing.
     *
     * Names on the left are Jodit's; on the right are ours. Anything not listed keeps Jodit's
     * icon — better a consistent stranger than a missing button.
     */
    applyOurIcons: function () {
      var Icon = (window.Jodit.modules && window.Jodit.modules.Icon) || window.Jodit.Icon;
      if (!Icon || typeof Icon.set !== 'function' || typeof wiIcon !== 'function') return;

      var map = {
        bold: 'bold', italic: 'italic', underline: 'underline', strikethrough: 'strikethrough',
        ul: 'list-ul', ol: 'list-ol',
        indent: 'indent', outdent: 'outdent',
        left: 'align-left', center: 'align-center', right: 'align-right', justify: 'align-justify',
        undo: 'rotate-left', redo: 'rotate-right',
        table: 'table', link: 'link', image: 'image', file: 'paperclip',
        eraser: 'eraser', paragraph: 'paragraph', fontsize: 'text-size',
        print: 'file-lines', preview: 'eye', fullsize: 'expand', copyformat: 'copy',
        hr: 'sort', video: 'play', search: 'magnifying-glass'
      };

      Object.keys(map).forEach(function (joditName) {
        try {
          Icon.set(joditName, wiIcon(map[joditName], 16));
        } catch (e) { /* one unknown name must not cost the whole toolbar */ }
      });
    },

    /**
     * The sheet, as CSS injected into the document's own frame.
     *
     * A4 at 96dpi is 794×1123px; the min-height means an empty document still looks like a
     * page rather than a sliver. Typography lives here rather than in pages.css because the
     * document is inside an iframe — the app's stylesheets cannot reach it, which is the
     * point of the mode.
     */
    pageStyle: function () {
      return [
        'html{background:#f3f4f6;padding:0;}',
        'body{',
        'background:#fff;max-width:794px;min-height:1123px;',
        'margin:24px auto 64px;padding:56px 64px;',
        'box-shadow:0 1px 3px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.06);',
        'font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.75;color:#1f2328;',
        '}',
        'h1{font-size:28px;font-weight:700;margin:28px 0 8px;}',
        'h2{font-size:22px;font-weight:700;margin:24px 0 8px;}',
        'h3{font-size:18px;font-weight:600;margin:20px 0 6px;}',
        'p{margin:0 0 10px;}',
        'ul,ol{padding-left:1.5em;margin:0 0 10px;}',
        'li{margin:4px 0;}',
        'a{color:#2563eb;text-decoration:underline;}',
        'img{max-width:100%;height:auto;}',
        'blockquote{border-left:3px solid #e5e7eb;margin:12px 0;padding:2px 0 2px 14px;color:#6b7280;}',
        'hr{border:0;border-top:1px solid #e5e7eb;margin:24px 0;}',
        // Tables are the reason this editor replaced the last one, so they get real styling.
        'table{border-collapse:collapse;width:100%;margin:12px 0;}',
        'th,td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top;}',
        'th{background:#f6f7f8;font-weight:600;}'
      ].join('');
    },

    /** Move the built toolbar into the host, if one was named. */
    relocateToolbar: function () {
      if (!this.toolbarHost || !this.jodit) return;

      try {
        var host = document.querySelector(this.toolbarHost);
        var container = this.jodit.container || (this.$refs.area && this.$refs.area.parentNode);
        var toolbar = container && container.querySelector('.jodit-toolbar__box');

        if (host && toolbar) host.appendChild(toolbar);
      } catch (e) { /* the toolbar stays where Jodit put it, which still works */ }
    },
    isFocused: function () {
      try {
        return !!(this.jodit && this.jodit.editor && document.activeElement &&
          this.jodit.editor.contains(document.activeElement));
      } catch (e) { return false; }
    },
    html: function () {
      if (!this.jodit) return '';
      var value = this.jodit.value || '';

      // An empty document still emits scaffolding; normalise so an emptied editor round-trips
      // to "" rather than to markup the server would strip anyway.
      return value.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '').trim() === '' ? '' : value;
    },
    setHtml: function (html) {
      if (!this.jodit) return;
      try {
        this.jodit.value = html || '';
      } catch (e) { /* a parse failure costs the pre-filled text, not the editor */ }
    },
    emitValue: function () {
      var html = this.html();
      if (html !== this.modelValue) this.$emit('update:modelValue', html);
    },
    /**
     * Push the current content to the model NOW.
     *
     * Typing syncs on a debounce, so a save inside that window reads the previous value and
     * concludes nothing changed — the edit looks discarded. Every save path calls this first.
     */
    flush: function () {
      if (!this.jodit) return;
      clearTimeout(this._syncTimer);
      this.emitValue();
    },
    /**
     * Point Jodit's uploader at the project's own media endpoint.
     *
     * The endpoint takes files named `file-0`, `file-1`… and answers `{result:[{url,…}]}`, or
     * `{errorMessage}` — neither of which is Jodit's default shape, so every hook below is a
     * translation between the two. Nothing here decides policy; the server re-checks type,
     * size and permission on every upload.
     */
    uploaderConfig: function () {
      if (!this.mediaUpload) return { insertImageAsBase64URI: true };

      var token = document.querySelector('meta[name="csrf-token"]');

      return {
        url: this.mediaUpload,
        headers: { 'X-CSRF-TOKEN': token ? token.content : '' },
        filesVariableName: function (i) { return 'file-' + i; },
        isSuccess: function (resp) { return resp && !resp.errorMessage; },
        getMessage: function (resp) { return (resp && resp.errorMessage) || ''; },
        process: function (resp) {
          return {
            files: ((resp && resp.result) || []).map(function (f) { return f.url; }),
            error: (resp && resp.errorMessage) || null
          };
        },
        defaultHandlerSuccess: function (data) {
          var self = this;
          (data.files || []).forEach(function (url) { self.s.insertImage(url); });
        }
      };
    }
  },
  template: '<div class="pg-editor"><textarea ref="area"></textarea></div>'
};
