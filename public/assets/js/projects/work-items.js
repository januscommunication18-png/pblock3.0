/* Project Workspace → Work Items (Phase 5) — state-grouped Tabulator list + Create work item
 * modal. Layout, chip style and grid skin follow html/work-items.html; the grid engine is the
 * same Tabulator build the Members listing uses.
 *
 * This slice covers the list and creation. Row property editing and the detail drawer land in
 * the next slice, so every chip below is display-only — no dead controls. */

// ---- State icons, keyed by the state's stable `group` (names stay user-editable) ----
function wiDot(color, dashed) {
  return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none">' +
    (dashed
      ? '<circle cx="12" cy="12" r="8" stroke="' + color + '" stroke-width="2" stroke-dasharray="3 3"/>'
      : '<circle cx="12" cy="12" r="8" stroke="' + color + '" stroke-width="2"/>') + '</svg>';
}
function wiFilled(color, glyph) {
  return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="' + color + '"/>' + glyph + '</svg>';
}
var WI_STATE_ICON = {
  backlog: function (c) { return wiDot(c || '#9ca3af', true); },
  unstarted: function (c) { return wiDot(c || '#6b7280', false); },
  started: function (c) {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8" stroke="' + (c || '#f59e0b') + '" stroke-width="2"/><path d="M12 4a8 8 0 010 16z" fill="' + (c || '#f59e0b') + '"/></svg>';
  },
  active: function (c) { return wiDot(c || '#14b8a6', false); },
  completed: function (c) { return wiFilled(c || '#22c55e', '<path d="M8 12l3 3 5-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'); },
  cancelled: function (c) { return wiFilled(c || '#ef4444', '<path d="M9 9l6 6M15 9l-6 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'); }
};
function wiStateIcon(state) {
  if (!state) return wiDot('#cbd5e1', true);
  var fn = WI_STATE_ICON[state.group] || WI_STATE_ICON.backlog;
  return fn(state.color);
}

