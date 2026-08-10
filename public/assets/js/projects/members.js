/* Project Settings › Members (PRJ-041). */
PB.boot('project-members', {
  props: { bootstrap: Object },
  data: function () {
    var b = this.bootstrap;
    return { members: b.members, candidates: b.candidates, roles: b.roles, endpoints: b.endpoints, addForm: { user_id: '', role: 'member' }, adding: false, confirm: { open: false, member: null } };
  },
  computed: {
    candidateOptions: function () {
      var have = {}; this.members.forEach(function (m) { have[String(m.user_id)] = true; });
      return this.candidates.filter(function (c) { return !have[String(c.id)]; }).map(function (c) { return { value: String(c.id), label: c.name + ' · ' + c.email }; });
    },
    roleOptions: function () { var r = this.roles; return Object.keys(r).map(function (k) { return { value: k, label: r[k] }; }); }
  },
  methods: {
    roleLabel: function (r) { return this.roles[r] || r; },
    add: async function () {
      if (!this.addForm.user_id || this.adding) return; this.adding = true;
      try { var resp = await this.$pb.api(this.endpoints.store, { method: 'POST', body: this.addForm }); this.members = resp.members; this.addForm = { user_id: '', role: 'member' }; this.$pb.toast('Member added.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.adding = false;
    },
    changeRole: async function (m, role) {
      try { var resp = await this.$pb.api(this.$pb.withId(this.endpoints.role, m.id), { method: 'PATCH', body: { role: role } }); this.members = resp.members; this.$pb.toast('Role updated.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
    },
    askRemove: function (m) { this.confirm = { open: true, member: m }; },
    doRemove: async function () {
      var m = this.confirm.member; if (!m) return;
      try { var resp = await this.$pb.api(this.$pb.withId(this.endpoints.remove, m.id), { method: 'DELETE' }); this.members = resp.members; this.$pb.toast('Member removed.'); }
      catch (e) { this.$pb.toast(this.$pb.firstError(e), 'error'); }
      this.confirm = { open: false, member: null };
    }
  },
  template:
    '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">' +
    '<pb-section-head title="Members" desc="Add people to this project and set their project role."/>' +

    '<div class="flex items-end gap-2 mb-5">' +
    '<div class="flex-1"><label class="block text-[13px] font-medium text-ink mb-1.5">Add member</label>' +
    '<pb-combo v-model="addForm.user_id" :options="candidateOptions" placeholder="Search workspace members…"/></div>' +
    '<div class="w-32"><pb-combo v-model="addForm.role" :options="roleOptions" :searchable="false"/></div>' +
    '<button class="h-11 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50" :disabled="!addForm.user_id || adding" @click="add">Add</button>' +
    '</div>' +

    '<div class="border border-line rounded-xl divide-y divide-line">' +
    '<div v-for="m in members" :key="m.id" class="flex items-center gap-3 px-4 py-3">' +
    '<span class="h-8 w-8 rounded-full bg-emerald-500 text-white grid place-items-center text-[12px] font-bold shrink-0">{{ m.initial }}</span>' +
    '<div class="min-w-0 flex-1"><div class="text-[13px] font-medium text-ink truncate">{{ m.name }}<span v-if="m.is_lead" class="text-[11px] bg-sel text-brand rounded px-1.5 py-0.5 ml-2">Lead</span></div>' +
    '<div class="text-[12px] text-faint truncate">{{ m.email }}</div></div>' +
    '<div class="w-32 shrink-0"><pb-combo :model-value="m.role" :options="roleOptions" :searchable="false" dense @update:model-value="function(v){ changeRole(m, v); }"/></div>' +
    '<button class="text-[12px] text-danger hover:opacity-80 px-1.5 shrink-0" @click="askRemove(m)">Remove</button>' +
    '</div></div>' +

    '<pb-confirm :open="confirm.open" title="Remove member?" :message="(confirm.member ? confirm.member.name : \'\') + \' will lose access to this project.\'" confirm-label="Remove" @close="confirm.open=false" @confirm="doRemove"/>' +
    '</div>'
});
