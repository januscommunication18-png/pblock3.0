/* Project Block — shared date picker.
   ------------------------------------------------------------------
   The single date control for the whole project workspace: the work item Start/Due date
   chips and the Create Cycle form all mount THIS component, so "pick a date" looks and
   behaves the same everywhere rather than each screen growing its own calendar.

   A plain script sharing global scope, loaded BEFORE the screen scripts that use it (both
   are `defer`, which preserves order). The `wi` prefix on the helpers is kept because
   work-items.js references them throughout; renaming them buys nothing and would touch a
   working screen for no reason.
   ------------------------------------------------------------------ */

// ---------------------------------------------------------------------------------------
// Date picker — a direct port of the POC's `datePicker()` (html/work-items.html): an
// anchored popover that opens on quick options (Today / Tomorrow / Next 3 / Next 5 days,
// divider, Custom Date) and switches to a month grid with month + year dropdowns.
//
// `after`/`before` are ours, not the POC's: the API rejects a due date before the start
// date, so out-of-range days are struck through here rather than surfacing as a 422.
// ---------------------------------------------------------------------------------------
var WI_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
var WI_CAL_ICON = '' + wiIcon('calendar', 15, 'text-faint shrink-0') + '';

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
  props: {
    value: String, after: String, before: String,
    /**
     * Which day-offset shortcuts to offer, as an array of numbers.
     *
     * The default is the forward-looking set the date CHIPS want — a start or due date is
     * nearly always today or soon. A form recording work that has already happened wants a
     * shorter list (Log work passes [0, 1]), because "Next 5 days" is not a day anybody
     * logged work on. The list is a prop rather than a second component so there stays one
     * calendar in this app.
     */
    quick: { type: Array, default: function () { return [0, 1, 3, 5]; } }
  },
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
    // `dropUp` starts true because upward is what every caller got before this was dynamic,
    // and the first paint happens before anything can be measured. placeSelf() corrects it on
    // the next tick, which is one frame — not something an eye catches.
    return { mode: 'quick', vy: seed.getFullYear(), vm: seed.getMonth(), monthOpen: false, yearOpen: false, dropUp: true };
  },

  mounted: function () {
    this.$nextTick(this.placeSelf);

    // The popover is only ever as tall as the mode it is in, and re-measuring on resize is
    // cheap next to a calendar hanging off the bottom of the window.
    this._replace = this.placeSelf.bind(this);
    window.addEventListener('resize', this._replace);
    window.addEventListener('scroll', this._replace, true);
  },

  beforeUnmount: function () {
    window.removeEventListener('resize', this._replace);
    window.removeEventListener('scroll', this._replace, true);
  },

  watch: {
    // Quick options are a short list; the month grid is roughly twice the height. What fitted
    // below a moment ago may not fit now.
    mode: function () { this.$nextTick(this.placeSelf); }
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
    /**
     * Open downward when there is room, upward when there is not.
     *
     * Measured against the ANCHOR — the positioned wrapper holding the trigger — rather than
     * against the popover itself, which is out of flow and so contributes nothing to its
     * parent's box. Absolutely positioned children never do, which is what makes this safe.
     *
     * When neither side fits, the roomier one wins: a calendar clipped a little beats one
     * clipped a lot, and there is nowhere else for it to go.
     */
    placeSelf: function () {
      try {
        var anchor = this.$el && this.$el.parentElement;
        if (!anchor) return;

        var rect = anchor.getBoundingClientRect();
        var height = this.$el.offsetHeight || 0;
        var GAP = 8;

        var below = window.innerHeight - rect.bottom - GAP;
        var above = rect.top - GAP;

        this.dropUp = below < height && above > below;
      } catch (e) { /* an unplaceable calendar still opens, upward */ }
    },

    addDays: function (n) { var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + n); return d; },
    /** Exclusive on both sides — the bounding day itself is not selectable. */
    outOfRange: function (d) {
      var b = this.bounds;
      return !!((b.after && d <= b.after) || (b.before && d >= b.before));
    },
    quickDisabled: function (n) { return this.outOfRange(this.addDays(n)); },
    quickLabel: function (n) {
      if (n === 0) return 'Today';
      if (n === 1) return 'Tomorrow';
      if (n === -1) return 'Yesterday';

      return (n > 0 ? 'Next ' : 'Last ') + Math.abs(n) + ' days';
    },
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
    '<div :class="[\'absolute left-0 w-[300px] rounded-lg bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50\', ' +
    'dropUp ? \'bottom-full mb-1\' : \'top-full mt-1\']" @click.stop>' +

    // -- Quick options --
    '<template v-if="mode===\'quick\'">' +
    '<button v-for="n in quick" :key="n" type="button" :disabled="quickDisabled(n)" @click="quickPick(n)" ' +
    ':class="quickClass(n)"><span v-html="calIcon"></span>{{ quickLabel(n) }}</button>' +
    '<div v-if="quick.length" class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="custom" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-ink hover:bg-hover"><span v-html="calIcon"></span>Custom Date</button>' +
    '<template v-if="selected">' +
    '<div class="my-1 border-t border-line"></div>' +
    '<button type="button" @click="$emit(\'clear\')" class="w-full text-left flex items-center gap-2.5 px-2 h-9 rounded-md text-[13px] text-danger hover:bg-hover">' +
    '' + wiIcon('xmark', 15, 'shrink-0') + 'Clear</button>' +
    '</template>' +
    '</template>' +

    // -- Custom Date grid --
    '<template v-else>' +
    '<button type="button" @click="back" class="mb-2 inline-flex items-center gap-1 text-[12px] text-sub hover:text-ink">' + wiIcon('chevron-left', 13) + 'Back</button>' +
    '<div class="flex items-center gap-1.5 mb-2">' +
    '<button type="button" :disabled="!canPrev" @click="nav(-1)" :class="[\'h-8 w-8 grid place-items-center rounded-md text-sub shrink-0\', canPrev ? \'hover:bg-hover\' : \'opacity-30 cursor-not-allowed\']">' + wiIcon('chevron-left', 16) + '</button>' +
    // Month dropdown
    '<div class="relative flex-1 min-w-0">' +
    '<button type="button" @click="monthOpen=!monthOpen; yearOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="truncate">{{ monthLabel }}</span>' + wiIcon('chevron-down', 14, 'text-faint shrink-0 ml-1') + '</button>' +
    '<ul v-if="monthOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="mo in months" :key="mo.i" @click="setMonth(mo.i)" :class="[\'group relative flex items-center cursor-pointer select-none py-1.5 pl-3 pr-8 text-[13px] hover:bg-brand hover:text-white\', mo.i===vm ? \'text-brand font-medium\' : \'text-ink\']">' +
    '<span class="truncate">{{ mo.name }}</span>' +
    '<span v-if="mo.i===vm" class="absolute right-2 text-brand group-hover:text-white">' + wiIcon('check-thin', 14) + '</span>' +
    '</li></ul></div>' +
    // Year dropdown
    '<div class="relative w-[92px] shrink-0">' +
    '<button type="button" @click="yearOpen=!yearOpen; monthOpen=false" class="w-full flex items-center justify-between h-8 px-2.5 rounded-md text-[13px] text-ink outline outline-1 -outline-offset-1 outline-stroke hover:bg-hover"><span class="shrink-0 whitespace-nowrap">{{ vy }}</span>' + wiIcon('chevron-down', 14, 'text-faint shrink-0 ml-1') + '</button>' +
    '<ul v-if="yearOpen" class="absolute z-50 mt-1 w-full max-h-52 overflow-auto rounded-md bg-white border border-line shadow-lg py-1">' +
    '<li v-for="y in years" :key="y" @click="setYear(y)" :class="[\'group relative flex items-center cursor-pointer select-none py-1.5 pl-3 pr-8 text-[13px] hover:bg-brand hover:text-white\', y===vy ? \'text-brand font-medium\' : \'text-ink\']">' +
    '<span class="truncate">{{ y }}</span>' +
    '<span v-if="y===vy" class="absolute right-2 text-brand group-hover:text-white">' + wiIcon('check-thin', 14) + '</span>' +
    '</li></ul></div>' +
    '<button type="button" :disabled="!canNext" @click="nav(1)" :class="[\'h-8 w-8 grid place-items-center rounded-md text-sub shrink-0\', canNext ? \'hover:bg-hover\' : \'opacity-30 cursor-not-allowed\']">' + wiIcon('chevron-right', 16) + '</button>' +
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
