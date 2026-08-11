{{--
  Invitation information panel (invite spec §20): workspace, invited by, role, email, and the
  workspace logo when one is configured. Shared by the public landing page and the signed-in
  join step so both describe the invitation identically.
--}}
<div class="rounded-xl border border-line bg-[#f8f9fa] p-4">
  <div class="flex items-center gap-3">
    @if ($workspace->logo_url)
      <img src="{{ $workspace->logo_url }}" alt="" class="h-11 w-11 rounded-lg object-cover border border-line" />
    @else
      <span class="h-11 w-11 rounded-lg bg-brand text-white grid place-items-center text-[16px] font-bold">{{ $workspace->initial() }}</span>
    @endif
    <div class="min-w-0">
      <div class="text-[15px] font-semibold text-head truncate">{{ $workspace->name }}</div>
      <div class="text-[13px] text-sub">Invited by {{ $inviterName }}</div>
    </div>
  </div>

  <dl class="mt-4 space-y-2">
    <div class="flex items-center justify-between gap-3">
      <dt class="text-[13px] text-sub">Your role</dt>
      <dd class="text-[13px] font-semibold text-ink">{{ $roleLabel }}</dd>
    </div>
    <div class="flex items-center justify-between gap-3">
      <dt class="text-[13px] text-sub">Email</dt>
      <dd class="text-[13px] font-semibold text-ink truncate">{{ $invitation->email }}</dd>
    </div>
    @if ($invitation->expires_at)
      <div class="flex items-center justify-between gap-3">
        <dt class="text-[13px] text-sub">Expires</dt>
        <dd class="text-[13px] font-semibold text-ink">{{ $invitation->expires_at->format('F j, Y') }}</dd>
      </div>
    @endif
  </dl>
</div>
