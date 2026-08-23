{{-- The shell around a Space's own email template (docs/features/help-center.md, P48).

     Deliberately almost nothing: a white page, a centred 560px column, and the template's HTML
     dropped into it. Everything a reader sees — greeting, wording, signature, ticket reference,
     footer — comes from the Space's template now, which is the point of the feature. A Blade
     view that added a header or a footer of its own would be a second layout competing with the
     one an administrator can actually edit.

     `{!! !!}` on $html is safe by construction. It is the output of EmailTemplateRenderer, whose
     substitution escapes every value except the ones config marks as `html` — and each of those
     is markup this application sanitized on the way in (P41). Same guarantee the rest of the
     module runs on: sanitize once on write, never escape on read. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#ffffff;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0">
        <tr><td style="padding:0 24px;font-size:15px;line-height:1.65;color:#23272f;">
          {!! $html !!}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
