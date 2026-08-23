{{-- The customer's acknowledgement (docs/features/help-center.md, P27).

     Written for somebody who is not a user of this product and never will be: no ProjectBlock
     vocabulary, no link into an app they cannot sign into, nothing to do. It answers the three
     questions a person has after emailing support — did it arrive, what do I quote, and is
     anyone going to reply — and then gets out of the way.

     Same table-based shell as emails/inbound-test.blade.php, for the same reason: mail clients
     are not browsers. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
        <tr><td style="padding:28px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">{{ $spaceName ?: 'Support' }}</div>
        </td></tr>

        <tr><td style="padding:8px 32px 0;">
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">We&rsquo;ve received your request</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;line-height:1.6;">
            {{ $customer ? 'Hello '.$customer.',' : 'Hello,' }} thanks for getting in touch. Your
            email reached our support team and we&rsquo;ve opened a ticket for it. There&rsquo;s
            nothing you need to do &mdash; someone will follow up on this shortly.
          </p>

          {{-- The Ticket Number, given the weight it is asked for: it is the one thing in this
               email the customer may need to quote back. --}}
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin:0 0 20px;">
            <tr><td style="padding:16px 18px;">
              <div style="font-size:12px;color:#9ca3af;margin:0 0 4px;">Your ticket number</div>
              <div style="font-family:ui-monospace,Menlo,monospace;font-size:20px;font-weight:700;color:#1b5f8a;">{{ $ticket }}</div>

              <div style="font-size:12px;color:#9ca3af;margin:16px 0 4px;">Subject</div>
              <div style="font-size:14px;color:#23272f;word-break:break-word;">{{ $subject }}</div>

              @if ($receivedAt)
                <div style="font-size:12px;color:#9ca3af;margin:16px 0 4px;">Received</div>
                <div style="font-size:14px;color:#23272f;">{{ $receivedAt->format('M j, Y \a\t g:i A') }}</div>
              @endif
            </td></tr>
          </table>

          <p style="font-size:13px;color:#6b7280;margin:0 0 28px;line-height:1.6;">
            If you have anything to add, simply reply to this email &mdash; your reply will be
            attached to the same ticket, so please keep the subject line as it is.
          </p>
        </td></tr>

        <tr><td style="padding:0 32px 28px;">
          <div style="border-top:1px solid #e5e7eb;padding-top:14px;font-size:12px;color:#9ca3af;line-height:1.6;">
            This message was sent automatically to confirm we received your email. Please do not
            share passwords or payment details by email.
          </div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
