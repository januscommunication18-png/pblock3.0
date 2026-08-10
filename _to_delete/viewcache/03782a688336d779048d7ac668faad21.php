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
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">Your verification code</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;">Enter this code to continue signing in to Project Block.</p>
        </td></tr>
        <tr><td style="padding:0 32px;">
          <div style="font-size:34px;font-weight:700;letter-spacing:10px;color:#1b5f8a;background:#eef1f4;border-radius:10px;text-align:center;padding:18px 0;"><?php echo e($code); ?></div>
        </td></tr>
        <tr><td style="padding:20px 32px 28px;">
          <p style="font-size:13px;color:#9ca3af;margin:0;">This code expires in <?php echo e($ttlMinutes); ?> minutes and can be used once. If you didn't request it, you can safely ignore this email.</p>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">&copy; <?php echo e(date('Y')); ?> Project Block</p>
    </td></tr>
  </table>
</body>
</html>
<?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/emails/login-code.blade.php ENDPATH**/ ?>