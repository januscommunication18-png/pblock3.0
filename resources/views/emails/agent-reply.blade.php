{{-- An agent's reply to the customer (docs/features/help-center.md, P36).

     Deliberately plainer than the confirmation: that one is a notice from a system and looks
     like it, while this is a person answering a question. Chrome around somebody's words makes
     them read as marketing, so there is a name, the words, and the ticket number in small print
     at the bottom for anyone who needs it. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#ffffff;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0">
        <tr><td style="padding:0 24px;">

          {{-- The reply itself.

               `{!! !!}` on `$bodyHtml` is safe by construction: it was run through
               RichTextSanitizer on the way into the database (P41), which is the same guarantee
               every other rich-text field in this application relies on — sanitize once on
               write rather than escape on every read.

               The plain-text branch is for messages stored before the editor existed, where
               `nl2br` on an escaped string preserves the line breaks the agent typed. --}}
          @if ($bodyHtml)
            <div style="font-size:15px;line-height:1.65;color:#23272f;">{!! $bodyHtml !!}</div>
          @else
            <div style="font-size:15px;line-height:1.65;color:#23272f;">{!! nl2br(e($body)) !!}</div>
          @endif

          @if ($agent)
            <div style="font-size:14px;color:#23272f;margin-top:20px;">{{ $agent }}</div>
          @endif
          @if ($spaceName)
            <div style="font-size:13px;color:#6b7280;">{{ $spaceName }}</div>
          @endif

          <div style="border-top:1px solid #e5e7eb;margin-top:24px;padding-top:12px;font-size:12px;color:#9ca3af;line-height:1.6;">
            Reply to this email to continue the conversation &mdash; please keep the subject line
            as it is so your message reaches the right ticket ({{ $ticket }}).
          </div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
