@extends('layouts.auth')
@section('title', 'Onboarding · Profile — Project Block')

@section('body')
  {{-- Progress: step 1 / 5 --}}
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:20%"></div></div>

  <header class="flex items-center justify-between px-5 sm:px-10 py-5">
    <div class="flex items-center gap-3">
      <span class="h-8 w-8 invisible"></span>
      <a class="flex items-center gap-2" href="#">
        <svg width="24" height="24" viewBox="0 0 32 32" fill="#0f0f10"><path d="M5 21 L15 4 L20.5 4 L10.5 21 Z"/><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z"/></svg>
        <span class="text-[18px] font-bold tracking-tight text-head">Project Block</span>
      </a>
    </div>
    <div class="flex items-center gap-2">
      <div class="flex items-center gap-2 border border-line rounded-full pl-1 pr-3 py-1 text-[13px] text-ink">
        <span class="h-5 w-5 rounded-full bg-brand grid place-items-center text-white text-[9px] font-bold">{{ $user->initial() }}</span>
        <span>{{ $user->displayName() }}</span>
      </div>
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" title="Log out" aria-label="Log out"
          class="flex items-center gap-1.5 h-8 px-3 rounded-full border border-line text-[13px] text-sub hover:bg-hover hover:text-ink transition-colors">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="hidden sm:inline">Log out</span>
        </button>
      </form>
    </div>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[430px] py-6 sm:py-12">
      <h1 class="text-[24px] font-bold text-head">Create your profile.</h1>
      <p class="text-[15px] text-sub mb-7">This is how you will appear in Project Block.</p>

      @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger/40 bg-danger/5 px-3.5 py-2.5 text-[13px] text-danger">{{ $errors->first() }}</div>
      @endif

      {{-- Avatar --}}
      <div class="flex items-center gap-4 mb-5">
        <span id="avatar" class="h-14 w-14 rounded-full bg-brand grid place-items-center text-white text-[20px] font-semibold bg-cover bg-center shrink-0"
          @if($user->avatar_url) style="background-image:url('{{ $user->avatar_url }}')" @endif>{{ $user->avatar_url ? '' : $user->initial() }}</span>
        <button id="upload-btn" type="button" class="flex items-center gap-2 text-[14px] text-sub hover:text-ink">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="2" stroke="currentColor" stroke-width="1.8"/><path d="M4 18l5-4 4 3 3-2 4 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Upload image
        </button>
        <input id="avatar-input" type="file" accept="image/*" class="hidden" />
      </div>

      {{-- Upload progress --}}
      <div id="upload-progress" class="hidden mb-5">
        <div class="flex items-center justify-between mb-1">
          <span id="upload-name" class="text-[12px] text-sub truncate max-w-[280px]">Uploading…</span>
          <span id="upload-pct" class="text-[12px] text-sub tabular-nums">0%</span>
        </div>
        <div class="h-1.5 w-full rounded-full bg-line overflow-hidden">
          <div id="upload-bar" class="h-full bg-brand rounded-full transition-all duration-150" style="width:0%"></div>
        </div>
        <p id="upload-error" class="hidden mt-1 text-[12px] text-danger"></p>
      </div>

      <form method="POST" action="{{ route('onboarding.profile.store') }}">
        @csrf
        <label class="block text-[13px] font-medium text-ink mb-1.5" for="fullname">Name <span class="text-danger">*</span></label>
        <input id="fullname" name="full_name" type="text" value="{{ old('full_name', $user->full_name) }}" placeholder="Enter your full name" class="pb-input {{ $errors->has('full_name') ? 'is-error' : '' }}" />

        {{-- Optional password --}}
        <div class="mt-4 border border-line rounded-lg">
          <button id="pw-toggle" type="button" class="w-full flex items-center gap-2 h-11 px-3.5 text-[14px] text-sub">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="4" y="10" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 10V7a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.8"/></svg>
            Set a password (Optional)
            <svg id="pw-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" class="ml-auto transition-transform {{ $errors->has('password') ? 'rotate-180' : '' }}"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div id="pw-body" class="{{ $errors->has('password') ? '' : 'hidden' }} px-3.5 pb-3.5 space-y-3">
            <input type="hidden" name="set_password" id="set_password" value="{{ old('set_password', $errors->has('password') ? 1 : 0) }}" />
            <div>
              <label class="block text-[13px] text-sub mb-1.5">Set a password</label>
              <div class="relative">
                <input name="password" type="password" placeholder="Set a password" class="pw-input pb-input has-suffix {{ $errors->has('password') ? 'is-error' : '' }}" />
                <button type="button" class="pw-eye absolute right-3 top-1/2 -translate-y-1/2 text-faint hover:text-sub"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg></button>
              </div>
              @error('password')<p class="mt-1 text-[12px] text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
              <label class="block text-[13px] text-sub mb-1.5">Confirm password</label>
              <div class="relative">
                <input name="password_confirmation" type="password" placeholder="Confirm password" class="pw-input pb-input has-suffix" />
                <button type="button" class="pw-eye absolute right-3 top-1/2 -translate-y-1/2 text-faint hover:text-sub"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg></button>
              </div>
            </div>
          </div>
        </div>

        <button id="continue" type="submit" disabled
          class="mt-6 w-full h-11 rounded-lg text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">
          Continue
        </button>

        <label class="mt-5 flex items-start gap-2.5 text-[13px] text-ink cursor-pointer">
          <input type="checkbox" name="marketing_opt_in" value="1" {{ old('marketing_opt_in', $user->marketing_opt_in) ? 'checked' : '' }} class="mt-0.5 h-4 w-4 accent-[#1b5f8a]" />
          <span>I agree to Project Block marketing communications
            <span class="block text-[12px] text-sub">You may unsubscribe anytime. <a href="#" class="text-link hover:underline">Read our privacy policy.</a></span>
          </span>
        </label>
      </form>
    </div>
  </main>

  <script>
    var nameEl = document.getElementById('fullname');
    var cont = document.getElementById('continue');
    function gate() {
      var ok = nameEl.value.trim().length > 0;
      cont.disabled = !ok;
      cont.className = 'mt-6 w-full h-11 rounded-lg text-[14px] font-semibold transition-colors ' +
        (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
    }
    nameEl.addEventListener('input', gate);
    gate();

    // Collapsible "Set a password" — flips the set_password flag so the server validates it.
    document.getElementById('pw-toggle').addEventListener('click', function () {
      var body = document.getElementById('pw-body');
      var hidden = body.classList.toggle('hidden');
      document.getElementById('pw-chevron').classList.toggle('rotate-180');
      document.getElementById('set_password').value = hidden ? 0 : 1;
    });
    document.querySelectorAll('.pw-eye').forEach(function (eye) {
      eye.addEventListener('click', function (e) {
        e.preventDefault();
        var inp = eye.parentElement.querySelector('.pw-input');
        inp.type = inp.type === 'password' ? 'text' : 'password';
      });
    });

    // Real avatar upload via fetch with progress (ONB-002).
    (function () {
      var input = document.getElementById('avatar-input');
      var btn = document.getElementById('upload-btn');
      var avatar = document.getElementById('avatar');
      var wrap = document.getElementById('upload-progress');
      var bar = document.getElementById('upload-bar');
      var pct = document.getElementById('upload-pct');
      var lbl = document.getElementById('upload-name');
      var err = document.getElementById('upload-error');
      var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

      btn.addEventListener('click', function () { input.click(); });

      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;

        err.classList.add('hidden'); err.textContent = '';
        lbl.textContent = file.name;
        wrap.classList.remove('hidden');
        bar.style.width = '0%'; pct.textContent = '0%';

        var data = new FormData();
        data.append('avatar', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{{ route('onboarding.avatar.store') }}');
        xhr.setRequestHeader('X-CSRF-TOKEN', token);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) {
            var p = Math.round((e.loaded / e.total) * 100);
            bar.style.width = p + '%'; pct.textContent = p + '%';
          }
        };
        xhr.onload = function () {
          if (xhr.status >= 200 && xhr.status < 300) {
            var res = JSON.parse(xhr.responseText);
            avatar.style.backgroundImage = 'url(' + res.url + ')';
            avatar.textContent = '';
            setTimeout(function () { wrap.classList.add('hidden'); }, 400);
          } else {
            var msg = 'Upload failed. Please try a JPG/PNG/WebP under 2 MB.';
            try { var j = JSON.parse(xhr.responseText); if (j.errors && j.errors.avatar) msg = j.errors.avatar[0]; } catch (e) {}
            err.textContent = msg; err.classList.remove('hidden');
          }
        };
        xhr.onerror = function () { err.textContent = 'Upload failed. Check your connection and try again.'; err.classList.remove('hidden'); };
        xhr.send(data);
      });
    })();
  </script>
@endsection
