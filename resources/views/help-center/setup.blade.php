{{-- Help Center Space onboarding — the six-step wizard (docs/features/help-center.md, P2 §2).

     This now extends the shared Help Center layout, so the module's left navigation is beside
     it like every other screen.

     It deliberately did NOT, when the wizard was only a first run: §1 asks for onboarding to be
     shown "instead of displaying an empty Help Desk interface", and a wizard framed by a Help
     Center navigation with nothing in it was exactly that. That reasoning stopped applying when
     "Spaces +" started leading here — creating a second Space is not onboarding, and hiding the
     Spaces you already have while you add another is the more confusing of the two. On a true
     first run the nav simply says "No Spaces yet", which is honest and still gives the rail,
     the workspace switcher and a way out. --}}
@extends('help-center.layout')

@section('title', 'Set up your Help Center')

@push('scripts')
  {{-- wizard.js is the six-step flow. The older three-step setup.js is left on disk but is no
       longer loaded — it targets endpoints that no longer exist. --}}
  <script defer src="{{ pb_asset('assets/js/help-center/wizard.js') }}"></script>
@endpush

@section('content')
  {{-- One Vue root for all six steps: they are one flow over one draft, and splitting them
       would mean each step re-fetching what the one before it already knows. --}}
  <div id="help-center-setup" data-bootstrap="{{ json_encode($bootstrap) }}">
    <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
  </div>
@endsection
