<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $email->subject }} — Email log</title>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <style>body{font-family:Inter,sans-serif}</style>
</head>
<body class="bg-[#f3f4f6] text-[#23272f]">
  <div class="max-w-2xl mx-auto px-5 py-8">
    <a href="{{ route('dev.emaillog') }}" class="text-[13px] text-[#2563eb] font-semibold hover:underline">&larr; Back to email log</a>
    <div class="bg-white border border-[#e5e7eb] rounded-xl mt-4 overflow-hidden">
      <div class="px-5 py-4 border-b border-[#e5e7eb]">
        <div class="text-[16px] font-bold text-[#0f0f10]">{{ $email->subject ?: '(no subject)' }}</div>
        <div class="text-[12px] text-[#6b7280] mt-1 space-y-0.5">
          <div><span class="text-[#9ca3af]">To:</span> {{ $email->to }}</div>
          <div><span class="text-[#9ca3af]">From:</span> {{ $email->from }}</div>
          <div><span class="text-[#9ca3af]">Sent:</span> {{ $email->created_at?->timezone('America/New_York')->format('l, M j, Y · g:i:s A T') }}</div>
          <div><span class="text-[#9ca3af]">Mailer:</span> {{ $email->mailer }}</div>
        </div>
      </div>
      <div class="p-2 bg-[#f9fafb]">
        @if($email->html_body)
          <iframe title="Email body" style="width:100%;height:520px;border:0;background:#fff;border-radius:8px" srcdoc="{{ $email->html_body }}"></iframe>
        @else
          <pre class="whitespace-pre-wrap text-[13px] p-4">{{ $email->text_body }}</pre>
        @endif
      </div>
    </div>
  </div>
</body>
</html>
