{{-- The inbound test probe (docs/features/help-center.md, P7).

     Written for the human who finds it in their support inbox and wonders what it is — a bare
     token would look like spam and might well be deleted before the forwarding rule fires. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="440" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
        <tr><td style="padding:28px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">Project Block</div>
        </td></tr>
        <tr><td style="padding:8px 32px 0;">
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">Inbound email test</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 16px;">
            This is an automated test. Your Help Desk sent it to check that mail arriving at this
            address is forwarded into ProjectBlock. No reply is needed &mdash; you can delete it.
          </p>
          <p style="font-size:14px;color:#6b7280;margin:0 0 8px;">Test reference:</p>
          <div style="font-family:ui-monospace,Menlo,monospace;font-size:14px;color:#1b5f8a;background:#eef1f4;border-radius:8px;padding:12px 14px;word-break:break-all;">{{ $token }}</div>
          <p style="font-size:12px;color:#9ca3af;margin:16px 0 24px;">
            Expected to be forwarded to {{ $inbound }}
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
