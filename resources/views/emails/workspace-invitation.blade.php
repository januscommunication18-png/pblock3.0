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
          {{--
            The headline and the line under it say what the person was actually invited to.
            An invitation sent from a Help Center Space passes that context in; one sent from
            Settings → Members passes nothing and keeps the wording it always had.
          --}}
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">
            {{ $contextTitle ?? "You've been invited to join a workspace" }}
          </h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;">
            {{-- Escaped, always: a Space name is typed by a user and reaches this template
                 unmodified, so it is untrusted content going into HTML. --}}
            @if ($contextLine)
              {{ $contextLine }}
            @else
              <strong style="color:#23272f;">{{ $inviterName }}</strong> has invited you to join
              <strong style="color:#23272f;">{{ $workspaceName }}</strong> on Project Block.
            @endif
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
            @if ($contextLabel && $contextValue)
              <tr>
                <td style="padding:12px 16px;font-size:13px;color:#6b7280;">{{ $contextLabel }}</td>
                <td style="padding:12px 16px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $contextValue }}</td>
              </tr>
              <tr>
                <td style="padding:0 16px 12px;font-size:13px;color:#6b7280;">Workspace</td>
                <td style="padding:0 16px 12px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $workspaceName }}</td>
              </tr>
            @else
              <tr>
                <td style="padding:12px 16px;font-size:13px;color:#6b7280;">Workspace</td>
                <td style="padding:12px 16px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $workspaceName }}</td>
              </tr>
            @endif
            <tr>
              <td style="padding:0 16px 12px;font-size:13px;color:#6b7280;">Your role</td>
              <td style="padding:0 16px 12px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $roleLabel }}</td>
            </tr>
            <tr>
              <td style="padding:0 16px 12px;font-size:13px;color:#6b7280;">Invited email</td>
              <td style="padding:0 16px 12px;font-size:13px;color:#23272f;font-weight:600;" align="right">{{ $invitedEmail }}</td>
            </tr>
          </table>
        </td></tr>

        <tr><td style="padding:20px 32px 4px;" align="center">
          <a href="{{ $acceptUrl }}"
             style="display:inline-block;background:#1b5f8a;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:8px;">
            Accept invitation
          </a>
        </td></tr>

        <tr><td style="padding:16px 32px 28px;">
          <p style="font-size:13px;color:#9ca3af;margin:0 0 8px;">This invitation expires on {{ $expiresOn }}.</p>
          <p style="font-size:12px;color:#9ca3af;margin:0;word-break:break-all;">
            If the button doesn't work, paste this link into your browser:<br />{{ $acceptUrl }}
          </p>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">&copy; {{ date('Y') }} Project Block</p>
    </td></tr>
  </table>
</body>
</html>
