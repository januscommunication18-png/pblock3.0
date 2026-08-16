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
          <p style="font-size:15px;color:#23272f;margin:0 0 16px;">
            <strong>{{ $actorName }}</strong> mentioned you in {{ $where }}.
          </p>
          <div style="font-size:12px;color:#6b7280;letter-spacing:.04em;">{{ $identifier }}</div>
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:4px 0 4px;">{{ $title }}</h1>
          <p style="font-size:13px;color:#6b7280;margin:0 0 18px;">{{ $projectName }}</p>
        </td></tr>

        {{-- What was actually said. A notification that only says "you were mentioned" makes
             everyone open the app to find out whether it mattered. --}}
        @if ($excerpt !== '')
          <tr><td style="padding:0 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-left:3px solid #1b5f8a;border-radius:0 8px 8px 0;">
              <tr><td style="padding:14px 16px;font-size:14px;color:#23272f;line-height:1.55;">“{{ $excerpt }}”</td></tr>
            </table>
          </td></tr>
        @endif

        @if (!empty($description))
          {{-- The work item's own description, under its own heading so it is never confused
               with what the person actually said. --}}
          <tr><td style="padding:16px 32px 0;">
            <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">About this work item</div>
            <p style="font-size:14px;color:#23272f;line-height:1.55;margin:6px 0 0;">{{ $description }}</p>
          </td></tr>
        @endif

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
