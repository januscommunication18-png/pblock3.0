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
var WI_CAL = '' + wiIcon('calendar', 13, 'text-faint') + '';
var WI_NO_STATE = 'none';
/** The single group key a <wi-list> in `flat` mode files every row under. */
var WI_FLAT_GROUP = '__all__';

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
    '' + wiIcon('circle-slash', 11) + '' +
    label + '</span>';
}


/**
 * A person's avatar: their uploaded photo when they have one, their initial when they do not.
 * The initial used to be the only option, which is why assigning someone with a profile
 * picture never showed it.
 */
function wiAvatar(person, px) {
  var size = px || 24;
  // The size is an inline STYLE, not `h-[Npx] w-[Npx]`.
  //
  // Building a class name by concatenation worked only because the Tailwind Play CDN
  // compiled whatever it found in the live DOM. Against a pre-built stylesheet those classes
  // are never generated — the scanner only ever sees `'h-[' +` — so the avatar lost its box
  // and collapsed to the size of the letter inside it. A computed pixel size is a style, and
  // saying so keeps it working whatever the CSS pipeline does.
  var box = 'width:' + size + 'px;height:' + size + 'px';
  var title = wiEsc(person.name || '');

  if (person.avatar_url) {
    return '<img src="' + wiEsc(person.avatar_url) + '" alt="' + title + '" data-tip="' + title + '" ' +
      'style="' + box + '" class="rounded-full shrink-0 object-cover border border-line" />';
  }

  // A colour per person, so a row of unphotographed people is still scannable.
  return '<span style="' + box + ';background:' + PB.avatarColor(person) + '" class="rounded-full shrink-0 text-white grid place-items-center text-[10px] font-bold" ' +
    'data-tip="' + title + '" aria-label="' + title + '">' + wiEsc(person.initial || '?') + '</span>';
}

// Display chip — the POC's chip style: 24px tall, white, 12px label.
function wiChip(inner, extra, tip, shrinkable) {
  var tipAttr = tip ? ' data-tip="' + wiEsc(tip) + '"' : '';
  return '<span class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-line bg-white text-[12px] ' +
    (extra || 'text-ink') + ' ' + (shrinkable ? 'min-w-0' : 'shrink-0') + '"' + tipAttr + '>' + inner + '</span>';
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
  // `shrinkable` chips give up width instead of pushing the cluster past the cell — see the
  // note on the container below.
  var chip = function (inner, act, extra, tip, shrinkable) {
    var tipAttr = tip ? ' data-tip="' + wiEsc(tip) + '" aria-label="' + wiEsc(tip) + '"' : '';
    if (!edit) return wiChip(inner, extra, tip, shrinkable);
    return '<button type="button" data-act="' + act + '" data-id="' + d.id + '"' + tipAttr +
      ' class="inline-flex items-center gap-1.5 h-6 px-2 rounded border border-stroke bg-white text-[12px] hover:bg-hover ' +
      (shrinkable ? 'min-w-0' : 'shrink-0') + ' ' + (extra || 'text-ink') + '">' + inner + '</button>';
  };

  var out = [];

  // Which project this row came from — only on a list that mixes them (Your Work). Never a
  // button, whatever `edit` says: moving a work item between projects is not a chip's job,
  // and the ID, the state and every label on the row belong to the project it is in.
  //
  // FIRST in the cluster and carrying the ml-auto, so it reads as the row's origin rather
  // than as another editable property, and so the cluster still overflows rightward.
  if (d.project) {
    out.push(wiChip(
      '<span class="shrink-0">' + (d.project.emoji || '📁') + '</span>' + wiEsc(d.project.name),
      'text-sub ml-auto', 'Project: ' + d.project.name, true
    ));
  }

  out.push(
    // ml-auto on the first chip rather than justify-end on the container: with justify-end an
    // over-full cluster overflows to the LEFT, so the state chip was the one sliced in half.
    // An auto margin collapses to 0 when there is no room, and the overflow goes right.
    chip(wiStateIcon(d.state) + wiEsc(d.state ? d.state.name : 'No state'), 'state',
      'text-ink' + (d.project ? '' : ' ml-auto'),
      (edit ? 'Change state — ' : 'State: ') + (d.state ? d.state.name : 'No state')),
    chip(pri.icon + pri.label, 'priority', pri.cls,
      (edit ? 'Change priority — ' : 'Priority: ') + pri.label)
  );

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
    avatars = '<span class="h-6 w-6 rounded-full border border-dashed border-stroke grid place-items-center text-faint shrink-0">' + wiIcon('user-thin', 12) + '</span>';
  }
  var who = (d.assignees || []).map(function (a) { return a.name; }).join(', ');
  var assigneeTip = who ? (edit ? 'Change assignee — ' + who : 'Assigned to ' + who) : (edit ? 'Assign someone' : 'Unassigned');
  out.push(edit
    ? '<button type="button" data-act="assignees" data-id="' + d.id + '" class="inline-flex items-center gap-0.5 shrink-0" data-tip="' + wiEsc(assigneeTip) + '" aria-label="' + wiEsc(assigneeTip) + '">' + avatars + '</button>'
    : '<span class="inline-flex items-center gap-0.5 shrink-0" data-tip="' + wiEsc(assigneeTip) + '">' + avatars + '</span>');

  // §16: the estimate on the row. Only when the item HAS one — an empty placeholder would
  // cost width on every row to say nothing — and only from xl up, where there is room for it
  // beside the dates. The label carries the reading, so it needs no icon.
  if (d.estimate) {
    out.push('<span class="hidden xl:inline-flex">' + chip(wiEsc(d.estimate.label), 'estimate', null,
      (edit ? 'Change estimate — ' : 'Estimate: ') + d.estimate.label) + '</span>');
  }

  // Labels. Skipped entirely when the project has the feature switched off — §3 hides the
  // label field from work items, and a row chip is a work item's label field. `opts.labels`
  // rather than reading `d`: whether labels EXIST on this row is a different question from
  // whether the project uses them, and the empty state is a chip too.
  //
  // Each one is a self-contained flex child of the chip — joining the names with a
  // stray '</span>' used to close the chip early, so the second label escaped its own border
  // and added width nothing had budgeted for, which is what pushed the state chip off the
  // left edge of the cell.
  //
  // Only the FIRST label gets a name; the rest are a "+N" count. Two full names side by side
  // are what made this cell overrun its width in the first place, and the pair was never
  // readable anyway — the tooltip below carries every name, so nothing is lost by not
  // printing them all in a cell this narrow.
  var showLabels = opts.labels !== false;
  var labels = (d.labels || []).slice(0, 1).map(function (l) {
    return '<span class="inline-flex items-center gap-1 min-w-0">' +
      '<span class="h-2 w-2 rounded-full shrink-0" style="background:' + wiEsc(l.color) + '"></span>' +
      '<span class="truncate">' + wiEsc(l.name) + '</span></span>';
  }).join('');
  var labelInner = labels || wiIcon('tag', 13, 'text-faint');
  if ((d.labels || []).length > 1) {
    labelInner += '<span class="shrink-0 text-sub">+' + (d.labels.length - 1) + '</span>';
  }
  var labelNames = (d.labels || []).map(function (l) { return l.name; }).join(', ');
  // Label names are free text of any length, so this is the chip that gives up width when the
  // cluster runs out. Everything else here is bounded (a date, a priority, an avatar).
  if (showLabels) {
    out.push('<span class="hidden lg:inline-flex min-w-0 max-w-[260px]">' + chip(labelInner, 'labels',
      labels ? 'text-ink' : 'text-faint',
      labelNames ? (edit ? 'Change labels — ' + labelNames : 'Labels: ' + labelNames) : (edit ? 'Add labels' : 'No labels'),
      true) + '</span>');
  }

  if (opts.action) out.push(opts.action);

  // min-w-0 lets the shrinkable label chip actually shrink; overflow-hidden clips whatever is
  // still too wide after that, and the ml-auto above decides which end pays for it.
  return '<div class="flex w-full items-center gap-1.5 flex-nowrap min-w-0 overflow-hidden">' + out.join('') + '</div>';
}

