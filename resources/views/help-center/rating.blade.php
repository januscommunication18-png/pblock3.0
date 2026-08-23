<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  {{-- Not indexable. A feedback form addressed to one person by a secret token has no business
       in a search result, and `noindex` is the difference between a leaked token and a leaked
       token somebody can find. --}}
  <meta name="robots" content="noindex, nofollow" />
  <title>{{ $state === 'ask' ? 'How did we do?' : 'Customer feedback' }}</title>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />

  {{-- The chosen-option styling, written out rather than reached for as Tailwind classes.

       `peer-checked:*` is not in the built stylesheet — it contains only what Tailwind found
       while scanning, and this page is new. On an internal screen the answer would be to rebuild;
       here it is better still, because this document loads for a stranger on an unknown device
       and every rule it needs being present in the page is one less thing that can be missing. --}}
  <style>
    .rate-opt input:checked + .rate-box { border-color: #2563eb; background: #eff6ff; }
    .rate-opt input:focus-visible + .rate-box { outline: 2px solid #2563eb; outline-offset: 2px; }
  </style>
</head>

{{-- The customer's rating page (docs/features/help-center.md, P56 §9).

     A standalone document, not the app layout: its visitor has no account, no sidebar to
     navigate and nothing else here to do. Rendering the application shell around it would offer
     a stranger a set of doors that all lead to a sign-in page.

     Plain Blade with no Vue. The whole interaction is "pick one of five, optionally type, press
     a button" — a form does that, works with JavaScript disabled, and needs no bundle. --}}
<body style="margin:0;background:#f9fafb;" class="text-ink">
  <div class="min-h-screen flex items-start justify-center px-4 py-12 sm:py-20">
    <div class="w-full" style="max-width:520px">

      @if ($state === 'ask')
        <form method="POST" action="{{ route('help-center.rating.store', ['token' => $rating->token]) }}"
              class="rounded-xl border border-line bg-white px-6 py-7">
          @csrf

          <h1 class="text-[20px] font-semibold text-head">How was your support experience?</h1>
          @if (!empty($spaceName))
            <p class="mt-1 text-[13px] text-sub">{{ $spaceName }}</p>
          @endif

          @if (!empty($error))
            <p class="mt-4 rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[13px] text-danger">{{ $error }}</p>
          @endif

          @php
            $points = $settings->points();
            $labels = $settings->labelList();
            $chosen = $score ?? null;
          @endphp

          {{-- Radios, styled as the Space's chosen scale.

               A real `<input type="radio">` under each glyph rather than JavaScript state: it is
               keyboard-navigable and submits without a bundle, which matters more here than on
               any internal screen — this page is opened on whatever device the customer happens
               to hold. --}}
          <fieldset class="mt-6">
            <legend class="sr-only">Your rating</legend>
            <div class="flex flex-wrap items-end gap-2">
              @for ($i = 1; $i <= $points; $i++)
                @php
                  $normalised = $settings->normalise($i);
                  $glyph = match ($settings->rating_type) {
                    'thumbs'  => $i === 2 ? '👍' : '👎',
                    'emoji5'  => ['😠','🙁','😐','🙂','😍'][min(4, $i - 1)],
                    'scale10' => (string) $i,
                    default   => '★',
                  };
                @endphp
                <label class="rate-opt cursor-pointer select-none text-center">
                  <input type="radio" name="score" value="{{ $i }}" class="sr-only"
                         @checked($chosen === $i) required />
                  <span class="rate-box block rounded-lg border border-stroke px-3 py-2 hover:bg-hover
                               {{ $settings->rating_type === 'scale10' ? 'text-[14px] font-semibold' : 'text-[26px] leading-none' }}">
                    {{ $glyph }}
                  </span>
                  @if ($points === 5 || $settings->rating_type === 'thumbs')
                    <span class="mt-1 block text-[11px] text-sub" style="max-width:78px">{{ $labels[$normalised] ?? '' }}</span>
                  @endif
                </label>
              @endfor
            </div>
          </fieldset>

          @if ($settings->allow_comment)
            <div class="mt-6">
              <label for="comment" class="block text-[13px] font-semibold text-ink mb-1">
                Tell us more about your experience
                @if ($settings->comment_requirement === 'always')
                  <span class="text-danger">*</span>
                @elseif ($settings->comment_requirement === 'low_only')
                  {{-- Said plainly rather than enforced silently: somebody who picks one star and
                       is then refused for an empty box should have been told first. --}}
                  <span class="font-normal text-faint">(required for a low rating)</span>
                @else
                  <span class="font-normal text-faint">(optional)</span>
                @endif
              </label>
              <textarea id="comment" name="comment" rows="4" maxlength="4000"
                        class="w-full rounded-md border border-stroke px-3 py-2 text-[14px]"
                        placeholder="What went well, or what could have gone better?">{{ $comment ?? '' }}</textarea>
            </div>
          @endif

          <button type="submit"
                  class="mt-6 inline-flex items-center h-10 px-5 rounded-md bg-brand text-white text-[14px] font-semibold hover:opacity-90">
            Submit Feedback
          </button>
        </form>

      @elseif ($state === 'thanks' || $state === 'done')
        <div class="rounded-xl border border-line bg-white px-6 py-8 text-center">
          <h1 class="text-[20px] font-semibold text-head">Thank you for your feedback.</h1>
          @if ($state === 'done')
            <p class="mt-2 text-[13px] text-sub">We have already recorded your rating for this request.</p>
          @else
            <p class="mt-2 text-[13px] text-sub">Your rating has been passed to the team.</p>
          @endif

          @if (!empty($rating) && $rating->isAnswered())
            <p class="mt-5 text-[24px] leading-none">{{ $rating->display() }}</p>
            <p class="mt-2 text-[13px] text-ink">{{ $settings->label((int) $rating->score) }}</p>
            @if ($rating->comment)
              <p class="mt-4 text-[13px] text-sub italic">&ldquo;{{ $rating->comment }}&rdquo;</p>
            @endif
          @endif
        </div>

      @elseif ($state === 'expired')
        <div class="rounded-xl border border-line bg-white px-6 py-8 text-center">
          <h1 class="text-[18px] font-semibold text-head">This rating request has expired.</h1>
          <p class="mt-2 text-[13px] text-sub">
            If you still need help, reply to the original email and the team will pick it up.
          </p>
        </div>

      @else
        {{-- One page for "no such token" and "withdrawn", on purpose.

             Telling the two apart would confirm to a stranger which tokens exist, which is an
             oracle worth more than the page it protects. --}}
        <div class="rounded-xl border border-line bg-white px-6 py-8 text-center">
          <h1 class="text-[18px] font-semibold text-head">This rating link is no longer available.</h1>
          <p class="mt-2 text-[13px] text-sub">
            The link may have been withdrawn or already used. Replying to the original email will
            still reach the team.
          </p>
        </div>
      @endif

    </div>
  </div>
</body>
</html>
