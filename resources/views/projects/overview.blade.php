<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $project->name }} — Overview</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>
</head>

{{-- Project → Overview (Project Overview §1).

     Read-only, and plain Blade with no Vue root: nothing here changes without a page load, so
     a screen that only reads should not ship a runtime to do it. The moment inline editing
     lands this becomes a mounted component like the other screens. --}}
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      {{-- The Overview | Milestones segment.
           Milestones appears only when the feature is on — decided by the controller from the
           same flag ProjectNavigation reads, so the segment and the tab bar cannot disagree,
           and the /milestones URL 404s while it is off rather than opening an empty screen. --}}
      <div class="flex items-center gap-2 px-5 sm:px-6 h-12 border-b border-line shrink-0">
        <div class="inline-flex items-center gap-0.5 p-0.5 rounded-lg bg-hover" role="tablist">
          @php($segments = ['overview' => ['Overview', route('projects.overview', $project)]])
          @if ($hasMilestones)
            @php($segments['milestones'] = ['Milestones', route('projects.milestones', $project)])
          @endif

          @foreach ($segments as $key => [$label, $url])
            <a href="{{ $url }}" role="tab"
               @if ($segment === $key) aria-current="page" @endif
               class="h-8 px-3 inline-flex items-center rounded-md text-[13px] whitespace-nowrap
                      {{ $segment === $key ? 'bg-white text-ink font-medium shadow-sm' : 'text-sub hover:text-ink' }}">
              {{ $label }}
            </a>
          @endforeach
        </div>
      </div>

      <div class="flex-1 min-h-0 flex overflow-hidden">

        {{-- ---------------- main column ---------------- --}}
        <div class="flex-1 min-w-0 overflow-y-auto">
          @if ($segment === 'milestones')
            <div class="px-6 py-24 flex flex-col items-center justify-center text-center">
              <div class="h-11 w-11 rounded-xl bg-hover grid place-items-center text-sub">
                {!! pb_icon('circle-info', 20) !!}
              </div>
              <h2 class="mt-4 text-[15px] font-semibold text-head">Milestones</h2>
              <p class="mt-1.5 text-[13px] text-sub max-w-sm">
                The feature is switched on for this project, but milestones themselves are not built yet.
              </p>
            </div>
          @else
            {{-- Cover. An uploaded image if there is one, otherwise the project's gradient —
                 the same fallback the project card uses, so a project looks the same in both
                 places. --}}
            <div class="h-40 w-full bg-center bg-cover"
                 style="background: {{ $project->cover_url ? "center/cover url('".e($project->cover_url)."')" : e($project->cover_gradient ?: '#9ca3af') }}"></div>

            <div class="px-6 sm:px-8">
              <div class="-mt-7">
                <span class="h-14 w-14 rounded-xl bg-white border border-line grid place-items-center text-[26px] shadow-sm">{{ $project->emoji ?: '📁' }}</span>
              </div>

              <h1 class="mt-4 text-[20px] font-bold text-head">{{ $project->name }}</h1>

              @if ($project->description)
                <p class="mt-2 text-[13px] text-sub max-w-3xl">{{ $project->description }}</p>
              @else
                <p class="mt-2 text-[13px] text-faint">No description.</p>
              @endif

              {{-- ---------------- progress ---------------- --}}
              <div class="mt-8 border-t border-line pt-6 pb-10">
                <h2 class="text-[13px] font-semibold text-head">Progress</h2>

                @if ($progress['total'] === 0)
                  <p class="mt-3 text-[13px] text-faint">No work items yet.</p>
                @else
                  {{-- Segments are sized from the RAW fraction, not the rounded percentage
                       shown below: five values rounded to whole numbers can total 101, which
                       would push the last segment out of the bar. --}}
                  <div class="mt-3 h-2 w-full rounded-full bg-line overflow-hidden flex">
                    @foreach ($progress['groups'] as $group)
                      @if ($group['count'] > 0)
                        <span class="h-full" title="{{ $group['label'] }}: {{ $group['count'] }}"
                              style="width: {{ $group['fraction'] * 100 }}%; background: {{ $group['color'] }}"></span>
                      @endif
                    @endforeach
                  </div>

                  <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    @foreach ($progress['groups'] as $group)
                      <div>
                        <div class="flex items-center gap-1.5 text-[13px] text-ink">
                          <span class="h-2 w-2 rounded-full shrink-0" style="background: {{ $group['color'] }}"></span>
                          {{ $group['label'] }}
                        </div>
                        <div class="mt-0.5 text-[13px] text-sub tabular-nums">
                          {{ $group['count'] }} <span class="text-faint">{{ $group['percent'] }}%</span>
                        </div>
                      </div>
                    @endforeach
                  </div>
                @endif
              </div>
            </div>
          @endif
        </div>

        {{-- ---------------- properties ----------------
             Every row reads a column that already exists on the project, so this panel and
             Project settings cannot show different answers. Read-only on purpose: editing
             lives in settings, and a second set of controls for one field is a second place
             for them to disagree. --}}
        <aside class="hidden lg:flex w-[320px] shrink-0 flex-col border-l border-line overflow-y-auto">
          <div class="px-5 py-4">
            <h2 class="text-[13px] font-semibold text-head">Properties</h2>

            <dl class="mt-3 space-y-3">
              @foreach ($properties as $row)
                <div class="flex items-start gap-3">
                  <dt class="flex items-center gap-2 w-[110px] shrink-0 text-[13px] text-sub">
                    {!! pb_icon($row['icon'], 15, 'text-faint shrink-0') !!}
                    {{ $row['label'] }}
                  </dt>
                  <dd class="min-w-0 flex-1 text-[13px] text-ink">{{ $row['value'] }}</dd>
                </div>
              @endforeach
            </dl>

            @if ($canManage)
              <a href="{{ route('projects.settings', ['project' => $project->id, 'section' => 'general']) }}"
                 class="mt-5 inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">
                {!! pb_icon('gear', 14, 'text-sub') !!}
                Project settings
              </a>
            @endif
          </div>
        </aside>
      </div>
    </main>
  </div>
</body>
</html>
