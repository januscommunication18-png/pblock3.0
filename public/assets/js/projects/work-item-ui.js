/* Project Block — work item row vocabulary.
   ------------------------------------------------------------------
   State icons, priority icons, chips, avatars and the HTML escaper: everything that decides
   what a work item LOOKS like in a list. Shared by the Work Items grid and the Cycles
   screen's cycle work item grid, so the two lists cannot drift apart — which is the whole
   reason a cycle's work items should read like the project's work items.

   A plain script sharing global scope, loaded BEFORE the screen scripts that use it (both
   are `defer`, which preserves order). The `wi` prefix is kept because work-items.js
   references these throughout.
   ------------------------------------------------------------------ */

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
    'data-tip="Waiting on ' + count + ' unresolved ' + (count > 1 ? 'work items' : 'work item') + '">' +
    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/><path d="M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
    label + '</span>';
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
    return '<img src="' + wiEsc(person.avatar_url) + '" alt="' + title + '" data-tip="' + title + '" ' +
      'class="' + box + ' object-cover border border-line" />';
  }

  return '<span class="' + box + ' bg-brand text-white grid place-items-center text-[10px] font-bold" data-tip="' + title + '" aria-label="' + title + '">' +
    wiEsc(person.initial || '?') + '</span>';
}

// Display chip — the POC's chip style: 24px tall, white, 12px label.
function wiChip(inner, extra, tip) {
  var tipAttr = tip ? ' data-tip="' + wiEsc(tip) + '"' : '';
  return '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] ' + (extra || 'text-ink') + ' shrink-0"' + tipAttr + '>' + inner + '</span>';
}



/**
 * A work item row's right-aligned chip cluster — state, priority, dates, assignee, labels.
 *
 * Shared verbatim by the project's Work Items grid and the Cycles screen's cycle work item
 * grid: a row must not mean one thing in one list and something else in the other, and the
 * only honest way to guarantee that is for both to render the SAME markup.
 *
 * `opts.edit` turns every chip into a button that opens its own picker (Work Items §4.2);
 * without it the same chips render as plain display chips, which is what a read-only viewer
 * of the work item list already sees. `opts.action` is the trailing control, because that is
 * the one thing that legitimately differs: the work item list ends in its ⋯ actions menu, a
 * cycle's list ends in remove-from-cycle.
 */
function wiMetaCell(d, opts) {
  opts = opts || {};
  var edit = !!opts.edit;
  var pri = WI_PRI[d.priority] || WI_PRI.none;

  // Each chip carries its own tooltip: the row shows a value, the tooltip names the property
  // it belongs to and says the chip is clickable — a bare "Medium" or a lone calendar icon
  // does not tell you either.
  var chip = function (inner, act, extra, tip) {
    var tipAttr = tip ? ' data-tip="' + wiEsc(tip) + '" aria-label="' + wiEsc(tip) + '"' : '';
    if (!edit) return wiChip(inner, extra, tip);
    return '<button type="button" data-act="' + act + '" data-id="' + d.id + '"' + tipAttr +
      ' class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-stroke bg-white text-[12px] hover:bg-hover shrink-0 ' + (extra || 'text-ink') + '">' + inner + '</button>';
  };

  var out = [
    chip(wiStateIcon(d.state) + wiEsc(d.state ? d.state.name : 'No state'), 'state', null,
      (edit ? 'Change state — ' : 'State: ') + (d.state ? d.state.name : 'No state')),
    chip(pri.icon + pri.label, 'priority', pri.cls,
      (edit ? 'Change priority — ' : 'Priority: ') + pri.label)
  ];

  // Dates: a set date shows its chip; an empty one shows a compact calendar button so it can
  // still be filled in from the row.
  out.push('<span class="hidden xl:inline-flex">' + (d.start_date
    ? chip(WI_CAL + wiFmtDate(d.start_date), 'start_date', null, 'Start date: ' + wiFmtDate(d.start_date))
    : chip(WI_CAL, 'start_date', 'text-faint', edit ? 'Set a start date' : 'No start date')) + '</span>');
  out.push('<span class="hidden xl:inline-flex">' + (d.due_date
    ? chip(WI_CAL + wiFmtDate(d.due_date), 'due_date', null, 'Due date: ' + wiFmtDate(d.due_date))
    : chip(WI_CAL, 'due_date', 'text-faint', edit ? 'Set a due date' : 'No due date')) + '</span>');

  // Assignees: stacked avatars, or a dashed placeholder when unassigned.
  var avatars = (d.assignees || []).slice(0, 3).map(function (a) { return wiAvatar(a, 24); }).join('');
  if ((d.assignees || []).length > 3) avatars += '<span class="text-[11px] text-sub">+' + (d.assignees.length - 3) + '</span>';
  if (!avatars) {
    avatars = '<span class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint shrink-0"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>';
  }
  var who = (d.assignees || []).map(function (a) { return a.name; }).join(', ');
  var assigneeTip = who ? (edit ? 'Change assignee — ' + who : 'Assigned to ' + who) : (edit ? 'Assign someone' : 'Unassigned');
  out.push(edit
    ? '<button type="button" data-act="assignees" data-id="' + d.id + '" class="inline-flex items-center gap-0.5 shrink-0" data-tip="' + wiEsc(assigneeTip) + '" aria-label="' + wiEsc(assigneeTip) + '">' + avatars + '</button>'
    : '<span class="inline-flex items-center gap-0.5 shrink-0" data-tip="' + wiEsc(assigneeTip) + '">' + avatars + '</span>');

  // Labels
  var labels = (d.labels || []).slice(0, 2).map(function (l) {
    return '<span class="h-2 w-2 rounded-full shrink-0" style="background:' + wiEsc(l.color) + '"></span>' + wiEsc(l.name);
  });
  var labelInner = labels.length
    ? labels.join('</span><span class="mx-1"></span><span>')
    : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M3 12l7-7h7a2 2 0 012 2v7l-7 7-9-9z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';
  var extraLabels = (d.labels || []).length > 2 ? ' +' + (d.labels.length - 2) : '';
  var labelNames = (d.labels || []).map(function (l) { return l.name; }).join(', ');
  out.push('<span class="hidden lg:inline-flex">' + chip(labelInner + extraLabels, 'labels',
    labels.length ? 'text-ink' : 'text-faint',
    labelNames ? (edit ? 'Change labels — ' + labelNames : 'Labels: ' + labelNames) : (edit ? 'Add labels' : 'No labels')) + '</span>');

  if (opts.action) out.push(opts.action);

  return '<div class="flex items-center justify-end gap-1.5 flex-nowrap">' + out.join('') + '</div>';
}

/** The title cell: the title, preceded by a Blocked marker when something is holding it up. */
function wiTitleCell(d) {
  var chip = d.blocked_by_count > 0 ? wiBlockedChip(d.blocked_by_count) : '';

  return '<span class="inline-flex items-center gap-2">' + chip +
    '<span class="text-[14px] text-ink">' + wiEsc(d.title) + '</span></span>';
}

/** The ⋯ row actions control, and the cycle list's remove-from-cycle control. */
function wiRowMenuButton(id) {
  return '<button type="button" data-act="menu" data-id="' + id + '" data-tip="Work item actions" aria-label="Work item actions" class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line shrink-0">' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg></button>';
}
