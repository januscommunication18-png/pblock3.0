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
          {!! pb_icon('right-from-bracket', 16) !!}
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
          {!! pb_icon('image', 18) !!}
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
        <input id="fullname" name="full_name" type="text" value="{{ old('full_name', $user->full_name) }}" placeholder="Enter your full name" autocomplete="name" class="pb-input {{ $errors->has('full_name') ? 'is-error' : '' }}" />
        <p id="fullname-error" class="mt-1 text-[12px] text-danger {{ $errors->has('full_name') ? '' : 'hidden' }}">{{ $errors->first('full_name') ?: 'Please enter your name, not an email address.' }}</p>

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
                <button type="button" class="pw-eye absolute right-3 top-1/2 -translate-y-1/2 text-faint hover:text-sub">{!! pb_icon('eye', 17) !!}</button>
              </div>
              @error('password')<p class="mt-1 text-[12px] text-danger">{{ $message }}</p>@enderror

              {{-- Password strength — GUIDANCE, never a gate.

                   The bar and the chips are hints; `Continue` is not conditioned on them and the
                   server's own rule is what decides. That is the requirement's instruction and it
                   is also the honest arrangement: the only rule actually enforced here is
                   `Password::defaults()`, which in this application is a minimum of 8 characters.
                   Marking all five chips identically would tell somebody that four things are
                   required which are not, so the note below says which one is.

                   Widths are inline styles rather than `w-1/4` and friends: the shipped
                   tailwind.css does not contain those fractions, so they would silently do
                   nothing — the same trap the upload bar above already avoids. --}}
              <div id="pw-meter" class="mt-2">
                <div id="pw-bar-row" class="hidden flex items-center gap-2">
                  <div class="h-1.5 flex-1 rounded-full bg-line overflow-hidden">
                    <div id="pw-bar" class="h-full rounded-full bg-danger transition-all duration-200" style="width:0%"></div>
                  </div>
                  {{-- Announced politely so a screen-reader user hears the strength change
                       without the message interrupting what they are typing. --}}
                  <span id="pw-label" class="text-[11px] font-semibold text-faint" aria-live="polite"></span>
                </div>

                <ul id="pw-reqs" class="mt-2 flex flex-wrap gap-3 text-[11px] text-faint">
                  <li data-req="len"><span class="pw-tick">&middot;</span> 8+ characters</li>
                  <li data-req="upper"><span class="pw-tick">&middot;</span> Uppercase</li>
                  <li data-req="lower"><span class="pw-tick">&middot;</span> Lowercase</li>
                  <li data-req="number"><span class="pw-tick">&middot;</span> Number</li>
                  <li data-req="symbol"><span class="pw-tick">&middot;</span> Special character</li>
                </ul>

                <p class="mt-1 text-[11px] text-faint">Only the length is required — the rest make your password stronger.</p>
              </div>
            </div>
            <div>
              <label class="block text-[13px] text-sub mb-1.5">Confirm password</label>
              <div class="relative">
                <input name="password_confirmation" type="password" placeholder="Confirm password" class="pw-input pb-input has-suffix" />
                <button type="button" class="pw-eye absolute right-3 top-1/2 -translate-y-1/2 text-faint hover:text-sub">{!! pb_icon('eye', 17) !!}</button>
              </div>
            </div>
          </div>
        </div>

        <button id="continue" type="submit" disabled
          class="mt-6 w-full h-11 rounded-lg text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">
          Continue
        </button>

        <label class="mt-5 flex items-start gap-2.5 text-[13px] text-ink cursor-pointer">
          {{-- The hidden 0 FIRST, and it is not decoration.

               An unchecked checkbox submits nothing, so `old('marketing_opt_in')` is null both
               when the user deliberately unticked it and when there is no old input at all.
               With the box now ticked by default, those two cases are opposite intentions —
               without this, failing validation on the name field would silently re-tick a box
               the user had just cleared. PHP keeps the LAST value for a repeated name, so the
               checkbox overrides this whenever it is ticked. --}}
          <input type="hidden" name="marketing_opt_in" value="0" />
          <input type="checkbox" name="marketing_opt_in" value="1" {{ old('marketing_opt_in', 1) ? 'checked' : '' }} class="mt-0.5 h-4 w-4 accent-[#1b5f8a]" />
          <span>I agree to Project Block marketing communications
            <span class="block text-[12px] text-sub">You may unsubscribe anytime. <a href="#" class="text-link hover:underline">Read our privacy policy.</a></span>
          </span>
        </label>
      </form>
    </div>
  </main>

  <script>
    var nameEl = document.getElementById('fullname');
    var nameErr = document.getElementById('fullname-error');
    var cont = document.getElementById('continue');
    function nameValid(v) {
      v = (v || '').trim();
      if (v.length < 2) return false;
      if (v.indexOf('@') !== -1) return false;   // reject email-looking input
      if (!/\p{L}/u.test(v)) return false;        // must contain a letter
      return true;
    }
    function gate() {
      var v = nameEl.value;
      var typed = v.trim().length > 0;
      var ok = nameValid(v);
      cont.disabled = !ok;
      cont.className = 'mt-6 w-full h-11 rounded-lg text-[14px] font-semibold transition-colors ' +
        (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
      // Inline hint: only once they've typed something that isn't a valid name.
      var showErr = typed && !ok;
      if (nameErr) {
        nameErr.textContent = (v.indexOf('@') !== -1)
          ? 'Please enter your name, not an email address.'
          : 'Please enter a valid name.';
        nameErr.classList.toggle('hidden', !showErr);
      }
      nameEl.classList.toggle('is-error', showErr);
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
    /* ---- Password strength (guidance only) ----
       Recalculated on every keystroke from the field itself, so it can never disagree with what
       is in the box — no cached score, no debounce. It is a handful of regexes; the cost of
       running them per character is nothing next to the cost of showing a stale verdict.

       Nothing here touches `Continue`. The requirement is explicit that this is guidance and
       that the real rules are enforced on submit, so a user who wants an eight-character
       password the meter calls Weak is still allowed to have one. */
    (function () {
      var field = document.querySelector('input[name="password"]');
      var meter = document.getElementById('pw-meter');

      if (!field || !meter) return;

      var barRow = document.getElementById('pw-bar-row');
      var bar = document.getElementById('pw-bar');
      var label = document.getElementById('pw-label');
      var items = meter.querySelectorAll('#pw-reqs li');

      var TESTS = {
        len: function (v) { return v.length >= 8; },
        upper: function (v) { return /[A-Z]/.test(v); },
        lower: function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); },
        // Anything that is not a letter, a digit or a space. Deliberately broad rather than a
        // fixed list: a password containing `£` or `—` is not weaker for being unusual, and a
        // list would quietly refuse to credit characters somebody legitimately used.
        symbol: function (v) { return /[^A-Za-z0-9\s]/.test(v); }
      };

      /* Four levels: how many of the five hold, plus a bonus that GROWS with length.

         Length is counted more than once on purpose — it is the property that actually resists
         a guessing attack. A single bonus at 12 was not enough: it rated
         `correcthorsebatterystaple` as Fair while the nine-character `Abcdefg1!` came out
         Strong, which is guidance that pushes people toward the weaker of the two. A second
         step at 16 puts a real passphrase ahead of a short password wearing punctuation.

         Nothing under 8 can be better than Weak, whatever else it contains. */
      var LEVELS = [
        { name: 'Weak', bar: 'bg-danger', text: 'text-danger', width: '25%' },
        { name: 'Fair', bar: 'bg-warn', text: 'text-warn', width: '50%' },
        { name: 'Good', bar: 'bg-brand', text: 'text-brand', width: '75%' },
        { name: 'Strong', bar: 'bg-success', text: 'text-success', width: '100%' }
      ];

      function render() {
        var v = field.value || '';
        var met = 0;

        items.forEach(function (li) {
          var ok = TESTS[li.getAttribute('data-req')](v);

          if (ok) met++;

          li.querySelector('.pw-tick').innerHTML = ok ? '&#10003;' : '&middot;';
          li.classList.toggle('text-success', ok);
          li.classList.toggle('text-faint', !ok);
        });

        // The bar appears with the first character; an empty field showing "Weak" would be a
        // verdict on nothing.
        barRow.classList.toggle('hidden', v.length === 0);

        if (v.length === 0) return;

        var bonus = v.length >= 16 ? 2 : v.length >= 12 ? 1 : 0;
        var score = TESTS.len(v) ? met + bonus : 0;
        var level = LEVELS[score <= 2 ? 0 : score === 3 ? 1 : score === 4 ? 2 : 3];

        bar.style.width = level.width;
        bar.className = 'h-full rounded-full transition-all duration-200 ' + level.bar;
        label.textContent = level.name;
        label.className = 'text-[11px] font-semibold ' + level.text;
      }

      field.addEventListener('input', render);
      render();
    })();

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
