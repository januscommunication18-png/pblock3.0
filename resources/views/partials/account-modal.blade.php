{{-- The account modal — Profile · Preference · Notification · Security (Account §1).

     Plain Blade and plain JS, not Vue. The topbar sits OUTSIDE `#settings-root`, so there is
     no Vue app mounted around it; the account menu next to this is wired the same way. Making
     this a component would mean booting a second Vue root on every page in the app to render
     a dialog that is closed almost all of the time.

     Layout follows html/designsystem.html and the settings mockups: 13px body text, `text-head`
     section titles, `border-line` rules, `rounded-xl` surfaces, and a footer rule above the
     primary action — the same shapes the in-app dialogs already use, so this reads as part of
     the app rather than as a new kind of window. --}}

@php
  // Resolved here rather than inherited. This is included from partials.account-menu, which is
  // included by three different shells — one sets `$__u`, the others set `$user`, and a fourth
  // caller may set neither. Reaching for one of those names blows up on the pages that use the
  // other, which is exactly what it did.
  $__acct = $__acct ?? ($__u ?? ($user ?? auth()->user()));

  // Normally already built by partials.account-menu, which renders the same values in its own
  // header. Rebuilt only if something included this dialog on its own.
  $__accountProfile = $__accountProfile ?? \App\Http\Controllers\Account\ProfileController::payload($__acct);

  // Language & Time is workspace-wide and owner/admin only (§2). Built only when it will be
  // shown — the timezone list alone is several hundred entries, and a user who cannot open
  // the tab should not be paying to render it into every page's HTML.
  $__accountCanManage = \App\Http\Controllers\Account\PreferenceController::allowed();
  $__accountPreference = $__accountCanManage
      ? \App\Http\Controllers\Account\PreferenceController::payload($__acct->currentWorkspace)
          + ['endpoint' => route('account.preference.update')]
      : [];
  $__accountEndpoints = [
      'update' => route('account.profile.update'),
      'password' => route('account.password.update'),
      'image' => route('account.profile.image.store'),
      'imageDestroy' => route('account.profile.image.destroy'),
  ];
@endphp

