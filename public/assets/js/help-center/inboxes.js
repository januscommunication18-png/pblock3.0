/* Help Center — the Inboxes screen (docs/features/help-center.md §14).
   ------------------------------------------------------------------
   Every Inbox the user can reach, its inbound address, and the customer-facing addresses that
   route into it. Adding and removing an address happens without a page load, which is the whole
   reason this screen needs Vue where Overview and the Space views do not.

   §14 lists more actions than this — edit, members, automation, disable, archive. They belong
   to work that has not been specified yet, and a button that opens nothing would be worse than
   its absence.
   ------------------------------------------------------------------ */
PB.boot('help-center-inboxes', {
  props: { bootstrap: Object },

  data: function () {
    var b = this.bootstrap || {};

    return {
      inboxes: b.inboxes || [],
      endpointTemplates: b.endpointTemplates || {},
      // Which Inbox's "add address" form is open, by id. One at a time: two open forms is two
      // places to type the same thing into.
      openForm: null,
      form: { email: '', name: '' },
      error: '',
      saving: false,
      copiedId: null
    };
  },

  methods: {
    icon: function (name, size, cls) {
      return window.wiIcon ? window.wiIcon(name, size || 16, cls || '') : '';
    },

    addressesUrl: function (inboxId) {
      return String(this.endpointTemplates.addresses || '').replace('__ID__', encodeURIComponent(inboxId));
    },

    addressUrl: function (inboxId, addressId) {
      return String(this.endpointTemplates.address || '')
        .replace('__ID__', encodeURIComponent(inboxId))
        .replace('__ADDRESS__', encodeURIComponent(addressId));
    },

    showForm: function (inbox) {
      this.openForm = this.openForm === inbox.id ? null : inbox.id;
      this.form = { email: '', name: '' };
      this.error = '';
    },

    add: async function (inbox) {
      if (this.saving) return;
      this.saving = true;
      this.error = '';

      try {
        var res = await this.$pb.api(this.addressesUrl(inbox.id), { method: 'POST', body: this.form });
        inbox.addresses.push(res.address);
        this.form = { email: '', name: '' };
        this.openForm = null;
        this.$pb.toast(res.message);
      } catch (e) {
        // §7's message arrives as a field error; showing it beside the input is the point of
        // asking the server rather than guessing here.
        this.error = this.$pb.firstError(e, 'Could not add that address.');
      } finally {
        this.saving = false;
      }
    },

    remove: async function (inbox, address) {
      try {
        var res = await this.$pb.api(this.addressUrl(inbox.id, address.id), { method: 'DELETE' });
        inbox.addresses = inbox.addresses.filter(function (a) { return a.id !== address.id; });
        this.$pb.toast(res.message);
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e, 'Could not remove that address.'), 'error');
      }
    },

    copy: function (inbox) {
      var self = this;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inbox.inbound_address).then(function () {
          self.copiedId = inbox.id;
          window.setTimeout(function () { self.copiedId = null; }, 2000);
        }, function () {
          self.$pb.toast('Could not copy — select the address and copy it manually.', 'error');
        });
        return;
      }

      this.$pb.toast('Select the address and copy it manually.', 'error');
    }
  },

  template: [
    '<div class="px-5 sm:px-8 py-6">',
    '  <h1 class="text-[18px] font-semibold text-head">Inboxes</h1>',
    '  <p class="mt-1 text-[13px] text-sub">Every Inbox in this Help Center, and the addresses that feed it.</p>',

    '  <div v-if="!inboxes.length" class="mt-6 rounded-lg border border-line px-6 py-12 text-center max-w-[720px]">',
    '    <p class="text-[13px] text-sub">No Inboxes yet.</p>',
    '  </div>',

    '  <div class="mt-6 space-y-4 max-w-[820px]">',
    '    <div v-for="inbox in inboxes" :key="inbox.id" class="rounded-lg border border-line">',

    '      <div class="px-4 py-3 border-b border-line">',
    '        <div class="flex items-center gap-2">',
    '          <span class="text-[14px] font-semibold text-head">{{ inbox.name }}</span>',
    '          <span class="text-[12px] text-faint">{{ inbox.space_name }}</span>',
    '        </div>',
    '        <div class="mt-2 flex flex-wrap items-center gap-2">',
    '          <span class="text-[12px] text-sub shrink-0">Inbound address</span>',
    '          <code class="_moretogether-break flex-1 min-w-[220px] rounded-md border border-line bg-[#f9fafb] px-2 py-1 text-[12px] text-ink">{{ inbox.inbound_address }}</code>',
    '          <button type="button" @click="copy(inbox)" class="_moretogether-iconbtn" :aria-label="\'Copy \' + inbox.inbound_address"',
    '                  :title="copiedId === inbox.id ? \'Copied\' : \'Copy address\'" v-html="icon(\'copy\', 14)"></button>',
    '        </div>',
    '      </div>',

    '      <div class="px-4 py-3">',
    '        <table v-if="inbox.addresses.length" class="w-full text-[13px]">',
    '          <thead>',
    '            <tr class="text-left text-[12px] text-faint border-b border-line">',
    '              <th class="py-2 font-medium">Name</th>',
    '              <th class="py-2 font-medium">Email Address</th>',
    '              <th class="py-2 font-medium">Status</th>',
    '              <th v-if="inbox.manageable" class="py-2 font-medium text-right">Action</th>',
    '            </tr>',
    '          </thead>',
    '          <tbody>',
    '            <tr v-for="a in inbox.addresses" :key="a.id" class="border-b border-line last:border-0">',
    '              <td class="py-2 text-ink">{{ a.name || \'—\' }}</td>',
    '              <td class="py-2 text-ink _moretogether-break">{{ a.email }}</td>',
    '              <td class="py-2">',
    '                <span :class="[\'_moretogether-badge\', \'_moretogether-badge--\' + a.status_tone]">{{ a.status_label }}</span>',
    '              </td>',
    '              <td v-if="inbox.manageable" class="py-2 text-right">',
    '                <button type="button" @click="remove(inbox, a)" class="text-[12px] text-sub hover:text-danger">Remove</button>',
    '              </td>',
    '            </tr>',
    '          </tbody>',
    '        </table>',
    '        <p v-else class="text-[12px] text-faint">No addresses connected yet.</p>',

    /* Only somebody who may manage the Space is offered the form. The server enforces it too
       (§19) — this is presentation, and the endpoint is the authority. */
    '        <div v-if="inbox.manageable" class="mt-3">',
    '          <button v-if="openForm !== inbox.id" type="button" @click="showForm(inbox)"',
    '                  class="inline-flex items-center gap-1 text-[12px] font-semibold text-brand hover:underline">',
    '            <span v-html="icon(\'plus\', 12)"></span> Add email address',
    '          </button>',

    '          <div v-else class="mt-2">',
    '            <div class="flex flex-wrap items-start gap-2">',
    '              <input v-model="form.email" type="email" class="pb-input flex-1 min-w-[200px]" placeholder="support@company.com"',
    '                     @keydown.enter.prevent="add(inbox)" />',
    '              <input v-model="form.name" maxlength="100" class="pb-input flex-1 min-w-[160px]" placeholder="Company Support"',
    '                     @keydown.enter.prevent="add(inbox)" />',
    '              <button type="button" @click="add(inbox)" :disabled="saving || !form.email"',
    '                      class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold disabled:opacity-50">Add</button>',
    '              <button type="button" @click="showForm(inbox)"',
    '                      class="inline-flex items-center h-9 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">Cancel</button>',
    '            </div>',
    '            <p v-if="error" class="mt-2 text-[12px] text-danger">{{ error }}</p>',
    '          </div>',
    '        </div>',
    '      </div>',

    '    </div>',
    '  </div>',
    '</div>'
  ].join('\n')
}, { root: 'help-center-inboxes' });
