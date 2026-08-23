/* Help Center — Company & Customer (docs/features/help-center.md, P75 §11).
   ------------------------------------------------------------------
   The management area behind the navigation item that sits after Spam: Customers and Companies
   as two tabs over one screen.

   TABS and not two pages, because they are two views of one dataset. Somebody looking for
   "everyone at Acme" starts in one and finishes in the other, and making that a page load would
   throw away the search they typed to get there.

   Both lists arrive with the page. They are capped server-side and the search re-queries, so
   the tab switch costs nothing and the first render needs no round trip — the alternative is a
   screen that flashes empty every time you change tab.
   ------------------------------------------------------------------ */
PB.boot('help-center-company-customer', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      tab: b.tab === 'companies' ? 'companies' : 'customers',
      customers: Array.isArray(b.customers) ? b.customers : [],
      companies: Array.isArray(b.companies) ? b.companies : [],
      canManage: !!b.canManage,
      endpoints: b.endpoints || {},

      search: '',
      searching: false,
      // The timer behind the debounce. One in flight at a time — typing "acme" should be one
      // query, not four.
      timer: null
    };
  },

  computed: {
    rows: function () { return this.tab === 'companies' ? this.companies : this.customers; },

    isEmpty: function () { return this.rows.length === 0; },

    /* The empty state says something different when a search is what emptied it. "No customers
       yet" under a search box containing "acme" is a screen telling the reader the wrong thing. */
    emptyTitle: function () {
      if (this.search.trim()) return 'Nothing matches “' + this.search.trim() + '”';

      return this.tab === 'companies' ? 'No companies yet' : 'No customers yet';
    },

    emptyMessage: function () {
      if (this.search.trim()) return 'Try a different name, email address or domain.';

      return this.tab === 'companies'
        ? 'Companies appear here as requests arrive and the mapping matches or creates them.'
        : 'Customers appear here as soon as somebody writes in to a Space that has Company & Customer switched on.';
    }
  },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    /* `__ID__` is not used here: the two profile endpoints are built with a 0 placeholder by the
       controller, which is the shape `route()` produces for a numeric parameter. Replacing the
       trailing 0 is deliberate and safe because the URL ends with it. */
    profileUrl: function (row) {
      var template = this.tab === 'companies' ? this.endpoints.company : this.endpoints.customer;

      return String(template || '').replace(/0$/, String(row.id));
    },

    setTab: function (tab) {
      if (this.tab === tab) return;

      this.tab = tab;

      // Reflected in the URL so the tab survives a refresh and can be linked to — the same
      // promise every other Help Center screen makes about where you are standing.
      try {
        var url = new URL(window.location.href);
        if (tab === 'companies') url.searchParams.set('tab', 'companies');
        else url.searchParams.delete('tab');
        window.history.replaceState({}, '', url.toString());
      } catch (e) { /* an environment without URL — the tab still works */ }
    },

    onSearch: function () {
      var self = this;

      if (this.timer) clearTimeout(this.timer);

      this.timer = setTimeout(function () { self.runSearch(); }, 250);
    },

    runSearch: async function () {
      this.searching = true;

      try {
        var res = await this.$pb.api(
          String(this.endpoints.search || '') + '?q=' + encodeURIComponent(this.search),
          { method: 'GET' }
        );
        this.customers = res.customers || [];
        this.companies = res.companies || [];
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not search.'), 'error');
      } finally {
        this.searching = false;
      }
    }
  },

  template: [
    '<div>',

    /* Toolbar — the same h-12 bordered bar every other Help Center screen carries. */
    '  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">',
    '    <button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar" aria-controls="sidebar" aria-expanded="false" class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0" v-html="icon(\'sidebar\', 16)"></button>',
    '    <span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>',
    '    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">',
    '      <span v-html="icon(\'users\', 16, \'text-sub\')"></span>Company &amp; Customer',
    '    </span>',
    '  </div>',

    '  <p class="px-5 sm:px-8 pt-5 text-[13px] text-sub max-w-[720px]">The people who write in and the organisations they belong to, built from your Spaces&rsquo; Ticket Metadata Mapping.</p>',

    /* Tabs, then the search. The search filters whichever tab is open, and both lists are
       re-queried at once so switching tab after a search is instant. */
    '  <div class="px-5 sm:px-8 mt-4 flex flex-wrap items-center gap-3 border-b border-line">',
    '    <div class="flex items-center gap-1 -mb-px">',
    '      <button type="button" @click="setTab(\'customers\')"',
    '              :class="[\'h-9 px-3 text-[13px] font-medium border-b-2\', tab === \'customers\' ? \'border-brand text-brand\' : \'border-transparent text-sub hover:text-ink\']">',
    '        Customers <span class="text-faint tabular-nums">{{ customers.length }}</span>',
    '      </button>',
    '      <button type="button" @click="setTab(\'companies\')"',
    '              :class="[\'h-9 px-3 text-[13px] font-medium border-b-2\', tab === \'companies\' ? \'border-brand text-brand\' : \'border-transparent text-sub hover:text-ink\']">',
    '        Companies <span class="text-faint tabular-nums">{{ companies.length }}</span>',
    '      </button>',
    '    </div>',
    '    <div class="ml-auto mb-2">',
    '      <input v-model="search" @input="onSearch" type="search" placeholder="Search name, email or domain"',
    '             class="h-8 w-[260px] px-3 rounded-md border border-stroke text-[13px] text-ink placeholder:text-faint" />',
    '    </div>',
    '  </div>',

    /* ===== Customers (§11) ===== */
    /* `min-w-` on the table, inside the `overflow-x-auto` above it: without a floor, a
       `w-full` table just compresses instead of scrolling, and seven columns become unreadable
       before the scrollbar ever appears.

       tailwind.config.js scans public/assets/js, so an arbitrary value written here IS emitted —
       but only by `npm run build:css`, and the compiled stylesheet is committed. A new arbitrary
       value therefore needs a rebuild in the same change, or it silently does nothing. */
    '  <div v-if="tab === \'customers\' && customers.length" class="px-5 sm:px-8 py-4 overflow-x-auto">',
    '    <table class="w-full text-[13px] min-w-[820px]">',
    '      <thead><tr class="text-left text-[12px] text-faint border-b border-line">',
    '        <th class="py-2 font-medium">Customer Name</th>',
    '        <th class="py-2 font-medium">Email</th>',
    '        <th class="py-2 font-medium">Company</th>',
    '        <th class="py-2 font-medium text-right">Open</th>',
    '        <th class="py-2 font-medium text-right">Total</th>',
    '        <th class="py-2 font-medium">Last Ticket</th>',
    '        <th class="py-2 font-medium">Created</th>',
    '      </tr></thead>',
    '      <tbody>',
    '        <tr v-for="c in customers" :key="c.id" class="border-b border-line last:border-0 hover:bg-hover">',
    '          <td class="py-2">',
    '            <a :href="profileUrl(c)" class="flex items-center gap-2 min-w-0 text-ink hover:text-brand">',
    '              <span class="h-6 w-6 shrink-0 rounded-full bg-hover grid place-items-center text-[11px] font-semibold text-sub">{{ c.initial }}</span>',
    '              <span class="truncate font-medium">{{ c.name }}</span>',
    '            </a>',
    '          </td>',
    '          <td class="py-2 text-sub _moretogether-break">{{ c.email }}</td>',
    '          <td class="py-2 text-sub">{{ c.company || \'—\' }}</td>',
    '          <td class="py-2 text-right tabular-nums text-ink">{{ c.open_tickets }}</td>',
    '          <td class="py-2 text-right tabular-nums text-sub">{{ c.total_tickets }}</td>',
    '          <td class="py-2 text-sub">{{ c.last_ticket || \'—\' }}</td>',
    '          <td class="py-2 text-faint">{{ c.created || \'—\' }}</td>',
    '        </tr>',
    '      </tbody>',
    '    </table>',
    '  </div>',

    /* ===== Companies (§11) ===== */
    '  <div v-else-if="tab === \'companies\' && companies.length" class="px-5 sm:px-8 py-4 overflow-x-auto">',
    '    <table class="w-full text-[13px] min-w-[820px]">',
    '      <thead><tr class="text-left text-[12px] text-faint border-b border-line">',
    '        <th class="py-2 font-medium">Company Name</th>',
    '        <th class="py-2 font-medium">Domain</th>',
    '        <th class="py-2 font-medium text-right">Customers</th>',
    '        <th class="py-2 font-medium text-right">Open</th>',
    '        <th class="py-2 font-medium text-right">Total</th>',
    '        <th class="py-2 font-medium">Last Activity</th>',
    '      </tr></thead>',
    '      <tbody>',
    '        <tr v-for="c in companies" :key="c.id" class="border-b border-line last:border-0 hover:bg-hover">',
    '          <td class="py-2">',
    '            <a :href="profileUrl(c)" class="flex items-center gap-2 min-w-0 text-ink hover:text-brand">',
    '              <span class="h-6 w-6 shrink-0 rounded-md bg-hover grid place-items-center text-[11px] font-semibold text-sub">{{ c.initial }}</span>',
    '              <span class="truncate font-medium">{{ c.name }}</span>',
    '            </a>',
    '          </td>',
    '          <td class="py-2 text-sub _moretogether-break">{{ c.domain || \'—\' }}</td>',
    '          <td class="py-2 text-right tabular-nums text-sub">{{ c.customers }}</td>',
    '          <td class="py-2 text-right tabular-nums text-ink">{{ c.open_tickets }}</td>',
    '          <td class="py-2 text-right tabular-nums text-sub">{{ c.total_tickets }}</td>',
    '          <td class="py-2 text-sub">{{ c.last_activity || \'—\' }}</td>',
    '        </tr>',
    '      </tbody>',
    '    </table>',
    '  </div>',

    /* The module's page-level empty state — centred and borderless, the shape Inbox and
       Overview use. */
    '  <div v-else class="mt-10 mx-auto max-w-[440px] text-center px-5 sm:px-8">',
    '    <span class="mx-auto h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub" v-html="icon(\'users\', 22)"></span>',
    '    <h2 class="mt-4 text-[15px] font-semibold text-head">{{ emptyTitle }}</h2>',
    '    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">{{ emptyMessage }}</p>',
    '  </div>',

    '</div>'
  ].join('\n')
}, { root: 'help-center-company-customer' });