<div id="account-modal" class="hidden fixed inset-0 z-[95]" role="dialog" aria-modal="true" aria-labelledby="account-modal-title">
  <div class="absolute inset-0 bg-black/30" data-account-close></div>

  {{-- Sized by its content: no height, only a ceiling. The window is as tall as the tab you
       are on and no taller, so a short tab does not open onto a screenful of white.

       The trade-off, accepted deliberately: the window resizes when you switch tabs, because
       Profile is tall and the other three are a short empty state. --}}
  <div class="absolute inset-0 flex items-start justify-center p-4 sm:pt-10 pointer-events-none">
    <div class="pointer-events-auto w-full max-w-3xl bg-white border border-line rounded-xl shadow-2xl flex flex-col max-h-[calc(100vh-5rem)]">

      {{-- Header --}}
      <div class="flex items-center gap-2 px-5 h-14 border-b border-line shrink-0">
        <h2 id="account-modal-title" class="text-[15px] font-semibold text-head">Account</h2>
        <button type="button" data-account-close aria-label="Close"
                class="ml-auto h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
          {!! pb_icon('xmark', 16) !!}
        </button>
      </div>

      {{-- Tabs. Only Profile has content; the other three are declared so the shape of the
           window is honest about what is coming, and each says so rather than opening blank.

           No `overflow-x-auto`: four short labels always fit inside `max-w-3xl`, and the
           scroll container was drawing a grey scrollbar track at the end of the row for a
           scroll that can never happen. --}}
      <div class="flex items-center gap-1 px-4 border-b border-line shrink-0" role="tablist">
        @foreach (['profile' => 'Profile', 'preference' => 'Preference', 'notification' => 'Notification', 'security' => 'Change password'] as $key => $label)
          <button type="button" role="tab" data-account-tab="{{ $key }}"
                  class="h-10 px-3 text-[13px] border-b-2 -mb-px whitespace-nowrap
                         border-transparent text-sub hover:text-ink">{{ $label }}</button>
        @endforeach
      </div>

      <div class="flex-1 min-h-0 overflow-y-auto">

        {{-- ---------------- Profile ---------------- --}}
        <section data-account-panel="profile" class="hidden">
          {{-- The cover, built like the Add Project one (public/assets/js/projects/index.js):
               a tall banner that is a gradient until an image is uploaded, "Change cover" in the
               corner, the palette swatches at the bottom right, and an upload progress
               bar in their place while a file is going up. Same control, same place, same
               words — a cover should not mean something different here.

               The avatar overlaps its lower edge, which is the arrangement the account menu's
               own header already uses, so the two read as the same person's card. --}}
          <div class="relative">
            <div id="account-cover" class="h-44 bg-center bg-cover"></div>

            {{-- Top RIGHT, with Remove tucked in beside it. Grouped in one flex row rather than
                 positioned separately, so Remove appearing and disappearing shifts nothing —
                 the row grows leftwards from the corner and "Change cover" stays put. --}}
            <div class="absolute top-3 right-3 flex items-center gap-1.5">
              <button type="button" data-account-remove="cover"
                      class="hidden h-8 w-8 grid place-items-center rounded-md bg-white/85 text-sub hover:bg-white hover:text-danger shadow-sm"
                      aria-label="Remove cover">{!! pb_icon('trash', 14) !!}</button>
              <button type="button" data-account-upload="cover"
                      class="h-8 px-3 rounded-md bg-white/85 text-[12px] font-medium text-ink hover:bg-white shadow-sm">Change cover</button>
            </div>

            {{-- Palette. Hidden while uploading so it does not sit under the progress bar. --}}
            <div id="account-swatches" class="absolute bottom-3 right-3 flex items-center gap-1.5"></div>

            <div id="account-progress" class="hidden absolute inset-x-3 bottom-3 bg-white/95 rounded-md px-3 py-2 shadow">
              <div class="flex items-center justify-between mb-1">
                <span id="account-progress-name" class="text-[12px] text-sub truncate max-w-[70%]">Uploading…</span>
                <span id="account-progress-pct" class="text-[12px] text-sub tabular-nums">0%</span>
              </div>
              <div class="h-1.5 w-full bg-line rounded-full overflow-hidden">
                <div id="account-progress-bar" class="h-full bg-brand rounded-full transition-all duration-150" style="width:0%"></div>
              </div>
            </div>

            <div class="absolute -bottom-8 left-6 flex items-end gap-3">
              {{-- rounded-full plus a white ring, so the circle reads against any cover behind
                   it; bg-cover keeps a non-square upload from stretching inside it. --}}
              <span id="account-avatar"
                    class="h-20 w-20 rounded-full bg-brand text-white grid place-items-center text-[26px] font-semibold ring-4 ring-white bg-cover bg-center shadow-sm overflow-hidden"></span>
              <button type="button" data-account-upload="avatar"
                      class="mb-1 h-8 px-3 rounded-md border border-line bg-white text-[12px] font-medium text-ink hover:bg-hover">Change photo</button>
              <button type="button" data-account-remove="avatar"
                      class="hidden mb-1 h-8 w-8 grid place-items-center rounded-md border border-line bg-white text-sub hover:text-danger"
                      aria-label="Remove photo">{!! pb_icon('trash', 14) !!}</button>
            </div>
          </div>

          <form id="account-profile-form" class="px-6 pt-14 pb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-first-name">First name</label>
                <input id="account-first-name" name="first_name" type="text" maxlength="80" autocomplete="given-name"
                       class="w-full h-9 px-3 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
              </div>
              <div>
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-last-name">Last name</label>
                <input id="account-last-name" name="last_name" type="text" maxlength="80" autocomplete="family-name"
                       class="w-full h-9 px-3 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
              </div>

              <div>
                {{-- Read-only, and the server does not accept it either: changing the address
                     you sign in with needs verification, which is a different feature. --}}
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-email">Email address</label>
                <input id="account-email" type="email" readonly
                       class="w-full h-9 px-3 rounded-md border border-line bg-hover text-[13px] text-sub outline-none cursor-not-allowed" />
                <p class="mt-1 text-[11px] text-faint">Contact an administrator to change this.</p>
              </div>
              <div>
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-display-name">Display name</label>
                <input id="account-display-name" name="display_name" type="text" maxlength="120"
                       class="w-full h-9 px-3 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
                <p class="mt-1 text-[11px] text-faint">What others see. Defaults to your name.</p>
              </div>

              {{-- Signature (P74) — spans both columns, because a sign-off is several lines and
                   half a dialog's width would wrap every one of them.

                   A textarea rather than the project's rich editor: that editor is a Vue
                   component and this dialog is plain JS, so mounting it here would turn the
                   whole dialog into a Vue island for one field. The requirement asks for "a
                   rich-text OR formatted text field" and its own example is three plain lines,
                   which this holds exactly — and a textarea round-trips its own content, which
                   HTML in a textarea does not. --}}
              {{-- `grid-column` inline, not `sm:col-span-2`: no col-span utility is present in
                   the shipped tailwind.css, so the class would silently do nothing and the
                   textarea would sit in one half-width column. --}}
              <div style="grid-column: 1 / -1">
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-signature">Signature</label>
                <textarea id="account-signature" name="signature" rows="4" maxlength="2000"
                          placeholder="Thanks,&#10;{{ $__acct->displayName() }}&#10;Customer Support"
                          class="w-full px-3 py-2 rounded-md border border-line text-[13px] leading-relaxed outline-none focus:border-brand"></textarea>
                <p class="mt-1 text-[11px] text-faint">
                  Added to the bottom of your replies to customers in the Help Center. You can edit it
                  before sending. Leave it empty to send replies without a signature.
                </p>
              </div>
            </div>

            <p id="account-error" class="hidden mt-4 text-[12px] text-danger"></p>
          </form>
        </section>

        {{-- ---------------- Preference: Language & Time (§2) ----------------
             A Vue island in an otherwise plain-JS dialog, mounted by account/preference.js.
             It is the only way to reach <pb-combo>, the searchable combobox the workspace
             timezone setting already uses — and four pickers, two of them over hundreds of
             options, is not a place to hand-roll a second one.

             Owner/admin only, and the markup is not the gate: the request re-checks
             `manageSettings`. This is why the panel is absent rather than disabled for
             everyone else — a control you can see but not use invites the question. --}}
        <section data-account-panel="preference" class="hidden">
          @if ($__accountCanManage)
            <div id="account-preference-root" data-bootstrap='@json($__accountPreference)'>
              <div class="px-6 py-20 text-center text-[13px] text-sub">Loading…</div>
            </div>
          @else
            <div class="px-6 py-20 flex flex-col items-center justify-center text-center">
              <div class="h-11 w-11 rounded-xl bg-hover grid place-items-center text-sub">
                {!! pb_icon('lock', 20) !!}
              </div>
              <h3 class="mt-4 text-[14px] font-semibold text-head">Preference</h3>
              <p class="mt-1.5 text-[13px] text-sub">Language and time are set for the whole workspace<br />by an owner or an admin.</p>
            </div>
          @endif
        </section>

        {{-- ---------------- Change password (§4) ---------------- --}}
        <section data-account-panel="security" class="hidden">
          <form id="account-password-form" class="px-6 py-5" autocomplete="off">
            <h3 class="text-[14px] font-semibold text-head">Change password</h3>

            {{-- Only shown when there IS a current password. An account created with a login
                 code or through an identity provider has no credential row at all (D-A2), and
                 asking for the old password would lock out exactly the people setting their
                 first one. The request applies the same rule. --}}
            @if ($__acct->hasPassword())
              <div class="mt-4">
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-current-password">Current password</label>
                <div class="relative">
                  <input id="account-current-password" name="current_password" type="password" autocomplete="current-password"
                         placeholder="Old password"
                         class="w-full h-9 pl-3 pr-10 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
                  <button type="button" data-account-eye="account-current-password" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 grid place-items-center rounded text-faint hover:text-sub">
                    {!! pb_icon('eye', 15) !!}
                  </button>
                </div>
                <p data-account-pw-error="current_password" class="hidden mt-1 text-[12px] text-danger"></p>
              </div>
            @else
              <p class="mt-2 text-[13px] text-sub">You sign in with a login code. Set a password to sign in with one instead.</p>
            @endif

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-new-password">New password</label>
                <div class="relative">
                  <input id="account-new-password" name="password" type="password" autocomplete="new-password"
                         placeholder="Enter new password"
                         class="w-full h-9 pl-3 pr-10 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
                  <button type="button" data-account-eye="account-new-password" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 grid place-items-center rounded text-faint hover:text-sub">
                    {!! pb_icon('eye', 15) !!}
                  </button>
                </div>
                <p data-account-pw-error="password" class="hidden mt-1 text-[12px] text-danger"></p>
              </div>

              <div>
                <label class="block text-[13px] font-medium text-ink mb-1.5" for="account-confirm-password">Confirm password</label>
                <div class="relative">
                  <input id="account-confirm-password" name="password_confirmation" type="password" autocomplete="new-password"
                         placeholder="Confirm password"
                         class="w-full h-9 pl-3 pr-10 rounded-md border border-line text-[13px] outline-none focus:border-brand" />
                  <button type="button" data-account-eye="account-confirm-password" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 grid place-items-center rounded text-faint hover:text-sub">
                    {!! pb_icon('eye', 15) !!}
                  </button>
                </div>
              </div>
            </div>

            {{-- Said before it happens, not after. Being signed out is the surprising part of
                 this form, and a warning that arrives as a consequence is not a warning. --}}
            <p class="mt-4 text-[12px] text-sub">
              {!! pb_icon('circle-info', 13, 'text-faint inline-block align-[-2px] mr-1') !!}
              Changing your password signs you out everywhere. You will need to sign in again with the new one.
            </p>

            <button type="submit" id="account-password-submit"
                    class="mt-4 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">
              Change password
            </button>
          </form>
        </section>

        {{-- ---------------- The one not yet specified ----------------
             Rendered as a plain "not built yet" rather than as an empty form. An empty form
             implies settings that exist and are not saving; this says what it is. --}}
        @foreach (['notification' => 'Notification'] as $key => $label)
          {{-- Its own vertical padding, not `h-full`: the window is sized by its content now,
               so a panel that asked for the full height would have nothing to take it from. --}}
          <section data-account-panel="{{ $key }}" class="hidden">
            <div class="px-6 py-20 flex flex-col items-center justify-center text-center">
              <div class="h-11 w-11 rounded-xl bg-hover grid place-items-center text-sub">
                {!! pb_icon('gear', 20) !!}
              </div>
              <h3 class="mt-4 text-[14px] font-semibold text-head">{{ $label }}</h3>
              <p class="mt-1.5 text-[13px] text-sub">Not built yet.</p>
            </div>
          </section>
        @endforeach
      </div>

      {{-- Footer. Only Profile can save, so the bar hides itself on the other tabs rather than
           offering a button that would do nothing. --}}
      <div id="account-footer" class="hidden items-center gap-2 px-5 h-14 border-t border-line shrink-0">
        <button type="button" data-account-close
                class="ml-auto h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>
        <button type="button" id="account-save"
                class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Save changes</button>
      </div>
    </div>
  </div>

  {{-- One input, reused by both upload buttons; `data-kind` records which one opened it. --}}
  <input type="file" id="account-file" accept="image/*" class="hidden" />
</div>

{{-- Built in PHP, then handed to @json as a single variable. Passing the array literal inline
     does not survive: @json splits its arguments on commas without tracking square brackets,
     so a multi-key array is read as several arguments and the directive fails to compile. --}}

<script>
  window.PB_ACCOUNT = @json($__accountProfile);
  window.PB_ACCOUNT_ENDPOINTS = @json($__accountEndpoints);
</script>
<script defer src="{{ pb_asset('assets/js/account/profile.js') }}"></script>
@if ($__accountCanManage)
  <script defer src="{{ pb_asset('assets/js/account/preference.js') }}"></script>
@endif
