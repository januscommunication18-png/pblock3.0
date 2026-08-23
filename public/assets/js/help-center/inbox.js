/* Project Block — a Space's Inbox (docs/features/help-center.md, P9).
   ------------------------------------------------------------------
   The Inbox is the Help Center's only queue. Every inbound email is a REQUEST carrying a
   Ticket Number, and this is the screen an agent works it from.

   It is the WORK ITEM GRID: the same Tabulator setup, the same `.wi-grid` skin, the same 44px
   rows, the same group headers with a count pill, the same helpers out of work-item-ui.js.
   A Request and a work item are both "a thing with a status and an owner", and reading one
   should not require learning a second list.

   Not <wi-list> itself. That component's row is a work item — priority, labels, epics, cycles,
   estimates, blocked-by counts — and a Request has none of those but has a customer, a preview,
   a ticket number and a waiting clock. Bending one component to render both would mean a props
   matrix deciding which half of a row applies, and the first thing to break would be the work
   item list, which is the more important of the two. Sharing the vocabulary and the stylesheet
   gets the same grid without giving two screens one mutable component.

   NOTHING HERE NAMES A STATUS. The groups, the filters and the status picker are all built from
   the Space's own workflow, which is why one Space can run `New / Investigating / Completed` and
   another `Open / Tier 1 / Tier 2 / Resolved` with no change to this file.

   Requires work-item-ui.js + icons.js + Tabulator, all loaded first (partials.work-item-assets).
   ------------------------------------------------------------------ */

/* ---- Event icons for the timeline (P38) ------------------------------------------------
   The same idea as work-items.js's `WI_EVENT_ICON`: a row's icon says what KIND of thing it
   was before the sentence is read, which is what makes a long feed skimmable. The paths for
   status, priority, assignee, tag and created are the work item's, so the two feeds speak one
   visual language.

   Defined here rather than imported: `partials.work-item-assets` loads work-item-ui.js and
   work-item-list.js but NOT work-items.js, so `WI_EVENT_ICON` is not on the page. */
function hcEventSvg(body) {
  return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
}

var HC_EVENT_ICON = {
  status: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.2"/>',
  priority: '<path d="M5 20v-6M12 20V7M19 20v-9"/>',
  assignee: '<circle cx="12" cy="8" r="3.2"/><path d="M5 20a7 7 0 0114 0"/>',
  tags: '<path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z"/><circle cx="14.5" cy="9.5" r="1.2"/>',
  created: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
  message: '<path d="M20 15a2 2 0 01-2 2H8l-4 4V6a2 2 0 012-2h12a2 2 0 012 2z"/>',
  update: '<path d="M5 21V5a1 1 0 011-1h9l-1.5 3L15 10H6"/><path d="M5 21h4"/>',
  spam: '<circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/>',
  // Snooze (P45). Both events carry `field: 'snooze'`, so one clock covers falling asleep and
  // waking up — the sentence beside it already says which of the two happened.
  snooze: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
};

/** Whose clock is running, as a one-word marker beside the waiting time. */
var HC_WAITING_TONE = {
  agent: { label: 'Agent', color: '#f59e0b' },
  customer: { label: 'Customer', color: '#3b82f6' },
  neither: { label: '', color: '#9ca3af' }
};

/* =====================================================================================
 * Reading a time somebody typed (P45)
 * ===================================================================================== */

/** Month names, long and short, in the order their index needs to be. */
var HC_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june',
  'july', 'august', 'september', 'october', 'november', 'december'];

var HC_DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

/** The hour a snooze lands on when the text names a day but not a time. */
var HC_DEFAULT_HOUR = 8;

/**
 * Pull a time-of-day out of a string — "8am", "at 8 am", "10:30pm", "17:45".
 *
 * Returns null when there is none, which is different from returning 8am: the caller needs to
 * know whether the person SAID a time, because "next Monday" means Monday at 8 and
 * "next Monday at 6pm" means Monday at 6.
 *
 * @return {{h: number, m: number, text: string}|null}
 */
