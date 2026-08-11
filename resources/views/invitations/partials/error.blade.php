{{--
  Why an acceptance was refused (invite spec §54, §62, §67, §68). Messages are deliberately
  plain: they never mention tokens, ids or internal state (§73), and the seat message never
  points the invited user at a subscription — that is the administrator's problem (§67).
--}}
@php
  $invitationMessages = [
      'no_seat' => 'This workspace currently does not have an available member seat. Please contact the workspace administrator.',
      'workspace_unavailable' => 'This workspace is currently unavailable. Please contact the workspace administrator.',
      'email_mismatch' => 'This invitation was sent to a different email address.',
      'invalid' => 'This invitation is no longer valid. Please contact the workspace administrator for a new one.',
  ];
@endphp

@if (! empty($error))
  <div class="mb-4 rounded-lg border border-danger/40 bg-danger/5 px-3.5 py-2.5 text-[13px] text-danger">
    {{ $invitationMessages[$error] ?? $invitationMessages['invalid'] }}
  </div>
@endif
