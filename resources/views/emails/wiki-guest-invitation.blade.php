<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="440" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">
        <tr><td style="padding:28px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">Project Block</div>
        </td></tr>

        <tr><td style="padding:8px 32px 0;">
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">A document has been shared with you</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;">
            Hello {{ $guestName }} — <strong style="color:#23272f;">{{ $inviterName }}</strong> has
            shared <strong style="color:#23272f;">{{ $collectionName }}</strong> with you.
          </p>
        </td></tr>

        @if ($workspaceLogoUrl)
          <tr><td style="padding:0 32px 16px;">
            <img src="{{ $workspaceLogoUrl }}" alt="{{ $workspaceName }}" width="48" height="48"
                 style="width:48px;height:48px;border-radius:10px;border:1px solid #e5e7eb;object-fit:cover;" />
          </td></tr>
        @endif

        <tr><td style="padding:0 32px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;">
            <tr>
              <td style="padding:12px 16px;font-size:13px;color:#6b7280;">Shared by</td>
              <td style="padding:12px 16px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $workspaceName }}</td>
            </tr>
            <tr>
              <td style="padding:0 16px 12px;font-size:13px;color:#6b7280;">Document</td>
              <td style="padding:0 16px 12px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $collectionName }}</td>
            </tr>
          </table>
        </td></tr>

        <tr><td style="padding:20px 32px 4px;" align="center">
          <a href="{{ $openUrl }}"
             style="display:inline-block;background:#1b5f8a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 22px;border-radius:8px;">
            Open the document
          </a>
        </td></tr>

        {{-- No password, by design: the link IS how they get in. Said plainly, because somebody
             expecting to make an account will otherwise go looking for a sign-up form. --}}
        <tr><td style="padding:12px 32px 0;">
          <p style="font-size:13px;color:#6b7280;margin:0;">
            There is nothing to sign up for and no password to set — this link is how you get in,
            and it will keep working until access is withdrawn.
          </p>
        </td></tr>

        {{-- The link is a credential. Whoever holds it can read the document, so say so rather
             than letting somebody forward it believing it is just a URL. --}}
        <tr><td style="padding:16px 32px 28px;">
          <p style="font-size:12px;color:#9ca3af;margin:0;">
            Please keep this link to yourself — anyone who has it can read the document. It gives
            access to this document only, and to nothing else in {{ $workspaceName }}.
          </p>
        </td></tr>
      </table>

      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">Sent by Project Block</p>
    </td></tr>
  </table>
</body>
</html>
