<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">
        <tr><td style="padding:28px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">Project Block</div>
        </td></tr>

        <tr><td style="padding:8px 32px 0;">
          <p style="font-size:14px;color:#6b7280;margin:0 0 6px;">
            <strong style="color:#23272f;">{{ $updatedBy }}</strong> updated a work item in
            <strong style="color:#23272f;">{{ $projectName }}</strong>.
          </p>
          {{-- Name first, ID in brackets — the format used everywhere a work item is named. --}}
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:4px 0 16px;">
            {{ $title }} <span style="font-weight:400;color:#6b7280;">({{ $identifier }})</span>
          </h1>
        </td></tr>

        {{-- The changes themselves. This is the whole point of the email: somebody who can see
             what moved from their inbox does not have to open the app to find out. --}}
        <tr><td style="padding:0 32px;">
          <div style="font-size:12px;font-weight:700;color:#6b7280;letter-spacing:.04em;margin-bottom:8px;">CHANGES</div>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;">
            @foreach ($changes as $change)
              <tr>
                <td style="padding:10px 16px;font-size:13px;color:#6b7280;width:35%;">{{ $change['field'] }}</td>
                <td style="padding:10px 16px;font-size:13px;color:#23272f;">
                  <span style="color:#9ca3af;text-decoration:line-through;">{{ $change['old'] }}</span>
                  &rarr;
                  <strong>{{ $change['new'] }}</strong>
                </td>
              </tr>
            @endforeach
          </table>
        </td></tr>

        <tr><td style="padding:16px 32px 0;font-size:13px;color:#6b7280;">
          <strong style="color:#23272f;">Updated by:</strong> {{ $updatedBy }}<br />
          <strong style="color:#23272f;">Updated at:</strong> {{ $updatedAt }}
        </td></tr>

        <tr><td style="padding:20px 32px 4px;" align="center">
          <a href="{{ $url }}"
             style="display:inline-block;background:#1b5f8a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 22px;border-radius:8px;">
            View work item
          </a>
        </td></tr>

        <tr><td style="padding:14px 32px 28px;">
          <p style="font-size:13px;color:#6b7280;margin:0;">
            Open the work item to review the latest details and activity.
          </p>
        </td></tr>
      </table>

      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">Sent by Project Block</p>
    </td></tr>
  </table>
</body>
</html>
