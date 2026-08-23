{{-- The centred card the four unauthenticated screens share (§2, §3, §5, §6).

     One partial, because they are one flow: a visitor moving from the email screen to the code
     screen to the password screen should see the same card change its contents, not three
     different pages. --}}
<div class="min-h-screen flex items-start justify-center px-5 py-16">
  <div class="w-full max-w-[420px]">
    <div class="text-center mb-6">
      <div class="text-[15px] font-semibold text-head">Project Block</div>
      <div class="text-[12px] font-semibold text-faint uppercase tracking-wide mt-0.5">Back Office</div>
    </div>

    <div class="bg-white border border-line rounded-xl px-6 py-6">
      {!! $slot !!}
    </div>

    @if (session('status'))
      <p class="mt-4 text-[12px] text-sub text-center">{{ session('status') }}</p>
    @endif
  </div>
</div>
