@extends('backoffice.layout')
@section('title', 'Dashboard')

@section('content')
  <div class="max-w-[1180px] mx-auto px-5 sm:px-8 py-8">
    <h1 class="text-[18px] font-semibold text-head">Back Office</h1>
    <p class="mt-1 text-[13px] text-sub">Signed in as {{ $user->email }}.</p>

    <div class="grid gap-4 mt-6 sm:grid-cols-3">
      <div class="bg-white border border-line rounded-xl px-4 py-4">
        <div class="text-[22px] font-semibold text-head tabular-nums">{{ $adminCount }}</div>
        <div class="text-[12px] text-sub mt-0.5">Active administrators</div>
      </div>
      <div class="bg-white border border-line rounded-xl px-4 py-4">
        <div class="text-[22px] font-semibold text-head tabular-nums">{{ $superAdminCount }}</div>
        <div class="text-[12px] text-sub mt-0.5">Active Super Admins</div>
      </div>
      <div class="bg-white border border-line rounded-xl px-4 py-4">
        <div class="text-[22px] font-semibold text-head tabular-nums">{{ count($recentFailures) }}</div>
        <div class="text-[12px] text-sub mt-0.5">Recent failed attempts</div>
      </div>
    </div>

    {{-- Said plainly rather than hidden behind nine empty nav items (BO-D8). An administrator
         should be able to tell "not built yet" from "broken" without clicking. --}}
    <div class="mt-6 bg-white border border-line rounded-xl px-4 py-4">
      <div class="text-[13px] font-medium text-ink">Platform modules</div>
      <p class="text-[12px] text-sub mt-1 max-w-[640px] leading-relaxed">
        Customers, workspaces, plans, subscriptions, invoices, settings, integrations and
        system logs are not part of this build. What is here is the authentication and security
        boundary, plus administrator management.
      </p>
    </div>

    <div class="mt-6 bg-white border border-line rounded-xl">
      <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Recent security events</div>

      @if (count($recentEvents))
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]" style="min-width:760px">
            <thead>
              <tr class="text-left text-[12px] text-faint border-b border-line">
                <th class="py-2 pl-4 font-medium">When</th>
                <th class="py-2 font-medium">Action</th>
                <th class="py-2 font-medium">Email</th>
                <th class="py-2 font-medium">IP</th>
                <th class="py-2 pr-4 font-medium">Result</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($recentEvents as $e)
                <tr class="border-b border-line last:border-0">
                  <td class="py-2 pl-4 text-sub whitespace-nowrap">{{ $e['at'] }}</td>
                  <td class="py-2 text-ink font-mono text-[12px]">{{ $e['action'] }}</td>
                  <td class="py-2 text-sub _moretogether-break">{{ $e['email'] ?? '—' }}</td>
                  <td class="py-2 text-sub">{{ $e['ip'] ?? '—' }}</td>
                  <td class="py-2 pr-4">
                    <span class="_moretogether-badge {{ $e['succeeded'] ? '_moretogether-badge--ok' : '_moretogether-badge--off' }}">
                      {{ $e['succeeded'] ? 'OK' : 'Failed' }}
                    </span>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <p class="px-4 py-4 text-[12px] text-faint">Nothing recorded yet.</p>
      @endif
    </div>
  </div>
@endsection
