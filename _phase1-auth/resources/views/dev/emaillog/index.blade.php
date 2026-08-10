<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email log — Project Block (local)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>body{font-family:Inter,sans-serif}</style>
</head>
<body class="bg-[#f3f4f6] text-[#23272f]">
  <div class="max-w-3xl mx-auto px-5 py-8">
    <div class="flex items-center justify-between mb-5">
      <h1 class="text-[20px] font-bold text-[#0f0f10]">Email log <span class="text-[13px] font-medium text-[#9ca3af]">local outbound mail · times in New York (ET)</span></h1>
      <span class="text-[12px] text-[#6b7280]">{{ count($emails) }} message{{ count($emails) === 1 ? '' : 's' }}</span>
    </div>

    @if($emails->isEmpty())
      <div class="bg-white border border-[#e5e7eb] rounded-xl p-10 text-center text-[14px] text-[#6b7280]">
        No emails captured yet. Trigger a sign-up or sign-in to see the verification code here.
      </div>
    @else
      <div class="bg-white border border-[#e5e7eb] rounded-xl divide-y divide-[#e5e7eb]">
        @foreach($emails as $email)
          <a href="{{ route('dev.emaillog.show', $email) }}" class="flex items-center gap-4 px-5 py-3.5 hover:bg-[#f3f4f6] transition-colors">
            <div class="min-w-0 flex-1">
              <div class="text-[14px] font-semibold text-[#0f0f10] truncate">{{ $email->subject ?: '(no subject)' }}</div>
              <div class="text-[12px] text-[#6b7280] truncate">to {{ $email->to }}</div>
            </div>
            <div class="text-[12px] text-[#9ca3af] whitespace-nowrap text-right">
              {{ $email->created_at?->timezone('America/New_York')->format('M j, Y') }}<br>
              {{ $email->created_at?->timezone('America/New_York')->format('g:i:s A T') }}
            </div>
          </a>
        @endforeach
      </div>
    @endif
  </div>
</body>
</html>