/**
 * Rich text as a short plain string — the client-side twin of RichTextSanitizer::excerpt().
 *
 * Used for the status-update tooltip, which is an attribute and so cannot hold markup.
 */
function wiPlainText(html, length) {
  var el = document.createElement('div');
  el.innerHTML = html || '';
  var text = (el.textContent || '').replace(/\s+/g, ' ').trim();

  return text.length > (length || 240) ? text.slice(0, length || 240) : text;
}

/**
 * "At Risk" / "Off Track" from the work item's latest status update (§8).
 *
 * Only these two appear. On Track is the ordinary case, and a badge on every row saying
 * "fine" is noise that makes the two that are NOT fine harder to spot.
 *
 * The label carries the update's own comment as its tooltip, because the label alone says
 * something is wrong without saying what — and having to open the item to find out is what
 * stops people checking.
 */
function wiStatusChip(update) {
  if (!update || !update.label) return '';

  var tone = update.status === 'off_track'
    ? 'color:#b91c1c;background:#fef2f2;border-color:#fecaca'
    : 'color:#b45309;background:#fffbeb;border-color:#fde68a';

  // §8.4: the label always travels with the colour — never colour on its own.
  var icon = update.status === 'off_track'
    ? '<path d="M12 8v5M12 16v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>'
    : '<path d="M12 3l9.5 16.5H2.5L12 3z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M12 10v3.5M12 16.5v.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>';

  var tip = update.comment
    ? update.label + ' — ' + update.comment
    : update.label + ' — no comment on this update';

  return '<span class="inline-flex items-center gap-1 h-5 px-1.5 rounded border text-[11px] font-semibold shrink-0" ' +
    'style="' + tone + '" data-tip="' + wiEsc(tip) + '" aria-label="' + wiEsc(tip) + '">' +
    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none">' + icon + '</svg>' +
    wiEsc(update.label) + '</span>';
}

/**
 * The title cell: the title, preceded by the markers that say this row needs attention —
 * Blocked (waiting on something else) and the At Risk / Off Track status update.
 */
function wiTitleCell(d) {
  var blocked = d.blocked_by_count > 0 ? wiBlockedChip(d.blocked_by_count) : '';

  return '<span class="inline-flex items-center gap-2 min-w-0">' + blocked + wiStatusChip(d.status_update) +
    '<span class="text-[14px] text-ink truncate">' + wiEsc(d.title) + '</span></span>';
}

/** The ⋯ row actions control, and the cycle list's remove-from-cycle control. */
// The trailing control on a row. Its box must match the group header's "+" (work-item-list.js)
// — they are one vertical column to anyone reading down the list, and 24px against 28px reads
// as a wobble even though both are flush to the same padding edge.
function wiRowMenuButton(id) {
  return '<button type="button" data-act="menu" data-id="' + id + '" data-tip="Work item actions" aria-label="Work item actions" class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-line shrink-0">' +
    '' + wiIcon('ellipsis-thin', 15) + '</button>';
}
