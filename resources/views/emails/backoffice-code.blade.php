{{-- The Back Office verification code (docs/features/backoffice-auth.md, §3).

     Deliberately plain and short. The one job is the code; everything else on the page is a
     place for the reader's eye to go instead. The warning at the bottom is the only addition,
     and it is there because this code opens platform administration rather than an account. --}}
<div style="font-family: Inter, -apple-system, Segoe UI, Helvetica, Arial, sans-serif; color: #23272f; line-height: 1.5;">
  <p style="margin: 0 0 16px; font-size: 15px; font-weight: 600; color: #0f0f10;">Back Office verification</p>

  <p style="margin: 0 0 20px; font-size: 14px;">
    Use this code to continue signing in to the Back Office.
  </p>

  <p style="margin: 0 0 20px; font-size: 30px; font-weight: 700; letter-spacing: 6px; color: #0f0f10;">
    {{ $code }}
  </p>

  <p style="margin: 0 0 20px; font-size: 13px; color: #6b7280;">
    This code expires in {{ $ttlMinutes }} minutes and can be used once.
  </p>

  <p style="margin: 0; font-size: 13px; color: #6b7280;">
    If you did not request it, somebody entered your address on the Back Office sign-in screen.
    The code alone does not grant access — a password is still required — but please tell your
    platform administrator.
  </p>
</div>