// ---- Priority icons (fixed vocabulary, spec §4.3) ----
function wiBars(c) {
  return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="4" y="13" width="3.5" height="7" rx="1" fill="' + c + '"/><rect x="10.25" y="9" width="3.5" height="11" rx="1" fill="' + c + '"/><rect x="16.5" y="5" width="3.5" height="15" rx="1" fill="' + c + '"/></svg>';
}
var WI_PRI = {
  urgent: { label: 'Urgent', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="3" fill="#ef4444"/><path d="M12 7v6M12 16v.5" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>', cls: 'text-red-600' },
  high: { label: 'High', icon: wiBars('#f97316'), cls: 'text-ink' },
  medium: { label: 'Medium', icon: wiBars('#f59e0b'), cls: 'text-ink' },
  low: { label: 'Low', icon: wiBars('#3b82f6'), cls: 'text-ink' },
  none: { label: 'None', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="#9ca3af" stroke-width="1.6"/><path d="M6 6l12 12" stroke="#9ca3af" stroke-width="1.6" stroke-linecap="round"/></svg>', cls: 'text-sub' }
};
var WI_CAL = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
var WI_NO_STATE = 'none';

// Escape user content before it reaches a Tabulator formatter (formatters return raw HTML).
function wiEsc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// "Blocked" marker for a work item waiting on an unresolved dependency (§27-§29). Red
// rather than a neutral chip: it is the one row state that needs someone to act.
function wiBlockedChip(count) {
  var label = count > 1 ? 'Blocked · ' + count : 'Blocked';
  return '<span class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-danger/30 bg-danger/5 text-[11px] font-semibold text-danger shrink-0" ' +
    'title="Waiting on ' + count + ' unresolved ' + (count > 1 ? 'work items' : 'work item') + '">' +
    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/><path d="M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    label + '</span>';
}

// ---- Event icons for the activity/history feeds (Activity & Audit spec §6.4) ------------
// A feed row's icon says what KIND of change it was before the sentence is read, which is
// what makes a long timeline skimmable. Keyed by the audit row's field, with the event as a
// fallback for rows that carry no field (creation).
function wiEventSvg(body, w) {
  var size = w || 15;
  return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" ' +
    'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
}
var WI_EVENT_ICON = {
  state: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.2"/>',
  priority: '<path d="M5 20v-6M12 20V7M19 20v-9"/>',
  assignees: '<circle cx="12" cy="8" r="3.2"/><path d="M5 20a7 7 0 0114 0"/>',
  date: '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/>',
  labels: '<path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z"/><circle cx="14.5" cy="9.5" r="1.2"/>',
  comment: '<path d="M20 15a2 2 0 01-2 2H8l-4 4V6a2 2 0 012-2h12a2 2 0 012 2z"/>',
  worklog: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  link: '<path d="M9 15l6-6"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1"/>',
  relation: '<path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/>',
  parent: '<rect x="3" y="4" width="7" height="7" rx="1.6"/><rect x="13" y="13" width="8" height="7" rx="1.6"/><path d="M6.5 11v4a2 2 0 002 2H13"/>',
  text: '<path d="M4 20h4l10-10-4-4L4 16v4z"/>',
  update: '<path d="M5 21V5a1 1 0 011-1h9l-1.5 3L15 10H6"/><path d="M5 21h4"/>',
  archive: '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4"/>',
  created: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>'
};
/** Which icon a feed row gets. */
function wiEventKind(entry) {
  var field = entry.field || '';
  if (entry.event === 'created') return 'created';
  if (field === 'state') return 'state';
  if (field === 'priority') return 'priority';
  if (field === 'assignees') return 'assignees';
  if (field === 'labels') return 'labels';
  if (field === 'start_date' || field === 'due_date') return 'date';
  if (field === 'comment' || field === 'comment_reply') return 'comment';
  if (field === 'worklog') return 'worklog';
  if (field === 'update') return 'update';
  if (field === 'archived_at') return 'archive';
  if (field.indexOf('link') === 0) return 'link';
  if (field.indexOf('relation') === 0 || field.indexOf('subtask') === 0) return 'relation';
  if (field === 'parent') return 'parent';
  return 'text';
}

/**
 * A person's avatar: their uploaded photo when they have one, their initial when they do not.
 * The initial used to be the only option, which is why assigning someone with a profile
 * picture never showed it.
 */
function wiAvatar(person, px) {
  var size = px || 24;
  var box = 'h-[' + size + 'px] w-[' + size + 'px] rounded-full shrink-0';
  var title = wiEsc(person.name || '');

  if (person.avatar_url) {
    return '<img src="' + wiEsc(person.avatar_url) + '" alt="' + title + '" title="' + title + '" ' +
      'class="' + box + ' object-cover border border-line" />';
  }

  return '<span class="' + box + ' bg-brand text-white grid place-items-center text-[10px] font-bold" title="' + title + '">' +
    wiEsc(person.initial || '?') + '</span>';
}

// Display chip — the POC's chip style: 24px tall, white, 12px label.
function wiChip(inner, extra) {
  return '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] ' + (extra || 'text-ink') + ' shrink-0">' + inner + '</span>';
}

// ---------------------------------------------------------------------------------------
// Date picker — a direct port of the POC's `datePicker()` (html/work-items.html): an
// anchored popover that opens on quick options (Today / Tomorrow / Next 3 / Next 5 days,
// divider, Custom Date) and switches to a month grid with month + year dropdowns.
//
// A local component so the two date chips share one implementation. `min`/`max` are ours,
// not the POC's: the API rejects a due date before the start date, so out-of-range days are
// struck through here rather than surfacing as a 422.
// ---------------------------------------------------------------------------------------
var WI_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
var WI_CAL_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

function wiParseISO(raw) {
  if (!raw) return null;
  var p = String(raw).slice(0, 10).split('-');
  if (p.length !== 3) return null;
  var d = new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1);
  d.setHours(0, 0, 0, 0);
  return isNaN(d.getTime()) ? null : d;
}
function wiISO(d) {
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
// The POC labels dates MM/DD/YYYY, in both the chips and the grid rows.
function wiFmtDate(raw) {
  var d = wiParseISO(raw);
  if (!d) return '';
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear();
}

var WI_YEAR_MIN = 2015, WI_YEAR_MAX = 2035;

/**
 * Does month `m` of year `y` contain at least one selectable day?
 *
 * Bounds are exclusive, so a month qualifies only if it reaches past them: its LAST day must
 * be after `after`, and its FIRST day before `before`. That correctly drops e.g. September
 * when the start date is Sep 30 — the only dates after it are in October.
 */
function wiMonthAllowed(after, before, y, m) {
  if (after && new Date(y, m + 1, 0) <= after) return false;
  if (before && new Date(y, m, 1) >= before) return false;
  return true;
}
function wiYearAllowed(after, before, y) {
  if (after && new Date(y, 11, 31) <= after) return false;
  if (before && new Date(y, 0, 1) >= before) return false;
  return true;
}

var WiCalendar = {
  /**
   * `after` / `before` are EXCLUSIVE bounds: a due date must fall strictly after the start
   * date, and a start date strictly before the due date. The bounding day itself is disabled
   * along with everything beyond it, so the pair can never be equal or inverted — the same
   * rule the API enforces with `after:start_date`.
   *
   * The bounds also drive navigation, not just the day grid: months and years with nothing
   * selectable in them are removed from the two dropdowns and the arrows stop at the edge,
   * so a due-date picker only ever offers dates forward of the start date.
   */
  props: { value: String, after: String, before: String },
  emits: ['pick', 'clear'],
  data: function () {
    var after = wiParseISO(this.after), before = wiParseISO(this.before);
    var seed = wiParseISO(this.value);
    // Open on the current value; failing that (or if it sits outside the bounds), on the
    // first day that IS selectable, so the grid never opens on a fully disabled month.
    if (!seed || !wiMonthAllowed(after, before, seed.getFullYear(), seed.getMonth())) {
      if (after) seed = new Date(after.getFullYear(), after.getMonth(), after.getDate() + 1);
      else if (before) seed = new Date(before.getFullYear(), before.getMonth(), before.getDate() - 1);
      else seed = new Date();
    }
    return { mode: 'quick', vy: seed.getFullYear(), vm: seed.getMonth(), monthOpen: false, yearOpen: false };
  },
  computed: {
    selected: function () { return wiParseISO(this.value); },
    bounds: function () { return { after: wiParseISO(this.after), before: wiParseISO(this.before) }; },
    // Only months/years that still hold a selectable day are offered.
    months: function () {
      var b = this.bounds, y = this.vy;
      return WI_MONTHS
        .map(function (name, i) { return { i: i, name: name }; })
        .filter(function (mo) { return wiMonthAllowed(b.after, b.before, y, mo.i); });
    },
    years: function () {
      var b = this.bounds, out = [];
      for (var y = WI_YEAR_MIN; y <= WI_YEAR_MAX; y++) {
        if (wiYearAllowed(b.after, b.before, y)) out.push(y);
      }
      return out;
    },
    canPrev: function () { return this.stepAllowed(-1); },
    canNext: function () { return this.stepAllowed(1); },
    monthLabel: function () { return WI_MONTHS[this.vm]; },
    calIcon: function () { return WI_CAL_ICON; },
    cells: function () {
      var y = this.vy, m = this.vm, sel = this.selected, b = this.bounds;
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var start = new Date(y, m, 1); start.setDate(1 - start.getDay());
      var same = function (a, c) { return a && c && a.getTime() === c.getTime(); };
      var out = [];
      for (var i = 0; i < 42; i++) {
        var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        out.push({
          key: d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(),
          day: d.getDate(), iso: wiISO(d), inMonth: d.getMonth() === m,
          isSel: same(d, sel), isToday: same(d, today),
          disabled: this.outOfRange(d)
        });
      }
      return out;
    }
  },
  methods: {
    addDays: function (n) { var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + n); return d; },
    /** Exclusive on both sides — the bounding day itself is not selectable. */
    outOfRange: function (d) {
      var b = this.bounds;
      return !!((b.after && d <= b.after) || (b.before && d >= b.before));
    },
    quickDisabled: function (n) { return this.outOfRange(this.addDays(n)); },
    quickPick: function (n) { if (!this.quickDisabled(n)) this.$emit('pick', wiISO(this.addDays(n))); },
    custom: function () {
      if (this.selected) { this.vy = this.selected.getFullYear(); this.vm = this.selected.getMonth(); }
      this.monthOpen = false; this.yearOpen = false; this.mode = 'cal';
    },
    back: function () { this.mode = 'quick'; this.monthOpen = false; this.yearOpen = false; },
    /** The month `delta` steps away, or null at a year boundary of the allowed range. */
    step: function (delta) {
      var m = this.vm + delta, y = this.vy;
      if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
      if (y < WI_YEAR_MIN || y > WI_YEAR_MAX) return null;
      var b = this.bounds;
      return wiMonthAllowed(b.after, b.before, y, m) ? { y: y, m: m } : null;
    },
    stepAllowed: function (delta) { return this.step(delta) !== null; },
    nav: function (delta) {
      var next = this.step(delta);
      if (!next) return;
      this.vy = next.y; this.vm = next.m;
    },
    setMonth: function (i) { this.vm = i; this.monthOpen = false; },
    setYear: function (y) {
      this.vy = y;
      this.yearOpen = false;
      // The visible month may not exist in the newly-picked year (e.g. jumping back to the
      // start date's year, where earlier months are out of range) — snap to the nearest one.
      var allowed = this.months;
      if (allowed.length && !allowed.some(function (mo) { return mo.i === this.vm; }, this)) {
        this.vm = this.vm < allowed[0].i ? allowed[0].i : allowed[allowed.length - 1].i;
      }
    },
    pick: function (c) { if (!c.disabled) this.$emit('pick', c.iso); },
    quickClass: function (n) {
      return 'w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink '
        + (this.quickDisabled(n) ? 'opacity-40 cursor-not-allowed' : 'hover:bg-hover');
    },
    cellClass: function (c) {
      var base = 'h-8 w-8 grid place-items-center rounded-md text-[13px] ';
      if (c.disabled) return base + 'text-faint/50 line-through cursor-not-allowed';
      var cls = base + 'cursor-pointer ';
      if (c.isSel) return cls + 'bg-brand text-white font-medium';
      if (!c.inMonth) return cls + 'text-faint hover:bg-hover';
      return cls + 'text-ink hover:bg-hover' + (c.isToday ? ' ring-1 ring-brand' : '');
    }
  },
  template:
    '<div class="absolute left-0 bottom-full mb-1 w-[300px] rounded-lg bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50" @click.stop>' +

    // -- Quick options --
    '<template v-if="mode===\'quick\'">' +
    '<button type="button" :disabled="quickDisabled(0)" @click="quickPick(0)" :class="quickClass(0)"><span v-html="calIcon"></span>Today</button>' +
    '<button type="button" :disabled="quickDisabled(1)" @click="quickPick(1)" :class="quickClass(1)"><span v-html="calIcon"></span>Tomorrow</button>' +
    '<button type="button" :disabled="quickDisabled(3)" @click="quickPick(3)" :class="quickClass(3)"><span v-html="calIcon"></span>Next 3 days</button>' +
    '<button type="button" :disabled="quickDisabled(5)" @click="quickPick(5)" :class="quickClass(5)"><span v-html="calIcon"></span>Next 5 days</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="custom" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover"><span v-html="calIcon"></span>Custom Date</button>' +
    '<template v-if="selected">' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="$emit(\'clear\')" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Clear</button>' +
    '</template>' +
    '</template>' +

    // -- Custom Date grid --
    '<template v-else>' +
    '<button type="button" @click="back" class="mb-2 inline-flex items-center gap-1 text-[12px] text-sub hover:text-ink"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Back</button>' +
    '<div class="flex items-center gap-1.5 mb-2">' +
    '<button type="button" :disabled="!canPrev" @click="nav(-1)" :class="[\'h-8 w-8 grid place-items-center rounded-md text-sub shrink-0\', canPrev ? \'hover:bg-hover\' : \'opacity-30 cursor-not-allowed\']"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    // Month dropdown
    '<div class="relative flex-1 min-w-0">' +
    '<button type="button" @click="monthOpen=!monthOpen; yearOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="truncate">{{ monthLabel }}</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<ul v-if="monthOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="mo in months" :key="mo.i" @click="setMonth(mo.i)" :class="[\'group relative flex items-center cursor-pointer select-none py-1.5 pl-3 pr-8 text-[13px] hover:bg-brand hover:text-white\', mo.i===vm ? \'text-brand font-medium\' : \'text-ink\']">' +
    '<span class="truncate">{{ mo.name }}</span>' +
    '<span v-if="mo.i===vm" class="absolute right-2 text-brand group-hover:text-white"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '</li></ul></div>' +
    // Year dropdown
    '<div class="relative w-[92px] shrink-0">' +
    '<button type="button" @click="yearOpen=!yearOpen; monthOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="shrink-0 whitespace-nowrap">{{ vy }}</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<ul v-if="yearOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="y in years" :key="y" @click="setYear(y)" :class="[\'group relative flex items-center cursor-pointer select-none py-1.5 pl-3 pr-8 text-[13px] hover:bg-brand hover:text-white\', y===vy ? \'text-brand font-medium\' : \'text-ink\']">' +
    '<span class="truncate">{{ y }}</span>' +
    '<span v-if="y===vy" class="absolute right-2 text-brand group-hover:text-white"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '</li></ul></div>' +
    '<button type="button" :disabled="!canNext" @click="nav(1)" :class="[\'h-8 w-8 grid place-items-center rounded-md text-sub shrink-0\', canNext ? \'hover:bg-hover\' : \'opacity-30 cursor-not-allowed\']"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '</div>' +
    // Day-of-week header
    '<div class="grid grid-cols-7 gap-0.5 mb-1">' +
    '<div v-for="d in [\'Su\',\'Mo\',\'Tu\',\'We\',\'Th\',\'Fr\',\'Sa\']" :key="d" class="h-7 grid place-items-center text-[11px] font-medium text-faint">{{ d }}</div>' +
    '</div>' +
    // Day grid
    '<div class="grid grid-cols-7 gap-0.5">' +
    '<button v-for="c in cells" :key="c.key" type="button" :disabled="c.disabled" @click="pick(c)" :class="cellClass(c)">{{ c.day }}</button>' +
    '</div>' +
    '</template>' +

    '</div>'
};


// ---------------------------------------------------------------------------------------
// Rich-text editor — Quill (snow theme), wrapped as a Vue component so it drops in like an
// <input>. Used by the description field, the comment composer and the update composer.
//
// The instance is created on mount and destroyed on unmount, which matters because the
// drawer is v-if'd: opening a different work item builds a fresh editor rather than leaving
// a detached one holding the previous item's content.
//
// The model is HTML, not a Quill Delta. Descriptions, comments and updates are stored and
// rendered as HTML everywhere else in this app (and sanitized server-side on the way in), so
// keeping Delta as a second representation would mean two sources of truth for the same text.
//
// Images do NOT go inline as base64: the default paste/insert behaviour would embed whole
// files in the column, so the image handler uploads through the project's media endpoint and
// inserts the URL it returns.
// ---------------------------------------------------------------------------------------
var WI_EDITOR_TOOLBAR = [
  [{ header: [1, 2, 3, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ color: [] }, { background: [] }],
  [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
  [{ align: [] }],
  ['blockquote', 'code-block'],
  ['link', 'image', 'video'],
  ['clean']
];

/**
 * One person's avatar, used everywhere a name appears: their uploaded photo when they have
 * one, their initial when they do not. This exists because the initials markup had been
 * copied to a dozen places, and every copy silently ignored the photo.
 */
var WiAvatar = {
  props: {
    person: { type: Object, default: null },
    size: { type: Number, default: 24 }
  },
  computed: {
    box: function () {
      return {
        width: this.size + 'px',
        height: this.size + 'px',
        fontSize: Math.max(9, Math.round(this.size * 0.42)) + 'px'
      };
    },
    name: function () { return this.person ? (this.person.name || '') : ''; }
  },
  template:
    '<img v-if="person && person.avatar_url" :src="person.avatar_url" :alt="name" :title="name" ' +
    'class="rounded-full object-cover border border-line shrink-0" :style="box" />' +
    '<span v-else class="rounded-full bg-brand text-white grid place-items-center font-bold shrink-0" ' +
    ':style="box" :title="name">{{ person ? (person.initial || \'?\') : \'?\' }}</span>'
};

var WiEditor = {
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Add a description…' },
    minHeight: { type: String, default: '140px' },
    disabled: { type: Boolean, default: false },
    // Media endpoints. Passed in rather than read from a global so the editor stays a
    // component you can drop anywhere, and so it degrades to no-media when they are absent.
    mediaUpload: { type: String, default: '' },
    mediaGallery: { type: String, default: '' },
    mediaMaxBytes: { type: Number, default: 5 * 1024 * 1024 }
  },
  emits: ['update:modelValue', 'blur'],
  data: function () {
    return { quill: null, gallery: { open: false, items: [], loading: false }, uploading: false };
  },
  mounted: function () {
    if (!window.Quill || !this.$refs.area) return;
    var self = this;

    this.quill = new window.Quill(this.$refs.area, {
      theme: 'snow',
      placeholder: this.placeholder,
      readOnly: this.disabled,
      modules: {
        toolbar: {
          container: WI_EDITOR_TOOLBAR,
          handlers: {
            image: function () { self.pickImage(); }
          }
        }
      }
    });

    if (this.modelValue) this.setHtml(this.modelValue);

    // Update the model on blur, not on every keystroke: callers save on blur, and a
    // per-keystroke sync would be one request per character.
    this.quill.on('selection-change', function (range, oldRange) {
      if (range === null && oldRange !== null) {
        self.emitValue();
        self.$emit('blur');
      }
    });
  },
  beforeUnmount: function () {
    // Quill has no destroy(); dropping the reference and letting the v-if remove the DOM is
    // the documented way to tear one down.
    this.quill = null;
  },
  watch: {
    // The item was replaced under us (another edit refreshed the row) — take the new value,
    // but never while the user is typing into it.
    modelValue: function (next) {
      if (this.quill && !this.quill.hasFocus() && next !== this.html()) this.setHtml(next || '');
    }
  },
  methods: {
    html: function () {
      if (!this.quill) return '';
      var html = this.quill.getSemanticHTML ? this.quill.getSemanticHTML() : this.quill.root.innerHTML;
      // Quill reports an empty document as a single empty paragraph; normalise so an emptied
      // editor round-trips to "" rather than to scaffolding the server would strip anyway.
      return this.quill.getText().trim() === '' && this.quill.getLength() <= 1 ? '' : html;
    },
    setHtml: function (html) {
      // dangerouslyPasteHTML is Quill's own name for "parse this into a document"; the value
      // is server-sanitized markup, and anything Quill cannot represent it simply drops.
      this.quill.clipboard.dangerouslyPasteHTML(html || '', 'silent');
    },
    emitValue: function () {
      var html = this.html();
      if (html !== this.modelValue) this.$emit('update:modelValue', html);
    },
    insert: function (embed, value) {
      var range = this.quill.getSelection(true) || { index: this.quill.getLength() };
      this.quill.insertEmbed(range.index, embed, value, 'user');
      this.quill.setSelection(range.index + 1, 'silent');
      this.emitValue();
    },

    // ---- Images -----------------------------------------------------------------------
    /** Toolbar image button: choose between uploading a file and this project's gallery. */
    pickImage: function () {
      if (!this.mediaUpload) return;
      if (this.mediaGallery) { this.openGallery(); return; }
      this.$refs.file.click();
    },
    openGallery: async function () {
      this.gallery = { open: true, items: [], loading: true };
      try {
        var resp = await this.$pb.api(this.mediaGallery);
        this.gallery.items = resp.result || [];
      } catch (e) { this.gallery.items = []; }
      this.gallery.loading = false;
    },
    chooseFromGallery: function (item) {
      this.gallery.open = false;
      this.insert('image', item.src);
    },
    uploadImage: async function (e) {
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) return;

      if (file.size > this.mediaMaxBytes) {
        this.$pb.toast('That image is larger than the upload limit.', 'error');
        return;
      }

      this.uploading = true;
      this.gallery.open = false;
      var form = new FormData();
      form.append('file-0', file); // the field name the media endpoint reads
      try {
        var resp = await this.$pb.api(this.mediaUpload, { method: 'POST', body: form });
        var uploaded = (resp.result || [])[0];
        if (uploaded) this.insert('image', uploaded.url);
      } catch (err) {
        this.$pb.toast(this.$pb.firstError(err) || 'Could not upload that image.', 'error');
      }
      this.uploading = false;
    }
  },
  template:
    '<div class="wi-editor" :style="{ \'--wi-editor-min\': minHeight }">' +
    '<div ref="area"></div>' +
    '<input ref="file" type="file" accept="image/*" class="hidden" @change="uploadImage" />' +
    '<div v-if="uploading" class="px-2 py-1 text-[12px] text-sub">Uploading image…</div>' +

    // Gallery picker: this project's uploads, plus a way to add a new one.
    '<div v-if="gallery.open" class="fixed inset-0 z-[120] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @click="gallery.open = false"></div>' +
    '<div class="relative w-full max-w-[560px] bg-white rounded-xl shadow-xl flex flex-col max-h-[70vh]">' +
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '<span class="text-[14px] font-semibold text-head">Insert image</span>' +
    '<button type="button" @click="$refs.file.click()" class="ml-auto h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Upload</button>' +
    '<button type="button" @click="gallery.open = false" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Close">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '</div>' +
    '<div class="p-3 overflow-y-auto">' +
    '<div v-if="gallery.loading" class="py-8 text-center text-[13px] text-sub">Loading…</div>' +
    '<div v-else-if="gallery.items.length" class="grid grid-cols-3 sm:grid-cols-4 gap-2">' +
    '<button v-for="(g, i) in gallery.items" :key="i" type="button" @click="chooseFromGallery(g)" ' +
    'class="aspect-square rounded-md border border-line overflow-hidden hover:border-brand">' +
    '<img :src="g.src" :alt="g.name" class="h-full w-full object-cover" /></button>' +
    '</div>' +
    '<div v-else class="py-8 text-center text-[13px] text-sub">No images uploaded to this project yet.</div>' +
    '</div></div></div>' +

    '</div>'
};

function wiBlankForm(stateId) {
  return {
    title: '', description: '', state_id: stateId || '', priority: 'none',
    start_date: '', due_date: '', parent_id: '',
    assignee_ids: [], label_ids: []
  };
}

PB.boot('work-items', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap || {};
    // On the per-item page the detail is the first thing painted, so its drafts are seeded
    // here rather than in mounted() — otherwise the title field renders empty for a frame.
    var pageItem = b.pageItemId
      ? (b.items || []).find(function (i) { return String(i.id) === String(b.pageItemId); })
      : null;

    return {
      project: b.project || {},
      items: Array.isArray(b.items) ? b.items : [],
      states: Array.isArray(b.states) ? b.states : [],
      labels: Array.isArray(b.labels) ? b.labels : [],
      members: Array.isArray(b.members) ? b.members : [],
      priorities: Array.isArray(b.priorities) ? b.priorities : [],
      defaultStateId: b.defaultStateId || '',
      canCreate: !!b.canCreate,
      canEdit: !!b.canEdit,
      endpoints: b.endpoints || {},
      // Detail view (§4.4). One component renders it two ways: a right-hand drawer over the
      // list, and — when the per-item URL was opened — the same panel as a full page with the
      // list hidden. `pageItemId` is the server telling us which.
      mediaMaxBytes: (b.mediaMaxKb || 5120) * 1024,
      pageMode: !!b.pageItemId,
      drawer: { open: !!b.pageItemId, id: b.pageItemId || null },
      // Structure sections (§19-§41): sub-tasks, dependencies, relations, links. Loaded with
      // the detail and replaced wholesale by every write, so the panel never has to merge a
      // partial response into what it already had.
      structure: null,
      // Collaboration tabs (§4.1). `All` is the default (§4.2); the tab lives in the URL so
      // a link can point at one (§4.4).
      tab: 'all',
      feed: null,
      feedLoading: false,
      timeTracking: b.timeTracking !== false,
      currentUserId: b.currentUserId || null,
      // The inline composer is for NEW comments only. Replying and editing happen in a
      // modal: both act on a specific comment that may be scrolled well out of view, and
      // typing into a box at the top of the tab gave no sense of what was being answered.
      composer: { content: '', busy: false },
      commentModal: { open: false, mode: 'reply', target: null, content: '', busy: false },
      updateForm: { open: false, id: null, status: 'on_track', content: '', busy: false },
      worklogForm: { open: false, id: null, date: '', hours: '', minutes: '', description: '', busy: false, error: '' },
      // The shared work-item picker behind "add sub-task" and every relation type.
      picker: { open: false, mode: '', type: '', title: '', query: '', allProjects: false, results: [], selected: [], busy: false },
      // Add / edit an external link (§38).
      linkModal: { open: false, id: null, url: '', title: '', busy: false, error: '' },
      // Which "add" dropdown is open — in the action row or in a section header.
      addMenu: '',
      // Sections collapse independently. Dependencies and Relations start CLOSED: they are
      // reference material about other work items, and expanded by default they push the
      // description and the conversation below the fold on every open.
      secOpen: { subtasks: true, dependencies: false, relations: false, links: true },
      // Per-row ⋯ menu inside the structure sections.
      structMenu: { open: false, kind: '', row: null, style: {} },
      // The description editor is raised by ⋯ → Edit rather than sitting on the panel: an
      // always-mounted rich-text editor costs its own initialisation on every open, and the
      // detail view is read far more often than it is written.
      editingDescription: false,
      // Title/description are free text, so they are edited as drafts and saved on blur
      // rather than PATCHed on every keystroke.
      draft: {
        title: pageItem ? (pageItem.title || '') : '',
        description: pageItem ? (pageItem.description || '') : '',
      },
      // Row editing: which chip picker / action menu is open, and for which item.
      rowMenu: { open: false, kind: '', item: null, style: {} },
      rowQuery: '',
      deleteConfirm: { open: false, item: null, busy: false },
      table: null,
      // Create modal
      open: false, saving: false, createMore: false, errors: {},
      // The state the modal was opened with — what "Create more" resets each new item to.
      seedStateId: '',
      // Set when the create modal was opened as "new sub-task" of the open work item.
      seedParentId: '',
      form: wiBlankForm(b.defaultStateId || ''),
      // Which chip popover is open in the modal ('state' | 'priority' | … | 'due_date').
      menu: '',
      // "Add parent" opens a full search panel rather than a popover (POC ParentSearchModal).
      parentOpen: false,
      memberQuery: '', labelQuery: '', parentQuery: ''
    };
  },
  computed: {
    // Tabulator group order: the project's states in their configured order, then a trailing
    // bucket for items whose state was deleted.
    groupValues: function () {
      return this.states.map(function (s) { return String(s.id); }).concat([WI_NO_STATE]);
    },
    statesById: function () {
      var map = {};
      this.states.forEach(function (s) { map[String(s.id)] = s; });
      return map;
    },
    formState: function () { return this.statesById[String(this.form.state_id)] || null; },
    formPriority: function () { return WI_PRI[this.form.priority] || WI_PRI.none; },
    filteredMembers: function () {
      var q = (this.memberQuery || '').toLowerCase();
      return this.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    filteredLabels: function () {
      var q = (this.labelQuery || '').toLowerCase();
      return this.labels.filter(function (l) { return !q || (l.name || '').toLowerCase().indexOf(q) > -1; });
    },
    // Parent candidates: any existing item in this project (a brand-new item cannot be its
    // own parent, so no self-exclusion is needed on create).
    parentCandidates: function () {
      var q = (this.parentQuery || '').toLowerCase();
      return this.items.filter(function (i) {
        return !q || (i.title || '').toLowerCase().indexOf(q) > -1 || (i.identifier || '').toLowerCase().indexOf(q) > -1;
      }).slice(0, 50);
    },
    parentSelected: function () {
      var id = this.form.parent_id;
      if (!id) return null;
      return this.items.find(function (i) { return String(i.id) === String(id); }) || null;
    },
    selectedAssignees: function () {
      var ids = this.form.assignee_ids.map(String);
      return this.members.filter(function (m) { return ids.indexOf(String(m.id)) > -1; });
    },
    selectedLabels: function () {
      var ids = this.form.label_ids.map(String);
      return this.labels.filter(function (l) { return ids.indexOf(String(l.id)) > -1; });
    },
    totalCount: function () { return this.items.length; },
    /**
     * The item the detail view is showing, read from `items` rather than copied into the
     * drawer — an inline edit anywhere refreshes that row, and the drawer follows along.
     */
    drawerItem: function () {
      if (!this.drawer.id) return null;
      var id = String(this.drawer.id);
      return this.items.find(function (i) { return String(i.id) === id; }) || null;
    },
    /**
     * The banner above each dependency group. A related work item's ID says nothing about
     * which way the dependency points, so the direction is named and colour-coded: being
     * blocked is the state that needs attention, so it reads as a warning.
     */
    depGroups: function () {
      return [
        {
          key: 'blocked_by', label: 'Blocked by', cls: 'text-danger bg-danger/5',
          icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M6 6l12 12" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'
        },
        {
          key: 'blocking', label: 'Blocking', cls: 'text-amber-700 bg-amber-50',
          icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 4l8 14H4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M12 10v3M12 15.5v.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'
        }
      ];
    },
    /** Same idea for relations, which are informational rather than blocking. */
    relGroups: function () {
      var chain = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M9 15l6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      var copy = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M15 9V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7a2 2 0 002 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
      return [
        { key: 'related', label: 'relates to', icon: chain },
        { key: 'duplicate_of', label: 'duplicate of', icon: copy },
        { key: 'duplicated_by', label: 'duplicated by', icon: copy }
      ];
    },
  },
  components: { 'wi-calendar': WiCalendar, 'wi-editor': WiEditor, 'wi-avatar': WiAvatar },
  mounted: function () {
    this.buildTable();
    this.bindGlobalCreate();

    // Escape closes the parent search panel first, then the create modal (POC behaviour).
    var self = this;
    this._onKeydown = function (e) {
      if (e.key !== 'Escape') return;
      // Innermost first: pickers, then the parent panel, then the create modal, then the
      // detail drawer — so Escape never closes the drawer out from under an open picker.
      if (self.rowMenu.open) { self.closeRowMenu(); }
      else if (self.commentModal.open) { self.closeCommentModal(); }
      else if (self.linkModal.open) { self.linkModal.open = false; }
      else if (self.picker.open) { self.closePicker(); }
      else if (self.addMenu) { self.addMenu = ''; }
      else if (self.parentOpen) { self.closeParent(); }
      else if (self.menu) { self.menu = ''; }
      else if (self.open) { self.closeCreate(); }
      else if (self.drawer.open && !self.pageMode) { self.closeDrawer(); }
    };
    document.addEventListener('keydown', this._onKeydown);

    // The action row's dropdowns are plain elements, not <details>, so closing on an outside
    // click is ours to do — otherwise one stays open behind whatever the user clicks next.
    this._onDocClick = function (e) {
      if (self.addMenu && !e.target.closest('[data-add-menu]')) self.addMenu = '';
    };
    document.addEventListener('click', this._onDocClick);

    // Arrived from another screen's "New work item" action (?create=1) — open the modal.
    try {
      var params = new URLSearchParams(window.location.search);
      if (this.canCreate && params.get('create') === '1') {
        this.openCreate('');
      }

      if (this.pageMode) {
        // The per-item URL: the detail is the page, so open it straight away.
        this.syncDraft();
        this.loadStructure();
        this.loadFeed();
        if (params.get('tab')) this.tab = params.get('tab');
      } else if (params.get('item')) {
        // ?item=<ID> deep link — the shape "Copy link" produced before the item page existed.
        var wanted = String(params.get('item'));
        var match = this.items.find(function (i) {
          return String(i.identifier) === wanted || String(i.id) === wanted;
        });
        if (match) this.openDrawer(match);
      }
    } catch (e) {}
  },
  beforeUnmount: function () {
    if (this._globalCreate) {
      this._globalCreate.el.removeEventListener('click', this._globalCreate.handler);
      this._globalCreate = null;
    }
    if (this._onKeydown) {
      document.removeEventListener('keydown', this._onKeydown);
      this._onKeydown = null;
    }
    if (this._onDocClick) {
      document.removeEventListener('click', this._onDocClick);
      this._onDocClick = null;
    }
  },
  methods: {
    /**
     * The sidebar's global "New work item" action (Blade, outside this component's root).
     * Its href already points at this screen with ?create=1 so it works without JS; here we
     * intercept the click and open the modal in place instead of reloading the page.
     */
    bindGlobalCreate: function () {
      var el = document.getElementById('new-work-item-btn');
      if (!el || !this.canCreate) return;
      var self = this;
      var handler = function (e) { e.preventDefault(); self.openCreate(''); };
      el.addEventListener('click', handler);
      this._globalCreate = { el: el, handler: handler };
    },
    // ---------- Tabulator ----------
    buildTable: function () {
      if (!window.Tabulator || !this.$refs.grid) return;
      var self = this;

      this.table = new Tabulator(this.$refs.grid, {
        data: this.rows(),
        index: 'id',
        layout: 'fitColumns',
        headerVisible: false,
        rowHeight: 44,
        // Let Tabulator own the scroll container (and virtualise long lists) inside the
        // flex column; `main` itself does not scroll.
        height: '100%',
        columnDefaults: { vertAlign: 'middle', headerSort: false },
        groupBy: 'gkey',
        groupToggleElement: 'header',
        groupValues: [this.groupValues],
        groupHeader: function (value, count) { return self.groupHeader(value, count); },
        columns: [
          { title: 'ID', field: 'identifier', width: self.idWidth(), formatter: function (cell) { return '<span class="text-[12px] text-sub">' + wiEsc(cell.getValue()) + '</span>'; } },
          { title: 'Title', field: 'title', minWidth: 160, widthGrow: 1, formatter: function (cell) {
            // An item waiting on an unresolved blocker says so on the row itself — a
            // dependency you have to open the item to discover is a dependency people miss.
            var d = cell.getRow().getData();
            var chip = d.blocked_by_count > 0 ? wiBlockedChip(d.blocked_by_count) : '';
            return '<span class="inline-flex items-center gap-2">' + chip + '<span class="text-[14px] text-ink">' + wiEsc(cell.getValue()) + '</span></span>';
          } },
          { title: '', field: 'meta', width: 620, hozAlign: 'right', formatter: function (cell) { return self.metaCell(cell.getRow().getData()); } }
        ]
      });

      // A row opens the detail drawer, except when the click landed on one of the row's own
      // controls — those edit in place and must not also open the panel behind them.
      this.table.on('rowClick', function (e, row) {
        if (e.target.closest('[data-act]')) return;
        self.openDrawer(row.getData());
      });

      // Per-group "+" creates an item already in that group's state (spec §4.2 / §11.2).
      // Capture phase so the click does not also toggle the group.
      this.$refs.grid.addEventListener('click', function (e) {
        var add = e.target.closest('[data-gadd]');
        if (!add) return;
        e.stopPropagation();
        self.openCreate(add.getAttribute('data-gadd'));
      }, true);

      // Row chips + the ⋯ menu. Delegated, because Tabulator re-renders rows on every
      // data change and re-bound listeners would leak.
      this.$refs.grid.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        e.stopPropagation();
        var item = self.items.find(function (i) { return String(i.id) === btn.getAttribute('data-id'); });
        if (item) self.openRowMenu(btn.getAttribute('data-act'), item, btn);
      }, true);
    },
    /**
     * Width of the ID column, sized to the longest ID actually on screen.
     *
     * A fixed width was left over from the `<PROJECT>-<n>` format; against a plain number it
     * strands ~50px of empty cell between the ID and the title. Measuring instead keeps that
     * gap at the grid's own 8px + 8px cell padding whatever the number grows to, while the
     * column stays a column — so titles still line up down the list.
     * 24 = the first cell's left padding, 8 = its right padding, 7.5px per digit at 12px.
     */
    idWidth: function () {
      var longest = this.items.reduce(function (n, i) {
        return Math.max(n, String(i.identifier || '').length);
      }, 1);

      return 24 + 8 + Math.ceil(longest * 7.5);
    },
    /** Bootstrap rows + the group key Tabulator buckets on. */
    rows: function () {
      var self = this;
      return this.items.map(function (i) { return self.row(i); });
    },
    refreshTable: function () {
      if (!this.table) return;
      var self = this;
      // The grid is v-show'd off while the list is empty, and an element that was
      // display:none measures zero width — so a redraw is needed the first time it appears,
      // and only then. (This used to trigger on every refresh of a one-item list.)
      var wasHidden = this._gridWasEmpty === true;
      this._gridWasEmpty = this.items.length === 0;
      this.table.replaceData(this.rows()).then(function () {
        // Creating the item that adds a digit widens the column with it.
        var id = self.table.getColumn('identifier');
        if (id) id.setWidth(self.idWidth());
        // A grid that was display:none at mount measures zero width; force a re-layout the
        // first time it becomes visible.
        if (wasHidden) self.$nextTick(function () { self.table.redraw(true); });
      });
    },
    groupHeader: function (value, count) {
      var state = this.statesById[String(value)] || null;
      var name = state ? state.name : 'No state';
      var add = this.canCreate
        ? '<button type="button" data-gadd="' + wiEsc(value) + '" class="ml-auto h-6 w-6 grid place-items-center rounded text-sub hover:bg-line" title="Add work item"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>'
        : '';
      return '<span class="wi-chevron grid place-items-center" style="color:#9ca3af"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
        '<span class="grid place-items-center">' + wiStateIcon(state) + '</span>' +
        '<span class="text-[13px] font-semibold" style="color:#0f0f10">' + wiEsc(name) + '</span>' +
        '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5" style="color:#6b7280;background:#f3f4f6">' + count + '</span>' +
        add;
    },
    /**
     * Right-aligned chip cluster. Every chip is a BUTTON that opens its own picker (§4.2:
     * "Row property buttons should open a picker without navigating away from the row"), and
     * the trailing kebab opens the row action menu (§4.4). Read-only users get plain chips.
     */
    metaCell: function (d) {
      var pri = WI_PRI[d.priority] || WI_PRI.none;
      var edit = this.canEdit;
      var chip = function (inner, act, extra) {
        if (!edit) return wiChip(inner, extra);
        return '<button type="button" data-act="' + act + '" data-id="' + d.id + '" class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-stroke bg-white text-[12px] hover:bg-hover shrink-0 ' + (extra || 'text-ink') + '">' + inner + '</button>';
      };
      var out = [
        chip(wiStateIcon(d.state) + wiEsc(d.state ? d.state.name : 'No state'), 'state'),
        chip(pri.icon + pri.label, 'priority', pri.cls)
      ];

      // Dates: a set date shows its chip; an empty one shows a compact calendar button so
      // it can still be filled in from the row.
      out.push('<span class="hidden xl:inline-flex">' + (d.start_date
        ? chip(WI_CAL + this.fmtDate(d.start_date), 'start_date')
        : chip(WI_CAL, 'start_date', 'text-faint')) + '</span>');
      out.push('<span class="hidden xl:inline-flex">' + (d.due_date
        ? chip(WI_CAL + this.fmtDate(d.due_date), 'due_date')
        : chip(WI_CAL, 'due_date', 'text-faint')) + '</span>');

      // Assignees: stacked avatars, or a dashed placeholder when unassigned.
      var avatars = (d.assignees || []).slice(0, 3).map(function (a) {
        return wiAvatar(a, 24);
      }).join('');
      if ((d.assignees || []).length > 3) avatars += '<span class="text-[11px] text-sub">+' + (d.assignees.length - 3) + '</span>';
      if (!avatars) {
        avatars = '<span class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint shrink-0"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>';
      }
      out.push(edit
        ? '<button type="button" data-act="assignees" data-id="' + d.id + '" class="inline-flex items-center gap-0.5 shrink-0" title="Assignees">' + avatars + '</button>'
        : '<span class="inline-flex items-center gap-0.5 shrink-0">' + avatars + '</span>');

      // Labels
      var labels = (d.labels || []).slice(0, 2).map(function (l) {
        return '<span class="h-2 w-2 rounded-full shrink-0" style="background:' + wiEsc(l.color) + '"></span>' + wiEsc(l.name);
      });
      var labelInner = labels.length
        ? labels.join('</span><span class="mx-1"></span><span>')
        : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';
      var extraLabels = (d.labels || []).length > 2 ? ' +' + (d.labels.length - 2) : '';
      out.push('<span class="hidden lg:inline-flex">' + chip(labelInner + extraLabels, 'labels', labels.length ? 'text-ink' : 'text-faint') + '</span>');

      // Row action menu (§4.4)
      if (edit) {
        out.push('<button type="button" data-act="menu" data-id="' + d.id + '" title="Work item actions" class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line shrink-0">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg></button>');
      }

      return '<div class="flex items-center justify-end gap-1.5 flex-nowrap">' + out.join('') + '</div>';
    },
    // ---------- Inline row editing (§4.2) ----------
    /** Open a chip picker or the action menu, anchored to the clicked control. */
    openRowMenu: function (kind, item, btn) {
      if (!this.canEdit) return;
      var widths = { state: 208, priority: 200, assignees: 256, labels: 256, start_date: 300, due_date: 300, menu: 200 };
      var w = widths[kind] || 208;
      var heights = { menu: 220, start_date: 340, due_date: 340 };
      var h = heights[kind] || 260;
      var r = btn.getBoundingClientRect();
      var style = { position: 'fixed', width: w + 'px', zIndex: 120, left: Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px' };
      // Prefer opening downward; flip up when the viewport bottom is close.
      if (r.bottom + h > window.innerHeight - 8 && r.top > h) style.bottom = (window.innerHeight - r.top + 6) + 'px';
      else style.top = (r.bottom + 6) + 'px';

      this.rowQuery = '';
      this.rowMenu = { open: true, kind: kind, item: item, style: style };
    },
    closeRowMenu: function () { this.rowMenu = { open: false, kind: '', item: null, style: {} }; },
    rowMembers: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.members.filter(function (m) {
        return !q || (m.name || '').toLowerCase().indexOf(q) > -1 || (m.email || '').toLowerCase().indexOf(q) > -1;
      });
    },
    rowLabels: function () {
      var q = (this.rowQuery || '').toLowerCase();
      return this.labels.filter(function (l) { return !q || (l.name || '').toLowerCase().indexOf(q) > -1; });
    },
    rowHasAssignee: function (m) {
      var it = this.rowMenu.item;
      return !!it && (it.assignees || []).some(function (a) { return String(a.id) === String(m.id); });
    },
    rowHasLabel: function (l) {
      var it = this.rowMenu.item;
      return !!it && (it.labels || []).some(function (x) { return String(x.id) === String(l.id); });
    },
    /** PATCH one or more properties and swap the refreshed row back into the grid. */
    /**
     * PATCH one or more properties and swap the refreshed row back into the grid.
     *
     * Confirms on success as well as on failure: a chip edit changes a single word on a row
     * the user may not be looking at, so without a toast there is nothing to distinguish
     * "saved" from "the click missed".
     */
    patchItem: async function (item, body, close, message) {
      if (!item || !this.endpoints.update) return;
      try {
        var url = this.$pb.withId(this.endpoints.update, item.id);
        var resp = await this.$pb.api(url, { method: 'PATCH', body: body });
        this.replaceItem(resp.item);
        this.$pb.toast(message || 'Work item updated.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      if (close !== false) this.closeRowMenu();
    },
    /**
     * Swap a refreshed card back into the list.
     *
     * When the row is only carrying different values, Tabulator updates that one row; a full
     * replaceData() rebuilds and regroups the entire grid, which is a lot of work to show a
     * changed priority. The grid is only rebuilt when the row has to MOVE — a state change
     * puts it in a different group — or when it was not in the list to begin with.
     */
    replaceItem: function (card) {
      if (!card) return;
      var previous = null;
      for (var i = 0; i < this.items.length; i++) {
        if (this.items[i].id === card.id) { previous = this.items[i]; this.items.splice(i, 1, card); break; }
      }

      var sameGroup = previous && String(previous.state_id || '') === String(card.state_id || '');
      if (sameGroup && this.table) {
        var self = this;
        this.table.updateData([this.row(card)]).then(function () {
          // The chip cluster is drawn by a formatter on a column with no field of its own,
          // so Tabulator sees nothing changed there and leaves the old HTML in place —
          // an added assignee would not appear until a reload. Reformat the row explicitly.
          var row = self.table.getRow(card.id);
          if (row) row.reformat();
        }).catch(function () { self.refreshTable(); });
        return;
      }

      this.refreshTable();
    },
    /** One card as the grid's row shape — the group key is derived, not stored. */
    row: function (i) {
      return Object.assign({}, i, { gkey: i.state_id ? String(i.state_id) : WI_NO_STATE });
    },
    removeItem: function (item) {
      var i = this.items.indexOf(item);
      if (i > -1) this.items.splice(i, 1);
      // The detail was showing the item that just left the list (archived or deleted) —
      // close it rather than leave an empty panel, or an empty page in page mode.
      if (item && this.drawer.id && String(this.drawer.id) === String(item.id)) this.closeDrawer();
      this.refreshTable();
    },
    setRowState: function (s) { this.patchItem(this.rowMenu.item, { state_id: s ? s.id : '' }, true, 'State updated.'); },
    setRowPriority: function (p) { this.patchItem(this.rowMenu.item, { priority: p.key }, true, 'Priority updated.'); },
    /**
     * A work item has exactly one assignee (§4.3, revised), so picking a member replaces
     * whoever held it — picking the current one clears it. The picker closes on choice,
     * because there is nothing left to multi-select.
     */
    toggleRowAssignee: function (m) {
      var it = this.rowMenu.item;
      var ids = this.rowHasAssignee(m) ? [] : [m.id];
      this.patchItem(it, { assignee_ids: ids }, true, ids.length ? 'Assignee updated.' : 'Assignee cleared.');
    },
    toggleRowLabel: function (l) {
      var it = this.rowMenu.item;
      var ids = (it.labels || []).map(function (x) { return x.id; });
      var at = ids.map(String).indexOf(String(l.id));
      if (at > -1) ids.splice(at, 1); else ids.push(l.id);
      this.patchItem(it, { label_ids: ids }, false, 'Labels updated.');
    },
    setRowDate: function (iso) {
      var body = {}; body[this.rowMenu.kind] = iso;
      var label = this.rowMenu.kind === 'start_date' ? 'Start date' : 'Due date';
      this.patchItem(this.rowMenu.item, body, true, iso ? label + ' updated.' : label + ' cleared.');
    },
    clearRowDate: function () { this.setRowDate(''); },

    // ---------- Detail view: drawer + page mode (§4.4) ----------
    /** Open the detail for an item. Same call from a grid row, a mobile card or a deep link. */
    openDrawer: function (item) {
      if (!item) return;
      this.drawer.id = item.id;
      this.drawer.open = true;
      this.syncDraft();
      this.loadStructure();
      this.loadFeed();
    },
    closeDrawer: function () {
      // On the per-item page there is nothing behind the panel — closing means going back to
      // the list, not hiding the only content on screen.
      if (this.pageMode) { window.location.href = this.endpoints.list || '/'; return; }
      this.drawer.open = false;
      this.drawer.id = null;
      this.feed = null;
    },
    // ---------- Collaboration tabs (§4-§11) ----------
    /** The seven tabs, minus Worklogs when the project has time tracking off (§9.2). */
    tabList: function () {
      var tabs = [
        { key: 'all', label: 'All' }, { key: 'activity', label: 'Activity' },
        { key: 'comments', label: 'Comments' }, { key: 'updates', label: 'Updates' },
        { key: 'worklogs', label: 'Worklogs' }, { key: 'transition', label: 'Transition' },
        { key: 'history', label: 'History' }
      ];
      return this.timeTracking ? tabs : tabs.filter(function (t) { return t.key !== 'worklogs'; });
    },
    setTab: function (key) {
      this.tab = key;
      // §4.4: reflect the tab in the URL so it survives a refresh and can be linked to,
      // without adding a history entry per click.
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        window.history.replaceState({}, '', url);
      } catch (e) {}
    },
    loadFeed: async function () {
      var it = this.drawerItem;
      this.feed = null;
      if (!it || !this.endpoints.feed) return;
      this.feedLoading = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.feed, it.id));
        this.feed = resp.feed;
      } catch (e) { this.feed = null; }
      this.feedLoading = false;
    },
    applyFeed: function (resp) {
      if (resp && resp.feed) this.feed = resp.feed;
      if (resp && resp.message) this.$pb.toast(resp.message);
    },
    feedUrl: function (name, suffix) {
      var it = this.drawerItem;
      if (!it || !this.endpoints[name]) return '';
      return this.$pb.withId(this.endpoints[name], it.id) + (suffix || '');
    },

    // ---------- Comments (§7) ----------
    /** Reply and edit both open the modal, on top of whatever is on screen. */
    startReply: function (comment) {
      this.commentModal = { open: true, mode: 'reply', target: comment, content: '', busy: false };
    },
    startEditComment: function (comment) {
      this.commentModal = { open: true, mode: 'edit', target: comment, content: comment.content || '', busy: false };
    },
    closeCommentModal: function () { this.commentModal.open = false; },
    /** Strip tags before testing for content: an editor's empty document is still markup. */
    hasText: function (html) {
      return (html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== '';
    },
    submitCommentModal: async function () {
      var m = this.commentModal;
      if (!this.hasText(m.content) || m.busy) return;
      m.busy = true;
      try {
        if (m.mode === 'edit') {
          this.applyFeed(await this.$pb.api(this.feedUrl('comments', '/' + m.target.id), {
            method: 'PATCH', body: { content: m.content }
          }));
        } else {
          this.applyFeed(await this.$pb.api(this.feedUrl('comments'), {
            method: 'POST', body: { content: m.content, parent_comment_id: m.target.id }
          }));
        }
        m.open = false;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      m.busy = false;
    },
    /** The inline composer now only ever posts a new top-level comment (§5.3). */
    postComment: async function () {
      if (!this.hasText(this.composer.content) || this.composer.busy) return;
      this.composer.busy = true;
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('comments'), {
          method: 'POST', body: { content: this.composer.content }
        }));
        this.composer.content = '';
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.composer.busy = false;
    },
    deleteComment: async function (comment) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('comments', '/' + comment.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    canEditComment: function (comment) {
      return comment.author && String(comment.author.id) === String(this.currentUserId);
    },

    // ---------- Updates (§8) ----------
    openUpdateForm: function (update) {
      this.updateForm = {
        open: true, id: update ? update.id : null,
        status: update ? update.status : 'on_track',
        content: update ? update.content : '', busy: false
      };
    },
    saveUpdate: async function () {
      var body = (this.updateForm.content || '').replace(/<[^>]*>/g, '').trim();
      if (!body || this.updateForm.busy) return;
      this.updateForm.busy = true;
      var url = this.feedUrl('updates', this.updateForm.id ? '/' + this.updateForm.id : '');
      try {
        this.applyFeed(await this.$pb.api(url, {
          method: this.updateForm.id ? 'PATCH' : 'POST',
          body: { status: this.updateForm.status, content: this.updateForm.content }
        }));
        this.updateForm.open = false;
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.updateForm.busy = false;
    },
    deleteUpdate: async function (update) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('updates', '/' + update.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    updateMeta: function (status) {
      // §8.4: icon and label always travel together — never colour on its own.
      var map = {
        on_track: { label: 'On Track', cls: 'text-success border-success/30 bg-success/5', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' },
        at_risk: { label: 'At Risk', cls: 'text-amber-700 border-amber-300 bg-amber-50', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 4l8 14H4z" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="M12 10v3M12 15.5v.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>' },
        off_track: { label: 'Off Track', cls: 'text-danger border-danger/30 bg-danger/5', icon: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.9"/><path d="M12 7.5v5M12 15.5v.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>' }
      };
      return map[status] || map.on_track;
    },

    // ---------- Worklogs (§9) ----------
    openWorklogForm: function (log) {
      var today = new Date();
      var iso = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      this.worklogForm = {
        open: true, id: log ? log.id : null,
        date: log ? log.work_date : iso,
        hours: log ? Math.floor(log.minutes / 60) : '',
        minutes: log ? log.minutes % 60 : '',
        description: log ? (log.description || '') : '',
        busy: false, error: ''
      };
    },
    saveWorklog: async function () {
      if (this.worklogForm.busy) return;
      var total = (parseInt(this.worklogForm.hours || 0, 10) * 60) + parseInt(this.worklogForm.minutes || 0, 10);
      // §9.5, checked here too so the refusal is instant — the server re-checks it.
      if (!total) { this.worklogForm.error = 'Log at least one minute.'; return; }
      this.worklogForm.busy = true; this.worklogForm.error = '';
      var url = this.feedUrl('worklogs', this.worklogForm.id ? '/' + this.worklogForm.id : '');
      try {
        this.applyFeed(await this.$pb.api(url, {
          method: this.worklogForm.id ? 'PATCH' : 'POST',
          body: {
            work_date: this.worklogForm.date,
            hours: parseInt(this.worklogForm.hours || 0, 10),
            minutes: parseInt(this.worklogForm.minutes || 0, 10),
            description: this.worklogForm.description
          }
        }));
        this.worklogForm.open = false;
      } catch (e) { this.worklogForm.error = this.$pb.firstError(e); }
      this.worklogForm.busy = false;
    },
    deleteWorklog: async function (log) {
      try {
        this.applyFeed(await this.$pb.api(this.feedUrl('worklogs', '/' + log.id), { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- Structure: sub-tasks, dependencies, relations, links (§19-§41) ----------
    loadStructure: async function () {
      var it = this.drawerItem;
      this.structure = null;
      if (!it || !this.endpoints.structure) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.structure, it.id));
        this.structure = resp.structure;
      } catch (e) { /* the sections are supporting detail — never block the panel on them */ }
    },
    /** Swap in the structure a write returned, and surface its message. */
    applyStructure: function (resp) {
      if (resp && resp.structure) this.structure = resp.structure;
      if (resp && resp.message) this.$pb.toast(resp.message);
    },
    structureUrl: function (name, extra) {
      var it = this.drawerItem;
      if (!it || !this.endpoints[name]) return '';
      return this.$pb.withId(this.endpoints[name], it.id) + (extra || '');
    },
    hasStructure: function () {
      var s = this.structure;
      if (!s) return false;
      return !!(s.subtasks.items.length || s.dependencies.blocking.length || s.dependencies.blocked_by.length ||
        s.relations.related.length || s.relations.duplicate_of.length || s.relations.duplicated_by.length ||
        s.links.length);
    },

    toggleSection: function (key) { this.secOpen[key] = !this.secOpen[key]; },

    /** Row ⋯ menu: Open · Copy link · Remove (§26/§32/§41). */
    openStructMenu: function (kind, row, ev) {
      var btn = ev.currentTarget;
      var r = btn.getBoundingClientRect();
      var w = 200;
      var style = { position: 'fixed', width: w + 'px', zIndex: 120, left: Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px' };
      if (r.bottom + 150 > window.innerHeight - 8 && r.top > 150) style.bottom = (window.innerHeight - r.top + 6) + 'px';
      else style.top = (r.bottom + 6) + 'px';
      this.structMenu = { open: true, kind: kind, row: row, style: style };
    },
    closeStructMenu: function () { this.structMenu = { open: false, kind: '', row: null, style: {} }; },
    structRemove: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      if (m.kind === 'subtask') this.removeSubtask(m.row);
      else if (m.kind === 'link') this.deleteLink(m.row);
      else this.removeRelation(m.row);
    },
    structOpen: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      window.location.href = m.kind === 'link' ? m.row.url : this.rowUrl(m.row);
    },
    structCopy: function () {
      var m = this.structMenu;
      this.closeStructMenu();
      if (!m.row) return;
      if (m.kind === 'link') this.copyText(m.row.url); else this.copyRowLink(m.row);
    },
    copyText: async function (text) {
      try { await navigator.clipboard.writeText(text); this.$pb.toast('Link copied.'); }
      catch (e) { window.prompt('Copy this link', text); }
    },
    /** "less than a minute ago" / "3 hours ago" — the timestamp a link row shows. */
    relativeTime: function (iso) {
      if (!iso) return '';
      var then = new Date(iso);
      if (isNaN(then)) return '';
      var secs = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
      if (secs < 60) return 'less than a minute ago';
      var units = [['minute', 60], ['hour', 3600], ['day', 86400], ['month', 2592000], ['year', 31536000]];
      for (var i = units.length - 1; i >= 0; i--) {
        var n = Math.floor(secs / units[i][1]);
        if (n >= 1) return n + ' ' + units[i][0] + (n === 1 ? '' : 's') + ' ago';
      }
      return 'just now';
    },

    // ---------- The shared work-item picker (§22 / §30 / §34) ----------
    /** §20: the sub-task button offers both routes; the choice decides which modal opens. */
    createSubtask: function () {
      this.addMenu = '';
      var it = this.drawerItem;
      if (!it) return;
      // Opens the normal create modal with the parent already set (§21), so a sub-task is
      // created through exactly the same path — and the same validation — as any work item.
      this.openCreate('', it.id);
    },
    openPicker: function (mode, type, title) {
      this.addMenu = '';
      this.picker = { open: true, mode: mode, type: type || '', title: title, query: '', allProjects: false, results: [], selected: [], busy: false };
      this.searchItems();
      var self = this;
      this.$nextTick(function () { if (self.$refs.pickerSearch) self.$refs.pickerSearch.focus(); });
    },
    closePicker: function () { this.picker.open = false; },
    searchItems: async function () {
      var it = this.drawerItem;
      if (!it || !this.endpoints.search) return;
      var q = encodeURIComponent(this.picker.query || '');
      var url = this.structureUrl('search', '?q=' + q + (this.picker.allProjects ? '&all_projects=1' : ''));
      try {
        var resp = await this.$pb.api(url);
        this.picker.results = resp.items || [];
      } catch (e) { this.picker.results = []; }
    },
    /** Debounced so typing does not fire a request per keystroke. */
    onPickerQuery: function () {
      var self = this;
      clearTimeout(this._pickerTimer);
      this._pickerTimer = setTimeout(function () { self.searchItems(); }, 200);
    },
    togglePick: function (row) {
      var ids = this.picker.selected.map(String);
      var at = ids.indexOf(String(row.id));
      if (at > -1) this.picker.selected.splice(at, 1); else this.picker.selected.push(row.id);
    },
    isPicked: function (row) { return this.picker.selected.map(String).indexOf(String(row.id)) > -1; },
    confirmPicker: async function () {
      if (!this.picker.selected.length || this.picker.busy) return;
      this.picker.busy = true;
      var body = { work_item_ids: this.picker.selected };
      var url = this.structureUrl(this.picker.mode === 'subtask' ? 'subtasks' : 'relations');
      if (this.picker.mode !== 'subtask') body.relation_type = this.picker.type;
      try {
        this.applyStructure(await this.$pb.api(url, { method: 'POST', body: body }));
        this.closePicker();
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.picker.busy = false;
    },
    removeSubtask: async function (row) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('subtasks') + '/' + row.id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    removeRelation: async function (row) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('relations') + '/' + row.relation_id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },

    // ---------- External links (§37-§41) ----------
    openLinkModal: function (link) {
      this.addMenu = '';
      this.linkModal = {
        open: true, id: link ? link.id : null,
        url: link ? link.url : '', title: link ? (link.title || '') : '',
        busy: false, error: ''
      };
    },
    saveLink: async function () {
      if (this.linkModal.busy || !this.linkModal.url.trim()) return;
      this.linkModal.busy = true; this.linkModal.error = '';
      var base = this.structureUrl('links');
      var url = this.linkModal.id ? base + '/' + this.linkModal.id : base;
      try {
        this.applyStructure(await this.$pb.api(url, {
          method: this.linkModal.id ? 'PATCH' : 'POST',
          body: { url: this.linkModal.url.trim(), title: this.linkModal.title.trim() }
        }));
        this.linkModal.open = false;
      } catch (e) { this.linkModal.error = this.$pb.firstError(e); }
      this.linkModal.busy = false;
    },
    deleteLink: async function (link) {
      try {
        this.applyStructure(await this.$pb.api(this.structureUrl('links') + '/' + link.id, { method: 'DELETE' }));
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    /** A related work item's own page, so every row is openable (§26/§32). */
    rowUrl: function (row) {
      return this.endpoints.item ? this.$pb.withId(this.endpoints.item, row.id) : '';
    },
    copyRowLink: async function (row) {
      var url = new URL(this.rowUrl(row), window.location.origin).href;
      try { await navigator.clipboard.writeText(url); this.$pb.toast('Link copied.'); }
      catch (e) { window.prompt('Copy this link', url); }
    },

    /** The parent's ID, resolved from the loaded list (relations land in a later slice). */
    parentLabel: function (item) {
      if (!item || !item.parent_id) return 'None';
      var id = String(item.parent_id);
      var parent = this.items.find(function (i) { return String(i.id) === id; });
      return parent ? parent.identifier + ' · ' + parent.title : 'None';
    },
    drawerCopyLink: async function () {
      var it = this.drawerItem;
      if (!it) return;
      var url = new URL(this.itemUrl(it), window.location.origin).href;
      try {
        await navigator.clipboard.writeText(url);
        this.$pb.toast('Link copied.');
      } catch (e) { window.prompt('Copy this link', url); }
    },
    /** Copy the drafts from the item whenever the detail switches to a different work item. */
    syncDraft: function () {
      var it = this.drawerItem;
      this.draft.title = it ? (it.title || '') : '';
      this.draft.description = it ? (it.description || '') : '';
      // Opening a different work item always starts in read mode.
      this.editingDescription = false;
    },
    /** ⋯ → Edit, and the "add a description" affordance, both land here. */
    editDescription: function () {
      if (!this.canEdit) return;
      this.draft.description = this.drawerItem ? (this.drawerItem.description || '') : '';
      this.editingDescription = true;
    },
    finishEditingDescription: async function () {
      await this.saveDescription();
      this.editingDescription = false;
    },
    /** Save the title if it actually changed; an emptied title is refused, not sent. */
    saveTitle: function () {
      var it = this.drawerItem;
      var next = (this.draft.title || '').trim();
      if (!it || !this.canEdit) return;
      if (!next) { this.draft.title = it.title; return; }
      if (next === it.title) return;
      this.patchItem(it, { title: next }, false, 'Title updated.');
    },
    saveDescription: async function () {
      var it = this.drawerItem;
      if (!it || !this.canEdit) return;
      var next = this.draft.description || '';
      if (next === (it.description || '')) return;
      await this.patchItem(it, { description: next }, false, 'Description updated.');
    },
    /** One line of the audit feed, phrased from the stored display values (§6). */
    activityLine: function (a) {
      var who = a.actor ? a.actor.name : 'Someone';
      var when = this.fmtDateTime(a.created_at);
      var meta = a.meta || {};
      var text;

      if (a.event === 'created') {
        text = 'created this work item';
      } else if (a.field === 'state') {
        text = 'changed state to ' + (meta.new_label || 'none');
      } else if (a.field === 'parent') {
        text = meta.new_label ? 'set parent to ' + meta.new_label : 'removed the parent';
      } else if (a.field === 'assignees' || a.field === 'labels') {
        var names = (meta.new_labels || []).join(', ');
        text = 'set ' + a.field + ' to ' + (names || 'none');
      } else if (a.field === 'description') {
        text = a.new_value ? 'updated the description' : 'cleared the description';
      } else if (a.field === 'archived_at') {
        text = a.new_value ? 'archived this work item' : 'restored this work item';
      } else if (a.field === 'title') {
        text = 'renamed this work item to "' + a.new_value + '"';
      } else if (a.field === 'priority') {
        // The stored value is the key ('medium'); the feed should read like the chip does.
        text = 'changed priority to ' + this.priorityMeta(a.new_value).label;
      } else if (a.field === 'start_date' || a.field === 'due_date') {
        var label = a.field === 'start_date' ? 'start date' : 'due date';
        text = a.new_value ? 'set the ' + label + ' to ' + this.fmtDate(a.new_value) : 'cleared the ' + label;
      } else {
        text = 'changed ' + String(a.field || 'a property').replace(/_/g, ' ') +
          ' to ' + (a.new_value || 'none');
      }

      return { id: a.id, who: who, initial: a.actor ? a.actor.initial : '?', text: text, when: when };
    },
    fmtDateTime: function (iso) {
      if (!iso) return '';
      var d = new Date(iso);
      if (isNaN(d)) return '';
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
        ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    },

    // ---------- Row action menu (§4.4) ----------
    itemUrl: function (item) {
      return this.endpoints.item ? this.$pb.withId(this.endpoints.item, item.id) : '';
    },
    rowEdit: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it) return;
      // From the grid this opens the item first; from the panel's own ⋯ it is already open.
      if (!this.drawer.open || String(this.drawer.id) !== String(it.id)) this.openDrawer(it);
      this.editDescription();
    },
    rowCopy: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it || !this.endpoints.duplicate) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.duplicate, it.id), { method: 'POST' });
        this.items.push(resp.item);
        this.refreshTable();
        this.$pb.toast(resp.message || 'Work item copied.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    rowOpenTab: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (it) window.open(this.itemUrl(it), '_blank', 'noopener');
    },
    rowCopyLink: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it) return;
      var url = new URL(this.itemUrl(it), window.location.origin).href;
      try {
        await navigator.clipboard.writeText(url);
        this.$pb.toast('Link copied.');
      } catch (e) { window.prompt('Copy this link', url); }
    },
    rowArchive: async function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (!it || !this.endpoints.archive) return;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.archive, it.id), { method: 'POST' });
        // Archived items leave the active list but keep their data (§4.4).
        this.removeItem(it);
        this.$pb.toast(resp.message || 'Work item archived.');
      } catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    rowAskDelete: function () {
      var it = this.rowMenu.item;
      this.closeRowMenu();
      if (it) this.deleteConfirm = { open: true, item: it, busy: false };
    },
    rowDelete: async function () {
      var it = this.deleteConfirm.item;
      if (!it || this.deleteConfirm.busy) return;
      this.deleteConfirm.busy = true;
      try {
        var resp = await this.$pb.api(this.$pb.withId(this.endpoints.destroy, it.id), { method: 'DELETE' });
        this.removeItem(it);
        this.deleteConfirm = { open: false, item: null, busy: false };
        this.$pb.toast(resp.message || 'Work item deleted.');
      } catch (e) {
        this.deleteConfirm.busy = false;
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },

    // ---------- Create modal ----------
    openCreate: function (stateKey, parentId) {
      if (!this.canCreate) return;
      // Remember what this modal was opened with so "Create more" can return to it. That
      // includes the parent: creating several sub-tasks in a row should keep creating them
      // under the same work item (§21).
      this.seedStateId = (stateKey && stateKey !== WI_NO_STATE ? stateKey : this.defaultStateId) || '';
      this.seedParentId = parentId || '';
      this.resetForm();
      this.open = true;
      var self = this;
      this.$nextTick(function () { if (self.$refs.titleInput) self.$refs.titleInput.focus(); });
    },
    /** Clear the form and every transient bit of modal state back to the opening seed. */
    resetForm: function () {
      this.form = wiBlankForm(this.seedStateId);
      this.form.parent_id = this.seedParentId || '';
      this.errors = {};
      this.menu = ''; this.parentOpen = false;
      this.memberQuery = ''; this.labelQuery = ''; this.parentQuery = '';
    },
    closeCreate: function () { this.open = false; this.menu = ''; this.parentOpen = false; },
    toggleMenu: function (name) { this.menu = this.menu === name ? '' : name; },
    // The calendar component owns its own view state, so opening is just a menu toggle.
    onDatePick: function (field, iso) { this.form[field] = iso; this.menu = ''; },
    onDateClear: function (field) { this.form[field] = ''; this.menu = ''; },
    // ---------- Parent search panel ----------
    openParent: function () {
      this.menu = '';
      this.parentQuery = '';
      this.parentOpen = true;
      var self = this;
      this.$nextTick(function () { if (self.$refs.parentSearch) self.$refs.parentSearch.focus(); });
    },
    closeParent: function () { this.parentOpen = false; },
    pickParent: function (item) {
      this.form.parent_id = item ? item.id : '';
      this.closeParent();
    },
    /** Create modal: one assignee, same rule as the row/drawer picker (§4.3, revised). */
    toggleAssignee: function (m) {
      this.form.assignee_ids = this.isAssigned(m) ? [] : [m.id];
      this.menu = '';
    },
    toggleLabel: function (l) {
      var ids = this.form.label_ids.map(String);
      var i = ids.indexOf(String(l.id));
      if (i > -1) this.form.label_ids.splice(i, 1);
      else this.form.label_ids.push(l.id);
    },
    isAssigned: function (m) { return this.form.assignee_ids.map(String).indexOf(String(m.id)) > -1; },
    isLabelled: function (l) { return this.form.label_ids.map(String).indexOf(String(l.id)) > -1; },
    save: async function () {
      if (this.saving || !this.form.title.trim()) return;
      this.saving = true; this.errors = {};
      try {
        var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: this.form });
        this.items.push(resp.item);
        this.refreshTable();
        this.$pb.toast('Work item created.');

        // Created as a sub-task of the work item currently open — its Sub-tasks section has
        // to show it without waiting for a reload.
        if (this.drawerItem && resp.item && String(resp.item.parent_id) === String(this.drawerItem.id)) {
          this.loadStructure();
        }
        if (this.createMore) {
          // "Create more" keeps the modal open, but the next work item starts CLEAN: every
          // chip returns to the state the modal was opened in, so nothing carries over from
          // the item just saved. `seedStateId` — not the last-used state — is what we reset
          // to, so a modal opened from a state group's "+" keeps creating in that group
          // while a manual change to State, Priority, dates, assignees, labels or parent is
          // discarded along with the title.
          this.resetForm();
          var self = this;
          this.$nextTick(function () { if (self.$refs.titleInput) self.$refs.titleInput.focus(); });
        } else {
          this.closeCreate();
        }
      } catch (e) {
        this.errors = this.$pb.fieldErrors(e);
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    },
    /** The round event badge shown at the start of a feed row (§6.4). */
    eventIcon: function (entry) {
      return wiEventSvg(WI_EVENT_ICON[wiEventKind(entry)] || WI_EVENT_ICON.text, 15);
    },
    /** The small icon that precedes a before/after value in History. */
    valueIcon: function (entry) {
      var kind = wiEventKind(entry);
      var inline = ['state', 'priority', 'assignees', 'date', 'labels'];
      return inline.indexOf(kind) > -1 ? wiEventSvg(WI_EVENT_ICON[kind], 12) : '';
    },
    /** Vue-side counterpart of wiAvatar: templates bind to these two fields. */
    avatarOf: function (person) {
      return person && person.avatar_url ? person.avatar_url : '';
    },
    fmtDate: function (d) { return wiFmtDate(d); },
    stateIcon: function (s) { return wiStateIcon(s); },
    priorityMeta: function (key) { return WI_PRI[key] || WI_PRI.none; }
  },
  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ===== List (hidden on the per-item page, where the detail IS the page) =====
    '<template v-if="!pageMode">' +

    // ===== Toolbar =====
    '<div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line shrink-0">' +
    '<span class="flex items-center gap-2 text-[13px] font-medium text-ink shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>' +
    'Work items <span class="text-[11px] font-semibold text-sub bg-hover rounded-full px-1.5 py-0.5">{{ totalCount }}</span></span>' +
    '<div class="ml-auto flex items-center gap-1.5">' +
    '<button v-if="canCreate" type="button" @click="openCreate(\'\')" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add work item</button>' +
    '</div></div>' +

    // ===== Grid (desktop) =====
    '<div v-show="items.length" ref="grid" id="wi-table" class="flex-1 min-h-0 hidden sm:block"></div>' +

    // ===== Cards (mobile) — same data, grouped by state =====
    '<div class="sm:hidden flex-1 overflow-y-auto">' +
    '<template v-for="s in states" :key="s.id">' +
    '<div v-if="items.filter(i => i.state_id === s.id).length" class="flex items-center gap-2 h-9 px-4 border-b border-line" style="background:#f6f7f8">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span>' +
    '<span class="text-[13px] font-semibold text-head">{{ s.name }}</span>' +
    '<span class="text-[11px] font-semibold rounded-full px-1.5 py-0.5 text-sub bg-hover">{{ items.filter(i => i.state_id === s.id).length }}</span>' +
    '<button v-if="canCreate" type="button" @click="openCreate(String(s.id))" class="ml-auto h-6 w-6 grid place-items-center rounded text-sub hover:bg-line" title="Add work item"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '</div>' +
    '<div v-for="i in items.filter(i => i.state_id === s.id)" :key="i.id" @click="openDrawer(i)" class="border-b border-line px-4 py-3">' +
    '<div class="text-[12px] text-sub">{{ i.identifier }}</div>' +
    '<div class="flex items-center gap-2 mt-0.5">' +
    '<span v-if="i.blocked_by_count" class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-danger/30 bg-danger/5 text-[11px] font-semibold text-danger shrink-0">Blocked</span>' +
    '<span class="text-[14px] text-ink">{{ i.title }}</span></div>' +
    '<div class="flex flex-wrap items-center gap-1.5 mt-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] text-ink"><span class="grid place-items-center" v-html="stateIcon(i.state)"></span>{{ i.state ? i.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px]" :class="priorityMeta(i.priority).cls"><span class="grid place-items-center" v-html="priorityMeta(i.priority).icon"></span>{{ priorityMeta(i.priority).label }}</span>' +
    '</div></div>' +
    '</template>' +
    '<div v-if="!items.length" class="px-6 py-14 text-center text-sub text-[13px]">No work items yet.</div>' +
    '</div>' +

    // ===== Empty state (desktop) =====
    '<div v-if="!items.length" class="hidden sm:flex flex-col items-center text-center px-6 py-16">' +
    '<h2 class="text-[16px] font-bold text-head">No work items yet</h2>' +
    '<p class="text-[13px] text-sub mt-1.5 max-w-sm">Work items are the units of work in this project. Add the first one to get started.</p>' +
    '<button v-if="canCreate" type="button" @click="openCreate(\'\')" class="mt-5 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add work item</button>' +
    '</div>' +

    '</template>' +


    // ===== Detail view (§4.4) — a right-hand drawer over the list, or the whole page when
    // the per-item URL was opened. Same markup either way; `pageMode` swaps the framing. =====
    '<div v-if="drawer.open && drawerItem" :class="pageMode ? \'flex-1 min-h-0 flex flex-col\' : \'fixed inset-0 z-[85]\'">' +
    '<div v-if="!pageMode" class="absolute inset-0 bg-black/20" @click="closeDrawer"></div>' +
    '<aside :class="pageMode ? \'flex-1 min-h-0 flex flex-col bg-white\' : \'absolute right-0 top-0 h-full w-full sm:w-[80%] bg-white shadow-2xl flex flex-col\'">' +

    // ---- Toolbar ----
    '<div class="flex items-center gap-1 px-4 h-14 border-b border-line shrink-0">' +
    '<template v-if="!pageMode">' +
    '<button type="button" @click="closeDrawer" title="Close" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<a :href="itemUrl(drawerItem)" title="Open as full page" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 9V4h5M20 15v5h-5M15 4h5v5M9 20H4v-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></a>' +
    '</template>' +
    // On the item page the toolbar carries a breadcrumb back to the list instead.
    '<div v-else class="flex items-center gap-1.5">' +
    '<a :href="endpoints.list" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Work items</a>' +
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<span class="text-[13px] text-ink font-medium">{{ drawerItem.identifier }}</span>' +
    '</div>' +

    '<div class="ml-auto flex items-center gap-1.5">' +
    '<button type="button" @click="drawerCopyLink" title="Copy link" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 15l6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<button v-if="canEdit" type="button" @click="openRowMenu(\'menu\', drawerItem, $event.currentTarget)" title="More" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="19" cy="12" r="1.6" fill="currentColor"/></svg></button>' +
    '</div></div>' +

    // ---- Content: main column + properties ----
    '<div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden">' +

    '<div class="flex-1 min-w-0 px-6 sm:px-8 py-6 lg:overflow-y-auto">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[12px] text-sub">{{ drawerItem.identifier }}</span>' +
    '<span v-if="drawerItem.blocked_by_count" class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-danger/30 bg-danger/5 text-[11px] font-semibold text-danger">' +
    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/><path d="M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Blocked</span>' +
    '</div>' +
    '<input v-if="canEdit" v-model="draft.title" @blur="saveTitle" @keydown.enter.prevent="$event.target.blur()" ' +
    'class="w-full text-[22px] font-semibold text-head mt-1 bg-transparent outline-none rounded px-1 -ml-1 hover:bg-hover focus:bg-hover" />' +
    '<h1 v-else class="text-[22px] font-semibold text-head mt-1">{{ drawerItem.title }}</h1>' +

    '<div class="mt-4 border-b border-line"></div>' +

    // The editor is mounted only while editing (⋯ → Edit). Reading is the common case, and
    // an editor that is always there pays its start-up cost on every open.
    '<div v-if="editingDescription && canEdit" class="mt-5">' +
    '<wi-editor v-model="draft.description" min-height="180px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '<div class="flex justify-end gap-2 mt-2">' +
    '<button type="button" @click="editingDescription = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="finishEditingDescription" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Save</button>' +
    '</div></div>' +
    // Read view: the stored markup, which the server sanitized on the way in.
    '<div v-else-if="drawerItem.description" class="wi-rich text-[14px] text-ink leading-relaxed mt-5" v-html="drawerItem.description"></div>' +
    '<button v-else-if="canEdit" type="button" @click="editDescription" class="mt-5 text-[14px] text-sub hover:text-ink">Add a description…</button>' +
    '<p v-else class="text-[14px] text-sub mt-5">No description.</p>' +


    // ---- Actions + structure sections (§66) --------------------------------------------
    // ---- Action row (§66, styled after html/work-items.html): a labelled sub-task button,
    // then one connected group of icon actions. Each dropdown is a menu, not a direct write,
    // because every one of them needs a choice before anything can happen. ----
    '<div v-if="canEdit" class="flex flex-wrap items-center gap-2 mt-6">' +

    // Add sub-work item — §20's two routes.
    '<div class="relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sub\' ? \'\' : \'sub\'" class="inline-flex items-center gap-1.5 rounded-md shadow-sm h-9 px-3 text-[13px] text-ink ring-1 ring-inset ring-stroke hover:bg-hover">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.6"/><rect x="13" y="13" width="8" height="7" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 11v4a2 2 0 002 2H13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    'Add sub-work item</button>' +
    '<div v-if="addMenu === \'sub\'" class="absolute left-0 top-full mt-1 w-52 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="createSubtask" class="w-full text-left flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Create new</button>' +
    '<button type="button" @click="openPicker(\'subtask\', \'\', \'Add existing work item\')" class="w-full text-left flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-sub"><rect x="3" y="4" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.6"/><rect x="13" y="13" width="8" height="7" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 11v4a2 2 0 002 2H13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Add existing</button>' +
    '</div></div>' +

    // Connected icon group: dependency ▾ · relation ▾ · link · attachment · pages
    '<span class="isolate inline-flex rounded-md shadow-sm">' +

    '<span class="relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'dep\' ? \'\' : \'dep\'" title="Add dependency" class="relative inline-flex items-center gap-1 rounded-l-md h-9 pl-2.5 pr-1.5 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<div v-if="addMenu === \'dep\'" class="absolute left-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'blocked_by\', \'Blocked by\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocked by</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'blocking\', \'Blocking\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocking</button>' +
    '</div></span>' +

    '<span class="relative -ml-px" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'rel\' ? \'\' : \'rel\'" title="Add relation" class="relative inline-flex items-center gap-1 h-9 pl-2.5 pr-1.5 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="8.5" width="7" height="7" rx="2" stroke="currentColor" stroke-width="1.6"/><rect x="14" y="8.5" width="7" height="7" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M10 12h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<div v-if="addMenu === \'rel\'" class="absolute left-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'related\', \'Related to\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Related to</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'duplicate_of\', \'Duplicate of\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Duplicate of</button>' +
    '</div></span>' +

    '<button type="button" @click="openLinkModal(null)" title="Add link" class="relative -ml-px inline-flex items-center justify-center h-9 w-9 text-sub ring-1 ring-inset ring-stroke hover:bg-hover focus:z-10">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M9 15l6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +

    // Attachments and Pages are drawn but inert until they ship (§42/§43) — a dead control
    // that silently does nothing is worse than one that says why.
    '<span title="Attachments — coming soon" class="relative -ml-px inline-flex items-center justify-center h-9 w-9 text-faint ring-1 ring-inset ring-stroke cursor-not-allowed">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M21 11l-9 9a5 5 0 01-7-7l9-9a3.5 3.5 0 015 5l-9 9a2 2 0 01-3-3l8-8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '<span title="Link pages — coming soon" class="relative -ml-px inline-flex items-center justify-center rounded-r-md h-9 w-9 text-faint ring-1 ring-inset ring-stroke cursor-not-allowed">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M8 3h6l4 4v13a1 1 0 01-1 1H8a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3v4h4M9.5 12h5M9.5 15.5h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +

    '</span></div>' +

    // ---- Structure sections (§23/§27/§33/§40) ------------------------------------------
    // One card, one block per kind. Each block: a collapsible header carrying its own count
    // and "+" action, then — for dependencies and relations — a tinted banner naming the
    // direction, because "TESTI-5" under "Dependencies" is meaningless without knowing which
    // way it points.
    '<div v-if="hasStructure()" class="mt-6 rounded-lg border border-line divide-y divide-line overflow-hidden">' +

    // ===== Sub-work items =====
    '<div v-if="structure.subtasks.items.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'subtasks\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="transition-transform" :class="secOpen.subtasks ? \'\' : \'-rotate-90\'"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<span class="text-[13px] font-semibold text-head">Sub-work items</span>' +
    '<span class="text-[12px] text-sub">{{ structure.subtasks.items.length }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-5 pl-1 pr-2 rounded-full border border-line text-[11px] text-sub" :title="structure.subtasks.progress.percent + \'% complete\'">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" :stroke="structure.subtasks.progress.percent === 100 ? \'#22c55e\' : \'#9ca3af\'" stroke-width="2.5"/></svg>' +
    '{{ structure.subtasks.progress.completed }}/{{ structure.subtasks.progress.total }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-sub\' ? \'\' : \'sec-sub\'" title="Add sub-work item" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>' +
    '<div v-if="addMenu === \'sec-sub\'" class="absolute right-0 top-full mt-1 w-52 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="createSubtask" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Create new</button>' +
    '<button type="button" @click="openPicker(\'subtask\', \'\', \'Add existing work item\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Add existing</button>' +
    '</div></div></div>' +
    '<ul v-show="secOpen.subtasks" class="mt-1">' +
    '<li v-for="row in structure.subtasks.items" :key="row.id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<span v-if="row.due_date" class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink">{{ fmtDate(row.due_date) }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" title="Unassigned">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>' +
    '<button type="button" @click="openStructMenu(\'subtask\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" title="More">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/></svg></button>' +
    '</span></li></ul></div>' +

    // ===== Dependencies =====
    '<div v-if="structure.dependencies.blocked_by.length || structure.dependencies.blocking.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'dependencies\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="transition-transform" :class="secOpen.dependencies ? \'\' : \'-rotate-90\'"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<span class="text-[13px] font-semibold text-head">Dependencies</span>' +
    '<span class="text-[12px] text-sub">{{ structure.dependencies.blocked_by.length + structure.dependencies.blocking.length }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-dep\' ? \'\' : \'sec-dep\'" title="Add dependency" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>' +
    '<div v-if="addMenu === \'sec-dep\'" class="absolute right-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'blocked_by\', \'Blocked by\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocked by</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'blocking\', \'Blocking\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Blocking</button>' +
    '</div></div></div>' +
    '<div v-show="secOpen.dependencies" class="mt-1 space-y-1">' +
    '<template v-for="g in depGroups" :key="g.key">' +
    '<div v-if="structure.dependencies[g.key].length">' +
    '<div class="flex items-center gap-2 h-8 px-2.5 rounded-md text-[12px]" :class="g.cls">' +
    '<span class="grid place-items-center" v-html="g.icon"></span>{{ g.label }}</div>' +
    '<ul>' +
    '<li v-for="row in structure.dependencies[g.key]" :key="row.relation_id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" title="Unassigned">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>' +
    '<button type="button" @click="openStructMenu(\'relation\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" title="More">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/></svg></button>' +
    '</span></li></ul></div></template></div></div>' +

    // ===== Relations =====
    '<div v-if="structure.relations.related.length || structure.relations.duplicate_of.length || structure.relations.duplicated_by.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'relations\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="transition-transform" :class="secOpen.relations ? \'\' : \'-rotate-90\'"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<span class="text-[13px] font-semibold text-head">Relations</span>' +
    '<span class="text-[12px] text-sub">{{ structure.relations.related.length + structure.relations.duplicate_of.length + structure.relations.duplicated_by.length }}</span>' +
    '<div v-if="canEdit" class="ml-auto relative" data-add-menu>' +
    '<button type="button" @click="addMenu = addMenu === \'sec-rel\' ? \'\' : \'sec-rel\'" title="Add relation" class="h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>' +
    '<div v-if="addMenu === \'sec-rel\'" class="absolute right-0 top-full mt-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-[90]">' +
    '<button type="button" @click="openPicker(\'relation\', \'related\', \'Related to\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Related to</button>' +
    '<button type="button" @click="openPicker(\'relation\', \'duplicate_of\', \'Duplicate of\')" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Duplicate of</button>' +
    '</div></div></div>' +
    '<div v-show="secOpen.relations" class="mt-1 space-y-1">' +
    '<template v-for="g in relGroups" :key="g.key">' +
    '<div v-if="structure.relations[g.key].length">' +
    '<div class="flex items-center gap-2 h-8 px-2.5 rounded-md text-[12px] text-sub bg-hover">' +
    '<span class="grid place-items-center" v-html="g.icon"></span>{{ g.label }}</div>' +
    '<ul>' +
    '<li v-for="row in structure.relations[g.key]" :key="row.relation_id" class="flex items-center gap-2.5 px-1 h-11 rounded hover:bg-hover">' +
    '<a :href="rowUrl(row)" class="text-[12px] text-sub shrink-0 hover:underline">{{ row.identifier }}</a>' +
    '<a :href="rowUrl(row)" class="text-[13px] text-ink truncate hover:underline">{{ row.title }}</a>' +
    '<span class="ml-auto flex items-center gap-1.5 shrink-0">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px] text-ink"><span class="grid place-items-center" v-html="stateIcon(row.state)"></span>{{ row.state ? row.state.name : \'No state\' }}</span>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-white text-[12px]" :class="priorityMeta(row.priority).cls"><span class="grid place-items-center" v-html="priorityMeta(row.priority).icon"></span>{{ priorityMeta(row.priority).label }}</span>' +
    '<wi-avatar v-if="row.assignees.length" :person="row.assignees[0]" :size="24" />' +
    '<span v-else class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint" title="Unassigned">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>' +
    '<button type="button" @click="openStructMenu(\'relation\', row, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line" title="More">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/></svg></button>' +
    '</span></li></ul></div></template></div></div>' +

    // ===== Links =====
    '<div v-if="structure.links.length" class="p-3 sm:p-4">' +
    '<div class="flex items-center gap-2">' +
    '<button type="button" @click="toggleSection(\'links\')" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-hover shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="transition-transform" :class="secOpen.links ? \'\' : \'-rotate-90\'"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<span class="text-[13px] font-semibold text-head">Links</span>' +
    '<span class="text-[12px] text-sub">{{ structure.links.length }}</span>' +
    '<button v-if="canEdit" type="button" @click="openLinkModal(null)" title="Add link" class="ml-auto h-7 w-7 grid place-items-center rounded text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>' +
    '</div>' +
    '<ul v-show="secOpen.links" class="mt-1 space-y-1">' +
    '<li v-for="l in structure.links" :key="l.id" class="flex items-center gap-2.5 px-2.5 h-11 rounded-md border border-line">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-link shrink-0"><path d="M9 15l6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<a :href="l.url" target="_blank" rel="noopener noreferrer" class="text-[13px] text-ink truncate hover:underline">{{ l.label }}</a>' +
    '<span class="ml-auto text-[12px] text-faint shrink-0 hidden sm:inline">{{ relativeTime(l.created_at) }}</span>' +
    '<button type="button" @click="copyText(l.url)" title="Copy link" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line shrink-0">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M15 9V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7a2 2 0 002 2h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>' +
    '<button v-if="canEdit" type="button" @click="openStructMenu(\'link\', l, $event)" class="h-6 w-6 grid place-items-center rounded text-faint hover:bg-line shrink-0" title="More">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/></svg></button>' +
    '</li></ul></div>' +

    '</div>' +

    // ---- Structure row ⋯ menu ----
    '<div v-if="structMenu.open" class="fixed inset-0 z-[110]" @click="closeStructMenu"></div>' +
    '<div v-if="structMenu.open" :style="structMenu.style" class="rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">' +
    '<button type="button" @click="structOpen" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">{{ structMenu.kind === \'link\' ? \'Open link\' : \'Open work item\' }}</button>' +
    '<button type="button" @click="structCopy" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Copy link</button>' +
    '<button v-if="structMenu.kind === \'link\'" type="button" @click="openLinkModal(structMenu.row); closeStructMenu();" class="w-full text-left px-3 h-9 text-[13px] text-ink hover:bg-hover">Edit</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button v-if="canEdit" type="button" @click="structRemove" class="w-full text-left px-3 h-9 text-[13px] text-danger hover:bg-hover">' +
    '{{ structMenu.kind === \'subtask\' ? \'Remove from parent\' : (structMenu.kind === \'link\' ? \'Delete link\' : \'Remove relation\') }}</button>' +
    '</div>' +

    // ---- Collaboration: All | Activity | Comments | Updates | Worklogs | Transition |
    // History (§4-§11). Seven views of one dataset — the server decides what belongs in
    // each, so the tabs cannot drift apart here. ----
    '<div class="mt-8 border-t border-line pt-4">' +

    // overflow-y-hidden matters: `overflow-x-auto` alone makes the Y axis `auto` too, and the
    // tabs' -mb-px puts content a pixel past the box — enough for a vertical scrollbar to
    // appear down the right-hand edge of the strip. `wi-tabs` hides the horizontal bar's
    // chrome while keeping the strip scrollable on narrow screens (§4.5).
    '<div role="tablist" class="wi-tabs flex items-center gap-4 border-b border-line overflow-x-auto overflow-y-hidden">' +
    '<button v-for="t in tabList()" :key="t.key" role="tab" :aria-selected="tab === t.key" @click="setTab(t.key)" ' +
    'class="shrink-0 h-9 text-[13px] border-b-2 -mb-px transition-colors" ' +
    ':class="tab === t.key ? \'border-brand text-ink font-semibold\' : \'border-transparent text-sub hover:text-ink\'">{{ t.label }}</button>' +
    '<span v-if="tab === \'worklogs\' && feed" class="ml-auto shrink-0 text-[12px] text-sub pb-2">Tracked time: <span class="font-semibold text-ink">{{ feed.worklogs.total_label }}</span></span>' +
    '</div>' +

    '<div v-if="feedLoading" class="py-6 space-y-3">' +
    '<div v-for="n in 3" :key="n" class="h-4 rounded bg-hover animate-pulse" :style="{width: (60 + n * 10) + \'%\'}"></div>' +
    '</div>' +

    '<div v-else-if="feed" class="pt-4">' +

    // ===== Comment composer — on All and Comments (§5.3/§7.3) =====
    '<div v-if="canEdit && (tab === \'all\' || tab === \'comments\')" class="mb-5">' +
    '<wi-editor v-model="composer.content" placeholder="Add comment" min-height="90px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '<div class="flex items-center mt-2">' +
    '<button type="button" @click="postComment" :disabled="composer.busy || !hasText(composer.content)" ' +
    'class="ml-auto h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Comment</button>' +
    '</div></div>' +

    // ===== All (§5) =====
    '<ul v-if="tab === \'all\'" class="space-y-4">' +
    '<li v-for="e in feed.all" :key="e.kind + \'-\' + e.id" class="flex items-start gap-2.5">' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(e)"></span>' +
    '<div class="min-w-0 flex-1">' +
    // A comment is somebody talking, so it keeps its card and its author's face; the badge
    // beside it just says "this row is a comment".
    '<template v-if="e.kind === \'comment\'">' +
    '<div class="rounded-lg border border-line bg-white p-3">' +
    '<div class="flex items-center gap-2">' +
    '<wi-avatar :person="e.author" :size="22" />' +
    '<span class="text-[13px] font-medium text-ink">{{ e.author ? e.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint">{{ relativeTime(e.created_at) }}</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="e.content"></div></div>' +
    '</template>' +
    '<template v-else-if="e.kind === \'update\'">' +
    '<div class="text-[13px]"><span class="font-medium text-ink">{{ e.author ? e.author.name : \'Someone\' }}</span> <span class="text-sub">posted an update</span> ' +
    '<span class="inline-flex items-center gap-1 h-5 px-1.5 rounded border text-[11px] font-semibold" :class="updateMeta(e.status).cls"><span v-html="updateMeta(e.status).icon"></span>{{ e.status_label }}</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1" v-html="e.content"></div>' +
    '</template>' +
    '<template v-else-if="e.kind === \'worklog\'">' +
    '<div class="text-[13px]"><span class="font-medium text-ink">{{ e.user ? e.user.name : \'Someone\' }}</span> <span class="text-sub">logged {{ e.duration }}</span></div>' +
    '<div v-if="e.description" class="text-[13px] text-sub mt-0.5">{{ e.description }}</div>' +
    '</template>' +
    '<div v-else class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(e).who }}</span> {{ activityLine(e).text }}</div>' +
    '<div v-if="e.kind !== \'comment\'" class="text-[12px] text-faint mt-0.5">{{ relativeTime(e.created_at) }}</div>' +
    '</div></li>' +
    '<li v-if="!feed.all.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No activity yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Activity and conversations will appear here.</div></li>' +
    '</ul>' +

    // ===== Activity (§6) =====
    '<ul v-else-if="tab === \'activity\'" class="space-y-3">' +
    '<li v-for="a in feed.activity" :key="a.id" class="flex items-start gap-2.5">' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(a)"></span>' +
    '<div class="min-w-0"><div class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(a).who }}</span> {{ activityLine(a).text }}</div>' +
    '<div class="text-[12px] text-faint">{{ relativeTime(a.created_at) }}</div></div></li>' +
    '<li v-if="!feed.activity.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No activity yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Changes to this work item will appear here.</div></li>' +
    '</ul>' +

    // ===== Comments (§7) =====
    // Each comment is a card: it separates one person's contribution from the next, which a
    // flat list of paragraphs does not. Actions are small icon buttons — they repeat on every
    // comment, and three words each turned the header into a wall of links — each carrying a
    // title and aria-label so an icon-only control still has an accessible name (§24).
    '<ul v-else-if="tab === \'comments\'" class="space-y-3">' +
    '<li v-for="c in feed.comments" :key="c.id" class="rounded-lg border border-line bg-white">' +

    '<div class="flex items-start gap-2.5 p-3">' +
    '<wi-avatar :person="c.author" :size="28" />' +
    '<div class="min-w-0 flex-1">' +
    '<div class="flex items-center gap-2">' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ c.author ? c.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint shrink-0">{{ relativeTime(c.created_at) }}</span>' +
    '<span v-if="c.edited" class="text-[11px] text-faint shrink-0">(edited)</span>' +
    '<span class="ml-auto flex items-center gap-0.5 shrink-0">' +
    '<button v-if="canEdit" type="button" @click="startReply(c)" title="Reply" aria-label="Reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 14l-5-5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9h9a7 7 0 010 14h-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<button v-if="canEditComment(c)" type="button" @click="startEditComment(c)" title="Edit" aria-label="Edit comment" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10-10-4-4L4 16v4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg></button>' +
    '<button v-if="canEdit" type="button" @click="deleteComment(c)" title="Delete" aria-label="Delete comment" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-danger hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 7h14M10 4h4M9 7l.8 12a1 1 0 001 1h4.4a1 1 0 001-1L17 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="c.content"></div>' +
    '</div></div>' +

    // Replies live inside the parent's card, so a thread reads as one block (§7.8).
    '<ul v-if="c.replies.length" class="border-t border-line bg-[#fafbfc] rounded-b-lg divide-y divide-line">' +
    '<li v-for="r in c.replies" :key="r.id" class="flex items-start gap-2.5 p-3 pl-6">' +
    '<wi-avatar :person="r.author" :size="24" />' +
    '<div class="min-w-0 flex-1"><div class="flex items-center gap-2">' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ r.author ? r.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint shrink-0">{{ relativeTime(r.created_at) }}</span>' +
    '<span v-if="r.edited" class="text-[11px] text-faint shrink-0">(edited)</span>' +
    '<span class="ml-auto flex items-center gap-0.5 shrink-0">' +
    '<button v-if="canEditComment(r)" type="button" @click="startEditComment(r)" title="Edit" aria-label="Edit reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-ink hover:bg-hover">' +
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10-10-4-4L4 16v4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg></button>' +
    '<button v-if="canEdit" type="button" @click="deleteComment(r)" title="Delete" aria-label="Delete reply" ' +
    'class="h-6 w-6 grid place-items-center rounded text-faint hover:text-danger hover:bg-hover">' +
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M5 7h14M10 4h4M9 7l.8 12a1 1 0 001 1h4.4a1 1 0 001-1L17 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '</span></div>' +
    '<div class="wi-rich text-[13px] text-ink mt-1.5" v-html="r.content"></div></div></li></ul>' +

    '</li>' +
    '<li v-if="!feed.comments.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No comments yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Start the conversation by adding a comment.</div></li>' +
    '</ul>' +

    // ===== Updates (§8) =====
    '<div v-else-if="tab === \'updates\'">' +
    '<div v-if="canEdit" class="flex justify-end mb-3">' +
    '<button type="button" @click="openUpdateForm(null)" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add update</button></div>' +

    '<div v-if="updateForm.open" class="mb-4 rounded-lg border border-line p-3">' +
    '<div class="flex items-center gap-2 mb-2">' +
    '<button v-for="st in [\'on_track\',\'at_risk\',\'off_track\']" :key="st" type="button" @click="updateForm.status = st" ' +
    'class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border text-[12px] font-semibold" ' +
    ':class="updateForm.status === st ? updateMeta(st).cls : \'border-line text-sub\'">' +
    '<span v-html="updateMeta(st).icon"></span>{{ updateMeta(st).label }}</button></div>' +
    '<wi-editor v-model="updateForm.content" placeholder="Add an update…" min-height="90px" class="block" />' +
    '<div class="flex justify-end gap-2 mt-2">' +
    '<button type="button" @click="updateForm.open = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveUpdate" :disabled="updateForm.busy" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ updateForm.id ? \'Save\' : \'Add update\' }}</button></div></div>' +

    '<ul class="space-y-3">' +
    '<li v-for="u in feed.updates" :key="u.id" class="rounded-lg border border-line p-3">' +
    '<div class="flex items-center gap-2">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border text-[12px] font-semibold" :class="updateMeta(u.status).cls">' +
    '<span v-html="updateMeta(u.status).icon"></span>{{ u.status_label }}</span>' +
    '<span class="text-[12px] text-sub">{{ relativeTime(u.created_at) }} · {{ u.author ? u.author.name : \'Someone\' }}</span>' +
    '<span v-if="u.edited" class="text-[11px] text-faint">(edited)</span>' +
    '<span v-if="canEdit" class="ml-auto flex items-center gap-2">' +
    '<button type="button" @click="openUpdateForm(u)" class="text-[12px] text-sub hover:underline">Edit</button>' +
    '<button type="button" @click="deleteUpdate(u)" class="text-[12px] text-danger hover:underline">Delete</button></span>' +
    '</div>' +
    '<div class="wi-rich text-[13px] text-ink mt-2" v-html="u.content"></div>' +
    '<div v-if="u.progress" class="flex items-center gap-2 mt-2 text-[12px] text-sub">' +
    '<span class="h-1.5 w-24 rounded-full bg-line overflow-hidden"><span class="block h-full bg-brand" :style="{width: u.progress.percent + \'%\'}"></span></span>' +
    'Progress {{ u.progress.percent }}% · {{ u.progress.completed }} / {{ u.progress.total }} done</div>' +
    '</li>' +
    '<li v-if="!feed.updates.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No updates yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Share the latest status of this work item.</div></li>' +
    '</ul></div>' +

    // ===== Worklogs (§9) =====
    '<div v-else-if="tab === \'worklogs\'">' +
    '<div v-if="canEdit" class="flex justify-end mb-3">' +
    '<button type="button" @click="openWorklogForm(null)" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Log work</button></div>' +

    '<div v-if="worklogForm.open" class="mb-4 rounded-lg border border-line p-3">' +
    '<div v-if="worklogForm.error" class="mb-2 rounded-md border border-danger/40 bg-danger/5 px-3 py-2 text-[12px] text-danger">{{ worklogForm.error }}</div>' +
    '<div class="flex flex-wrap items-end gap-3">' +
    '<div><label class="block text-[12px] text-sub mb-1">Date</label><input v-model="worklogForm.date" type="date" class="pb-input h-9 w-40" /></div>' +
    '<div><label class="block text-[12px] text-sub mb-1">Hours</label><input v-model="worklogForm.hours" type="number" min="0" max="99" class="pb-input h-9 w-20" /></div>' +
    '<div><label class="block text-[12px] text-sub mb-1">Minutes</label><input v-model="worklogForm.minutes" type="number" min="0" max="59" class="pb-input h-9 w-20" /></div>' +
    '<div class="flex-1 min-w-[180px]"><label class="block text-[12px] text-sub mb-1">Description</label>' +
    '<input v-model="worklogForm.description" type="text" placeholder="What did you complete?" class="pb-input h-9" /></div>' +
    '</div>' +
    '<div class="flex justify-end gap-2 mt-3">' +
    '<button type="button" @click="worklogForm.open = false" class="h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveWorklog" :disabled="worklogForm.busy" class="h-8 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Save</button></div></div>' +

    '<ul class="space-y-2">' +
    '<li v-for="w in feed.worklogs.entries" :key="w.id" class="flex items-start gap-2.5 rounded-lg border border-line p-3">' +
    '<wi-avatar :person="w.user" :size="28" />' +
    '<div class="min-w-0 flex-1"><div class="flex items-center gap-2">' +
    '<span class="text-[13px] text-ink"><span class="font-medium">{{ w.user ? w.user.name : \'Someone\' }}</span> logged <span class="font-semibold">{{ w.duration }}</span></span>' +
    '<span class="text-[12px] text-faint">{{ fmtDate(w.work_date) }}</span>' +
    '<span v-if="canEdit && String(w.user_id) === String(currentUserId)" class="ml-auto flex items-center gap-2">' +
    '<button type="button" @click="openWorklogForm(w)" class="text-[12px] text-sub hover:underline">Edit</button>' +
    '<button type="button" @click="deleteWorklog(w)" class="text-[12px] text-danger hover:underline">Delete</button></span></div>' +
    '<div v-if="w.description" class="text-[13px] text-sub mt-1">{{ w.description }}</div></div></li>' +
    '<li v-if="!feed.worklogs.entries.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No work logged yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Track time spent working on this item.</div></li>' +
    '</ul></div>' +

    // ===== Transition (§10) =====
    '<ul v-else-if="tab === \'transition\'" class="space-y-3">' +
    '<li v-for="t in feed.transition" :key="t.id" class="flex items-start gap-2.5">' +
    '<wi-avatar :person="t.actor" :size="24" />' +
    '<div class="min-w-0"><div class="text-[13px] text-ink">' +
    '<span class="font-medium">{{ t.actor ? t.actor.name : \'Someone\' }}</span> set the state to {{ t.to || \'none\' }}.</div>' +
    '<div class="flex items-center gap-2 mt-1 text-[12px]">' +
    '<span v-if="t.from" class="inline-flex items-center h-5 px-1.5 rounded border border-line text-sub">{{ t.from }}</span>' +
    '<span v-if="t.from" class="text-faint">→</span>' +
    '<span class="inline-flex items-center h-5 px-1.5 rounded border border-line text-ink">{{ t.to || \'none\' }}</span>' +
    '<span class="text-faint">{{ t.is_current ? t.duration + \' so far\' : t.duration }}</span>' +
    '</div>' +
    '<div class="text-[12px] text-faint mt-0.5">{{ relativeTime(t.transitioned_at) }}</div></div></li>' +
    '<li v-if="!feed.transition.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No transitions yet</div>' +
    '<div class="text-[13px] text-sub mt-1">State changes will appear here.</div></li>' +
    '</ul>' +

    // ===== History (§11) =====
    '<ul v-else-if="tab === \'history\'" class="space-y-1">' +
    // A rail down the badges reads as one audit trail rather than a stack of unrelated rows.
    '<li v-for="h in feed.history" :key="h.id" class="relative flex items-start gap-2.5 pb-4 last:pb-0">' +
    '<span class="absolute left-[13px] top-8 bottom-0 w-px bg-line last:hidden"></span>' +
    '<span class="h-7 w-7 rounded-full border border-line bg-white grid place-items-center text-sub shrink-0" v-html="eventIcon(h)"></span>' +
    '<div class="min-w-0 pt-0.5">' +
    '<div class="text-[13px] text-ink"><span class="font-medium">{{ activityLine(h).who }}</span> {{ activityLine(h).text }} ' +
    '<span class="text-[12px] text-faint">· {{ relativeTime(h.created_at) }}</span></div>' +
    '<div class="flex items-center gap-2 mt-1.5 text-[12px]">' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line text-sub">' +
    '<span v-if="valueIcon(h)" class="grid place-items-center text-faint" v-html="valueIcon(h)"></span>' +
    '{{ h.meta && h.meta.old_label ? h.meta.old_label : (h.old_value || \'None\') }}</span>' +
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line text-ink">' +
    '<span v-if="valueIcon(h)" class="grid place-items-center text-faint" v-html="valueIcon(h)"></span>' +
    '{{ h.meta && h.meta.new_label ? h.meta.new_label : (h.new_value || \'None\') }}</span>' +
    '</div></div></li>' +
    '<li v-if="!feed.history.length" class="py-8 text-center"><div class="text-[13px] font-semibold text-head">No history yet</div>' +
    '<div class="text-[13px] text-sub mt-1">Changes to work item properties will appear here.</div></li>' +
    '</ul>' +

    '</div>' +

    // §16.3: a failed load must offer a way back, not an empty panel.
    '<div v-else class="py-8 text-center">' +
    '<div class="text-[13px] font-semibold text-head">Unable to load this work item\'s activity</div>' +
    '<button type="button" @click="loadFeed" class="mt-2 h-8 px-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Try again</button>' +
    '</div>' +

    '</div>' +

    '<div class="h-6"></div>' +
    '</div>' +

    // ---- Properties. Each control opens the SAME picker the grid row uses, so there is one
    //      implementation of "change a property" and one PATCH path behind it. ----
    '<aside class="w-full lg:w-[340px] shrink-0 border-t lg:border-t-0 lg:border-l border-line px-5 sm:px-6 py-6 lg:overflow-y-auto">' +
    '<h3 class="text-[15px] font-semibold text-head">Properties</h3>' +
    '<div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-4">' +

    '<div><div class="text-[12px] text-sub mb-1.5">State</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'state\', drawerItem, $event.currentTarget)" class="inline-flex items-center gap-1.5 text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">' +
    '<span class="grid place-items-center" v-html="stateIcon(drawerItem.state)"></span>{{ drawerItem.state ? drawerItem.state.name : \'No state\' }}</button></div>' +

    '<div><div class="text-[12px] text-sub mb-1.5">Priority</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'priority\', drawerItem, $event.currentTarget)" class="inline-flex items-center gap-1.5 text-[13px] rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="priorityMeta(drawerItem.priority).cls">' +
    '<span class="grid place-items-center" v-html="priorityMeta(drawerItem.priority).icon"></span>{{ priorityMeta(drawerItem.priority).label }}</button></div>' +

    '<div><div class="text-[12px] text-sub mb-1.5">Assignee</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'assignees\', drawerItem, $event.currentTarget)" class="inline-flex items-center gap-1.5 text-[13px] text-ink rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">' +
    '<template v-if="drawerItem.assignees && drawerItem.assignees.length">' +
    '<wi-avatar :person="drawerItem.assignees[0]" :size="20" />' +
    '{{ drawerItem.assignees[0].name }}</template>' +
    '<span v-else class="text-sub">Unassigned</span></button></div>' +

    '<div><div class="text-[12px] text-sub mb-1.5">Start date</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'start_date\', drawerItem, $event.currentTarget)" class="text-[13px] text-left rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="drawerItem.start_date ? \'text-ink\' : \'text-sub\'">' +
    '{{ drawerItem.start_date ? fmtDate(drawerItem.start_date) : \'None\' }}</button></div>' +

    '<div><div class="text-[12px] text-sub mb-1.5">Due date</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'due_date\', drawerItem, $event.currentTarget)" class="text-[13px] text-left rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover" :class="drawerItem.due_date ? \'text-ink\' : \'text-sub\'">' +
    '{{ drawerItem.due_date ? fmtDate(drawerItem.due_date) : \'None\' }}</button></div>' +
    '</div>' +

    '<div class="text-[13px] font-medium text-sub mt-6 mb-1">Details</div>' +
    '<div class="divide-y divide-line">' +
    '<div class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Parent</div>' +
    '<span class="text-[13px]" :class="drawerItem.parent_id ? \'text-ink\' : \'text-sub\'">{{ parentLabel(drawerItem) }}</span></div>' +
    '<div class="py-2.5"><div class="text-[12px] text-sub mb-1.5">Labels</div>' +
    '<button type="button" :disabled="!canEdit" @click="openRowMenu(\'labels\', drawerItem, $event.currentTarget)" class="flex flex-wrap items-center gap-1.5 text-left rounded px-1.5 py-0.5 -ml-1.5 hover:bg-hover">' +
    '<span v-for="l in drawerItem.labels" :key="l.id" class="inline-flex items-center gap-1 h-5 px-1.5 rounded border border-line text-[11px] text-ink">' +
    '<span class="h-2 w-2 rounded-full" :style="{background: l.color}"></span>{{ l.name }}</span>' +
    '<span v-if="!drawerItem.labels || !drawerItem.labels.length" class="text-[13px] text-sub">None</span></button></div>' +
    '</div>' +

    '<div class="mt-6 pt-4 border-t border-line text-[12px] text-sub space-y-1.5">' +
    '<div v-if="drawerItem.created_by">Created by {{ drawerItem.created_by }}</div>' +
    '<div v-if="drawerItem.created_at">Created on {{ fmtDateTime(drawerItem.created_at) }}</div>' +
    '<div v-if="drawerItem.updated_at">Updated on {{ fmtDateTime(drawerItem.updated_at) }}</div>' +
    '</div>' +
    '</aside>' +

    '</div></aside></div>' +



    // ===== Reply / edit a comment (§7.5/§7.8) =====
    // A modal rather than the composer at the top of the tab: both actions are ABOUT a
    // particular comment, which may be scrolled out of sight, so the one being answered is
    // quoted right above the editor.
    '<div v-if="commentModal.open" class="fixed inset-0 z-[102] flex items-start justify-center p-4 sm:pt-20">' +
    '<div class="absolute inset-0 bg-black/40" @click="closeCommentModal"></div>' +
    '<div class="relative w-full max-w-[640px] bg-white rounded-xl shadow-xl flex flex-col max-h-[85vh]">' +

    '<div class="flex items-center gap-3 px-5 py-4 border-b border-line shrink-0">' +
    '<h2 class="text-[15px] font-semibold text-head">{{ commentModal.mode === \'edit\' ? \'Edit comment\' : \'Reply to comment\' }}</h2>' +
    '<button type="button" @click="closeCommentModal" title="Close" aria-label="Close" ' +
    'class="ml-auto h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '</div>' +

    '<div class="px-5 py-4 overflow-y-auto">' +
    // What is being replied to, so the answer has its question in view.
    '<div v-if="commentModal.mode === \'reply\' && commentModal.target" class="mb-3 rounded-lg border border-line bg-[#fafbfc] p-3">' +
    '<div class="flex items-center gap-2">' +
    '<wi-avatar :person="commentModal.target.author" :size="22" />' +
    '<span class="text-[13px] font-medium text-ink">{{ commentModal.target.author ? commentModal.target.author.name : \'Someone\' }}</span>' +
    '<span class="text-[12px] text-faint">{{ relativeTime(commentModal.target.created_at) }}</span></div>' +
    '<div class="wi-rich text-[13px] text-sub mt-1.5 max-h-24 overflow-hidden" v-html="commentModal.target.content"></div>' +
    '</div>' +

    '<wi-editor v-model="commentModal.content" :placeholder="commentModal.mode === \'edit\' ? \'Edit your comment\' : \'Write a reply\'" ' +
    'min-height="120px" class="block" ' +
    ':media-upload="endpoints.mediaUpload" :media-gallery="endpoints.mediaGallery" :media-max-bytes="mediaMaxBytes" />' +
    '</div>' +

    '<div class="px-5 py-3 border-t border-line flex justify-end gap-2 shrink-0">' +
    '<button type="button" @click="closeCommentModal" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="submitCommentModal" :disabled="commentModal.busy || !hasText(commentModal.content)" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">' +
    '{{ commentModal.busy ? \'Saving…\' : (commentModal.mode === \'edit\' ? \'Save\' : \'Reply\') }}</button>' +
    '</div></div></div>' +

    // ===== Work item picker (§22 / §30 / §34) — one dialog for sub-tasks and every relation
    // type; only the title and what happens on Add differ. =====
    '<div v-if="picker.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @click="closePicker"></div>' +
    '<div class="relative w-full max-w-[640px] bg-white rounded-xl shadow-xl flex flex-col max-h-[75vh]">' +
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '<span class="text-[14px] font-semibold text-head shrink-0">{{ picker.title }}</span>' +
    '<input ref="pickerSearch" v-model="picker.query" @input="onPickerQuery" type="text" placeholder="Search by ID or title" ' +
    'class="flex-1 h-8 text-[14px] text-ink placeholder:text-faint outline-none bg-transparent" />' +
    '<button type="button" @click="closePicker" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Close">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '</div>' +

    // §57: this project by default, the whole workspace on request.
    '<label class="flex items-center gap-2 px-5 py-2 border-b border-line text-[12px] text-sub shrink-0">' +
    '<input type="checkbox" v-model="picker.allProjects" @change="searchItems" class="h-3.5 w-3.5 rounded border-stroke text-brand" />' +
    'Search all projects in this workspace</label>' +

    '<div class="p-2 overflow-y-auto">' +
    '<button v-for="row in picker.results" :key="row.id" type="button" @click="togglePick(row)" ' +
    'class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover" :class="isPicked(row) ? \'bg-sel/40\' : \'\'">' +
    '<span class="h-4 w-4 rounded border grid place-items-center shrink-0" :class="isPicked(row) ? \'bg-brand border-brand text-white\' : \'border-stroke\'">' +
    '<svg v-if="isPicked(row)" width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
    '<span class="grid place-items-center shrink-0" v-html="stateIcon(row.state)"></span>' +
    '<span class="text-[12px] text-sub shrink-0">{{ row.identifier }}</span>' +
    '<span class="text-[14px] text-ink truncate">{{ row.title }}</span>' +
    '<span v-if="picker.allProjects" class="text-[11px] text-faint shrink-0">{{ row.project }}</span>' +
    '<span class="ml-auto grid place-items-center shrink-0" v-html="priorityMeta(row.priority).icon"></span>' +
    '</button>' +
    '<div v-if="!picker.results.length" class="px-3 py-8 text-[13px] text-sub text-center">No work items found</div>' +
    '</div>' +

    '<div class="px-5 py-3 border-t border-line flex items-center gap-2 shrink-0">' +
    '<span class="text-[12px] text-sub">{{ picker.selected.length }} selected</span>' +
    '<button type="button" @click="closePicker" class="ml-auto h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="confirmPicker" :disabled="!picker.selected.length || picker.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">{{ picker.busy ? \'Adding…\' : \'Add\' }}</button>' +
    '</div></div></div>' +

    // ===== Add / edit link (§38) =====
    '<div v-if="linkModal.open" class="fixed inset-0 z-[95] flex items-start justify-center p-4 sm:pt-28">' +
    '<div class="absolute inset-0 bg-black/40" @click="linkModal.open = false"></div>' +
    '<div class="relative w-full max-w-[460px] bg-white rounded-xl shadow-xl">' +
    '<div class="px-5 py-4 border-b border-line">' +
    '<h2 class="text-[15px] font-semibold text-head">{{ linkModal.id ? \'Edit link\' : \'Add link\' }}</h2></div>' +
    '<div class="px-5 py-4 space-y-3">' +
    '<div v-if="linkModal.error" class="rounded-lg border border-danger/40 bg-danger/5 px-3 py-2 text-[13px] text-danger">{{ linkModal.error }}</div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">URL</label>' +
    '<input v-model="linkModal.url" type="url" placeholder="https://www.figma.com/…" class="pb-input" @keydown.enter.prevent="saveLink" /></div>' +
    '<div><label class="block text-[13px] font-medium text-ink mb-1.5">Title <span class="text-faint font-normal">(optional)</span></label>' +
    '<input v-model="linkModal.title" type="text" placeholder="Checkout UX design" class="pb-input" @keydown.enter.prevent="saveLink" /></div>' +
    '</div>' +
    '<div class="px-5 py-3 border-t border-line flex justify-end gap-2">' +
    '<button type="button" @click="linkModal.open = false" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>' +
    '<button type="button" @click="saveLink" :disabled="!linkModal.url.trim() || linkModal.busy" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">{{ linkModal.id ? \'Save\' : \'Add link\' }}</button>' +
    '</div></div></div>' +

    // ===== Row chip pickers + action menu (§4.2 / §4.4) =====
    // One shared, fixed-positioned popover so it escapes the grid's scroll container.
    '<div v-if="rowMenu.open" class="fixed inset-0 z-[110]" @click="closeRowMenu"></div>' +
    '<div v-if="rowMenu.open" :style="rowMenu.style" class="rounded-md bg-white shadow-lg outline outline-1 outline-black/5">' +

    // -- State --
    '<template v-if="rowMenu.kind===\'state\'">' +
    '<div class="py-1">' +
    '<button v-for="s in states" :key="s.id" type="button" @click="setRowState(s)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span><span class="flex-1 truncate">{{ s.name }}</span>' +
    '<svg v-if="rowMenu.item && rowMenu.item.state_id===s.id" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button></div></template>' +

    // -- Priority --
    '<template v-else-if="rowMenu.kind===\'priority\'">' +
    '<div class="py-1">' +
    '<button v-for="p in priorities" :key="p.key" type="button" @click="setRowPriority(p)" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="priorityMeta(p.key).icon"></span><span class="flex-1">{{ p.label }}</span>' +
    '<svg v-if="rowMenu.item && rowMenu.item.priority===p.key" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button></div></template>' +

    // -- Assignees (multi-select; stays open) --
    '<template v-else-if="rowMenu.kind===\'assignees\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search members..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="m in rowMembers()" :key="m.id" type="button" @click="toggleRowAssignee(m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<wi-avatar :person="m" :size="24" /><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<svg v-if="rowHasAssignee(m)" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!rowMembers().length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div></div></template>' +

    // -- Labels (multi-select; stays open) --
    '<template v-else-if="rowMenu.kind===\'labels\'">' +
    '<div class="p-2">' +
    '<input v-model="rowQuery" placeholder="Search labels..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-52 overflow-y-auto">' +
    '<button v-for="l in rowLabels()" :key="l.id" type="button" @click="toggleRowLabel(l)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: l.color}"></span><span class="flex-1 truncate">{{ l.name }}</span>' +
    '<svg v-if="rowHasLabel(l)" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!rowLabels().length" class="px-2 py-3 text-[13px] text-sub text-center">No labels configured for this project</div>' +
    '</div></div></template>' +

    // -- Dates: the same calendar the create modal uses, with the same ordering bounds --
    '<template v-else-if="rowMenu.kind===\'start_date\' || rowMenu.kind===\'due_date\'">' +
    '<wi-calendar class="!static !mb-0 !w-full !shadow-none !outline-none"' +
    ' :value="rowMenu.kind===\'start_date\' ? rowMenu.item.start_date : rowMenu.item.due_date"' +
    ' :after="rowMenu.kind===\'due_date\' ? rowMenu.item.start_date : null"' +
    ' :before="rowMenu.kind===\'start_date\' ? rowMenu.item.due_date : null"' +
    ' @pick="setRowDate" @clear="clearRowDate" />' +
    '</template>' +

    // -- Action menu (§4.4) --
    '<template v-else-if="rowMenu.kind===\'menu\'">' +
    '<div class="py-1 text-[13px]">' +
    '<button type="button" @click="rowEdit" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4 12.5-12.5z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Edit</button>' +
    '<button type="button" @click="rowCopy" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M5 15V5a2 2 0 012-2h10" stroke="currentColor" stroke-width="1.7"/></svg>Make a copy</button>' +
    '<button type="button" @click="rowOpenTab" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M14 4h6v6M20 4l-8 8M10 6H5a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Open in new tab</button>' +
    '<button type="button" @click="rowCopyLink" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M9 15l6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M10.5 6.5l1-1a3.5 3.5 0 015 5l-1 1M13.5 17.5l-1 1a3.5 3.5 0 01-5-5l1-1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Copy link</button>' +
    '<button type="button" @click="rowArchive" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-ink"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><rect x="3" y="4" width="18" height="5" rx="1.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 9v9a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Archive</button>' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="rowAskDelete" class="w-full text-left flex items-center gap-2.5 px-3 h-9 hover:bg-hover text-danger"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Delete</button>' +
    '</div></template>' +

    '</div>' +

    // ===== Delete confirmation (§4.4: delete is permanent) =====
    '<pb-modal :open="deleteConfirm.open" title="Delete work item?" @close="deleteConfirm.open=false">' +
    '<p class="text-[13px] text-sub leading-relaxed">This permanently deletes <span class="font-semibold text-ink">{{ deleteConfirm.item ? deleteConfirm.item.identifier : \'\' }}</span> and everything on it. This cannot be undone.</p>' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="deleteConfirm.open=false">Cancel</button>' +
    '<button type="button" :disabled="deleteConfirm.busy" @click="rowDelete" class="h-9 px-4 rounded-md bg-danger text-white text-[13px] font-semibold disabled:opacity-50">{{ deleteConfirm.busy ? \'Deleting…\' : \'Delete\' }}</button>' +
    '</template></pb-modal>' +

    // ===== Create work item modal =====
    // z-index above the detail drawer (z-85): "Create new sub-task" opens this modal from
    // inside the drawer, and at a lower layer it rendered behind the drawer's panel — open,
    // but invisible.
    '<div v-if="open" class="fixed inset-0 z-[100] flex items-start justify-center p-4 sm:pt-20">' +
    '<div class="absolute inset-0 bg-black/40" @click="closeCreate"></div>' +
    '<div class="relative w-full max-w-[720px] bg-white rounded-xl shadow-xl flex flex-col max-h-[86vh]">' +

    // Header
    '<div class="flex items-center gap-2 px-6 pt-5 shrink-0">' +
    // Project context chip — the POC's bordered chip, not a filled one.
    '<span class="inline-flex items-center gap-1.5 h-7 px-2 rounded-md border border-stroke text-[13px] text-ink"><span>{{ project.emoji || \'📁\' }}</span>{{ project.name }}</span>' +
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<span class="text-[13px] text-sub">New work item</span>' +
    '<button @click="closeCreate" class="ml-auto h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
    '</div>' +

    // Body. `overflow-visible` (as in the POC) is load-bearing: every chip popover opens
    // upward with `bottom-full`, and a scrolling body would clip the calendar.
    '<div class="px-6 pt-4 overflow-visible">' +
    '<input ref="titleInput" v-model="form.title" type="text" placeholder="Title" class="pb-input h-11 text-[15px]" :class="{\'is-error\': errors.title}" @keyup.enter="save" />' +
    '<p v-if="errors.title" class="text-[12px] text-danger mt-1">{{ errors.title[0] }}</p>' +
    // Plain textarea, not the rich-text editor: creating a work item is a quick capture, and
    // the full editor (with its toolbar, uploads and gallery) belongs on the detail view
    // where the description is actually written. The server treats what is typed here as
    // plain text — see StoreWorkItemRequest.
    '<textarea v-model="form.description" rows="4" placeholder="Click to add description" class="pb-textarea mt-3"></textarea>' +
    '<p v-if="errors.description" class="text-[12px] text-danger mt-1">{{ errors.description[0] }}</p>' +

    // Attribute chips
    '<div class="flex flex-wrap items-center gap-2 mt-4 pb-4">' +

    // State
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'state\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<span class="grid place-items-center" v-html="stateIcon(formState)"></span>{{ formState ? formState.name : \'State\' }}</button>' +
    '<div v-if="menu===\'state\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'state\'" class="absolute left-0 bottom-full mb-1 w-48 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<button v-for="s in states" :key="s.id" type="button" @click="form.state_id=s.id; menu=\'\'" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="stateIcon(s)"></span><span class="flex-1 truncate">{{ s.name }}</span>' +
    '<svg v-if="String(form.state_id)===String(s.id)" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button></div></div>' +

    // Priority
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'priority\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] hover:bg-hover" :class="formPriority.cls">' +
    '<span class="grid place-items-center" v-html="formPriority.icon"></span>{{ formPriority.label }}</button>' +
    '<div v-if="menu===\'priority\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'priority\'" class="absolute left-0 bottom-full mb-1 w-44 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<button v-for="p in priorities" :key="p.key" type="button" @click="form.priority=p.key; menu=\'\'" class="w-full text-left flex items-center gap-2 px-2.5 h-8 hover:bg-hover text-[13px] text-ink">' +
    '<span class="grid place-items-center" v-html="priorityMeta(p.key).icon"></span><span class="flex-1">{{ p.label }}</span>' +
    '<svg v-if="form.priority===p.key" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button></div></div>' +

    // Assignees
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'assignees\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.7"/><path d="M3 19a6 6 0 0112 0M16 6a3 3 0 010 6M18 19a6 6 0 00-3-5.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>' +
    '{{ selectedAssignees.length ? (selectedAssignees.length === 1 ? selectedAssignees[0].name : selectedAssignees.length + \' assignees\') : \'Assignees\' }}</button>' +
    '<div v-if="menu===\'assignees\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'assignees\'" class="absolute left-0 bottom-full mb-1 w-64 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<input v-model="memberQuery" placeholder="Search members..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button v-for="m in filteredMembers" :key="m.id" type="button" @click="toggleAssignee(m)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<wi-avatar :person="m" :size="24" /><span class="flex-1 truncate">{{ m.name }}</span>' +
    '<svg v-if="isAssigned(m)" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!filteredMembers.length" class="px-2 py-3 text-[13px] text-sub text-center">No members found</div>' +
    '</div></div></div>' +

    // Labels
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'labels\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="14.5" cy="9.5" r="1.3" fill="currentColor"/></svg>' +
    '{{ selectedLabels.length ? (selectedLabels.length === 1 ? selectedLabels[0].name : selectedLabels.length + \' labels\') : \'Labels\' }}</button>' +
    '<div v-if="menu===\'labels\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<div v-if="menu===\'labels\'" class="absolute left-0 bottom-full mb-1 w-64 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">' +
    '<input v-model="labelQuery" placeholder="Search labels..." class="w-full h-9 px-3 mb-1 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />' +
    '<div class="max-h-48 overflow-y-auto">' +
    '<button v-for="l in filteredLabels" :key="l.id" type="button" @click="toggleLabel(l)" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md hover:bg-hover text-[13px] text-ink">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: l.color}"></span><span class="flex-1 truncate">{{ l.name }}</span>' +
    '<svg v-if="isLabelled(l)" width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!filteredLabels.length" class="px-2 py-3 text-[13px] text-sub text-center">No labels configured for this project</div>' +
    '</div></div></div>' +

    // Start date — anchored calendar popover (quick options → Custom Date), per the POC
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'start_date\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    '{{ form.start_date ? fmtDate(form.start_date) : \'Start date\' }}</button>' +
    '<div v-if="menu===\'start_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'start_date\'" :value="form.start_date" :before="form.due_date" ' +
    '@pick="iso => onDatePick(\'start_date\', iso)" @clear="onDateClear(\'start_date\')" />' +
    '</div>' +

    // Due date
    '<div class="relative">' +
    '<button type="button" @click.stop="toggleMenu(\'due_date\')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
    '{{ form.due_date ? fmtDate(form.due_date) : \'Due date\' }}</button>' +
    '<div v-if="menu===\'due_date\'" class="fixed inset-0 z-40" @click="menu=\'\'"></div>' +
    '<wi-calendar v-if="menu===\'due_date\'" :value="form.due_date" :after="form.start_date" ' +
    '@pick="iso => onDatePick(\'due_date\', iso)" @clear="onDateClear(\'due_date\')" />' +
    '</div>' +

    // Add parent — opens the search panel below (POC: ParentSearchModal)
    '<button type="button" @click.stop="openParent" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">' +
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>' +
    '{{ parentSelected ? parentSelected.identifier : \'Add parent\' }}</button>' +

    '</div>' +

    // Chip-field validation messages (these controls have no input of their own)
    '<p v-for="(msgs, field) in errors" :key="field" v-show="field !== \'title\' && field !== \'description\'" class="text-[12px] text-danger mb-2">{{ msgs[0] }}</p>' +
    '</div>' +

    // Footer
    '<div class="flex items-center gap-3 px-6 py-4 border-t border-line shrink-0">' +
    '<button type="button" role="switch" :aria-checked="createMore ? \'true\' : \'false\'" @click="createMore = !createMore" :class="[\'ml-auto shrink-0 relative inline-flex h-5 w-9 items-center rounded-full px-0.5 transition-colors\', createMore ? \'bg-brand\' : \'bg-stroke\']">' +
    '<span :class="[\'h-4 w-4 rounded-full bg-white shadow transition-transform\', createMore ? \'translate-x-4\' : \'translate-x-0\']"></span></button>' +
    '<span class="text-[13px] text-sub">Create more</span>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" @click="closeCreate">Discard</button>' +
    '<button type="button" :disabled="saving || !form.title.trim()" @click="save" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">{{ saving ? \'Saving…\' : \'Save\' }}</button>' +
    '</div>' +

    '</div></div>' +

    // ===== Parent search panel (POC: ParentSearchModal) — sits above the create modal =====
    '<div v-if="parentOpen" class="fixed inset-0 z-[105] flex items-start justify-center p-4 sm:pt-24">' +
    '<div class="absolute inset-0 bg-black/40" @click="closeParent"></div>' +
    '<div class="relative w-full max-w-[720px] bg-white rounded-xl shadow-xl flex flex-col max-h-[70vh]">' +

    // Search header
    '<div class="flex items-center gap-3 px-5 py-3 border-b border-line shrink-0">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    '<input ref="parentSearch" v-model="parentQuery" type="text" placeholder="Type to search" class="flex-1 h-8 text-[14px] text-ink placeholder:text-faint outline-none bg-transparent" />' +
    '<span class="h-5 w-px bg-line"></span>' +
    '<span class="text-[13px] text-sub shrink-0">{{ project.identifier }}</span>' +
    '</div>' +

    // Results
    '<div class="p-2 overflow-y-auto">' +
    '<button v-if="parentSelected" type="button" @click="pickParent(null)" class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    '<span class="text-[14px] text-sub">Remove parent</span></button>' +
    '<button v-for="i in parentCandidates" :key="i.id" type="button" @click="pickParent(i)" class="w-full text-left flex items-center gap-3 px-3 h-11 rounded-md hover:bg-hover">' +
    '<span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{background: i.state ? i.state.color : \'#9ca3af\'}"></span>' +
    '<span class="text-[13px] text-sub shrink-0">{{ i.identifier }}</span>' +
    '<span class="text-[14px] text-ink truncate">{{ i.title }}</span>' +
    '<svg v-if="parentSelected && parentSelected.id===i.id" width="15" height="15" viewBox="0 0 24 24" fill="none" class="ml-auto text-brand shrink-0"><path d="M5 12l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '</button>' +
    '<div v-if="!parentCandidates.length" class="px-3 py-6 text-[13px] text-sub text-center">No work items found</div>' +
    '</div>' +

    '</div></div>' +

    '</div>'
});
