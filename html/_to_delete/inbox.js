/* Inbox — data + rendering + responsive navigation
 * ------------------------------------------------------------------
 * NOTE (future Laravel + Vue migration):
 *   - `issues` maps directly to a Vue prop / API resource collection.
 *   - renderInbox() becomes a <InboxList> component (v-for over issues).
 *   - The mobile show/hide logic below becomes reactive state
 *     (e.g. a `mobileView` ref: 'list' | 'detail') instead of class toggles.
 * ------------------------------------------------------------------ */

const issues = [
  { id: 'TOT-23', title: 'Branding',                    avatar: 'FH' },
  { id: 'TOT-20', title: 'Service Area',                avatar: 'FH' },
  { id: 'TOT-21', title: 'Hours',                       avatar: 'FH', selected: true },
  { id: 'TOT-22', title: 'Setting / Business / Region', avatar: 'dot' },
  { id: 'TOT-17', title: 'Business - Page',             avatar: 'dot' },
  { id: 'TOT-19', title: 'Business / Location',         avatar: 'dot' },
  { id: 'TOT-25', title: 'Dropdown type',               avatar: 'dot', warn: true },
  { id: 'TOT-18', title: 'Setting / Business Page',     avatar: 'dot' },
  { id: 'TOT-26', title: 'Date Picker',                 avatar: 'FH', warn: true },
  { id: 'TOT-27', title: 'Business / Compliance',       avatar: 'FH' },
];

const checkIcon = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-emerald-500"><circle cx="12" cy="12" r="9" fill="#22c55e"/><path d="M8 12l2.5 2.5L16 9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
const warnIcon = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="4" fill="#f59e0b"/><path d="M12 7v6M12 16.5v.5" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>`;

/* ---- Render the inbox list ---- */
function renderInbox() {
  const listEl = document.getElementById('issue-list');
  if (!listEl) return;

  listEl.innerHTML = issues
    .map(function (it) {
      const av =
        it.avatar === 'FH'
          ? `<span class="h-6 w-6 rounded-full bg-emerald-500 grid place-items-center text-white text-[9px] font-bold shrink-0">FH</span>`
          : `<span class="h-6 w-6 rounded-full bg-hover grid place-items-center shrink-0"><span class="h-2 w-2 rounded-full bg-[#5e6ad2]"></span></span>`;
      const trail = it.warn ? warnIcon : checkIcon;
      return `
        <button type="button" data-issue="${it.id}" class="issue-row w-full text-left flex items-center gap-2.5 px-4 py-2 cursor-pointer ${it.selected ? 'bg-sel' : 'hover:bg-hover'}">
          ${av}
          <div class="min-w-0 flex-1">
            <div class="truncate ${it.selected ? 'text-ink font-medium' : 'text-ink'}">${it.id} ${it.title}</div>
            <div class="truncate text-faint text-[12px]">Marked as completed by faizanhumayun486</div>
          </div>
          <div class="flex items-center gap-1.5 shrink-0">
            ${trail}
            <span class="text-faint text-[12px]">3w</span>
          </div>
        </button>`;
    })
    .join('');

  // On mobile, tapping an issue opens the detail pane.
  listEl.querySelectorAll('.issue-row').forEach(function (row) {
    row.addEventListener('click', showDetailMobile);
  });
}

/* ---- Mobile sidebar drawer ---- */
function openSidebar() {
  document.getElementById('sidebar').classList.remove('-translate-x-full');
  document.getElementById('sidebar-backdrop').classList.remove('hidden');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.add('-translate-x-full');
  document.getElementById('sidebar-backdrop').classList.add('hidden');
}

/* ---- Mobile master/detail navigation ---- */
function showDetailMobile() {
  document.getElementById('list-column').classList.add('hidden');
  const detail = document.getElementById('detail-column');
  detail.classList.remove('hidden');
  detail.classList.add('flex');
}
function backToListMobile() {
  const detail = document.getElementById('detail-column');
  detail.classList.add('hidden');
  detail.classList.remove('flex');
  document.getElementById('list-column').classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', function () {
  renderInbox();

  const bind = function (id, evt, fn) {
    const el = document.getElementById(id);
    if (el) el.addEventListener(evt, fn);
  };
  bind('open-sidebar', 'click', openSidebar);
  bind('close-sidebar', 'click', closeSidebar);
  bind('sidebar-backdrop', 'click', closeSidebar);
  bind('back-to-list', 'click', backToListMobile);
});
