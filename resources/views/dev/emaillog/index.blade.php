<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email log — Project Block (local)</title>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <style>body{font-family:Inter,sans-serif}</style>
</head>
<body class="bg-[#f3f4f6] text-[#23272f]">
  <div class="max-w-3xl mx-auto px-5 py-8">
    <div class="flex items-center justify-between mb-5">
      <h1 class="text-[20px] font-bold text-[#0f0f10]">Email log <span class="text-[13px] font-medium text-[#9ca3af]">local outbound mail · times in New York (ET)</span></h1>
      <div class="flex items-center gap-3">
        <span class="text-[12px] text-[#6b7280]">{{ count($emails) }} message{{ count($emails) === 1 ? '' : 's' }}</span>
        @unless($emails->isEmpty())
          {{-- Emptying the log cannot be undone, so it asks first. --}}
          <form method="POST" action="{{ route('dev.emaillog.destroy') }}"
                onsubmit="return confirm('Delete all captured emails? This cannot be undone.')">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="h-8 px-3 rounded-lg border border-[#e5e7eb] bg-white text-[13px] font-semibold text-[#b42318] hover:bg-[#fef3f2] hover:border-[#fda29b] transition-colors">
              Delete all
            </button>
          </form>
        @endunless
      </div>
    </div>

    @if(session('status'))
      <div class="mb-4 rounded-lg border border-[#e5e7eb] bg-white px-4 py-2.5 text-[13px] text-[#23272f]">
        {{ session('status') }}
      </div>
    @endif

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