function hcParseTimeOfDay(text) {
  var m = text.match(/(?:^|\s|at\s)(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i);

  if (m) {
    var h = parseInt(m[1], 10) % 12;

    return {
      h: /pm/i.test(m[3]) ? h + 12 : h,
      m: m[2] ? parseInt(m[2], 10) : 0,
      text: m[0],
    };
  }

  // 24-hour, and only WITH a colon. A bare "17" in "Aug 17" is a date, and reading it as five
  // in the afternoon is how "Aug 17" silently becomes today at 17:00.
  m = text.match(/(?:^|\s|at\s)(\d{1,2}):(\d{2})\b/);

  if (m) {
    return { h: parseInt(m[1], 10), m: parseInt(m[2], 10), text: m[0] };
  }

  return null;
}

/**
 * Turn what an agent typed into an actual moment — the requirement's natural-language input.
 *
 * Parsed in the BROWSER, deliberately, and the resolved instant is what goes to the server. The
 * browser is the only party that knows what "8 am" means to the person typing it; sending the
 * words instead would make the server guess a timezone, and a support ticket that comes back
 * five hours early is worse than one that never slept.
 *
 * Understood, per the spec's list:
 *
 *   tomorrow · tomorrow at 8am · 3 days · in 2 weeks · next monday · monday
 *   aug 25 · 25 aug · aug 25 at 10:30am · 8am (today or tomorrow, whichever is next)
 *
 * Returns null for anything it cannot read, and the dialog says so rather than guessing — a
 * parser that quietly picks a date when it did not understand is how a ticket disappears for a
 * month.
 *
 * @param {string} text  what was typed
 * @param {Date}  [now]  injectable so the behaviour is testable and midnight is not a special case
 * @return {Date|null}
 */
function hcParseWhen(text, now) {
  var raw = String(text || '').trim().toLowerCase();
  if (!raw) return null;

  now = now || new Date();

  var time = hcParseTimeOfDay(raw);
  // The rest of the string, once the time has been lifted out of it — so "aug 25 at 10:30am"
  // leaves "aug 25" for the date rules below rather than a "25" and a "10" to fight over.
  var rest = (time ? raw.replace(time.text, ' ') : raw).replace(/\bat\b/g, ' ').trim();

  var at = function (d) {
    d.setHours(time ? time.h : HC_DEFAULT_HOUR, time ? time.m : 0, 0, 0);

    return d;
  };

  var day = function (offset) {
    var d = new Date(now.getTime());
    d.setDate(d.getDate() + offset);

    return at(d);
  };

  if (/^(today|tonight)$/.test(rest)) {
    // "tonight" with no time means the evening, not eight in the morning that has already gone.
    var today = day(0);
    if (!time && rest === 'tonight') today.setHours(18, 0, 0, 0);

    return today > now ? today : null;
  }

  if (/^tomorrow$/.test(rest)) return day(1);

  /*
   * "weekend" / "next weekend" — the coming Saturday, matching the quick option of that name.
   *
   * Here because the quick buttons are worded in English and somebody who reads "Next weekend"
   * on a button will type it into the box below. A parser that does not understand its own
   * shortcuts' labels is a parser that looks broken.
   */
  if (/^(next\s+|this\s+)?weekend$/.test(rest)) {
    var toSat = (6 - now.getDay() + 7) % 7 || 7;

    return day(toSat);
  }

  // "3 days", "in 2 weeks", "1 hour", "90 minutes" — a duration from now.
  var dur = rest.match(/^(?:in\s+)?(\d+)\s*(min(?:ute)?s?|h(?:ou)?rs?|days?|weeks?|months?)$/);

  if (dur) {
    var n = parseInt(dur[1], 10);
    var unit = dur[2];
    var d = new Date(now.getTime());

    if (/^min/.test(unit)) { d.setMinutes(d.getMinutes() + n); return d; }
    if (/^h/.test(unit)) { d.setHours(d.getHours() + n); return d; }
    if (/^week/.test(unit)) { d.setDate(d.getDate() + n * 7); return at(d); }
    if (/^month/.test(unit)) { d.setMonth(d.getMonth() + n); return at(d); }

    d.setDate(d.getDate() + n);

    /*
     * "3 days" keeps the CURRENT time of day unless one was typed.
     *
     * Three days from now means three days from now. Snapping it to 8am would make "3 days"
     * typed at 4pm mean two days and sixteen hours, which is not what anybody counted.
     */
    return time ? at(d) : d;
  }

  // "next monday", or a bare "monday" — the next one that is not today.
  var dayName = rest.match(/^(?:next\s+|this\s+)?([a-z]+)$/);

  if (dayName) {
    var idx = HC_DAYS.indexOf(dayName[1]);

    if (idx !== -1) {
      var ahead = (idx - now.getDay() + 7) % 7;
      // Never today: "Monday" said on a Monday means the Monday coming, not five minutes ago.
      if (ahead === 0) ahead = 7;

      return day(ahead);
    }
  }

  // "aug 25", "25 aug", "august 25" — with the YEAR inferred.
  var md = rest.match(/^([a-z]+)\.?\s+(\d{1,2})$/) || (function () {
    var r = rest.match(/^(\d{1,2})\s+([a-z]+)\.?$/);

    return r ? [r[0], r[2], r[1]] : null;
  })();

  if (md) {
    var mi = -1;

    for (var i = 0; i < HC_MONTHS.length; i++) {
      if (HC_MONTHS[i].indexOf(md[1]) === 0 && md[1].length >= 3) { mi = i; break; }
    }

    if (mi !== -1) {
      var dd = parseInt(md[2], 10);

      if (dd >= 1 && dd <= 31) {
        var t = at(new Date(now.getFullYear(), mi, dd));

        /*
         * A date already gone means NEXT year — compared as DATES, not as instants.
         *
         * "Jan 5" typed in December is next January; reading it as this one gives a snooze
         * eleven months in the past. But the comparison has to be day-to-day: "Aug 21" typed at
         * 2pm on Aug 21 resolves to 8am that morning, and an instant comparison would call that
         * "past" and quietly move it TWELVE MONTHS. Rolling a year because somebody was four
         * hours late is the worst possible reading of what they meant.
         *
         * Left in this year, it stays a past instant and the dialog says "Pick a time in the
         * future" — which is true, fixable, and not a ticket that vanishes until next August.
         */
        var midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        if (new Date(now.getFullYear(), mi, dd) < midnight) {
          t = at(new Date(now.getFullYear() + 1, mi, dd));
        }

        // Rolled over (Feb 30) — the month it landed in is not the month that was named.
        return t.getMonth() === mi ? t : null;
      }
    }
  }

  // Just a time — "8am". Today if it is still ahead, otherwise tomorrow.
  if (time && rest === '') {
    var only = at(new Date(now.getTime()));

    return only > now ? only : day(1);
  }

  return null;
}

/**
 * The quick options, computed against `now` (P45).
 *
 * Computed rather than written down, because the requirement asks that each one shows its
 * calculated date and time before the agent confirms — and a label that says "Tomorrow" beside a
 * date that was worked out somewhere else is a label free to be wrong.
 */
function hcQuickSnoozes(now) {
  now = now || new Date();

  var atEight = function (d) { d.setHours(HC_DEFAULT_HOUR, 0, 0, 0); return d; };

  var tomorrow = new Date(now.getTime());
  tomorrow.setDate(tomorrow.getDate() + 1);

  // Monday: the next one, never today.
  var monday = new Date(now.getTime());
  var toMonday = (1 - monday.getDay() + 7) % 7 || 7;
  monday.setDate(monday.getDate() + toMonday);

  // Saturday: same rule, so "next weekend" said on a Saturday means the one coming.
  var saturday = new Date(now.getTime());
  var toSaturday = (6 - saturday.getDay() + 7) % 7 || 7;
  saturday.setDate(saturday.getDate() + toSaturday);

  return [
    { key: 'tomorrow', label: 'Tomorrow', when: atEight(tomorrow) },
    { key: 'next-week', label: 'Next week', when: atEight(monday) },
    { key: 'next-weekend', label: 'Next weekend', when: atEight(saturday) },
  ];
}

/**
 * Re-render a snooze activity row in the READER'S timezone (P45).
 *
 * This application formats dates on the server, in UTC (config/app.timezone). For most of the
 * timeline that is merely a convention; for a snooze it is a contradiction — an agent types
 * "10:30am", and a history telling them they set 2:30 PM is a history arguing with them about
 * something they just did.
 *
 * So the two snooze events carry their instant in `meta` as ISO, and the date in the sentence is
 * replaced with the browser's rendering of it. Everything else about the row — the phrasing, the
 * actor, the icon — is still the server's; only the moment is re-read, by the only party that
 * knows what clock the person is looking at.
 *
 * NOT a fix for the app's UTC formatting generally. That is a real and separate problem — every
 * other timestamp on this screen has it — and quietly correcting one feature's dates while its
 * neighbours stay wrong is worth doing only because this is the feature where the user supplies
 * the time themselves.
 */
function hcSnoozeLabel(row) {
  if (!row || !row.snoozed) return null;

  var iso = row.snoozed_until_iso;

  // Fall back to the server's string when there is no ISO to read — a payload built before this
  // existed still says something true, just in UTC.
  if (!iso) return row.snooze_label || null;

  var when = new Date(iso);

  return isNaN(when.getTime()) ? (row.snooze_label || null) : 'Snoozed until ' + hcWhenLabel(when);
}

function hcLocaliseSnooze(e) {
  if (!e || e.field !== 'snooze') return e;

  var meta = e.meta || {};
  var iso = meta.until || meta.was_until;
  if (!iso) return e;

  var when = new Date(iso);
  if (isNaN(when.getTime())) return e;

  var label = hcWhenLabel(when);
  var out = Object.assign({}, e);

  // `new` is the time it was set TO; on an unsnooze it is `old` that held it.
  if (meta.until) { out.new = label; } else { out.old = label; }

  // The phrase embeds the date mid-sentence ("snoozed this ticket until …"), so the server's
  // rendering of that same instant is swapped for this one rather than the string rebuilt.
  if (e.phrase && e.old) out.phrase = e.phrase.split(e.old).join(label);
  if (e.phrase && e.new) out.phrase = out.phrase.split(e.new).join(label);

  return out;
}

/** "Sat, Aug 23 at 8:00 AM" — how every resolved snooze time is shown, worded in one place. */
function hcWhenLabel(d) {
  if (!d) return '';

  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
    + ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/* The files that came with a message (P66).
   ------------------------------------------------------------------
   One function rather than two copies of the markup, because the same strip renders in two
   places that are otherwise nothing alike: under the ticket HEADLINE (the opening email, which
   is where a screenshot almost always arrives) and under each customer reply in the timeline.

   `root` is the expression holding the message — `drawer.detail.original` in one place, `e` in
   the other — so the caller decides where the data comes from and the shape stays in one place.

   Every chip is a plain <a> to the authorized download route. NOT a fetch and not a blob: the
   file is a stranger's, the server always answers with Content-Disposition: attachment, and a
   link is the one control that cannot end up rendering it in this page. */
function hcAttachments(root) {
  return [
    '<div v-if="(' + root + '.attachments && ' + root + '.attachments.length) || ' + root + '.attachments_skipped" class="mt-3">',
    '  <div v-if="' + root + '.attachments && ' + root + '.attachments.length" class="flex flex-wrap gap-2">',
    '    <a v-for="f in ' + root + '.attachments" :key="f.id" :href="f.url"',
    // `download` asks the browser to save rather than navigate; the server sets the same
    // disposition, so this is a courtesy rather than the guarantee.
    '       :download="f.name" :title="f.name"',
    '       class="inline-flex items-center gap-2 max-w-full h-8 px-2.5 rounded-md border border-line bg-[#f9fafb] hover:bg-hover">',
    '      <span class="text-faint shrink-0" v-html="hcClip"></span>',
    // `truncate` on the name and never on the size: a long filename must not push the size out
    // of the chip, because the size is what tells somebody whether the download is worth it.
    '      <span class="text-[12px] text-ink truncate">{{ f.name }}</span>',
    '      <span class="text-[11px] text-faint shrink-0">{{ f.size_label }}</span>',
    '    </a>',
    '  </div>',
    // What was refused, and why. Said plainly rather than hidden in a log: "see attached" with
    // nothing attached is a question the ticket has to answer.
    '  <p v-if="' + root + '.attachments_skipped" class="mt-2 text-[11px] text-warn">',
    '    <span v-text="' + root + '.attachments_skipped.label"></span>',
    '    <span v-for="x in ' + root + '.attachments_skipped.items" :key="x.name">',
    '      &middot; {{ x.name }} ({{ x.reason }})</span>',
    '  </p>',
    '</div>'
  ].join('\n');
}

var HcRequestList = {
  props: {
    rows: { type: Array, default: function () { return []; } },
    statuses: { type: Array, default: function () { return []; } },
    /*
     * 'status' on a Space's Inbox, 'space' on the Help Center's cross-Space queue (P21).
     *
     * A merged list cannot group by status: two Spaces run two workflows, so "Open" would be
     * two different groups wearing one name — or worse, one group holding two Spaces' idea of
     * it. Which Space a Request came from is the thing that separates the rows on that screen.
     */
    groupBy: { type: String, default: 'status' },
    /*
     * Whether a status with no rows still gets a header.
     *
     * True on the Space's Inbox, where the workflow's shape IS information — "Closed 0" says
     * the Space has a Closed state and nothing is in it. False on a view, where the filter is
     * the URL and "Closed 0" under Mine is a header that can only ever mean nothing.
     */
    emptyGroups: { type: Boolean, default: true },
    canManage: { type: Boolean, default: false },
    /** Label and colour for the priority chip — the same map the picker offers. */
    priorities: { type: Object, default: function () { return {}; } },
    /** Row ids with a save in flight, so their chips can show it (P28). */
    savingIds: { type: Array, default: function () { return []; } },
    height: { type: String, default: '100%' }
  },
  data: function () { return { table: null }; },
  watch: {
    rows: function () { this.refresh(); },
    statuses: function () { this.refresh(); },
    // A row that started or finished saving redraws so its chips dim or come back.
    savingIds: function () { this.reformatAll(); }
  },
  /*
   * Escape closes the picker, then the drawer (P32).
   *
   * On DOCUMENT, not as `@keydown.esc` on the root: the drawer and the pickers are teleported
   * to <body>, so a key pressed while the focus is inside one of them never reaches the screen's
   * own root element and the binding there did nothing.
   */
  mounted: function () {
    this.onEsc = function (e) {
      if (e.key !== 'Escape') return;
      if (this.menu) { this.closeMenu(); return; }
      if (this.drawer) this.closeDrawer();
    }.bind(this);

    document.addEventListener('keydown', this.onEsc);
  },

  beforeUnmount: function () {
    document.removeEventListener('keydown', this.onEsc);
  },

  computed: {
    /** Grouping by Space is a plain list of ids; grouping by status is the workflow's order. */
    bySpace: function () { return this.groupBy === 'space'; },

    /** Group order: the Space's workflow order, with "no status" last. */
    groupValues: function () {
      /*
       * Only the Spaces actually present, and no catch-all unless something needs it.
       *
       * Tabulator draws a header for every declared group value, empty or not — which is right
       * for a workflow (the Space chose those statuses and an empty one is information) and
       * wrong here, where "No Space 0" is a row that can only ever mean "nothing to say".
       */
      if (this.bySpace) {
        var seen = [];
        this.rows.forEach(function (r) {
          var key = r.space ? String(r.space.id) : '__none__';
          if (seen.indexOf(key) === -1) seen.push(key);
        });

        return seen;
      }

      var declared = this.statuses.map(function (s) { return String(s.id); }).concat(['__none__']);

      if (this.emptyGroups) return declared;

      var present = {};
      this.rows.forEach(function (r) { present[r.status_id ? String(r.status_id) : '__none__'] = true; });

      return declared.filter(function (key) { return present[key]; });
    },

    /** Space id → name, for the group header on the cross-Space queue. */
    spaceById: function () {
      var map = {};
      this.rows.forEach(function (r) { if (r.space) map[String(r.space.id)] = r.space.name; });

      return map;
    },
    statusById: function () {
      var map = {};
      this.statuses.forEach(function (s) { map[String(s.id)] = s; });

      return map;
    }
  },
  mounted: function () { this.build(); },
  beforeUnmount: function () {
    if (this.table) { this.table.destroy(); this.table = null; }
  },
  methods: {
    build: function () {
      if (!window.Tabulator || !this.$refs.grid || this.table) return;
      var self = this;

      this.table = new Tabulator(this.$refs.grid, {
        data: this.gridRows(),
        index: 'id',
        layout: 'fitColumns',
        headerVisible: false,
        // 52 rather than the work item list's 44: a Request row carries two lines — the subject
        // and the preview under it — which is the one place the two lists legitimately differ.
        rowHeight: 52,
        height: this.height,
        columnDefaults: { vertAlign: 'middle', headerSort: false },
        groupBy: 'gkey',
        groupToggleElement: 'header',
        groupValues: [this.groupValues],
        groupHeader: function (value, count) { return self.groupHeader(value, count); },
        columns: [
          // Ticket # — the ID column, and the only identifier that ever reaches a screen.
          { title: 'Ticket', field: 'identifier', width: self.idWidth(), formatter: function (cell) {
            return '<span class="text-[12px] font-medium text-sub tabular-nums">' + wiEsc(cell.getValue()) + '</span>';
          } },
          // Customer — the AVATAR only; the name and address are on its tooltip (P30).
          { title: 'Customer', field: 'customer', width: 44, formatter: function (cell) {
            return self.customerCell(cell.getRow().getData());
          } },
          // Subject / Preview — the growing column, two lines.
          { title: 'Subject', field: 'subject', minWidth: 220, widthGrow: 1, formatter: function (cell) {
            return self.subjectCell(cell.getRow().getData());
          } },
          // Waiting, updated, assignee and the ••• — one right-aligned cluster.
          // Wider than the 320 it was: the cluster is five controls now, not three glyphs.
          { title: '', field: 'meta', width: 620, hozAlign: 'right', formatter: function (cell) {
            return self.metaCell(cell.getRow().getData());
          } }
        ]
      });

      this.table.on('rowClick', function (e, row) {
        /*
         * A control acts in place and must not also open whatever is behind it — and neither
         * must a READING (P31).
         *
         * `[data-static]` is the waiting clock and the last-activity time. They are measurements
         * with nothing to click, so clicking one should do nothing at all: opening the ticket
         * from them made the two facts behave like the one part of the row that is supposed to
         * open it, which is the subject.
         */
        if (e.target.closest('[data-act],[data-static]')) return;
        self.$emit('open', row.getData());
      });

      /*
       * Delegated in the capture phase.
       *
       * Tabulator re-renders rows on every data change, so a listener bound to a row would leak
       * with it — and the ••• must not also toggle the group it sits in.
       */
      this.$refs.grid.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        e.stopPropagation();

        var id = btn.getAttribute('data-id');
        var row = self.rows.filter(function (r) { return String(r.id) === String(id); })[0];

        // The ACT travels with it: 'menu' is the ••• as before, and every chip added in P28
        // arrives through this same listener asking for its own picker.
        if (row) self.$emit('chip', { act: btn.getAttribute('data-act'), row: row, el: btn });
      }, true);
    },

    refresh: function () {
      if (!this.table) { this.$nextTick(this.build); return; }
      var self = this;

      this.table.setGroupValues([this.groupValues]);
      this.table.replaceData(this.gridRows()).then(function () {
        var id = self.table.getColumn('identifier');
        if (id) id.setWidth(self.idWidth());
        // A grid that was display:none measured zero width; re-lay it out now it is visible.
        self.$nextTick(function () { self.table.redraw(true); });
      });
    },

    /** Sized to the longest ticket number on screen — "#000011" and "#001011" differ. */
    idWidth: function () {
      var longest = this.rows.reduce(function (n, r) {
        return Math.max(n, String(r.identifier || '').length);
      }, 7);

      return 24 + 8 + Math.ceil(longest * 7.5);
    },

    gridRow: function (r) {
      var key = this.bySpace
        ? (r.space ? String(r.space.id) : '__none__')
        : (r.status_id ? String(r.status_id) : '__none__');

      return Object.assign({}, r, { gkey: key });
    },

    gridRows: function () {
      var self = this;

      return this.rows.map(function (r) { return self.gridRow(r); });
    },

    /**
     * A group header — one workflow status.
     *
     * The dot takes the status's own colour, because the workflow chose it. Colours are INLINE
     * for the reason work-item-list.js records: Tabulator's base theme styles
     * `.tabulator-group span` at equal specificity and a utility class loses to it.
     */
    groupHeader: function (value, count) {
      if (this.bySpace) return this.spaceHeader(value, count);

      var status = this.statusById[String(value)] || null;
      var waiting = status && HC_WAITING_TONE[status.waiting_on];
      var tag = waiting && waiting.label
        ? '<span class="text-[11px] shrink-0" style="color:' + waiting.color + '">Waiting on ' + waiting.label + '</span>'
        : '';

      return '<span class="wi-chevron grid place-items-center" style="color:#9ca3af">' + wiIcon('chevron-right', 14) + '</span>' +
        '<span class="grid place-items-center">' + wiDot(status ? status.color : '#cbd5e1', !status) + '</span>' +
        '<span class="text-[13px] font-semibold" style="color:#0f0f10">' + wiEsc(status ? status.name : 'No status') + '</span>' +
        '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5" style="color:#6b7280;background:#f3f4f6">' + count + '</span>' +
        tag;
    },

    /**
     * A group header — one Space, on the cross-Space queue.
     *
     * Deliberately the same furniture as the status header (chevron, name, count pill) minus
     * the coloured dot and the waiting tag, which are facts about a status and not about a
     * Space. Colours are INLINE for the reason the status header records.
     */
    spaceHeader: function (value, count) {
      var name = this.spaceById[String(value)] || 'No Space';

      return '<span class="wi-chevron grid place-items-center" style="color:#9ca3af">' + wiIcon('chevron-right', 14) + '</span>' +
        '<span class="text-[13px] font-semibold" style="color:#0f0f10">' + wiEsc(name) + '</span>' +
        '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5" style="color:#6b7280;background:#f3f4f6">' + count + '</span>';
    },

    /**
     * Customer: the AVATAR, and nothing else (P30).
     *
     * The name and address used to be printed beside it, in a fixed 210px column, on every row.
     * That is 210px spent on two facts an agent scanning a queue is not scanning FOR — they are
     * reading subjects — and it was 210px taken from the subject and preview, which is what they
     * are reading. Both facts are still one hover away, and the avatar's colour and initial
     * already distinguish one customer from another down the list.
     */
    customerCell: function (d) {
      var who = d.customer_avatar || { name: d.customer, initial: '?' };
      var tip = d.customer_email && d.customer_email !== d.customer
        ? d.customer + ' — ' + d.customer_email
        : (d.customer || d.customer_email || 'Unknown sender');

      /*
       * The tooltip goes ON the avatar, not on a wrapper around it.
       *
       * `wiAvatar` writes its own `data-tip` from `person.name`, and the tooltip handler reads
       * the CLOSEST `[data-tip]` — so a wrapper's tip is shadowed by the avatar's every time. It
       * showed the name and swallowed the address, which is half of what this hover exists for.
       *
       * `email` is passed too: `PB.avatarColor` prefers it over the name, so the colour is keyed
       * to the address rather than to a display string that now holds both.
       */
      return wiAvatar({
        name: tip,
        email: d.customer_email,
        initial: who.initial,
        avatar_url: who.avatar_url,
      }, 26);
    },

    /** Subject on the first line, the message's opening 200 characters on the second. */
    subjectCell: function (d) {
      var priority = this.priorityDot(d.priority);

      return '<span class="block min-w-0 leading-tight">' +
        '<span class="flex items-center gap-1.5 min-w-0">' + priority +
          '<span class="text-[13px] font-medium text-ink truncate">' + wiEsc(d.subject) + '</span></span>' +
        '<span class="block text-[11px] text-faint truncate">' + wiEsc(d.preview || '') + '</span>' +
      '</span>';
    },

    /** Priority as a dot rather than a chip — it must not compete with the subject. */
    priorityDot: function (priority) {
      var colors = { urgent: '#ef4444', high: '#f59e0b' };

      // Normal and Low get nothing: a marker on every row marks nothing.
      return colors[priority]
        ? '<span class="h-1.5 w-1.5 rounded-full shrink-0" style="background:' + colors[priority] + '" data-tip="' + wiEsc(priority) + '"></span>'
        : '';
    },

    /**
     * Waiting period · last activity · assignee · •••
     *
     * Read right to left it answers "is this mine, and how long has it been ignored" — the
     * question an agent scanning a queue is actually asking.
     */
    metaCell: function (d) {
      var out = '';

      /*
       * The snooze indicator (P45) — FIRST, and static like the two readings below it.
       *
       * First because it is the one fact that changes what every other chip on the row means: a
       * snoozed ticket is not in anybody's queue, and reading its assignee and its waiting clock
       * without knowing that is reading them wrong.
       *
       * Static for the same reason the clock is (P31): it is a state, not a picker. Changing it
       * is the dialog, reached from the row menu — a chip that opened a modal would be the only
       * one on the row that did.
       */
      if (d.snoozed) {
        out += '<span data-static="1" class="inline-flex items-center gap-1 text-[12px] shrink-0 cursor-default" ' +
          'style="color:#6b7280" data-tip="' + wiEsc(hcSnoozeLabel(d) || 'Snoozed') +
          (d.snooze_condition_label ? ' \u00b7 ' + wiEsc(d.snooze_condition_label) : '') + '">' +
          wiIcon('clock', 12) + 'Snoozed</span>';
      }

      /*
       * The two READINGS, deliberately not chips (P31).
       *
       * The waiting clock and the last-activity time are measurements — they are what the Request
       * has done, not settings anybody can pick. They used to be drawn with a border and a
       * rounded box, which is exactly the treatment the five editable chips beside them carry,
       * so the row offered no way to tell a control from a fact until you clicked one and
       * nothing happened.
       *
       * Plain text now: no border, no background, no hover, no pointer. The rule the eye can
       * learn is "boxed means you can change it", and it only works if nothing else is boxed.
       * The clock icon and the amber past four hours are kept — those carry meaning, not
       * affordance.
       */
      if (d.waiting_label) {
        var tone = HC_WAITING_TONE[d.waiting_on] || HC_WAITING_TONE.neither;
        // Amber past four hours: the number alone does not say whether it is a problem.
        var stale = d.waiting_minutes >= 240;
        out += '<span data-static="1" class="inline-flex items-center gap-1 text-[12px] shrink-0 cursor-default" ' +
          'style="color:' + (stale ? tone.color : '#6b7280') + '" ' +
          'data-tip="Waiting on ' + wiEsc(tone.label || 'nobody') + ' for ' + wiEsc(d.waiting_label) + ' — this is a measurement, not a setting">' +
          wiIcon('clock', 12) + wiEsc(d.waiting_label) + '</span>';
      } else {
        // No clock running — say so, rather than leaving a gap that reads as missing data.
        out += '<span data-static="1" class="text-[12px] text-faint shrink-0 cursor-default" data-tip="Nobody is waiting on this Request">&mdash;</span>';
      }

      if (d.updated_at) {
        // 5px of right padding separates the last reading from the first chip, so the eye has a
        // gap where the facts end and the controls begin. An inline style, not `pr-[5px]`:
        // Tailwind emits only what it finds in the sources it scans, and this file is not one.
        out += '<span data-static="1" style="padding-right:5px" ' +
          'class="text-[12px] text-sub shrink-0 w-14 text-right cursor-default" ' +
          'data-tip="Last activity ' + wiEsc(d.updated_at) + ' ago">' + wiEsc(d.updated_at) + '</span>';
      }

      // ---- The editable chips (P28) ----
      // `manageable` is per ROW on the cross-Space queue — somebody may run one Space and
      // merely work in another. Absent on a Space's own Inbox, where the screen-level flag is
      // the whole answer. Without it every chip below renders as a plain span: the same row,
      // read-only, rather than a row of controls that refuse to work.
      var edit = this.canManage && d.manageable !== false;

      out += this.statusChip(d, edit);
      out += this.assigneeChip(d, edit);
      out += this.priorityChip(d, edit);
      out += this.tagChip(d, edit);

      if (edit) {
        out += '<button type="button" data-act="menu" data-id="' + d.id + '" data-tip="Request actions" ' +
          'aria-label="Request actions" class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line shrink-0">' +
          wiIcon('ellipsis-thin', 15) + '</button>';
      }

      // Dimmed while a change is in flight — the "loading state" the requirement asks for, and
      // the reason a second click on a half-saved row looks wrong rather than doing something.
      var busy = this.savingIds.indexOf(d.id) !== -1 ? ' opacity-50 pointer-events-none' : '';

      return '<span class="flex items-center justify-end gap-1.5 min-w-0' + busy + '">' + out + '</span>';
    },

    /**
     * One editable chip.
     *
     * The same shape as the work item grid's: a `data-act` button the delegated listener turns
     * into a picker, or a plain span when the reader may not edit. Hover changes the background,
     * which is what says "this is a control" on a row full of text.
     */
    chip: function (d, act, inner, extra, tip, edit) {
      var tipAttr = ' data-tip="' + wiEsc(tip) + '" aria-label="' + wiEsc(tip) + '"';
      var base = 'inline-flex items-center gap-1.5 h-6 px-2 rounded border text-[12px] shrink-0 max-w-[180px] ';

      if (!edit) {
        return '<span class="' + base + 'border-line bg-white ' + (extra || 'text-ink') + '"' + tipAttr + '>' + inner + '</span>';
      }

      return '<button type="button" data-act="' + act + '" data-id="' + d.id + '"' + tipAttr +
        ' class="' + base + 'border-stroke bg-white hover:bg-hover cursor-pointer ' + (extra || 'text-ink') + '">' +
        inner + '</button>';
    },

    statusChip: function (d, edit) {
      var name = d.status ? d.status.name : 'No status';

      return this.chip(d, 'status',
        wiDot(d.status ? d.status.color : '#cbd5e1', !d.status) + '<span class="truncate">' + wiEsc(name) + '</span>',
        'text-ink', (edit ? 'Change status — ' : 'Status: ') + name, edit);
    },

    assigneeChip: function (d, edit) {
      var who = d.assignee ? d.assignee.name : 'Unassigned';
      var face = d.assignee
        ? wiAvatar(d.assignee, 18)
        : '<span class="h-[18px] w-[18px] rounded-full border border-dashed border-stroke shrink-0"></span>';

      return this.chip(d, 'assignee', face + '<span class="truncate">' + wiEsc(who) + '</span>',
        d.assignee ? 'text-ink' : 'text-faint',
        (edit ? 'Change assignee — ' : 'Assignee: ') + who, edit);
    },

    priorityChip: function (d, edit) {
      var key = d.priority || 'none';
      var p = this.priorities[key] || { label: 'No Priority', color: '#d1d5db' };

      return this.chip(d, 'priority',
        '<span class="h-2 w-2 rounded-full shrink-0" style="background:' + wiEsc(p.color) + '"></span>' +
        '<span class="truncate">' + wiEsc(p.label) + '</span>',
        key === 'none' ? 'text-faint' : 'text-ink',
        (edit ? 'Change priority — ' : 'Priority: ') + p.label, edit);
    },

    /**
     * Tags: the first name plus a count, or "+ Tag" when there are none.
     *
     * One name and "+2" rather than every name, for the reason the work item grid learned the
     * hard way — two free-text names side by side are what make this cell overrun its width,
     * and they were never readable at that size anyway. The tooltip carries all of them.
     */
    tagChip: function (d, edit) {
      var tags = d.tags || [];

      if (!tags.length) {
        return this.chip(d, 'tags', wiIcon('tag', 12) + '<span>Tag</span>', 'text-faint',
          edit ? 'Add tags' : 'No tags', edit);
      }

      var inner = '<span class="truncate">' + wiEsc(tags[0].name) + '</span>';
      if (tags.length > 1) inner += '<span class="shrink-0 text-sub">+' + (tags.length - 1) + '</span>';

      var names = tags.map(function (t) { return t.name; }).join(', ');

      return this.chip(d, 'tags', wiIcon('tag', 12, 'text-faint') + inner, 'text-ink',
        (edit ? 'Change tags — ' : 'Tags: ') + names, edit);
    },

    /** Redraw every row's formatters without rebuilding the table. */
    reformatAll: function () {
      if (!this.table) return;
      var self = this;
      this.$nextTick(function () {
        self.table.getRows().forEach(function (r) { r.reformat(); });
      });
    }
  },
  template: '<div ref="grid" class="wi-grid"></div>'
};

/* ---- The screen ---------------------------------------------------------------------- */

