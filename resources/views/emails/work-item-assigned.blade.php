<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">
        <tr><td style="padding:28px 32px 4px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">Project Block</div>
        </td></tr>

        <tr><td style="padding:16px 32px 0;">
          <div style="font-size:12px;color:#6b7280;letter-spacing:.04em;">{{ $identifier }}</div>
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:4px 0 12px;">{{ $title }}</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;">
            <strong style="color:#23272f;">{{ $assignerName }}</strong> assigned this work item to you in
            <strong style="color:#23272f;">{{ $projectName }}</strong>.
          </p>
        </td></tr>

        <tr><td style="padding:0 32px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;">
            <tr><td style="padding:12px 16px 4px;font-size:13px;font-weight:600;color:#23272f;">Updates</td></tr>
            <tr>
              <td style="padding:4px 16px 14px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                  <tr>
                    <td style="padding:12px 14px;font-size:13px;color:#6b7280;">Assignee</td>
                    <td style="padding:12px 14px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $assigneeName }}</td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </td></tr>

        <tr><td style="padding:20px 32px 4px;">
          <a href="{{ $url }}"
             style="display:inline-block;background:#1b5f8a;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:11px 22px;border-radius:8px;">
            View work item
          </a>
        </td></tr>

        <tr><td style="padding:18px 32px 28px;">
          <p style="font-size:12px;color:#9ca3af;margin:0;word-break:break-all;">{{ $url }}</p>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">&copy; {{ date('Y') }} Project Block</p>
    </td></tr>
  </table>
</body>
</html>