PB.boot('help-center-inbox', {
  /*
   * `pg-editor` is the project's rich-text editor (P41) — Jodit, defined in
   * projects/page-editor.js and already on this page: `partials.work-item-assets` includes
   * `partials.rich-editor`, which loads Tribute, Jodit and that script for the grid's sake.
   *
   * `wi-editor` (the Quill fallback) is NOT available here: it lives inside work-items.js,
   * which this screen does not load. So this reaches for `pg-editor` directly and falls back to
   * a plain textarea when Jodit is absent from a checkout — see `useEditor`.
   */
  components: { 'hc-request-list': HcRequestList, 'pg-editor': typeof PgEditor !== 'undefined' ? PgEditor : null },
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};

    return {
      allRows: b.rows || [],
      statuses: b.statuses || [],
      views: b.views || {},
      priorities: b.priorities || {},
      // The Snooze dialog's two options (P45), from config so the server owns the vocabulary.
      snoozeConditions: b.snoozeConditions || [],
      /*
       * Page mode (P46) — this screen IS a Request's own page rather than a queue.
       *
       * Set by the bootstrap carrying a `pageRequestId`, which only `help-center/request.blade`
       * sends. The work item screen does exactly this: one component, two framings, so the
       * detail on the page is the drawer itself and cannot drift from it.
       */
      pageRequestId: b.pageRequestId || null,
      members: b.members || [],
      // The Space's tag vocabulary, for the Tag picker (P28). Flat on a Space's own Inbox;
      // per Space in `spaceOptions` on the cross-Space queue.
      tags: b.tags || [],
      me: b.me || null,
      inboundAddress: b.inboundAddress || null,
      // Built once here rather than inline in the template: v-html wants a string, and
      // wiIcon is a plain global that Vue's template compiler cannot reach.
      inboxGlyph: (typeof wiIcon === 'function' ? wiIcon('inbox', 22) : ''),
      canManage: !!b.canManage,
      endpoints: b.endpoints || {},
      endpointTemplates: b.endpointTemplates || {},
      /*
       * Which screen this is (P21).
       *
       * 'space' — a Space's own Inbox: every row of one Space, filtered in the browser by the
       * Space's workflow statuses. 'queue' — the Help Center's cross-Space queue, where the
       * VIEW is the URL and the server already sent exactly the rows that belong to it.
       */
      mode: b.mode === 'queue' ? 'queue' : 'space',

      /* Live updates (P67).
         `spaceId` is the channel; `rowsUrl` is what a socket event sends the list back for.
         Both absent on screens the server did not wire for it, which leaves the page working
         exactly as it did before — the socket only ever ADDS to what is on screen. */
      spaceId: b.spaceId || null,
      /* The channels this screen listens on (P67).
         A Space's Inbox has exactly one; the cross-Space queue has every Space the signed-in
         user may view — sent by the server rather than derived from the rows on screen, so a
         Space that is empty in this view still has a channel and the first ticket to arrive
         there is heard. */
      liveSpaceIds: Array.isArray(b.liveSpaceIds) ? b.liveSpaceIds : (b.spaceId ? [b.spaceId] : []),
      rowsUrl: b.rowsUrl || (b.endpoints && b.endpoints.rows) || '',
      liveOff: null,
      liveBusy: false,
      livePending: false,
      view: b.view || null,
      groupBy: b.groupBy === 'space' ? 'space' : 'status',
      /*
       * Per-Space pickers, for the cross-Space queue's row menu.
       *
       * A Space's Inbox has one workflow and one member list and can send them flat. This
       * screen shows several Spaces at once, and a Request may only ever take a status from
       * ITS OWN Space's workflow (P9) or an assignee from its own Space's members — so the
       * menu reads the row's Space rather than a merged list that would offer both.
       */
      spaceOptions: b.spaceOptions || {},
      // Only the queue screen sends these; a Space's Inbox derives its own from the filter.
      bootTitle: b.emptyTitle || null,
      bootMessage: b.emptyMessage || null,
      // 'all' or a status id as a string. The ownership views are no longer filters here —
      // they are navigation (P21), so this only ever holds a status.
      filter: 'all',
      /*
       * The open picker (P28): `{ act, row, top, left }`.
       *
       * ONE piece of state for all five — status, assignee, priority, tags and the ••• menu are
       * the same interaction (click a control on a row, choose, save) and giving each its own
       * flag is how two of them end up open at once.
       */
      menu: null,
      // Free text inside the searchable pickers — assignee and tags.
      query: '',
      /*
       * Row ids with a save in flight.
       *
       * A LIST, not a boolean: two chips on two rows can be saving at the same time, and one
       * shared flag would lock the whole grid for the slower of them.
       */
      savingIds: [],
      /*
       * The detail drawer (P32): `{ row, loading, detail }`.
       *
       * The ROW is held as well as the fetched detail, so the drawer can render its header and
       * chips the instant it opens rather than after a round trip — the row already carries
       * everything except the thread, and a panel that slides in empty and fills a moment later
       * reads as slow even when it is not.
       */
      drawer: null,
      // Expand: the panel at full window width rather than 80%. Kept OUTSIDE `drawer` so it
      // survives opening the next Request — somebody who wants it wide wants it wide.
      drawerWide: false,
      // The sender block's two disclosures: their other tickets, and the edit form.
      showPrevious: false,
      /*
       * The drawer's tab (P36): all | activity | reply | updates | transition | history.
       *
       * `all` by default — somebody opening a ticket wants what happened, not a filter over it.
       */
      tab: 'all',
      // The Show more control's two halves, the work item's names for them.
      originalExpanded: false,
      originalOverflows: false,
      replyBody: '',
      replySending: false,
      /*
       * The work item's `updateForm` shape (P39): closed until "Add update" is pressed, and
       * carrying an `id` when it is editing rather than adding.
       *
       * A form that is always open invites an update nobody meant to write, and it puts a
       * textarea between the reader and the updates they came to read.
       */
      updateForm: { open: false, id: null, status: 'on_track', content: '', busy: false },
      // The update whose Delete has been pressed once — see `deleteUpdate`.
      confirmDelete: null,
      // The note composer, and the note being edited. Same shape as `updateForm` (P39).
      noteForm: { open: false, id: null, content: '', busy: false },
      confirmDeleteNote: null,
      /*
       * Which open the in-flight detail fetch belongs to.
       *
       * Closing and reopening the SAME ticket quickly left the first fetch running; its result —
       * or its failure — then landed against the second open, which is where the stray "Could
       * not open the Request" came from. A row id is not enough to tell the two apart; a
       * sequence number is.
       */
      drawerSeq: 0,
      /*
       * Whether the rich editor is available at all.
       *
       * A checkout without the licensed Jodit package has no `pg-editor`, and a component that
       * is not registered renders as nothing — a composer that silently disappears is worse
       * than a plain textarea. This is what the templates branch on.
       */
      useEditor: typeof PgEditor !== 'undefined' && typeof window.Jodit !== 'undefined',
      /*
       * The work item comment composer's toolbar, character for character.
       *
       * A field, not a surface: a reply is a paragraph and a link, not a document, so it gets
       * the short list rather than Jodit's full set. Set for every breakpoint, because Jodit
       * otherwise falls BACK to the full set as the viewport narrows — the toolbar grows when
       * the room shrinks.
       */
      editorButtons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,|,ul,ol,|,link,|,eraser',
      editorLicense: (b.editorLicense || ''),
      customerForm: null,

      /*
       * The Snooze dialog (P45).
       *
       * Null when closed, so `:open="!!snoozeForm"` is the whole test. `row` is carried on the
       * form rather than read from `menu`, because the dialog outlives the menu it was opened
       * from — closing the picker to open the modal would otherwise take the ticket with it.
       *
       * `now` is stamped when the dialog opens and NOT updated while it is open. Every quick
       * option and every preview is computed against it, so what the agent reads is what gets
       * sent; a clock ticking underneath would let "Tomorrow" mean one day at the moment it was
       * rendered and another at the moment it was clicked.
       */
      snoozeForm: null,
      snoozeSaving: false,

      /*
       * The "Mark this ticket as spam?" confirmation (P47).
       *
       * Holds the ROW, not a boolean, so the dialog survives the menu closing under it — the
       * same shape `snoozeForm` uses and for the same reason.
       *
       * Only marking is confirmed. Restoring is not: it is the recovery action, and asking
       * somebody to confirm undoing a mistake is a second chance to get the mistake wrong.
       */
      spamConfirm: null,
      // Which failed reply is being re-sent (P64). An id rather than a boolean, so two failed
      // replies on one ticket do not both show "Sending…".
      retryingId: null,
      customerSaving: false,
      saving: false
    };
  },
  /**
   * On a Request's page, open the drawer at once (P46).
   *
   * The page's whole content IS the drawer, so there is nothing to click and nothing to wait
   * for. The row is already in `allRows` — the server sent exactly one — and the detail fetch
   * that `open()` fires is the same one the queue's drawer makes.
   */
  mounted: function () {
    this.listen();

    if (!this.pageMode) return;

    var id = this.pageRequestId;
    var row = this.allRows.filter(function (r) { return String(r.id) === String(id); })[0];

    if (row) this.open(row);
  },

  beforeUnmount: function () {
    if (typeof this.liveOff === 'function') this.liveOff();
  },
  computed: {
    /* The paperclip, resolved ONCE (P66).
       `icon()` is a method, and a method called from inside a v-for runs on every re-render for
       every chip — a computed is the same markup produced once and reused. */
    hcClip: function () { return this.icon('paperclip', 13); },

    /** Is this screen a Request's own page (P46), rather than a queue with a drawer over it? */
    pageMode: function () {
      return this.pageRequestId !== null;
    },

    /** Where the page's breadcrumb goes back to — the Space's Inbox this Request lives in. */
    queueUrl: function () {
      return this.endpoints.queue || '';
    },

    // ---- Snooze (P45) ----------------------------------------------------------------------

    /** The three shortcuts, each with the date and time it works out to. */
    snoozeQuick: function () {
      if (!this.snoozeForm) return [];

      return hcQuickSnoozes(this.snoozeForm.now).map(function (q) {
        return { key: q.key, label: q.label, when: q.when, whenLabel: hcWhenLabel(q.when) };
      });
    },

    /**
     * What the dialog will actually send, as a Date — or null if nothing readable was typed.
     *
     * A quick button that has been clicked wins over re-parsing its own label: "Next weekend"
     * and the button that produced it must resolve to the same instant, even on the Friday when
     * the words alone could just as well be read as tomorrow.
     */
    snoozeWhen: function () {
      if (!this.snoozeForm) return null;

      if (this.snoozeForm.quick) {
        var key = this.snoozeForm.quick;
        var hit = this.snoozeQuick.filter(function (q) { return q.key === key; })[0];
        if (hit) return hit.when;
      }

      return hcParseWhen(this.snoozeForm.text, this.snoozeForm.now);
    },

    /**
     * The line under the input: what was understood, or why nothing was.
     *
     * Three states rather than two, because "we could not read that" and "that has already
     * happened" are different problems with different fixes, and one message for both leaves an
     * agent retyping a date that was never the thing wrong with it.
     */
    snoozePreview: function () {
      if (!this.snoozeForm || !this.snoozeForm.text.trim()) return null;

      var when = this.snoozeWhen;

      if (!when) return { ok: false, text: 'Not a time we can read — try “tomorrow”, “3 days”, or “Aug 25 at 9am”.' };
      if (when <= new Date()) return { ok: false, text: 'That time has already passed.' };

      return { ok: true, text: hcWhenLabel(when) };
    },

    canSnooze: function () {
      return !!(this.snoozePreview && this.snoozePreview.ok) && !this.snoozeSaving;
    },
    /**
     * The filter bar — GENERATED FROM THE WORKFLOW (P9, Inbox Status Filters).
     *
     * "All", then one per workflow status, then the standing views that are about ownership
     * rather than status. Change the Space's workflow and this bar changes with it; no status
     * name appears anywhere in this file.
     */
    statusFilters: function () {
      var self = this;
      var out = [{ key: 'all', label: 'All', count: this.allRows.length }];

      this.statuses.forEach(function (s) {
        out.push({
          key: 'status:' + s.id,
          label: s.name,
          color: s.color,
          count: self.rowsFor('status:' + s.id).length
        });
      });

      return out;
    },
    /*
     * Unassigned / Mine / Draft / Assigned / Closed / Spam are GONE from this bar (P21).
     *
     * They were chips here and they are the Help Center's top-level navigation now, one URL
     * each and counted across every Space. Leaving a copy behind would be two controls for one
     * idea — and the one down here could only ever answer for the Space you happened to be in.
     */
    rows: function () { return this.rowsFor(this.filter); },

    /** The open row's Space options — the whole screen's, on a Space's own Inbox. */
    menuOptions: function () {
      if (this.mode !== 'queue' || !this.menu || !this.menu.row.space) {
        return { statuses: this.statuses, members: this.members, tags: this.tags };
      }

      return this.spaceOptions[String(this.menu.row.space.id)] || { statuses: [], members: [], tags: [] };
    },
    menuStatuses: function () { return this.menuOptions.statuses || []; },
    menuMembers: function () { return this.menuOptions.members || []; },
    menuTags: function () { return this.menuOptions.tags || []; },

    /** The member list, narrowed by what has been typed into the picker's search box. */
    filteredMembers: function () {
      var q = this.query.trim().toLowerCase();
      if (!q) return this.menuMembers;

      return this.menuMembers.filter(function (m) {
        return String(m.name || '').toLowerCase().indexOf(q) !== -1;
      });
    },

    filteredTags: function () {
      var q = this.query.trim().toLowerCase();
      if (!q) return this.menuTags;

      return this.menuTags.filter(function (t) {
        return String(t.name || '').toLowerCase().indexOf(q) !== -1;
      });
    },

    /** May the drawer's chips be used at all — the row's own permission (P32). */
    canEditDrawer: function () {
      var row = this.drawerRow();

      return !!row && this.canManage && row.manageable !== false && !this.isSaving(row);
    },

    /**
     * Every entry, newest FIRST (P78).
     *
     * It read newest-last — a conversation reading downward, the shape of an email client. On a
     * ticket that is the wrong way round: an agent opening one wants the last thing that
     * happened, and on a thread that has run for a week that meant scrolling past everything
     * they had already read to find it.
     *
     * Reversed HERE and nowhere else. Every tab below is a filter over this one list, so one
     * reverse flips Activity, Notes, Updates, Transition and History together and they cannot
     * disagree about the order.
     *
     * Deliberately NOT reversed on the server. `HelpCenterRequest::messages()` is documented
     * oldest-first and `reply()` reads the LAST row of it to build the `In-Reply-To` threading
     * header — flipping the relation would silently thread every reply off the wrong message.
     * The order things happened in is the server's; the order they are READ in is the screen's.
     *
     * `slice()` before `reverse()`: `reverse()` mutates, and the array here belongs to
     * `drawer.detail`. Reversing it in place would flip the stored payload too, so every
     * recompute would flip it back — the list would appear to toggle its own order.
     *
     * Snooze rows pass through `hcLocaliseSnooze` on the way out, so their dates are the ones
     * the agent typed rather than their UTC equivalents. Done HERE, at the single point every
     * tab reads from, so the six filters below cannot disagree about what a snooze row says.
     */
    timeline: function () {
      var rows = (this.drawer && this.drawer.detail && this.drawer.detail.timeline) || [];

      return rows.map(hcLocaliseSnooze).slice().reverse();
    },

    /**
     * The rows the open tab shows.
     *
     * Every tab is a FILTER over the one list the server built, so no two of them can disagree
     * about the order things happened in — which is exactly what six separately-assembled lists
     * would eventually do.
     */
    tabRows: function () {
      var t = this.tab;

      if (t === 'all') return this.timeline;
      if (t === 'updates') return this.timeline.filter(function (r) { return r.kind === 'update'; });
      if (t === 'notes') return this.timeline.filter(function (r) { return r.kind === 'note'; });
      if (t === 'transition') return this.timeline.filter(function (r) { return r.transition; });
      if (t === 'activity' || t === 'history') {
        return this.timeline.filter(function (r) { return r.kind === 'activity'; });
      }

      /* Reply: the conversation with the customer.
         Newest first like every other tab (P78) — the composer sits under the list, so the
         message being answered is the one directly above it. */
      return this.timeline.filter(function (r) { return r.kind === 'message'; });
    },

    /** The six tabs, with the counts the tab bar shows. */
    tabs: function () {
      var t = this.timeline;
      var count = function (fn) { return t.filter(fn).length; };

      return [
        { key: 'all', label: 'All', count: t.length },
        { key: 'activity', label: 'Activity', count: count(function (r) { return r.kind === 'activity'; }) },
        { key: 'reply', label: 'Reply to Customer', count: count(function (r) { return r.kind === 'message'; }) },
        { key: 'notes', label: 'Internal Notes', count: count(function (r) { return r.kind === 'note'; }) },
        { key: 'updates', label: 'Updates', count: count(function (r) { return r.kind === 'update'; }) },
        { key: 'transition', label: 'Transition', count: count(function (r) { return r.transition; }) },
        { key: 'history', label: 'History', count: count(function (r) { return r.kind === 'activity'; }) },
      ];
    },

    /** The heading of an empty tab — the work item's shape: a title, then a line under it. */
    emptyTabTitle: function () {
      return {
        all: 'Nothing yet',
        activity: 'No activity yet',
        reply: 'No replies yet',
        notes: 'No internal notes yet',
        updates: 'No updates yet',
        transition: 'No transitions yet',
        history: 'No history yet',
      }[this.tab] || 'Nothing yet';
    },

    /** What an empty tab says — the reason it is empty, not a shrug. */
    emptyTabMessage: function () {
      return {
        all: 'Nothing has happened since the ticket was opened.',
        activity: 'No activity recorded yet.',
        reply: 'No replies yet — the customer\'s first message is above.',
        notes: 'Discuss this ticket with the team. The customer never sees these.',
        updates: 'No internal updates yet. Write the first one below.',
        transition: 'This ticket has not changed status yet.',
        history: 'No changes recorded yet.',
      }[this.tab] || 'Nothing here yet.';
    },

    drawerEndpoints: function () {
      return (this.drawer && this.drawer.detail && this.drawer.detail.endpoints) || {};
    },

    canReply: function () {
      return !!(this.drawer && this.drawer.detail && this.drawer.detail.canReply);
    },

    /** The read-only metadata block, or an empty object while the detail is still loading. */
    drawerMeta: function () {
      return (this.drawer && this.drawer.detail && this.drawer.detail.meta) || {};
    },

    /** The sender, once the detail has arrived. Null until then — the block simply waits. */
    drawerCustomer: function () {
      return (this.drawer && this.drawer.detail && this.drawer.detail.customer) || null;
    },

    /* The Company & Customer block (P75 §10). Null when the Space does not run the feature. */
    drawerCompanyCustomer: function () {
      return (this.drawer && this.drawer.detail && this.drawer.detail.companyCustomer) || null;
    },

    /** The drawer's priority, resolved once rather than three times in the template. */
    drawerPriority: function () {
      var row = this.drawerRow();
      var key = (row && row.priority) || 'none';

      return this.priorities[key] || { label: 'No Priority', color: '#d1d5db' };
    },

    /** Is the open row already assigned to the person reading it? */
    assignedToMe: function () {
      var row = this.menu && this.menu.row;

      return !!row && !!row.assignee && row.assignee.id === this.me;
    },

    /** The ids the open row currently carries, for the tag picker's ticks. */
    openRowTagIds: function () {
      return ((this.menu && this.menu.row.tags) || []).map(function (t) { return t.id; });
    },
    activeLabel: function () {
      if (this.mode === 'queue') return this.bootTitle || 'this view';

      var hit = this.statusFilters.filter(function (f) { return f.key === this.filter; }, this)[0];

      return hit ? hit.label : 'All';
    },
    /** Is the Inbox itself empty, as opposed to the filter the agent is standing on? */
    isEmpty: function () { return this.allRows.length === 0; },

    emptyTitle: function () {
      // The queue screen is told what to say: "Nothing in Mine" is the honest heading there
      // even when the whole workspace is empty, because Mine is what was asked for.
      if (this.mode === 'queue') return this.bootTitle || 'Nothing here';

      return this.isEmpty ? 'Your inbox is ready' : 'Nothing in ' + this.activeLabel;
    },
    /**
     * The empty state's wording follows the ACTIVE FILTER, not the screen.
     *
     * "No Requests yet" under a Closed filter would be false whenever there are open ones — the
     * filter is empty, the Space is not, and those are different facts.
     */
    emptyMessage: function () {
      if (this.mode === 'queue') return this.bootMessage || 'No Requests match this view.';

      if (this.isEmpty) {
        return 'We\u2019re waiting for your first customer request. When a new email arrives, ' +
          'it will appear here automatically.';
      }

      return (this.views[this.filter] || {}).empty || 'No Requests match this filter.';
    }
  },
  methods: {
    /**
     * Send a failed reply again (P64).
     *
     * The stored message is re-sent — the agent's words survived, only the delivery did not, and
     * a Retry that made them retype it would be an apology rather than a fix.
     */
    retryReply: function (row) {
      var url = row && row.delivery && row.delivery.retry;
      if (!url || this.retryingId) return;

      var self = this;
      this.retryingId = row.id;

      this.$pb.api(url, { method: 'POST' })
        .then(function (resp) {
          self.$pb.toast(
            (resp && resp.message) || 'Could not send the reply.',
            resp && resp.ok ? 'success' : 'error',
          );

          // Re-read either way: a retry that failed again has a NEW provider message on it, and
          // the row should show what happened this time rather than last time.
          self.refreshDrawer();
        })
        .catch(function () { self.$pb.toast('Could not send the reply.', 'error'); })
        .finally(function () { self.retryingId = null; });
    },

    // ---- Spam (P47) --------------------------------------------------------------------------

    /** Ask before marking — the requirement's confirmation. */
    askSpam: function (row) {
      row = row || (this.menu && this.menu.row);
      if (!row) return;

      this.closeMenu();
      this.spamConfirm = row;
    },

    /**
     * Mark it, once confirmed.
     *
     * Everything the requirement asks to happen next already happens server-side and is not
     * repeated here: the snooze is cancelled (P45), the waiting clock stops, and the row leaves
     * every active view because `viewsFor()` and `RequestViews::tags()` both read `is_spam`.
     * This sends one field and lets those rules run.
     */
    confirmSpam: function () {
      var row = this.spamConfirm;
      if (!row) return;

      this.spamConfirm = null;
      this.change({ is_spam: true }, { is_spam: true }, row);
    },

    /** Not Spam — straight back into the workflow, no question asked. */
    restoreFromSpam: function (row) {
      row = row || (this.menu && this.menu.row);
      if (!row) return;

      this.closeMenu();
      this.change({ is_spam: false }, { is_spam: false }, row);
    },

    // ---- Snooze (P45) ----------------------------------------------------------------------

    /**
     * Open the Snooze dialog for one Request.
     *
     * Seeded from the row when it is ALREADY snoozed, which is how the requirement's "change the
     * snooze time" and "change the snooze condition" are reached — there is no second dialog for
     * editing, because editing a snooze and setting one are the same write.
     */
    openSnooze: function (row) {
      row = row || (this.menu && this.menu.row);
      if (!row) return;

      // The picker is a teleported overlay in its own stacking context; leaving it open behind
      // the dialog means a backdrop swallowing clicks meant for the fields.
      this.closeMenu();

      this.snoozeForm = {
        row: row,
        text: '',
        // `if_no_reply` is the requirement's primary option, so it is the one already chosen.
        condition: row.snooze_condition || 'if_no_reply',
        // Stamped once — see the note on `snoozeForm` in data().
        now: new Date(),
        // Which quick button is lit. Null while the text box is driving.
        quick: null,
      };
    },

    /** Pick a shortcut — it fills the box too, so the two controls can never disagree. */
    pickQuickSnooze: function (q) {
      if (!this.snoozeForm) return;

      this.snoozeForm.quick = q.key;
      this.snoozeForm.text = q.label;
    },

    /** Typing takes over from a quick button — otherwise the lit button would outrank the box. */
    onSnoozeTyped: function () {
      if (this.snoozeForm) this.snoozeForm.quick = null;
    },

    /**
     * Send it.
     *
     * The RESOLVED instant crosses the wire, never the words — see `hcParseWhen` for why the
     * parsing happens on this side. `toISOString()` is UTC, so the server stores the moment the
     * agent actually meant rather than one it had to guess a zone for.
     */
    saveSnooze: function () {
      if (!this.canSnooze) return;

      var self = this;
      var row = this.snoozeForm.row;

      this.snoozeSaving = true;

      this.$pb.api(this.snoozeUrl(row), {
        method: 'POST',
        body: { until: this.snoozeWhen.toISOString(), condition: this.snoozeForm.condition },
      })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not snooze the ticket.', 'error');

            return;
          }

          self.applySnoozed(row, resp);
          self.snoozeForm = null;
        })
        .catch(function () { self.$pb.toast('Could not snooze the ticket.', 'error'); })
        .finally(function () { self.snoozeSaving = false; });
    },

    /** Manual Unsnooze (P45) — the same URL, the other verb. */
    unsnooze: function (row) {
      row = row || (this.menu && this.menu.row);
      if (!row) return;

      var self = this;

      this.closeMenu();

      this.$pb.api(this.snoozeUrl(row), { method: 'DELETE' })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not unsnooze the ticket.', 'error');

            return;
          }

          self.applySnoozed(row, resp);
        })
        .catch(function () { self.$pb.toast('Could not unsnooze the ticket.', 'error'); });
    },

    /**
     * Fold a snooze answer back into the list — the same rules `change()` applies.
     *
     * Written out rather than routed through `change()` because that method is built around a
     * PATCH with an optimistic patch to roll back, and a snooze has neither: there is no
     * plausible local guess at what a server-side write will return, and pretending otherwise
     * would flash a wrong date before the right one arrived.
     */
    applySnoozed: function (row, resp) {
      var at = this.allRows.findIndex(function (r) { return r.id === row.id; });

      if (at !== -1) {
        var merged = Object.assign({}, this.allRows[at], resp.request, {
          views: this.viewsFor(resp.request),
        });

        if (this.leaves(merged)) {
          this.allRows.splice(at, 1);
        } else {
          this.allRows.splice(at, 1, merged);
        }
      }

      /*
       * The toast is worded HERE when a snooze was set, not taken from the server.
       *
       * The server's message is formatted in UTC, so confirming "10:30am" with "Snoozed until
       * Aug 25, 2:30 PM" tells the agent their input was misread when it was not. Unsnoozing
       * carries no time, so the server's wording is fine for that.
       */
      var label = hcSnoozeLabel(resp.request);

      this.$pb.toast(label ? label + '.' : (resp.message || 'Ticket updated.'));
      window.dispatchEvent(new CustomEvent('helpcenter:requests-changed'));

      // The drawer's timeline has a new activity row in it now (P36).
      if (this.drawer && this.drawer.row.id === row.id) this.refreshDrawer();
    },

    /** The template's way in to `hcSnoozeLabel` — the reader's clock, not the server's. */
    snoozeLabelFor: function (row) {
      return hcSnoozeLabel(row);
    },

    /**
     * A Request's own page (P46) — what Expand links to and what Copy Ticket Link copies.
     *
     * Templated on the Space as well as the id, like every other per-row endpoint, so a row in
     * the cross-Space queue resolves to its own Space's URL rather than to whichever Space the
     * screen happens to be showing.
     */
    requestUrl: function (row) {
      if (!row) return '';
      if (this.endpoints.page) return this.endpoints.page.replace('__ID__', row.id);

      return (this.endpointTemplates.page || '')
        .replace('__SPACE__', row.space ? row.space.id : '')
        .replace('__ID__', row.id);
    },

    /**
     * Has this row stopped belonging on the screen it is sitting on? (P47)
     *
     * Two rules, and they were written out twice before this — once in `change()` and once in
     * `applySnoozed()` — which is how the second one came to be missing the case the first one
     * had.
     *
     * On a QUEUE screen the URL is the filter: assigning something from Unassigned means it is
     * not unassigned any more, and leaving it sitting there says otherwise.
     *
     * A Space's Inbox is a fuller list — it groups by workflow status, Closed included — so a
     * status change just moves the row to another group and it stays. But spam and snoozed are
     * not statuses; they are removals, and the server leaves both out of this screen's rows. A
     * row that stays visible after being marked spam contradicts the one thing the action
     * promised, until the next reload quietly proves it right.
     */
    leaves: function (row) {
      if (this.mode === 'queue') {
        return !!this.view && row.views.indexOf(this.view) === -1;
      }

      return !!row.is_spam || !!row.snoozed;
    },

    /** One URL, two verbs — templated like every other per-row endpoint. */
    snoozeUrl: function (row) {
      if (this.endpoints.snooze) return this.endpoints.snooze.replace('__ID__', row.id);

      return (this.endpointTemplates.snooze || '')
        .replace('__SPACE__', row.space ? row.space.id : '')
        .replace('__ID__', row.id);
    },
    rowsFor: function (key) {
      /*
       * A COPY, not `this.allRows` itself.
       *
       * The grid re-reads its data from a watcher on the `rows` prop, and a watcher does not
       * fire when the new value is the same array reference as the old one — so returning the
       * live array meant an in-place change (splicing a row out of a view it had left, or
       * folding the server's answer back into it) mutated the list and the grid went on drawing
       * what it had. Only the case that emptied the list looked right, because that flips the
       * `v-if` and unmounts the grid rather than refreshing it.
       */
      if (key === 'all') return this.allRows.slice();

      if (key.indexOf('status:') === 0) {
        var id = key.slice(7);

        return this.allRows.filter(function (r) { return String(r.status_id) === id; });
      }

      return this.allRows.filter(function (r) { return (r.views || []).indexOf(key) !== -1; });
    },

    /**
     * Open the Request in the drawer (P32).
     *
     * The row's own data first, the thread a moment later — see `drawer` in data() for why.
     */
    open: function (row) {
      this.closeMenu();
      // Collapsed for each ticket opened: "show more" is a decision about the message in front
      // of you, not a preference that should follow you down the queue.
      this.originalExpanded = false;
      this.originalOverflows = false;

      var seq = ++this.drawerSeq;

      this.drawer = { row: row, loading: true, detail: null, seq: seq };

      var self = this;
      var url = (this.endpointTemplates.detail || this.endpoints.detail || '')
        .replace('__SPACE__', row.space ? row.space.id : '')
        .replace('__ID__', row.id);

      if (!url) { this.drawer.loading = false; return; }

      this.$pb.api(url)
        .then(function (resp) {
          // Closed, another row opened, or this same row reopened since — any of the three
          // means this answer is stale.
          if (!self.drawer || self.drawer.seq !== seq) return;

          if (!resp || !resp.ok) {
            self.drawer.loading = false;
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not open the Request.', 'error');

            return;
          }

          self.drawer.detail = resp;
          self.drawer.loading = false;
          // The composer starts with the agent's signature in it (P74).
          self.seedSignature();
          self.measureOriginal();
        })
        .catch(function () {
          /*
           * Only if this open is still the one on screen AND still waiting.
           *
           * `loading` is the second half of the guard: a fetch that fails after something else
           * has already filled the panel — a refresh after an edit, say — has nobody waiting on
           * it, and an error toast over a drawer that is plainly showing the ticket is a message
           * about nothing the reader can see.
           */
          if (!self.drawer || self.drawer.seq !== seq || !self.drawer.loading) return;

          self.drawer.loading = false;
          self.$pb.toast('Could not open the Request.', 'error');
        });
    },

    /**
     * Which icon a feed row gets (P38).
     *
     * Keyed on the FIELD where there is one, falling back to the kind — the same rule
     * `wiEventKind` follows, so a status change carries the same glyph in both feeds.
     */
    eventIcon: function (e) {
      var key = e.kind === 'message' ? 'message'
        : e.kind === 'update' ? 'update'
        : e.field === 'is_spam' ? 'spam'
        : (e.field || (e.event === 'created' ? 'created' : 'update'));

      return hcEventSvg(HC_EVENT_ICON[key] || HC_EVENT_ICON.update);
    },

    /** Raw icon markup for the template's `v-html` — the wizard's helper, same reason. */
    icon: function (name, size) {
      return window.wiIcon ? window.wiIcon(name, size || 16) : '';
    },

    /** Send an agent reply, and fold the new entry into the timeline. */
    sendReply: function () {
      var body = this.replyBody.trim();
      if (!body || this.replySending || !this.drawerEndpoints.reply) return;

      var self = this;
      this.replySending = true;

      this.$pb.api(this.drawerEndpoints.reply, { method: 'POST', body: { body: body } })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not send the reply.', 'error');

            return;
          }

          /* Cleared, then re-seeded once the refreshed detail lands below — a second reply on
             the same ticket should start with the signature just as the first did. */
          self.replyBody = '';
          /*
           * Re-read the ticket rather than splicing the reply in locally.
           *
           * Sending also moves the waiting clock and writes an activity row, and guessing all
           * three in the browser is three chances to show something the server did not do.
           */
          self.refreshDrawer();
          // `sent: false` means the reply is stored and the mail failed — the message says so,
          // and it is not an error toast because nothing was lost.
          self.$pb.toast(resp.message, resp.sent === false ? 'error' : 'success');
        })
        .catch(function () { self.$pb.toast('Could not send the reply.', 'error'); })
        .finally(function () { self.replySending = false; });
    },

    /**
     * The status badge's label, colour and icon — `updateMeta` from work-items.js (P39).
     *
     * Icon and label always travel together, never colour on its own: a chip that says "at
     * risk" only by being amber says nothing to anyone who cannot see amber.
     */
    updateMeta: function (status) {
      var map = {
        on_track: { label: 'On Track', cls: 'text-success border-success/30 bg-success/5', icon: 'check' },
        at_risk: { label: 'At Risk', cls: 'text-amber-700 border-amber-300 bg-amber-50', icon: 'triangle-exclamation' },
        off_track: { label: 'Off Track', cls: 'text-danger border-danger/30 bg-danger/5', icon: 'circle-slash' },
      };

      return map[status] || map.on_track;
    },

    /** Open the note composer — empty to add, filled to edit. */
    openNoteForm: function (n) {
      this.noteForm = { open: true, id: n ? n.raw_id : null, content: n ? n.content : '', busy: false };
    },

    closeNoteForm: function () {
      this.noteForm = { open: false, id: null, content: '', busy: false };
    },

    saveNote: function () {
      var body = this.noteForm.content.trim();
      if (!body || this.noteForm.busy) return;

      var editing = this.noteForm.id !== null;
      var url = editing
        ? (this.drawerEndpoints.noteEdit || '').replace('__ID__', this.noteForm.id)
        : this.drawerEndpoints.noteStore;

      if (!url) return;

      var self = this;
      this.noteForm.busy = true;

      this.$pb.api(url, { method: editing ? 'PATCH' : 'POST', body: { content: body } })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save the note.', 'error');

            return;
          }

          self.closeNoteForm();
          self.refreshDrawer();
          // The message names how many people were notified — worth showing, because sending
          // mail to a colleague is the one side effect a note has.
          self.$pb.toast(resp.message || 'Note added.');
        })
        .catch(function () { self.$pb.toast('Could not save the note.', 'error'); })
        .finally(function () { self.noteForm.busy = false; });
    },

    /** Two clicks, on the card — never `window.confirm` (P39). */
    deleteNote: function (n) {
      if (this.confirmDeleteNote !== n.raw_id) {
        this.confirmDeleteNote = n.raw_id;

        return;
      }

      this.confirmDeleteNote = null;

      var url = (this.drawerEndpoints.noteDelete || '').replace('__ID__', n.raw_id);
      if (!url) return;

      var self = this;

      this.$pb.api(url, { method: 'DELETE' })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not delete the note.', 'error');

            return;
          }

          self.refreshDrawer();
          self.$pb.toast(resp.message || 'Note deleted.');
        })
        .catch(function () { self.$pb.toast('Could not delete the note.', 'error'); });
    },

    /** Open the form — empty to add, filled to edit. */
    openUpdateForm: function (u) {
      this.updateForm = {
        open: true,
        id: u ? u.raw_id : null,
        status: u ? u.status : 'on_track',
        content: u ? u.content : '',
        busy: false,
      };
    },

    closeUpdateForm: function () {
      this.updateForm = { open: false, id: null, status: 'on_track', content: '', busy: false };
    },

    /** Add or save, depending on whether the form carries an id. */
    saveUpdate: function () {
      var body = this.updateForm.content.trim();
      if (!body || this.updateForm.busy) return;

      var editing = this.updateForm.id !== null;
      var url = editing
        ? (this.drawerEndpoints.updateEdit || '').replace('__ID__', this.updateForm.id)
        : this.drawerEndpoints.update;

      if (!url) return;

      var self = this;
      this.updateForm.busy = true;

      this.$pb.api(url, {
        method: editing ? 'PATCH' : 'POST',
        body: { content: body, status: this.updateForm.status },
      })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not save the update.', 'error');

            return;
          }

          self.closeUpdateForm();
          self.refreshDrawer();
          self.$pb.toast(resp.message || 'Update saved.');
        })
        .catch(function () { self.$pb.toast('Could not save the update.', 'error'); })
        .finally(function () { self.updateForm.busy = false; });
    },

    /**
     * Withdraw an update.
     *
     * Confirmed, because it removes somebody's words from a timeline — the same rule the Space
     * member removal follows (P19). Soft on the server, so the record survives.
     */
    deleteUpdate: function (u) {
      /*
       * Confirmed INLINE, on the card, not with `window.confirm`.
       *
       * A native confirm blocks the whole renderer — it is what froze the wizard's Cancel Setup
       * (P24) — and it looks nothing like the pb-modal every other confirmation in this module
       * uses. Two clicks on the card itself asks the same question without either problem.
       */
      if (this.confirmDelete !== u.raw_id) {
        this.confirmDelete = u.raw_id;

        return;
      }

      this.confirmDelete = null;

      var url = (this.drawerEndpoints.updateDelete || '').replace('__ID__', u.raw_id);
      if (!url) return;

      var self = this;

      this.$pb.api(url, { method: 'DELETE' })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not delete the update.', 'error');

            return;
          }

          self.refreshDrawer();
          self.$pb.toast(resp.message || 'Update deleted.');
        })
        .catch(function () { self.$pb.toast('Could not delete the update.', 'error'); });
    },

    /**
     * Does the opening message overflow its clamp? — the work item's measurement (P37).
     *
     * `scrollHeight` and `clientHeight` only differ while the clamp is applied, so this is taken
     * in the COLLAPSED state and kept while expanded. Run after every detail load, because the
     * body is what has just arrived.
     *
     * Both call sites survived an edit that dropped this definition, so the flag stayed false
     * and the Show more control never appeared — `v-if="originalOverflows"` on a value nothing
     * ever set. A method called from two places and defined in none fails silently in
     * JavaScript only if the calls are inside a `.then`, which is exactly where these are.
     */
    measureOriginal: function () {
      var self = this;

      this.$nextTick(function () {
        var el = self.$refs.originalBody;

        if (!el) { self.originalOverflows = false; return; }
        if (self.originalExpanded) return;

        self.originalOverflows = el.scrollHeight - el.clientHeight > 4;
      });
    },

    /** Re-read the open ticket's detail, keeping the panel where it is. */
    /* ---- live updates (P67) ---------------------------------------------------------
       "the Inbox should update automatically without requiring the agent to refresh".

       Subscribe to the Space's channel and, when something lands, refetch. The refetch rather
       than a splice is the whole design: ordering ("longest waiting first"), the spam and
       snooze exclusions, the URL's drill-down filters and the queue counts are all rules the
       SERVER owns, and a browser inserting a row where it guessed it belonged would be a
       second implementation of every one of them.

       Nothing here is a timer. It answers events, which is what the requirement asks for in
       preference to polling. */
    listen: function () {
      if (!this.liveSpaceIds.length || this.liveOff) return;

      var self = this;

      /*
       * realtime.js is DEFERRED and this screen is not, so `PB.onSpaceTickets` may not exist
       * yet when mounted() runs — which is what happened the first time: the socket connected,
       * the user channel subscribed, and the Space channel never did.
       *
       * Try now, and if the hook is not there yet, wait to be told. `liveOff` above makes the
       * second path a no-op when the first already worked, so neither order double-subscribes.
       */
      if (!window.PB || typeof window.PB.onSpaceTickets !== 'function') {
        window.addEventListener('pb:realtime-ready', function () { self.listen(); }, { once: true });

        return;
      }

      this.liveOff = window.PB.onSpaceTickets(this.liveSpaceIds, function (payload) {
        self.onTicketEvent(payload || {});
      });
    },

    onTicketEvent: function (payload) {
      var ticket = payload.ticket || {};

      /*
       * The OPEN ticket first, and only when it is the one that moved.
       *
       * `refreshDrawer()` replaces `drawer.detail` and touches nothing else — so the tab the
       * agent is on, the scroll of the panel and, above all, `replyBody` survive. A reply
       * somebody is halfway through typing must not be the price of a live update.
       */
      if (this.drawer && String(this.drawer.row.id) === String(ticket.id)) {
        this.refreshDrawer();
      }

      this.refreshRows();

      // The navigation's queue counts are somebody else's business; this is the same shout
      // every in-place change on this screen already makes.
      window.dispatchEvent(new CustomEvent('helpcenter:requests-changed'));

      if (payload.toast) this.notify(payload, ticket);
    },

    /*
     * Refetch the list, COALESCED.
     *
     * A customer replying to five tickets at once, or an agent bulk-closing a queue, produces a
     * burst of events. Without this each would start its own request and they would land out of
     * order, so the list could settle on an older answer than one already received. One in
     * flight, one queued, and the queued one runs when the first returns.
     */
    refreshRows: function () {
      if (!this.rowsUrl) return;

      if (this.liveBusy) { this.livePending = true; return; }

      var self = this;
      this.liveBusy = true;

      this.$pb.api(this.rowsUrl).then(function (resp) {
        if (resp && resp.ok && Array.isArray(resp.rows)) {
          /*
           * Replacing the array is what the grid watches, and Tabulator's `replaceData` keeps
           * the scroll position where it was — which is the requirement's "preserve the agent's
           * current scroll position". `setData` would jump to the top.
           */
          self.allRows = resp.rows;

          /*
           * The cross-Space queue's row menus are keyed by SPACE and built from the rows, so a
           * ticket arriving from a Space that had nothing in this view a moment ago would land
           * with a menu that could offer it neither a status nor an assignee. The server sends
           * the rebuilt map with the rows.
           */
          if (resp.spaceOptions) self.spaceOptions = resp.spaceOptions;
        }
      }).catch(function () {
        // A failed refresh leaves the rows that are on screen. They are a moment stale, which
        // is better than an empty list, and the next event will try again.
      }).then(function () {
        self.liveBusy = false;

        if (self.livePending) { self.livePending = false; self.refreshRows(); }
      });
    },

    /*
     * The toast the requirement specifies — title, one line, ~5 seconds, and clickable.
     *
     * NOT shown for the ticket the agent already has open: they are looking at the reply as it
     * arrives, and telling them about it is noise. The server decides WHETHER an event deserves
     * a toast at all (a status change does not); this decides whether THIS screen needs it.
     */
    notify: function (payload, ticket) {
      var open = this.drawer && String(this.drawer.row.id) === String(ticket.id);

      if (open) return;

      var url = ticket.url || '';

      this.$pb.toast(payload.toast.body, 'success', {
        title: payload.toast.title,
        timeout: 5000,
        onClick: url ? function () { window.location.href = url; } : null
      });
    },

    /* The agent's signature, dropped into the composer (P74).
       ------------------------------------------------------------------
       "The signature should appear in the reply composer so the agent can review or edit it
       before sending." Resolved by the SERVER — the same `SignatureResolver` the send uses — so
       what is shown is what would have gone out.

       Only into an EMPTY composer, and that guard is the whole of the logic. This runs on every
       drawer refresh, and a live update (P67) refreshes the drawer while somebody is typing: a
       version that appended unconditionally would push a second signature into a half-written
       reply every time the customer sent something.

       Nothing here touches the internal note or the update field. The requirement is explicit —
       no signature on internal notes, system comments or activity — and the shape of this
       enforces it: `replyBody` is the only variable named. */
    seedSignature: function () {
      var sig = this.drawer && this.drawer.detail ? this.drawer.detail.signature : '';

      if (!sig || this.replyBody.trim() !== '') return;

      // Two blank lines above it, so the agent types their answer and the sign-off is already
      // below the cursor rather than jammed against it.
      this.replyBody = '<p><br></p>' + sig;
    },

    refreshDrawer: function () {
      if (!this.drawer) return;

      var self = this;
      var row = this.drawer.row;
      var url = (this.endpointTemplates.detail || this.endpoints.detail || '')
        .replace('__SPACE__', row.space ? row.space.id : '')
        .replace('__ID__', row.id);

      var seq = this.drawer.seq;

      this.$pb.api(url).then(function (resp) {
        if (!self.drawer || self.drawer.seq !== seq || !resp || !resp.ok) return;
        self.drawer.detail = resp;
        self.seedSignature();
        self.measureOriginal();
      });

      // The row's own chips may have moved with it — the reply hands the wait back, and a
      // status rule may have reassigned. The counts in the navigation move too.
      window.dispatchEvent(new CustomEvent('helpcenter:requests-changed'));
    },

    closeDrawer: function () {
      /*
       * On a Request's page there is nothing to close TO (P46).
       *
       * The drawer is the page, so dismissing it would leave a blank screen behind. Escape and
       * anything else that closes goes back to the queue instead — the work item page does the
       * same thing for the same reason.
       */
      if (this.pageMode) {
        if (this.queueUrl) window.location.href = this.queueUrl;

        return;
      }

      this.drawer = null;
      this.tab = 'all';
      this.confirmDelete = null;
      this.confirmDeleteNote = null;
      this.noteForm = { open: false, id: null, content: '', busy: false };
      this.replyBody = '';
      this.updateBody = '';
      // The disclosures belong to the ticket that was open, not to the drawer.
      this.showPrevious = false;
      this.customerForm = null;
      // The dialogs belong to the ticket that was open, not to the drawer.
      this.snoozeForm = null;
      this.spamConfirm = null;
    },

    /**
     * Open the Update customer dialog, seeded with what is already known (P44).
     *
     * `email` is carried for DISPLAY only and never sent — the dialog shows which customer is
     * being edited, and the server would reject it anyway.
     *
     * The name falls back to blank when it equals the address: a customer whose email header
     * carried no display name is stored with the address as the name (P33), and pre-filling the
     * field with it would invite somebody to save `alex@acme.com` as a person's name.
     */
    startCustomerEdit: function () {
      var c = this.drawerCustomer;
      if (!c) return;

      // A picker left open behind the dialog is a menu the backdrop swallows the click for.
      this.closeMenu();

      this.customerForm = {
        email: c.email || '',
        name: c.name === c.email ? '' : (c.name || ''),
        company: c.company || '',
        phone: c.phone || '',
        external_id: c.external_id || '',
      };
    },

    /**
     * Save the customer, and fold the answer back into the OPEN drawer.
     *
     * The server's version replaces the form's, because it also returns the counts — and those
     * are computed from rows, not from anything typed here.
     */
    saveCustomer: function () {
      var c = this.drawerCustomer;
      if (!c || !c.endpoint || this.customerSaving) return;

      var self = this;
      this.customerSaving = true;

      // The editable four, explicitly — never the whole form. `email` is in it for the dialog's
      // header and is not the customer's to change here.
      var body = {
        name: this.customerForm.name,
        company: this.customerForm.company,
        phone: this.customerForm.phone,
        external_id: this.customerForm.external_id,
      };

      this.$pb.api(c.endpoint, { method: 'PATCH', body: body })
        .then(function (resp) {
          if (!resp || !resp.ok) {
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not update the customer.', 'error');

            return;
          }

          if (self.drawer && self.drawer.detail) {
            // `previous` and `endpoint` are the panel's, not the model's — carried over.
            self.drawer.detail.customer = Object.assign({}, self.drawer.detail.customer, resp.customer);
          }

          self.customerForm = null;
          self.$pb.toast(resp.message || 'Customer updated.');
        })
        .catch(function () { self.$pb.toast('Could not update the customer.', 'error'); })
        .finally(function () { self.customerSaving = false; });
    },

    /**
     * The row the drawer is showing, as the LIST currently holds it.
     *
     * Not `drawer.row`, which is the snapshot taken when it opened: changing a chip inside the
     * drawer patches the list, and reading back through the list is what makes the drawer's own
     * chips update with it rather than showing what was true when it slid in.
     */
    drawerRow: function () {
      if (!this.drawer) return null;

      var id = this.drawer.row.id;

      return this.allRows.filter(function (r) { return r.id === id; })[0] || this.drawer.row;
    },

    /**
     * A chip or the ••• was clicked — open its picker under it (P28).
     *
     * One handler for all five. The picker is positioned against the control that opened it
     * rather than against the row, so it reads as belonging to the thing being changed; it is
     * clamped so a chip near the right edge cannot open a panel off-screen.
     */
    onChip: function (payload) {
      var box = payload.el.getBoundingClientRect();
      var width = payload.act === 'menu' ? 236 : 260;

      this.query = '';
      this.menu = {
        act: payload.act,
        row: payload.row,
        width: width,
        top: box.bottom + window.scrollY + 4,
        left: Math.max(8, Math.min(
            box.left + window.scrollX,
            window.innerWidth - width - 12,
        )),
      };

      // The search box only exists on the two pickers that have one.
      if (payload.act === 'assignee' || payload.act === 'tags') {
        var self = this;
        this.$nextTick(function () { self.$refs.search && self.$refs.search.focus(); });
      }
    },

    closeMenu: function () { this.menu = null; this.query = ''; },

    /** Is this row waiting on the server? */
    isSaving: function (row) { return this.savingIds.indexOf(row.id) !== -1; },

    /**
     * A chip inside the DRAWER opens the same picker the row's chips do (P32).
     *
     * The pickers are keyed off `menu.act` and read their options from `menu.row`, so the drawer
     * hands them a row exactly as the grid does — one set of pickers, one set of save paths, and
     * no second implementation of "what may this Request become".
     */
    openDrawerPicker: function (act, event) {
      var row = this.drawerRow();
      if (!row) return;

      this.onChip({ act: act, row: row, el: event.currentTarget });
    },

    /**
     * Send one change, applying it to the row FIRST (P28).
     *
     * The requirement asks for the chip to update without a refresh, for a loading state while
     * it saves, and for the previous value to come back if it fails — which is the optimistic
     * pattern in three sentences. The row is patched locally, the id goes into `savingIds` so
     * its chips dim, and the SERVER's version of the row replaces the guess on success: a status
     * change also moves the waiting clock and may close the Request, and reproducing those rules
     * in the browser would be a second implementation free to disagree.
     *
     * On failure the snapshot goes back. Not a re-fetch: the row we had is the row that was
     * true a moment ago, and a reload would also undo anything else the agent changed meanwhile.
     */
    change: function (patch, optimistic, row) {
      row = row || (this.menu && this.menu.row);
      if (!row || this.isSaving(row)) return;

      var self = this;
      var url = this.updateUrl(row);
      var i = this.allRows.findIndex(function (r) { return r.id === row.id; });
      if (i === -1) return;

      var before = Object.assign({}, this.allRows[i]);

      this.closeMenu();
      this.savingIds = this.savingIds.concat([row.id]);

      if (optimistic) {
        this.allRows.splice(i, 1, Object.assign({}, before, optimistic));
      }

      this.$pb.api(url, { method: 'PATCH', body: patch })
        .then(function (resp) {
          var at = self.allRows.findIndex(function (r) { return r.id === row.id; });

          if (!resp || !resp.ok) {
            // Put it back exactly as it was, and say why.
            if (at !== -1) self.allRows.splice(at, 1, before);
            self.$pb.toast(self.$pb.firstError(resp) || 'Could not update the Request.', 'error');

            return;
          }

          if (at !== -1) {
            var merged = Object.assign({}, self.allRows[at], resp.request, {
              views: self.viewsFor(resp.request)
            });

            if (self.leaves(merged)) {
              self.allRows.splice(at, 1);
            } else {
              self.allRows.splice(at, 1, merged);
            }
          }

          self.$pb.toast(resp.message || 'Request updated.');
          // The navigation's counts are drawn by the server; this is what tells them a Request
          // moved. The sidebar listens — see partials/help-center-nav.blade.php.
          window.dispatchEvent(new CustomEvent('helpcenter:requests-changed'));

          /*
           * Re-read the open ticket, if this change was made to it.
           *
           * The chip patches the ROW; the timeline lives on the drawer's fetched detail, and
           * every change now writes an activity row (P36). Without this the panel showed a
           * ticket whose status had visibly just changed above a feed insisting nothing had
           * happened since it was opened.
           */
          if (self.drawer && self.drawer.row.id === row.id) self.refreshDrawer();
        })
        .catch(function () {
          var at = self.allRows.findIndex(function (r) { return r.id === row.id; });
          if (at !== -1) self.allRows.splice(at, 1, before);
          self.$pb.toast('Could not update the Request.', 'error');
        })
        .finally(function () {
          self.savingIds = self.savingIds.filter(function (id) { return id !== row.id; });
        });
    },

    // ---- what each picker sends ------------------------------------------------------------

    setStatus: function (status) {
      this.change({ status_id: status.id }, { status: { id: status.id, name: status.name, color: status.color } });
    },

    setAssignee: function (member) {
      this.change(
        { assignee_id: member ? member.id : null },
        { assignee: member ? { id: member.id, name: member.name, initial: member.initial, avatar_url: member.avatar_url } : null },
      );
    },

    setPriority: function (key) {
      this.change({ priority: key }, { priority: key });
    },

    /**
     * Tags are a SET, so the picker toggles rather than chooses.
     *
     * The whole list is sent every time — `sync` on the server — because "the tags are now
     * these" is one fact, while an add and a remove endpoint would be two requests racing to
     * describe the same row.
     */
    toggleTag: function (tag) {
      var row = this.menu && this.menu.row;
      if (!row) return;

      var current = (row.tags || []).slice();
      var at = current.findIndex(function (t) { return t.id === tag.id; });

      if (at === -1) {
        current.push({ id: tag.id, name: tag.name });
      } else {
        current.splice(at, 1);
      }

      // The picker stays OPEN — adding three tags should be three clicks, not three round
      // trips through the chip. `change()` closes it, so the row reference is re-read after.
      var ids = current.map(function (t) { return t.id; });
      this.change({ tag_ids: ids }, { tags: current }, row);
    },

    /**
     * Where this row's PATCH goes.
     *
     * A Space's Inbox has one endpoint for every row — it is all one Space. The cross-Space
     * queue has a template instead, because the update route is nested under the Space the
     * Request belongs to, which is where the permission is checked.
     */
    updateUrl: function (row) {
      if (this.endpoints.update) return this.endpoints.update.replace('__ID__', row.id);

      return (this.endpointTemplates.update || '')
        .replace('__SPACE__', row.space ? row.space.id : '')
        .replace('__ID__', row.id);
    },

    /**
     * Re-derive which views a just-changed row belongs to.
     *
     * The update endpoint returns the Request, not the whole queue, so this one row's membership
     * has to be recomputed locally. Deliberately the SAME rules the server applies in
     * `App\Services\HelpCenter\RequestViews::tags()` — if those ever diverge, this is the half
     * to delete.
     */
    viewsFor: function (r) {
      var out = [];
      var open = !r.closed && !r.is_spam;
      // Snoozing takes a row out of the active views and puts it in exactly one other (P45).
      var active = open && !r.snoozed;

      if (active && !r.assignee) out.push('unassigned');
      if (active && r.assignee && r.assignee.id === this.me) out.push('mine');
      if (active && r.assignee) out.push('assigned');
      // Spam and closed both outrank a snooze (P47). The server clears the snooze when a ticket
      // is marked, so this only matters for the optimistic patch in between — but a row that
      // flickers into Snoozed on its way out of every queue is still a row in the wrong place.
      if (r.snoozed && open) out.push('snoozed');
      if (r.closed && !r.is_spam) out.push('closed');
      if (r.is_spam) out.push('spam');
      if (active) out.push('inbox');

      return out;
    },

    /** Assign to Me — the one-click case the ••• exists for. */
    assignToMe: function () {
      // Belt and braces beside the disabled button: nothing to do, and doing it anyway would
      // move `last_activity_at` and reorder the queue for a change that changed nothing.
      if (this.assignedToMe) return;

      var mine = this.menuMembers.filter(function (m) { return !m.pending && m.id === this.me; }, this)[0];

      // Fall back to a bare id: somebody may be able to manage a Space they are not a member
      // of, in which case the server refuses and says so — which is the honest outcome.
      this.setAssignee(mine || { id: this.me, name: 'You', initial: 'Y', avatar_url: null });
    },

    setClosed: function (closed) {
      this.change({ closed: closed }, null);
    },

    copyTicketNumber: function () {
      var row = this.menu && this.menu.row;
      if (!row) return;

      this.copy(row.identifier, 'Ticket number ' + row.identifier + ' copied.');
    },

    /** One clipboard path, so a failure reads the same wherever it came from. */
    copy: function (text, done) {
      this.closeMenu();

      if (!navigator.clipboard) {
        this.$pb.toast('Copying is not available in this browser.', 'error');

        return;
      }

      var self = this;
      navigator.clipboard.writeText(text)
        .then(function () { self.$pb.toast(done); })
        .catch(function () { self.$pb.toast('Could not copy.', 'error'); });
    },

    copyLink: function () {
      var row = this.menu && this.menu.row;
      if (!row) return;

      /*
       * The Request's own page (P46).
       *
       * This used to be the current URL with `#request-<id>` on the end — a fragment nothing
       * read, because there was no page to link to and a link that only works if the recipient
       * is already on the right queue is not a link. It resolves to the real address now, and
       * falls back to the old fragment only if the template is missing.
       */
      var url = this.requestUrl(row);

      this.copy(
        url ? window.location.origin + url : window.location.href + '#request-' + row.id,
        'Link to ' + row.identifier + ' copied.',
      );
    }
  },
  template: [
    '<div>',

    // ---- Filters: the Space's own workflow statuses, and nothing else ----
    //
    // The ownership views that used to sit here behind a divider — Unassigned, Mine, Draft,
    // Assigned, Closed, Spam — are NAVIGATION now, at the top of the Help Center and inside
    // each Space (P21, P22). This bar is only ever the Space's own workflow.
    //
    // Gated on the MODE, not on `statuses.length`: a Space's queue view is sent that Space's
    // statuses too — it groups by them and its row menu offers them — and drawing chips there
    // would put a second filter on a screen whose filter is already the URL.
    //
    // Pulled up 8px so the space ABOVE the bar matches the space below it: the page wrapper's
    // py-6 puts 24px over it and the grid sits 16px under it, and unequal gaps around a row of
    // chips read as the bar belonging to whichever side is tighter.
    //
    // An inline style rather than `-mt-2`, which is not in the built stylesheet — Tailwind only
    // emits what it finds in the sources it scans, and a class that exists only inside a
    // JavaScript template string is a class that silently does nothing.
    // Everything down to the drawer is the QUEUE, and a Request's page has none of it (P46).
    '  <template v-if="!pageMode">',
    '  <div v-if="mode === \'space\' && !isEmpty" style="margin-top:-8px"',
    '       class="flex flex-wrap items-center gap-1">',
    '    <button v-for="f in statusFilters" :key="f.key" type="button" @click="filter = f.key"',
    '            :class="[\'inline-flex items-center gap-1.5 h-7 px-3 rounded-md text-[12px] border\',',
    '                     filter === f.key ? \'border-stroke bg-sel text-brand font-semibold\' : \'border-line text-ink hover:bg-hover\']">',
    '      <span v-if="f.color" class="h-2 w-2 rounded-full" :style="{ background: f.color }"></span>',
    '      <span v-text="f.label"></span>',
    '      <span v-if="f.count" class="text-[11px] font-semibold rounded-full px-1.5 text-sub bg-hover" v-text="f.count"></span>',
    '    </button>',
    '  </div>',

    // ---- The grid, flush to the page edges like the Work Items screen ----
    '  <div v-if="rows.length" :class="[\'-mx-5 sm:-mx-8 border-t border-line\', mode === \'space\' ? \'mt-4\' : \'\']">',
    '    <hc-request-list ref="grid" :rows="rows" :statuses="statuses" :can-manage="canManage"',
    '                     :group-by="groupBy" :empty-groups="mode === \'space\'"',
    '                     :priorities="priorities" :saving-ids="savingIds"',
    '                     height="calc(100vh - 280px)" @open="open" @chip="onChip"></hc-request-list>',
    '  </div>',
    // A STANDALONE message, never an empty table. A grid with headers and no rows reads as
    // "something failed to load"; this reads as "nothing has happened yet", which is the truth.
    '  <div v-else class="mt-10 mx-auto max-w-[440px] text-center">',
    '    <span class="mx-auto h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub" v-html="inboxGlyph"></span>',
    '    <h2 class="mt-4 text-[15px] font-semibold text-head" v-text="emptyTitle"></h2>',
    '    <p class="mt-1.5 text-[13px] text-sub leading-relaxed" v-text="emptyMessage"></p>',
    // The inbound address, because "waiting for your first request" invites the obvious
    // question of where it should be sent — and answering it here saves a trip to Settings.
    '    <p v-if="isEmpty && inboundAddress" class="mt-5 text-[12px] text-faint">',
    '      Forwarding to <span class="text-sub _moretogether-break" v-text="inboundAddress"></span>',
    '    </p>',
    '  </div>',
    '  </template>',

    // ---- The detail drawer (P32) ----
    //
    // A slide-over, the shape the work item drawer uses: a dimmed backdrop, a panel pinned to
    // the right, the subject and its thread on the left of the panel and the properties down
    // the right. The chips in it are the SAME chips — same pickers, same save path — so a
    // Request cannot mean one thing on the row and another in the panel.
    /*
     * Teleported ONLY as a slide-over (P46).
     *
     * A drawer over a queue has to escape the page's stacking context or the grid's own layers
     * paint over it. A Request's page is the opposite case: teleporting there lifted the whole
     * detail out to `document.body`, where it rendered after the topbar and left the app rail
     * and the Help Center navigation off the screen entirely — a page with no way out of it.
     *
     * `:disabled` rather than a second copy of the markup, so the two framings stay one drawer.
     */
    '  <teleport to="body" :disabled="pageMode">',
    // The SAME shell the work item drawer uses (projects/work-items.js): `fixed inset-0 z-[85]`,
    // a 20%-black backdrop, and a panel pinned right at `w-full sm:w-[80%]` with `shadow-2xl`.
    // Copied class for class rather than approximated — a slide-over that is 860px here and 80%
    // there is two drawers, and the whole point of following it is that it is one.
    // In page mode this is not a slide-over at all: no fixed shell, no backdrop, no width — it
    // fills the host the blade gave it. The work item screen does the same, and it is why the
    // page's detail is the drawer rather than a second copy of it.
    '    <div v-if="drawer" :class="pageMode ? \'h-full flex flex-col\' : \'fixed inset-0 z-[85]\'">',
    '      <div v-if="!pageMode" class="absolute inset-0 bg-black/20" @click="closeDrawer"></div>',
    '      <aside :class="pageMode',
    '               ? \'flex-1 min-h-0 flex flex-col bg-white\'',
    '               : [\'absolute right-0 top-0 h-full bg-white shadow-2xl flex flex-col\',',
    '                  drawerWide ? \'w-full\' : \'w-full sm:w-[80%]\']"',
    '             :role="pageMode ? null : \'dialog\'" aria-label="Request detail">',

    // ---- toolbar: Close, Expand, then the actions — the work item drawer's order ----
    '      <div class="flex items-center gap-1 px-4 h-14 border-b border-line shrink-0">',
    '      <template v-if="!pageMode">',
    '        <button type="button" @click="closeDrawer" data-tip="Close" aria-label="Close"',
    '                class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover"',
    '                v-html="icon(\'arrow-right-long\', 18)"></button>',
    /* Expand is a LINK to the Request's own page (P46), the way the work item's is.
       It used to widen the panel instead, because there was no page to open. There is now, and
       "expand" meaning two different things in two drawers was the thing worth fixing: an
       address you can bookmark, paste to a colleague or middle-click is what the control has
       always promised, and a wider panel is not that. */
    '        <a :href="requestUrl(drawerRow())" data-tip="Open as full page" aria-label="Open as full page"',
    '           class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover"',
    '           v-html="icon(\'expand\', 16)"></a>',
    '        <span class="ml-2 text-[13px] font-medium text-ink tabular-nums" v-text="drawerRow().identifier"></span>',
    '        <span v-if="drawerRow().space" class="text-[12px] text-faint">&middot;</span>',
    '        <span v-if="drawerRow().space" class="text-[12px] text-faint truncate" v-text="drawerRow().space.name"></span>',
    '      </template>',

    // On the Request's own page the toolbar carries a breadcrumb back to the queue instead —
    // the work item page's toolbar, and for the same reason: a page reached by expanding
    // something has to say what it came out of.
    '      <template v-else>',
    // The same control the Blade toolbars include, rendered here because this page has no
    // Blade toolbar. The sidebar's listener is delegated on the ATTRIBUTE, which is exactly
    // what lets a Vue-rendered header carry it (see partials/sidebar-expand).
    '        <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar"',
    '                aria-controls="sidebar" aria-expanded="false"',
    '                class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0"',
    '                v-html="icon(\'sidebar\', 16)"></button>',
    '        <span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>',
    '        <a :href="queueUrl" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink min-w-0">',
    '          <span v-html="icon(\'inbox\', 15)"></span>',
    '          <span class="truncate" v-text="drawerRow().space ? drawerRow().space.name : \'Inbox\'"></span>',
    '        </a>',
    '        <span class="text-faint shrink-0" v-html="icon(\'chevron-right\', 13)"></span>',
    '        <span class="text-[13px] font-medium text-ink tabular-nums shrink-0" v-text="drawerRow().identifier"></span>',
    '      </template>',
    '        <div class="ml-auto flex items-center gap-1.5">',
    // Snooze, on the toolbar and not only in the ••• (P45): the requirement puts this action on
    // the Ticket Detail view, and burying the one action that removes a ticket from your day
    // inside a menu makes it something people never find.
    '          <button v-if="!drawerRow().closed && !drawerRow().is_spam" type="button" @click="openSnooze(drawerRow())"',
    '                  :data-tip="drawerRow().snoozed ? \'Change snooze\' : \'Snooze until\u2026\'"',
    '                  :aria-label="drawerRow().snoozed ? \'Change snooze\' : \'Snooze until\'"',
    '                  class="h-8 w-8 grid place-items-center rounded-md hover:bg-hover"',
    '                  :class="drawerRow().snoozed ? \'text-brand\' : \'text-sub\'"',
    '                  v-html="icon(\'clock\', 16)"></button>',
    '          <button type="button" @click="onChip({ act: \'menu\', row: drawerRow(), el: $event.currentTarget })"',
    '                  data-tip="Request actions" aria-label="Request actions"',
    '                  class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover"',
    '                  v-html="icon(\'ellipsis-thin\', 16)"></button>',
    '        </div>',
    '      </div>',

    '      <div class="flex-1 min-h-0 flex">',

    // ---- left: subject, customer, the thread ----
    '        <div class="flex-1 min-w-0 overflow-y-auto px-6 py-5">',

    // ---- the snooze indicator (P45) ----
    //
    // A banner rather than a chip among the others, because it is not a property of the ticket
    // in the way status and priority are — it is a statement that this ticket is not currently
    // in anybody's queue, which is the first thing somebody opening it needs to know.
    '          <div v-if="drawerRow().snoozed" class="mb-4 flex items-start gap-2.5 rounded-md border border-line bg-hover px-3 py-2.5">',
    '            <span class="text-sub mt-0.5 shrink-0" v-html="icon(\'clock\', 15)"></span>',
    '            <div class="min-w-0 flex-1">',
    '              <p class="text-[13px] font-semibold text-ink" v-text="snoozeLabelFor(drawerRow())"></p>',
    '              <p class="text-[12px] text-sub">',
    '                <span v-if="drawerRow().snooze_condition_label" v-text="drawerRow().snooze_condition_label"></span>',
    '                <span v-if="drawer.detail && drawer.detail.meta.snooze && drawer.detail.meta.snooze.by">',
    '                  &middot; by <span v-text="drawer.detail.meta.snooze.by"></span></span>',
    '              </p>',
    '            </div>',
    '            <button type="button" @click="unsnooze(drawerRow())"',
    '                    class="shrink-0 inline-flex items-center h-7 px-2.5 rounded-md border border-stroke bg-white text-[12px] font-semibold text-ink hover:bg-hover">Unsnooze</button>',
    '          </div>',

    '          <h2 class="text-[16px] font-semibold text-head" v-text="drawerRow().subject || \'(no subject)\'"></h2>',

    '          <div v-if="drawer.detail" class="mt-2 flex items-center gap-2 text-[12px] text-sub">',
    '            <span class="h-6 w-6 rounded-full shrink-0 text-white grid place-items-center text-[10px] font-bold"',
    '                  :style="{ background: $pb.avatarColor({ email: drawer.detail.customer.email }) }"',
    '                  v-text="drawer.detail.customer.initial"></span>',
    '            <span class="text-ink" v-text="drawer.detail.customer.name"></span>',
    '            <span class="text-faint" v-text="drawer.detail.customer.email"></span>',
    '          </div>',

    // ---- what the customer asked (P37) ----
    //
    // Under the subject and ABOVE the tabs, so it is visible whichever tab is selected. It is
    // the ticket, not an entry in the ticket's history — which is why it is also excluded from
    // the timeline below rather than appearing twice.
    // No border and no card: the work item drawer prints a description straight onto the panel,
    // and a box around the ticket's own question makes it read as a quotation of something else.
    //
    // `wi-desc-clamp` and the Show more control are the work item's, verbatim — the class comes
    // from work-items.css, which this screen already loads for the grid skin.
    '          <div v-if="drawer.detail && drawer.detail.original" class="mt-4">',
    '            <p ref="originalBody" class="text-[14px] text-ink leading-relaxed"',
    '               :class="originalExpanded ? \'\' : \'wi-desc-clamp\'" style="white-space:pre-wrap"',
    '               v-text="drawer.detail.original.body"></p>',
    '            <button v-if="originalOverflows" type="button" @click="originalExpanded = !originalExpanded"',
    '                    class="mt-2 text-[13px] font-medium text-link hover:underline"',
    '                    v-text="originalExpanded ? \'Show less\' : \'Show more\'"></button>',
    '            <p class="mt-2 text-[11px] text-faint">',
    '              <span v-text="drawer.detail.original.from_name"></span>',
    '              <span v-if="drawer.detail.original.at"> &middot; <span v-text="drawer.detail.original.at"></span></span>',
    '            </p>',
    // The opening email's files (P66) — under the sender line, because they belong to that
    // message rather than to the ticket's title.
    hcAttachments('drawer.detail.original'),
    '          </div>',
    '          <p v-else-if="drawer.detail" class="mt-4 text-[13px] text-faint">',
    '            No message body was stored for this ticket.',
    '          </p>',

    // ---- tabs (P36) ----
    // Six readings of ONE list. Every tab is a filter over the timeline the server assembled,
    // so no two of them can disagree about the order things happened in.
    '          <div class="mt-4 flex flex-wrap items-center gap-1 border-b border-line">',
    '            <button v-for="t in tabs" :key="t.key" type="button" @click="tab = t.key"',
    '                    :class="[\'px-3 h-8 text-[13px] border-b-2 -mb-px\',',
    '                             tab === t.key ? \'border-brand text-ink font-medium\' : \'border-transparent text-sub hover:text-ink\']">',
    '              <span v-text="t.label"></span>',
    '              <span v-if="t.count" class="ml-1 text-[11px] text-faint" v-text="t.count"></span>',
    '            </button>',
    '          </div>',

    '          <p v-if="drawer.loading" class="mt-6 text-[13px] text-sub">Loading the conversation&hellip;</p>',

    // ---- the stream (P38) ----
    //
    // The work item detail's feed, row for row: an event-icon circle, then either a card (for
    // something somebody SAID) or a one-line sentence (for something that HAPPENED), then the
    // relative time. History is the exception — it stays a table of old → new, because a list of
    // changes reads better as values than as prose.
    // ---- Reply to Customer: the work item's COMMENT section (P40) ----
    //
    // Composer first, then a card per message — the shape work-items.js gives its Comments tab.
    // A conversation is read from the top and added to at the top; the composer sitting under a
    // long thread is a composer nobody scrolls to.
    '          <div v-else-if="tab === \'reply\'" class="mt-4">',

    '            <div v-if="canReply" class="mb-5">',
    '              <pg-editor v-if="useEditor" v-model="replyBody" placeholder="Write a reply to the customer\u2026"',
    '                         min-height="160px" :document-view="false" :buttons="editorButtons"',
    '                         :license="editorLicense" :disabled="replySending" />',
    // Without the licensed package there is no `pg-editor` to render, and an empty space where
    // the composer should be is worse than a plain box.
    '              <textarea v-else v-model="replyBody" rows="5" :disabled="replySending"',
    '                        placeholder="Write a reply to the customer\u2026" class="pb-textarea w-full"></textarea>',
    '              <div class="flex items-center gap-2 mt-2">',
    // LEFT-aligned, and therefore FIRST in the row rather than pushed there with a margin —
    // the button is what this row is for, and the note beside it is the caption. The work
    // item's Comment button sits on the right; a reply that emails a customer is not the same
    // act as a comment, and it belongs where the eye lands after reading the composer.
    '                <button type="button" @click="sendReply" :disabled="replySending || !replyBody.trim()"',
    '                        class="h-8 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50"',
    '                        v-text="replySending ? \'Sending\u2026\' : \'Send reply\'"></button>',
    '                <span class="text-[12px] text-faint">This is emailed to the customer.</span>',
    '              </div>',
    '            </div>',
    '            <p v-else class="mb-5 text-[12px] text-faint">',
    '              This Request has no customer address to reply to.',
    '            </p>',

    '            <ul class="space-y-3">',
    '              <li v-for="m in tabRows" :key="m.id" class="rounded-lg border border-line bg-white">',
    '                <div class="flex items-start gap-2.5 p-3">',
    '                  <span class="h-7 w-7 rounded-full shrink-0 text-white grid place-items-center text-[11px] font-bold"',
    '                        :style="{ background: $pb.avatarColor({ email: m.author_email }) }" v-text="m.initial"></span>',
    '                  <div class="min-w-0 flex-1 overflow-hidden">',
    '                    <div class="flex items-center gap-2">',
    '                      <span class="text-[13px] font-medium text-ink truncate" v-text="m.author_name"></span>',
    '                      <span :class="[\'text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 shrink-0\',',
    '                                     m.inbound ? \'bg-sel text-brand\' : \'bg-line text-sub\']" v-text="m.label"></span>',
    '                      <span class="text-[12px] text-faint shrink-0" :title="m.at" v-text="m.ago"></span>',
    // No Edit or Delete, unlike a comment: a reply is an email that has already left. Editing
    // one would change our record of what the customer was sent, which is the opposite of what
    // an edit is for.
    '                    </div>',
    // `v-html` on a message body: an agent's reply was sanitized on the way in (P41), and an
    // inbound email's body is plain text the server flattened — see `readableBody()`. The
    // `wi-rich` class is the same one the work item's comments render inside.
    '                    <div v-if="m.body_html" class="wi-rich text-[13px] text-ink mt-1.5" v-html="m.body_html"></div>',
    '                    <p v-else class="text-[13px] text-ink mt-1.5 leading-relaxed" style="white-space:pre-wrap" v-text="m.body"></p>',
    '                  </div>',
    '                </div>',
    '              </li>',
    '              <li v-if="!tabRows.length" class="py-8 text-center">',
    '                <div class="text-[13px] font-semibold text-head">No replies yet</div>',
    '                <div class="text-[13px] text-sub mt-1">The customer\u2019s first message is above. Reply to start the conversation.</div>',
    '              </li>',
    '            </ul>',
    '          </div>',

    // ---- Internal Notes (P42) ----
    //
    // The comment section's shape again, with two differences that exist to stop an agent
    // mistaking an internal discussion for a customer reply: an amber left rule with an amber
    // ground, and a standing line saying the customer never sees this. Everything private in
    // this drawer is amber; everything the customer will read is not.
    '          <div v-else-if="tab === \'notes\'" class="mt-4">',

    '            <div v-if="canEditDrawer" class="mb-4 rounded-lg border border-line bg-amber-50 p-3"',
    // The left rule is inline: `border-l-2` is not in the built stylesheet, and a class that
    // does nothing is the trap this codebase keeps stepping into (P22, P23, P32).
    '                 style="border-left:2px solid #fcd34d">',
    '              <pg-editor v-if="useEditor" v-model="noteForm.content"',
    '                         placeholder="Write an internal note\u2026 use @ to mention a teammate"',
    '                         min-height="120px" :document-view="false" :buttons="editorButtons"',
    '                         :license="editorLicense" :mention-url="drawerEndpoints.mentionUsers"',
    '                         :disabled="noteForm.busy" />',
    '              <textarea v-else v-model="noteForm.content" rows="4"',
    '                        placeholder="Write an internal note\u2026" class="pb-textarea w-full"></textarea>',
    '              <div class="flex items-center gap-2 mt-2">',
    '                <span class="text-[12px] text-amber-700">Internal &mdash; never sent to the customer.</span>',
    '                <button v-if="noteForm.id" type="button" @click="closeNoteForm"',
    '                        class="ml-auto h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '                <button type="button" @click="saveNote" :disabled="noteForm.busy || !noteForm.content.trim()"',
    '                        :class="[\'h-8 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50\', noteForm.id ? \'\' : \'ml-auto\']"',
    '                        v-text="noteForm.busy ? \'Saving\u2026\' : (noteForm.id ? \'Save note\' : \'Add note\')"></button>',
    '              </div>',
    '            </div>',

    '            <ul class="space-y-3">',
    '              <li v-for="n in tabRows" :key="n.id" class="rounded-lg border border-line bg-amber-50"',
    '                  style="border-left:2px solid #fcd34d">',
    '                <div class="flex items-start gap-2.5 p-3">',
    '                  <span class="h-7 w-7 rounded-full shrink-0 text-white grid place-items-center text-[11px] font-bold"',
    '                        :style="{ background: $pb.avatarColor(n.author || { name: n.author_name }) }"',
    '                        v-text="(n.author && n.author.initial) || \'?\'"></span>',
    '                  <div class="min-w-0 flex-1 overflow-hidden">',
    '                    <div class="flex items-center gap-2">',
    '                      <span class="text-[13px] font-medium text-ink truncate" v-text="n.author_name"></span>',
    '                      <span class="text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-700 shrink-0">Internal Note</span>',
    '                      <span class="text-[12px] text-faint shrink-0" :title="n.at" v-text="n.ago"></span>',
    '                      <span v-if="n.edited" class="text-[11px] text-faint shrink-0">(edited)</span>',
    '                      <span class="ml-auto flex items-center gap-2 shrink-0">',
    '                        <button v-if="n.mine" type="button" @click="openNoteForm(n)"',
    '                                class="text-[12px] text-sub hover:underline">Edit</button>',
    '                        <template v-if="canEditDrawer">',
    '                          <span v-if="confirmDeleteNote === n.raw_id" class="text-[12px] text-sub">Delete?</span>',
    '                          <button type="button" @click="deleteNote(n)" class="text-[12px] text-danger hover:underline"',
    '                                  v-text="confirmDeleteNote === n.raw_id ? \'Yes, delete\' : \'Delete\'"></button>',
    '                          <button v-if="confirmDeleteNote === n.raw_id" type="button" @click="confirmDeleteNote = null"',
    '                                  class="text-[12px] text-sub hover:underline">Cancel</button>',
    '                        </template>',
    '                      </span>',
    '                    </div>',
    '                    <div class="wi-rich text-[13px] text-ink mt-1.5" v-html="n.content"></div>',
    // Who was named, from the STORED ids rather than the markup — the ids are what was checked.
    '                    <div v-if="n.mentions.length" class="mt-2 text-[11px] text-sub">',
    '                      Notified: <span v-text="n.mentions.map(function (m) { return m.name; }).join(\', \')"></span>',
    '                    </div>',
    '                  </div>',
    '                </div>',
    '              </li>',
    '              <li v-if="!tabRows.length" class="py-8 text-center">',
    '                <div class="text-[13px] font-semibold text-head" v-text="emptyTabTitle"></div>',
    '                <div class="text-[13px] text-sub mt-1" v-text="emptyTabMessage"></div>',
    '              </li>',
    '            </ul>',
    '          </div>',

    // ---- Updates: the work item's own tab, control for control (P39) ----
    //
    // Not a row in the shared stream: the work item gives Updates a card each, a status badge, an
    // Add button that opens a form, and Edit / Delete on every card. Rendering them as feed
    // sentences here and as cards there would be the same feature with two faces.
    '          <div v-else-if="tab === \'updates\'" class="mt-4">',

    '            <div v-if="canEditDrawer && !updateForm.open" class="flex justify-end mb-3">',
    '              <button type="button" @click="openUpdateForm(null)"',
    '                      class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">',
    '                <span v-html="icon(\'plus\', 14)"></span>Add update',
    '              </button>',
    '            </div>',

    '            <div v-if="updateForm.open" class="mb-4 rounded-lg border border-line p-3">',
    // The three statuses as toggles, not a <select>: the choice is three things and a dropdown
    // hides two of them behind a click.
    '              <div class="flex items-center gap-2 mb-2">',
    '                <button v-for="st in [\'on_track\',\'at_risk\',\'off_track\']" :key="st" type="button"',
    '                        @click="updateForm.status = st"',
    '                        class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border text-[12px] font-semibold"',
    '                        :class="updateForm.status === st ? updateMeta(st).cls : \'border-line text-sub\'">',
    '                  <span v-html="icon(updateMeta(st).icon, 13)"></span>',
    '                  <span v-text="updateMeta(st).label"></span>',
    '                </button>',
    '              </div>',
    '              <pg-editor v-if="useEditor" v-model="updateForm.content" placeholder="Add an update\u2026"',
    '                         min-height="120px" :document-view="false" :buttons="editorButtons"',
    '                         :license="editorLicense" :disabled="updateForm.busy" />',
    '              <textarea v-else v-model="updateForm.content" rows="4" placeholder="Add an update\u2026"',
    '                        class="pb-textarea w-full"></textarea>',
    '              <div class="flex justify-end gap-2 mt-2">',
    '                <button type="button" @click="closeUpdateForm"',
    '                        class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '                <button type="button" @click="saveUpdate" :disabled="updateForm.busy || !updateForm.content.trim()"',
    '                        class="h-8 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50"',
    '                        v-text="updateForm.id ? \'Save\' : \'Add update\'"></button>',
    '              </div>',
    '            </div>',

    '            <ul class="space-y-3">',
    '              <li v-for="u in tabRows" :key="u.id" class="rounded-lg border border-line p-3">',
    '                <div class="flex items-center gap-2">',
    '                  <span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border text-[12px] font-semibold"',
    '                        :class="updateMeta(u.status).cls">',
    '                    <span v-html="icon(updateMeta(u.status).icon, 13)"></span>',
    '                    <span v-text="updateMeta(u.status).label"></span>',
    '                  </span>',
    '                  <span class="text-[12px] text-sub" v-text="(u.ago || \'\') + \' \u00b7 \' + u.author_name"></span>',
    '                  <span v-if="u.edited" class="text-[11px] text-faint">(edited)</span>',
    '                  <span class="ml-auto flex items-center gap-2">',
    // Edit is the AUTHOR'S only — the server refuses anyone else, and a button that always
    // fails is worse than one that is not there.
    '                    <button v-if="u.mine" type="button" @click="openUpdateForm(u)"',
    '                            class="text-[12px] text-sub hover:underline">Edit</button>',
    '                    <template v-if="canEditDrawer">',
    '                      <span v-if="confirmDelete === u.raw_id" class="text-[12px] text-sub">Delete this update?</span>',
    '                      <button type="button" @click="deleteUpdate(u)"',
    '                              class="text-[12px] text-danger hover:underline"',
    '                              v-text="confirmDelete === u.raw_id ? \'Yes, delete\' : \'Delete\'"></button>',
    '                      <button v-if="confirmDelete === u.raw_id" type="button" @click="confirmDelete = null"',
    '                              class="text-[12px] text-sub hover:underline">Cancel</button>',
    '                    </template>',
    '                  </span>',
    '                </div>',
    '                <div class="wi-rich text-[13px] text-ink mt-2" v-html="u.content"></div>',
    '              </li>',
    '              <li v-if="!tabRows.length" class="py-8 text-center">',
    '                <div class="text-[13px] font-semibold text-head">No updates yet</div>',
    '                <div class="text-[13px] text-sub mt-1">Share the latest status of this ticket.</div>',
    '              </li>',
    '            </ul>',
    '          </div>',

    '          <ul v-else class="mt-4" :class="tab === \'history\' ? \'space-y-2\' : \'space-y-4\'">',
    '            <li v-for="e in tabRows" :key="e.id" class="flex items-start gap-2.5">',

    '              <span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0"',
    '                    v-html="eventIcon(e)"></span>',

    '              <div class="min-w-0 flex-1">',

    // A message is somebody talking, so it keeps a card and the sender's face — the same
    // treatment the work item gives a comment.
    '                <template v-if="e.kind === \'message\'">',
    '                  <div :class="[\'rounded-lg border border-line bg-white p-3\', e.inbound ? \'\' : \'bg-hover\']">',
    '                    <div class="flex items-center gap-2">',
    // `h-6 w-6`, NOT `h-[22px] w-[22px]`.
    //
    // Tailwind here is PRE-BUILT and does not scan `public/assets/js/**`, so an arbitrary value
    // written in this file only works if the same one happens to appear in a scanned source.
    // `h-[22px]`/`w-[22px]` do not, so this span had no width and no height at all: it collapsed
    // to the width of its single letter and stretched to the flex line, which is why every
    // timeline avatar rendered as a thin oval while the `h-7 w-7` ones beside it were round.
    '                      <span class="h-6 w-6 rounded-full shrink-0 text-white grid place-items-center text-[10px] font-bold"',
    '                            :style="{ background: $pb.avatarColor({ email: e.author_email }) }" v-text="e.initial"></span>',
    '                      <span class="text-[13px] font-medium text-ink" v-text="e.author_name"></span>',
    '                      <span :class="[\'text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5\',',
    '                                     e.inbound ? \'bg-sel text-brand\' : \'bg-line text-sub\']" v-text="e.label"></span>',
    '                      <span class="text-[12px] text-faint" v-text="e.ago"></span>',
    '                    </div>',
    '                    <p class="text-[13px] text-ink mt-1.5 leading-relaxed" style="white-space:pre-wrap" v-text="e.body"></p>',

    // The customer's files, on the reply that carried them (P66).
    hcAttachments('e'),

    // ---- delivery (P64) ----
    //
    // Only on an agent reply, and only ever the truth: "Sent" says the provider accepted it,
    // never that the customer read it. On failure the row turns into something ACTIONABLE — the
    // provider's own words and a Retry — because a reply that did not arrive is work still owed
    // to somebody, and a toast that vanished is no way to be told.
    '                    <div v-if="e.delivery" class="mt-2 pt-2 border-t border-line">',
    '                      <div v-if="e.delivery.failed" class="flex flex-wrap items-center gap-2">',
    '                        <span class="inline-flex items-center h-5 px-2 rounded-full bg-danger/5 text-danger text-[11px] font-semibold">Failed to send</span>',
    '                        <button type="button" @click="retryReply(e)" :disabled="retryingId === e.id"',
    '                                class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke bg-white text-[12px] font-semibold text-ink hover:bg-hover disabled:opacity-50"',
    '                                v-text="retryingId === e.id ? \'Sending…\' : \'Retry\'"></button>',
    '                        <span v-if="e.delivery.error" class="text-[11px] text-faint _moretogether-break" v-text="e.delivery.error"></span>',
    '                      </div>',
    '                      <div v-else class="text-[11px] text-faint">',
    '                        <span class="text-sub font-medium">Sent</span>',
    '                        <span v-if="e.delivery.at"> &middot; <span v-text="e.delivery.at"></span></span>',
    '                        <span v-if="e.delivery.to"> &middot; to <span v-text="e.delivery.to"></span></span>',
    '                      </div>',
    // The two addresses the requirement asks the ticket to show. Small and last: they answer
    // "which mailbox did this go from?", which is a question somebody asks occasionally and
    // never wants shouted at them.
    //
    // BOTH are always printed, even though P64 makes them the same address. Hiding the Reply-To
    // when it matches would mean the one line that answers "will their answer come back here?"
    // disappears exactly when the answer is yes — and a Space configured the older way, whose
    // From and Reply-To genuinely differ, would look identical to one configured correctly.
    '                      <div v-if="e.delivery.from" class="text-[11px] text-faint mt-0.5 _moretogether-break">',
    '                        From <span v-text="e.delivery.from"></span>',
    '                        <template v-if="e.delivery.reply_to">',
    '                          &middot; Reply-To <span v-text="e.delivery.reply_to"></span></template>',
    '                      </div>',
    '                    </div>',
    '                  </div>',
    '                </template>',

    // A note in All: the same amber card, so nothing private ever renders like a customer reply.
    '                <template v-else-if="e.kind === \'note\'">',
    '                  <div class="rounded-lg border border-line bg-amber-50 p-3" style="border-left:2px solid #fcd34d">',
    '                    <div class="flex items-center gap-2 text-[12px]">',
    '                      <span class="font-semibold text-ink" v-text="e.author_name"></span>',
    '                      <span class="text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-700">Internal Note</span>',
    '                      <span class="ml-auto text-faint shrink-0" :title="e.at" v-text="e.ago"></span>',
    '                    </div>',
    '                    <div class="wi-rich text-[13px] text-ink mt-1.5" v-html="e.content"></div>',
    '                  </div>',
    '                </template>',

    // An update is also somebody talking, but internally — a sentence and its status badge,
    // then the words, exactly as the work item renders one.
    '                <template v-else-if="e.kind === \'update\'">',
    '                  <div class="text-[13px]">',
    '                    <span class="font-medium text-ink" v-text="e.author_name"></span>',
    '                    <span class="text-sub"> posted an internal update </span>',
    '                    <span class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-line text-[11px] font-semibold text-sub">',
    '                      <span class="h-2 w-2 rounded-full" :style="{ background: e.status_color }"></span>',
    '                      <span v-text="e.status_label"></span>',
    '                    </span>',
    '                  </div>',
    '                  <div class="wi-rich text-[13px] text-ink mt-1" v-html="e.content"></div>',
    '                </template>',

    // History: the values, side by side.
    '                <template v-else-if="tab === \'history\'">',
    '                  <div class="text-[13px] text-ink" v-text="e.title"></div>',
    '                  <div v-if="e.old || e.new" class="text-[13px]">',
    '                    <span class="text-sub" v-text="e.old || \'\u2014\'"></span>',
    '                    <span class="text-faint"> \u2192 </span>',
    '                    <span class="text-ink font-medium" v-text="e.new || \'\u2014\'"></span>',
    '                  </div>',
    '                </template>',

    // Everything else: what happened, said as a sentence with the actor's name leading it.
    '                <div v-else class="text-[13px] text-ink">',
    '                  <span class="font-medium" v-text="e.actor_name"></span>',
    '                  <span v-text="\' \' + (e.phrase || e.title)"></span>',
    '                </div>',

    '                <div v-if="e.kind !== \'message\'" class="text-[12px] text-faint mt-0.5">',
    '                  <span v-if="tab === \'history\'" v-text="e.actor_name + \' \u00b7 \' + (e.at || \'\')"></span>',
    '                  <span v-else v-text="e.ago"></span>',
    '                </div>',
    '              </div>',
    '            </li>',

    // The work item's empty state: a heading and a line saying what would appear here.
    '            <li v-if="!tabRows.length" class="py-8 text-center">',
    '              <div class="text-[13px] font-semibold text-head" v-text="emptyTabTitle"></div>',
    '              <div class="text-[13px] text-sub mt-1" v-text="emptyTabMessage"></div>',
    '            </li>',
    '          </ul>',


    // ---- composers ----
    // Reply and Update are separate composers on separate tabs, because they do different
    // things: one leaves the building and one does not. A single box with a toggle is how an
    // internal note ends up in a customer's inbox.


    '        </div>',

    // ---- right: properties, details, sender (P33/P34) ----
    //
    // The WORK ITEM DRAWER'S property style, control for control (projects/work-items.js): a
    // 2-column grid of borderless buttons, each with a small grey label above it, highlighting
    // on hover — not the bordered full-width boxes this panel had first. A property in a panel
    // is a value you can click, and a box around it says "form field" instead.
    '        <aside class="w-[320px] shrink-0 border-l border-line px-5 py-6 overflow-y-auto">',

    '          <h3 class="text-[15px] font-semibold text-head">Properties</h3>',
    '          <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-4">',

    '            <div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Status</div>',
    '              <button type="button" :disabled="!canEditDrawer" @click="openDrawerPicker(\'status\', $event)"',
    '                      :data-tip="(canEditDrawer ? \'Change status \u2014 \' : \'Status: \') + (drawerRow().status ? drawerRow().status.name : \'No status\')"',
    '                      class="flex items-center gap-1.5 max-w-full text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">',
    '                <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: drawerRow().status ? drawerRow().status.color : \'#cbd5e1\' }"></span>',
    '                <span class="truncate" v-text="drawerRow().status ? drawerRow().status.name : \'No status\'"></span>',
    '              </button></div>',

    '            <div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Priority</div>',
    '              <button type="button" :disabled="!canEditDrawer" @click="openDrawerPicker(\'priority\', $event)"',
    '                      :data-tip="canEditDrawer ? \'Change priority\' : \'Priority\'"',
    '                      class="inline-flex items-center gap-1.5 max-w-full text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">',
    '                <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: drawerPriority.color }"></span>',
    '                <span class="truncate" v-text="drawerPriority.label"></span>',
    '              </button></div>',

    // A long name truncates rather than wrapping: these cells are half a narrow column, and a
    // name breaking over two lines pushes every property below it out of alignment.
    '            <div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Assignee</div>',
    '              <button type="button" :disabled="!canEditDrawer" @click="openDrawerPicker(\'assignee\', $event)"',
    '                      :data-tip="(canEditDrawer ? \'Change assignee \u2014 \' : \'Assignee: \') + (drawerRow().assignee ? drawerRow().assignee.name : \'Unassigned\')"',
    '                      class="flex items-center gap-1.5 max-w-full text-[13px] rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">',
    '                <span v-if="drawerRow().assignee" class="h-5 w-5 rounded-full shrink-0 text-white grid place-items-center text-[10px] font-bold"',
    '                      :style="{ background: $pb.avatarColor(drawerRow().assignee) }" v-text="drawerRow().assignee.initial"></span>',
    '                <span class="truncate" :class="drawerRow().assignee ? \'text-ink\' : \'text-sub\'"',
    '                      v-text="drawerRow().assignee ? drawerRow().assignee.name : \'Unassigned\'"></span>',
    '              </button></div>',

    '            <div class="min-w-0"><div class="text-[12px] text-sub mb-1.5">Waiting</div>',
    // A reading, so no button and no hover (P31).
    '              <div class="text-[13px] px-1.5 py-0.5 -ml-1.5" :class="drawerRow().waiting_label ? \'text-ink\' : \'text-sub\'"',
    '                   v-text="drawerRow().waiting_label || \'Nobody\'"></div></div>',
    '          </div>',

    // ---- Details: tags as chips, then the facts ----
    '          <div class="text-[13px] font-medium text-sub mt-6 mb-1">Details</div>',
    '          <div class="divide-y divide-line">',

    // Tags read like the work item's Labels: a chip each, and a dashed Add when there are none.
    '            <div class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Tags</div>',
    '              <div class="flex flex-wrap items-center gap-1.5">',
    '                <button v-for="t in (drawerRow().tags || [])" :key="t.id" type="button"',
    '                        :disabled="!canEditDrawer" @click="openDrawerPicker(\'tags\', $event)"',
    '                        class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line text-[12px] text-ink min-w-0 hover:bg-hover">',
    '                  <span class="truncate" v-text="t.name"></span>',
    '                </button>',
    '                <button v-if="canEditDrawer" type="button" @click="openDrawerPicker(\'tags\', $event)"',
    '                        :data-tip="(drawerRow().tags || []).length ? \'Change tags\' : \'Add tags\'"',
    '                        class="inline-flex items-center gap-1 h-6 px-1.5 rounded-md border border-dashed border-stroke text-[12px] text-sub hover:bg-hover hover:text-ink">',
    '                  <span v-html="icon(\'plus\', 12)"></span><span v-text="(drawerRow().tags || []).length ? \'Tag\' : \'Add tag\'"></span>',
    '                </button>',
    '                <span v-if="!canEditDrawer && !(drawerRow().tags || []).length" class="text-[13px] text-sub">None</span>',
    '              </div></div>',

    '            <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Workflow</span>',
    '              <span class="text-ink truncate" v-text="drawerMeta.workflow || \'\u2014\'"></span></div>',
    '            <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Ticket #</span>',
    '              <span class="text-ink tabular-nums" v-text="drawerRow().identifier"></span></div>',
    '            <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Channel</span>',
    '              <span class="text-ink" v-text="drawerMeta.channel || \'\u2014\'"></span></div>',
    '            <div v-if="drawerMeta.inbox" class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Inbox</span>',
    '              <span class="text-ink truncate" v-text="drawerMeta.inbox"></span></div>',
    '            <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Created</span>',
    '              <span class="text-ink" v-text="drawerMeta.created || \'\u2014\'"></span></div>',
    '            <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Updated</span>',
    '              <span class="text-ink" v-text="drawerMeta.updated || \'\u2014\'"></span></div>',
    '          </div>',

    // ================= Ticket Sender =================
    '          <div v-if="drawerCustomer" class="mt-6 pt-5 border-t border-line space-y-3">',
    '            <h3 class="text-[15px] font-semibold text-head">Ticket sender</h3>',

    '            <div class="flex items-start gap-2">',
    '              <span class="h-8 w-8 rounded-full shrink-0 text-white grid place-items-center text-[11px] font-bold"',
    '                    :style="{ background: $pb.avatarColor({ email: drawerCustomer.email }) }" v-text="drawerCustomer.initial"></span>',
    '              <div class="min-w-0">',
    '                <div class="text-[13px] font-semibold text-ink truncate" v-text="drawerCustomer.name"></div>',
    '                <a :href="\'mailto:\' + drawerCustomer.email" class="block text-[12px] text-brand truncate hover:underline" v-text="drawerCustomer.email"></a>',
    '              </div>',
    '            </div>',

    '            <div class="divide-y divide-line">',
    '              <div v-if="drawerCustomer.company" class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Company</span>',
    '                <span class="text-ink truncate" v-text="drawerCustomer.company"></span></div>',
    '              <div v-if="drawerCustomer.phone" class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Phone</span>',
    '                <span class="text-ink" v-text="drawerCustomer.phone"></span></div>',
    '              <div v-if="drawerCustomer.external_id" class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Customer ID</span>',
    '                <span class="text-ink truncate" v-text="drawerCustomer.external_id"></span></div>',
    '              <div v-if="drawerCustomer.first_contact" class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">First contact</span>',
    '                <span class="text-ink" v-text="drawerCustomer.first_contact"></span></div>',
    '              <div class="py-2.5 flex gap-2 text-[13px]"><span class="w-24 shrink-0 text-sub">Total tickets</span>',
    '                <span class="text-ink" v-text="drawerCustomer.total_tickets"></span></div>',
    '            </div>',

    '            <div class="flex flex-wrap gap-1.5">',
    '              <button type="button" @click="copy(drawerCustomer.email, \'Email address copied.\')"',
    '                      class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">Copy email</button>',
    '              <button v-if="drawerCustomer.previous && drawerCustomer.previous.length" type="button" @click="showPrevious = !showPrevious"',
    '                      class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover"',
    '                      v-text="(showPrevious ? \'Hide\' : \'View\') + \' previous tickets\'"></button>',
    '              <button v-if="drawerCustomer.endpoint" type="button" @click="startCustomerEdit"',
    '                      class="inline-flex items-center h-7 px-2.5 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">Update customer</button>',
    '            </div>',

    '            <ul v-if="showPrevious" class="space-y-1.5">',
    '              <li v-for="t in drawerCustomer.previous" :key="t.id">',
    '                <a :href="t.url" class="block rounded-md border border-line px-2.5 py-1.5 hover:bg-hover">',
    '                  <span class="text-[11px] text-sub tabular-nums" v-text="t.identifier"></span>',
    '                  <span class="block text-[12px] text-ink truncate" v-text="t.subject"></span>',
    '                  <span class="text-[11px] text-faint" v-text="(t.status || \'\u2014\') + \' \u00b7 \' + (t.when || \'\')"></span>',
    '                </a>',
    '              </li>',
    '            </ul>',

    '          </div>',

    // ================= Company & Customer (P75 §10) =================
    //
    // Below Ticket sender rather than replacing it. The two answer different questions: Ticket
    // sender is what THIS message said about who wrote in, and this is what the workspace knows
    // about them and their organisation across every Space.
    //
    // The whole section is absent when the Space does not run the feature — the server sends
    // null, which is the difference between "this Space does not track companies" and "this
    // ticket has no company", two states an empty block would render identically.
    '          <div v-if="drawerCompanyCustomer" class="mt-6 pt-5 border-t border-line space-y-4">',
    '            <h3 class="text-[15px] font-semibold text-head">Company &amp; Customer</h3>',

    '            <div v-if="drawerCompanyCustomer.customer">',
    '              <div class="text-[11px] font-semibold text-faint uppercase tracking-wide">Customer</div>',
    '              <div class="text-[13px] font-semibold text-ink truncate mt-1" v-text="drawerCompanyCustomer.customer.name"></div>',
    '              <a :href="\'mailto:\' + drawerCompanyCustomer.customer.email" class="block text-[12px] text-brand truncate hover:underline" v-text="drawerCompanyCustomer.customer.email"></a>',
    '              <div v-if="drawerCompanyCustomer.customer.phone" class="text-[12px] text-sub" v-text="drawerCompanyCustomer.customer.phone"></div>',
    '              <a :href="drawerCompanyCustomer.customer.url" class="inline-flex items-center h-7 px-2.5 mt-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">View Customer</a>',
    '              <dl v-if="drawerCompanyCustomer.customer.fields.length" class="mt-2 divide-y divide-line">',
    '                <div v-for="f in drawerCompanyCustomer.customer.fields" :key="f.id" class="py-2 flex gap-2 text-[12px]">',
    '                  <dt class="w-24 shrink-0 text-sub truncate" v-text="f.name"></dt>',
    '                  <dd class="text-ink _moretogether-break" v-text="f.value"></dd>',
    '                </div>',
    '              </dl>',
    '            </div>',

    '            <div v-if="drawerCompanyCustomer.company">',
    '              <div class="text-[11px] font-semibold text-faint uppercase tracking-wide">Company</div>',
    '              <div class="text-[13px] font-semibold text-ink truncate mt-1" v-text="drawerCompanyCustomer.company.name"></div>',
    '              <div v-if="drawerCompanyCustomer.company.domain" class="text-[12px] text-sub truncate" v-text="drawerCompanyCustomer.company.domain"></div>',
    '              <a :href="drawerCompanyCustomer.company.url" class="inline-flex items-center h-7 px-2.5 mt-2 rounded-md border border-stroke text-[12px] text-ink hover:bg-hover">View Company</a>',
    '              <dl v-if="drawerCompanyCustomer.company.fields.length" class="mt-2 divide-y divide-line">',
    '                <div v-for="f in drawerCompanyCustomer.company.fields" :key="f.id" class="py-2 flex gap-2 text-[12px]">',
    '                  <dt class="w-24 shrink-0 text-sub truncate" v-text="f.name"></dt>',
    '                  <dd class="text-ink _moretogether-break" v-text="f.value"></dd>',
    '                </div>',
    '              </dl>',
    '            </div>',

    // Honest about the gap. A Space that tracks companies and a ticket with none is a real
    // state, and saying so beats a heading with nothing under it.
    '            <p v-if="!drawerCompanyCustomer.company" class="text-[12px] text-faint">',
    '              No company matched for this request.</p>',
    '          </div>',
    '        </aside>',
    '      </div>',
    '      </aside>',
    '    </div>',
    '  </teleport>',

    // ---- Mark as spam? (P47) ----
    //
    // `pb-confirm`, the project's standard destructive confirmation, at `z-[95]` because it can
    // be raised from inside the drawer (P44).
    '  <pb-confirm :open="!!spamConfirm" title="Mark as spam?" z="z-[95]"',
    '              :message="spamConfirm',
    '                ? \'\u201c\' + (spamConfirm.subject || spamConfirm.identifier) + \'\u201d moves to Spam and leaves every active queue. Its history, messages and notes are kept, and nothing further happens to it until you restore it.\'',
    '                : \'\'"',
    '              confirm-label="Mark as Spam" @confirm="confirmSpam" @close="spamConfirm = null"></pb-confirm>',

    // ---- Snooze until… (P45) ----
    //
    // `pb-modal` at `z-[95]`, for the same reason the customer dialog is there (P44): it opens
    // from inside the drawer, whose shell is `z-[85]`.
    '  <pb-modal :open="!!snoozeForm" title="Snooze until…" z="z-[95]" @close="snoozeForm = null">',
    '    <div v-if="snoozeForm" class="space-y-4">',

    '      <p class="text-[12px] text-sub">',
    '        <span class="tabular-nums font-semibold text-ink" v-text="snoozeForm.row.identifier"></span>',
    '        <span v-text="\' · \' + (snoozeForm.row.subject || \'\')"></span>',
    '      </p>',

    // The free-text box, with the requirement's own placeholder.
    '      <div>',
    '        <input v-model="snoozeForm.text" @input="onSnoozeTyped" ref="snoozeInput"',
    '               @keydown.enter.prevent="saveSnooze"',
    '               placeholder="Try: 8 am, 3 days, Aug 7" class="pb-input w-full" />',

    // What was understood, before anybody confirms — the requirement asks for exactly this, and
    // it is also the only way a natural-language box is ever trustworthy.
    '        <p v-if="snoozePreview" class="mt-1.5 text-[12px]"',
    '           :class="snoozePreview.ok ? \'text-ink\' : \'text-danger\'">',
    '          <span v-if="snoozePreview.ok" class="text-sub">Comes back </span>',
    '          <span :class="snoozePreview.ok ? \'font-semibold\' : \'\'" v-text="snoozePreview.text"></span>',
    '        </p>',
    '        <p v-else class="mt-1.5 text-[12px] text-faint">Type a time, or pick one below.</p>',
    '      </div>',

    // The three shortcuts, each showing the date it works out to (P45).
    '      <div class="grid grid-cols-3 gap-1.5">',
    '        <button v-for="q in snoozeQuick" :key="q.key" type="button" @click="pickQuickSnooze(q)"',
    '                class="rounded-md border px-2 py-2 text-left"',
    '                :class="snoozeForm.quick === q.key ? \'border-brand bg-brand/5\' : \'border-stroke hover:bg-hover\'">',
    '          <span class="block text-[12px] font-semibold text-ink" v-text="q.label"></span>',
    '          <span class="block text-[11px] text-sub" v-text="q.whenLabel"></span>',
    '        </button>',
    '      </div>',

    // The condition (P45). A radio list rather than a <select>: there are two options, each with
    // a sentence explaining what it does, and a dropdown hides the sentence that matters.
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1.5">Condition</label>',
    '        <div class="space-y-1">',
    '          <label v-for="c in snoozeConditions" :key="c.value"',
    '                 class="flex items-start gap-2 rounded-md border px-2.5 py-2 cursor-pointer"',
    '                 :class="snoozeForm.condition === c.value ? \'border-brand bg-brand/5\' : \'border-stroke hover:bg-hover\'">',
    '            <input type="radio" :value="c.value" v-model="snoozeForm.condition" class="mt-0.5" />',
    '            <span class="min-w-0">',
    '              <span class="block text-[13px] text-ink" v-text="c.label"></span>',
    '              <span v-if="c.help" class="block text-[12px] text-sub" v-text="c.help"></span>',
    '            </span>',
    '          </label>',
    '        </div>',
    '      </div>',
    '    </div>',
    '    <template #footer>',
    '      <button type="button" @click="snoozeForm = null"',
    '              class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '      <button type="button" @click="saveSnooze" :disabled="!canSnooze"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50"',
    '              v-text="snoozeSaving ? \'Snoozing…\' : \'Snooze\'"></button>',
    '    </template>',
    '  </pb-modal>',

    // ---- Update customer: the project's standard dialog (P44) ----
    //
    // `pb-modal`, the same shell Create a Space and Invite a coworker use — labelled fields, a
    // titled header with its own close, Cancel and Save in the footer. It replaced a stack of
    // three bare inputs that appeared in the middle of the side panel and pushed everything
    // below it down the page.
    //
    // `z-[95]` because it is opened from INSIDE the drawer: the drawer's shell is `z-[85]`, and
    // the dialog's own default (`z-[70]`) would render it behind the panel that opened it. Below
    // the toast at 100, which has to stay readable over the top of it.
    //
    // Outside the drawer's teleport rather than inside it: `pb-modal` teleports to the body
    // itself, and a teleport nested in a teleport is a stacking context nobody needs.
    '  <pb-modal :open="!!customerForm" title="Update customer" z="z-[95]" @close="customerForm = null">',
    '    <div v-if="customerForm" class="space-y-4">',

    // The email is shown and NOT editable — it is what every Request was matched on (see
    // CustomerController). Shown anyway, because "which customer am I editing?" is the first
    // thing a dialog with the ticket hidden behind it has to answer.
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Email</label>',
    '        <p class="h-9 flex items-center px-3 rounded-md border border-line bg-hover text-[13px] text-sub truncate" v-text="customerForm.email"></p>',
    '        <p class="mt-1 text-[12px] text-faint">Tickets are matched on this address, so it cannot be changed here.</p>',
    '      </div>',

    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Name</label>',
    '        <input v-model="customerForm.name" maxlength="255" class="pb-input w-full" placeholder="Alex Johnson" />',
    '      </div>',
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Company <span class="text-faint font-normal">(optional)</span></label>',
    '        <input v-model="customerForm.company" maxlength="255" class="pb-input w-full" placeholder="Acme Ltd" />',
    '      </div>',
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Phone <span class="text-faint font-normal">(optional)</span></label>',
    '        <input v-model="customerForm.phone" maxlength="40" class="pb-input w-full" placeholder="+44 20 7946 0000" />',
    '      </div>',
    '      <div>',
    '        <label class="block text-[12px] font-semibold text-ink mb-1">Customer ID <span class="text-faint font-normal">(optional)</span></label>',
    '        <input v-model="customerForm.external_id" maxlength="100" class="pb-input w-full" placeholder="Your CRM\'s reference" />',
    '      </div>',
    '    </div>',
    '    <template #footer>',
    '      <button type="button" @click="customerForm = null"',
    '              class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>',
    '      <button type="button" @click="saveCustomer" :disabled="customerSaving"',
    '              class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50"',
    '              v-text="customerSaving ? \'Saving\u2026\' : \'Save changes\'"></button>',
    '    </template>',
    '  </pb-modal>',

    // ---- The pickers (P28) ----
    //
    // One backdrop and one panel for all five, switched on `menu.act`. They share the shell
    // because they share the interaction; only the list inside differs.
    '  <teleport to="body">',
    // 90/95, not 80/81: the drawer's shell is `z-[85]` (it matches the work item drawer), so a
    // picker opened from inside it has to clear that or it opens underneath the panel.
    '    <div v-if="menu" class="fixed inset-0 z-[90]" @click="closeMenu"></div>',
    '    <div v-if="menu" class="absolute z-[95] rounded-lg border border-line bg-white shadow-lg py-1 text-[13px]"',
    '         :style="{ top: menu.top + \'px\', left: menu.left + \'px\', width: menu.width + \'px\' }">',
    '      <p class="px-3 py-1.5 text-[11px] font-semibold text-faint" v-text="menu.row.identifier"></p>',

    // ---- Status: the SPACE'S OWN workflow, never a fixed list ----
    '      <template v-if="menu.act === \'status\'">',
    '        <button v-for="s in menuStatuses" :key="s.id" type="button" @click="setStatus(s)"',
    '                class="w-full flex items-center gap-2 px-3 h-8 text-left text-ink hover:bg-hover">',
    '          <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: s.color }"></span>',
    '          <span class="truncate" v-text="s.name"></span>',
    '          <span v-if="menu.row.status_id === s.id" class="ml-auto text-brand">&check;</span>',
    '        </button>',
    '        <p v-if="!menuStatuses.length" class="px-3 py-1.5 text-[12px] text-faint">This Space has no workflow statuses.</p>',
    '      </template>',

    // ---- Assignee: searchable, members only, with an explicit Unassigned ----
    '      <template v-else-if="menu.act === \'assignee\'">',
    '        <div class="px-2 pb-1.5">',
    '          <input ref="search" v-model="query" placeholder="Search members…" class="pb-input !h-8 w-full" />',
    '        </div>',
    '        <button type="button" @click="setAssignee(null)"',
    '                class="w-full flex items-center gap-2 px-3 h-8 text-left text-ink hover:bg-hover">',
    '          <span class="h-5 w-5 rounded-full border border-dashed border-stroke shrink-0"></span>Unassigned',
    '          <span v-if="!menu.row.assignee" class="ml-auto text-brand">&check;</span>',
    '        </button>',
    '        <div class="max-h-56 overflow-y-auto">',
    // An INVITED member is shown and not offered (P29): present, so this list matches the
    // Members grid, and unclickable, because there is no account to assign work to yet.
    '          <template v-for="m in filteredMembers" :key="m.id || m.name">',
    '            <button v-if="!m.pending" type="button" @click="setAssignee(m)"',
    '                    class="w-full flex items-center gap-2 px-3 h-8 text-left text-ink hover:bg-hover">',
    '              <span class="h-5 w-5 rounded-full shrink-0 text-white grid place-items-center text-[10px] font-bold"',
    '                    :style="{ background: $pb.avatarColor(m) }" v-text="m.initial"></span>',
    '              <span class="truncate" v-text="m.name"></span>',
    '              <span v-if="menu.row.assignee && menu.row.assignee.id === m.id" class="ml-auto text-brand">&check;</span>',
    '            </button>',
    '            <span v-else class="w-full flex items-center gap-2 px-3 h-8 text-faint cursor-not-allowed select-none"',
    '                  title="This person has not accepted their invitation yet, so there is no account to assign to.">',
    '              <span class="h-5 w-5 rounded-full shrink-0 border border-dashed border-stroke"></span>',
    '              <span class="truncate" v-text="m.name"></span>',
    '              <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5 shrink-0">Invited</span>',
    '            </span>',
    '          </template>',
    '        </div>',
    '        <p v-if="!menuMembers.length" class="px-3 py-1.5 text-[12px] text-faint">This Space has no members yet.</p>',
    '        <p v-else-if="!filteredMembers.length" class="px-3 py-1.5 text-[12px] text-faint">No matches.</p>',
    '      </template>',

    // ---- Priority ----
    '      <template v-else-if="menu.act === \'priority\'">',
    '        <button v-for="(p, key) in priorities" :key="key" type="button" @click="setPriority(key)"',
    '                class="w-full flex items-center gap-2 px-3 h-8 text-left text-ink hover:bg-hover">',
    '          <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: p.color }"></span>',
    '          <span v-text="p.label"></span>',
    '          <span v-if="(menu.row.priority || \'none\') === key" class="ml-auto text-brand">&check;</span>',
    '        </button>',
    '      </template>',

    // ---- Tags: multi-select, and it STAYS OPEN so three tags are three clicks ----
    '      <template v-else-if="menu.act === \'tags\'">',
    '        <div class="px-2 pb-1.5">',
    '          <input ref="search" v-model="query" placeholder="Search tags…" class="pb-input !h-8 w-full" />',
    '        </div>',
    '        <div class="max-h-56 overflow-y-auto">',
    '          <button v-for="t in filteredTags" :key="t.id" type="button" @click="toggleTag(t)"',
    '                  class="w-full flex items-center gap-2 px-3 h-8 text-left text-ink hover:bg-hover">',
    '            <span class="truncate" v-text="t.name"></span>',
    '            <span v-if="openRowTagIds.indexOf(t.id) !== -1" class="ml-auto text-brand">&check;</span>',
    '          </button>',
    '        </div>',
    // A Space with no vocabulary yet gets sent to the page that creates one, rather than an
    // empty list that looks broken.
    '        <p v-if="!menuTags.length" class="px-3 py-2 text-[12px] text-faint">',
    '          No tags yet — add them on Settings &rsaquo; Tag.',
    '        </p>',
    '        <p v-else-if="!filteredTags.length" class="px-3 py-1.5 text-[12px] text-faint">No matches.</p>',
    '      </template>',

    // ---- The ••• actions menu ----
    '      <template v-else>',
    /* "Open Ticket in New Window" (P80).
       An <a target="_blank">, NOT a button calling window.open():
         - middle-click and cmd/ctrl-click keep working, which they cannot on a button;
         - popup blockers leave a real link alone;
         - the browser shows the destination on hover, so the action is inspectable before it
           is taken.
       `requestUrl()` resolves the row's OWN Space, so a row in the cross-Space queue opens its
       own ticket rather than one in whichever Space the screen happens to be showing.
       The menu is closed on the way out; the Inbox behind it is otherwise untouched, which is
       the point of the action — its filters, search, scroll position and open view all survive
       because nothing on this page navigates. */
    '        <a :href="requestUrl(menu.row)" target="_blank" rel="noopener" @click="closeMenu"',
    '           class="block w-full text-left px-3 h-8 leading-8 text-ink hover:bg-hover">Open Ticket in New Window</a>',
    // Disabled once it is already yours: the action would send the assignee it already has,
    // and an item that offers to do what has been done is a menu asking to be clicked twice.
    '        <button type="button" @click="assignToMe" :disabled="assignedToMe"',
    '                :title="assignedToMe ? \'This Request is already assigned to you\' : \'\'"',
    // `disabled:hover:bg-transparent` is NOT in the built stylesheet, so the hover is bound
    // conditionally instead — a disabled row that still lights up on hover reads as clickable.
    '                class="w-full text-left px-3 h-8 disabled:opacity-50 disabled:cursor-not-allowed"',
    '                :class="assignedToMe ? \'text-faint\' : \'text-ink hover:bg-hover\'">',
    '          Assign to Me',
    '          <span v-if="assignedToMe" class="ml-1 text-[11px]">&mdash; already yours</span>',
    '        </button>',
    '        <button type="button" @click="setClosed(!menu.row.closed)" class="w-full text-left px-3 h-8 text-ink hover:bg-hover"',
    '                v-text="menu.row.closed ? \'Reopen Ticket\' : \'Mark as Closed\'"></button>',

    // Snooze (P45). Two items rather than one toggle: "Snooze until…" opens a dialog and
    // "Unsnooze" does not, and a single control that sometimes asks a question and sometimes
    // acts immediately is a control nobody can predict.
    '        <button v-if="!menu.row.closed && !menu.row.is_spam" type="button" @click="openSnooze(menu.row)"',
    '                class="w-full text-left px-3 h-8 text-ink hover:bg-hover"',
    '                v-text="menu.row.snoozed ? \'Change snooze\u2026\' : \'Snooze until\u2026\'"></button>',
    '        <button v-if="menu.row.snoozed" type="button" @click="unsnooze(menu.row)"',
    '                class="w-full text-left px-3 h-8 text-ink hover:bg-hover">Unsnooze</button>',
    '        <div class="my-1 border-t border-line"></div>',
    '        <button type="button" @click="copyTicketNumber" class="w-full text-left px-3 h-8 text-ink hover:bg-hover">Copy Ticket Number</button>',
    '        <button type="button" @click="copyLink" class="w-full text-left px-3 h-8 text-ink hover:bg-hover">Copy Ticket Link</button>',
    '        <div class="my-1 border-t border-line"></div>',
    // Spam (P47) — two items, not one toggle. Marking asks; restoring does not, and a single
    // control that sometimes opens a dialog and sometimes acts is one nobody can predict.
    '        <button v-if="!menu.row.is_spam" type="button" @click="askSpam(menu.row)"',
    '                class="w-full text-left px-3 h-8 text-ink hover:bg-hover">Mark as Spam</button>',
    '        <button v-else type="button" @click="restoreFromSpam(menu.row)"',
    '                class="w-full text-left px-3 h-8 text-ink hover:bg-hover">Not Spam &mdash; Restore Ticket</button>',
    '      </template>',
    '    </div>',
    '  </teleport>',

    '</div>'
  ].join('')
}, { root: 'help-center-inbox' });
