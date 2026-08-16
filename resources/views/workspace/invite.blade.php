@extends('layouts.auth')
@section('title', 'Invite teammates — Project Block')

@php
    // Server-render existing rows so validation errors + prior input survive a redirect.
    $oldInvites = old('invites', []);
    $rowCount = max($rows, count($oldInvites));
    $placeholders = ['charlie.taylor@company.com', 'octave.chanute@company.com', 'george.spratt@company.com'];
@endphp

@section('body')
  <!-- Minimal header: back (left) · title · close (right) — matches Create workspace -->
  <header class="relative h-14 shrink-0 border-b border-line flex items-center px-4">
    <a href="{{ route('welcome') }}" title="Back" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('chevron-left', 18) !!}
    </a>
    <span class="absolute left-1/2 -translate-x-1/2 font-semibold text-[15px] text-head">Invite teammates</span>
    <a href="{{ route('welcome') }}" title="Close" class="ml-auto h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('xmark', 18) !!}
    </a>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[460px] py-10 sm:py-14">
      <h1 class="text-[24px] font-bold text-head">Invite your teammates</h1>
      <p class="text-[15px] text-sub mb-8">Add people to <span class="font-medium text-ink">{{ $workspace->name }}</span>. They'll appear as pending until they accept.</p>

      @if ($errors->has('invites'))
        <div class="mb-4 rounded-md border border-danger/40 bg-red-50 px-3 py-2 text-[13px] text-danger">{{ $errors->first('invites') }}</div>
      @endif

      <form method="POST" action="{{ route('workspaces.invite.store') }}" id="invite-form">
        @csrf
        <div class="grid grid-cols-[1fr_140px] gap-3 mb-2">
          <span class="text-[13px] font-medium text-ink">Email</span>
          <span class="text-[13px] font-medium text-ink">Role</span>
        </div>

        <div id="invite-list" class="space-y-3" data-next="{{ $rowCount }}">
          @for ($i = 0; $i < $rowCount; $i++)
            @php
              $rowEmail = $oldInvites[$i]['email'] ?? '';
              $rowRole  = $oldInvites[$i]['role'] ?? '';
              $emailErr = $errors->first("invites.$i.email");
              $roleErr  = $errors->first("invites.$i.role");
            @endphp
            <div class="invite-row">
              <div class="grid grid-cols-[1fr_140px] gap-3">
                <input type="email" name="invites[{{ $i }}][email]" value="{{ $rowEmail }}"
                  placeholder="{{ $placeholders[$i] ?? 'name@company.com' }}"
                  class="pb-input {{ $emailErr ? 'border-danger ring-1 ring-danger' : '' }}" />
                <div class="relative pb-combo">
                  <input type="hidden" name="invites[{{ $i }}][role]" class="pb-combo-input" value="{{ $rowRole }}" />
                  <button type="button" class="pb-combo-btn pb-input text-left cursor-pointer {{ $roleErr ? 'border-danger ring-1 ring-danger' : '' }}" aria-haspopup="listbox" aria-expanded="false">
                    <span class="flex items-center justify-between h-full">
                      <span class="pb-combo-value {{ $rowRole ? 'text-ink' : 'text-faint' }} truncate">{{ $rowRole ? $roleLabels[$rowRole] : 'Select role' }}</span>
                      {!! pb_icon('chevron-down', 16, 'text-faint shrink-0 ml-1') !!}
                    </span>
                  </button>
                  <ul role="listbox" class="pb-combo-list hidden absolute z-40 mt-1 w-full max-h-56 overflow-auto rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">
                    @foreach ($roles as $rk)
                      <li role="option" data-val="{{ $rk }}" class="group relative flex items-center cursor-pointer select-none py-2 pl-3 pr-9 text-[14px] text-ink hover:bg-brand hover:text-white">
                        <span class="block truncate">{{ $roleLabels[$rk] }}</span>
                        <span class="pb-combo-check {{ $rowRole === $rk ? '' : 'hidden' }} absolute inset-y-0 right-0 flex items-center pr-3 text-brand group-hover:text-white">{!! pb_icon('check-thin', 16) !!}</span>
                      </li>
                    @endforeach
                  </ul>
                </div>
              </div>
              @if ($emailErr) <p class="text-[12px] text-danger mt-1">{{ $emailErr }}</p> @endif
              @if ($roleErr) <p class="text-[12px] text-danger mt-1">{{ $roleErr }}</p> @endif
            </div>
          @endfor
        </div>

        <button type="button" id="add-invite" class="flex items-center gap-1.5 mt-4 text-[14px] font-semibold text-link hover:underline">
          {!! pb_icon('plus', 16) !!}
          Add another
        </button>

        <div class="flex items-center gap-3 mt-8">
          <button type="submit" id="send" class="h-10 px-5 rounded-md text-[14px] font-semibold bg-brand hover:bg-brand-dark text-white transition-colors">Send invitations</button>
          <a href="{{ route('welcome') }}" class="h-10 px-5 grid place-items-center rounded-md border border-stroke text-[14px] font-semibold text-ink hover:bg-hover">Go back</a>
        </div>
      </form>
    </div>
  </main>

  {{-- Template for a fresh row appended by "Add another" (__I__ is replaced with the next index). --}}
  <template id="invite-row-template">
    <div class="invite-row">
      <div class="grid grid-cols-[1fr_140px] gap-3">
        <input type="email" name="invites[__I__][email]" placeholder="name@company.com" class="pb-input" />
        <div class="relative pb-combo">
          <input type="hidden" name="invites[__I__][role]" class="pb-combo-input" />
          <button type="button" class="pb-combo-btn pb-input text-left cursor-pointer" aria-haspopup="listbox" aria-expanded="false">
            <span class="flex items-center justify-between h-full">
              <span class="pb-combo-value text-faint truncate">Select role</span>
              {!! pb_icon('chevron-down', 16, 'text-faint shrink-0 ml-1') !!}
            </span>
          </button>
          <ul role="listbox" class="pb-combo-list hidden absolute z-40 mt-1 w-full max-h-56 overflow-auto rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">
            @foreach ($roles as $rk)
              <li role="option" data-val="{{ $rk }}" class="group relative flex items-center cursor-pointer select-none py-2 pl-3 pr-9 text-[14px] text-ink hover:bg-brand hover:text-white">
                <span class="block truncate">{{ $roleLabels[$rk] }}</span>
                <span class="pb-combo-check hidden absolute inset-y-0 right-0 flex items-center pr-3 text-brand group-hover:text-white">{!! pb_icon('check-thin', 16) !!}</span>
              </li>
            @endforeach
          </ul>
        </div>
      </div>
    </div>
  </template>

  <script>
    (function () {
      var listEl = document.getElementById('invite-list');
      var tpl = document.getElementById('invite-row-template');
      var next = parseInt(listEl.getAttribute('data-next'), 10) || 0;

      document.getElementById('add-invite').addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__I__/g, String(next));
        listEl.insertAdjacentHTML('beforeend', html);
        next++;
      });

      function closeAllCombos() {
        listEl.querySelectorAll('.pb-combo-list').forEach(function (ul) { ul.classList.add('hidden'); });
      }
      listEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-combo-btn');
        if (btn) {
          e.stopPropagation();
          var ul = btn.parentElement.querySelector('.pb-combo-list');
          var willOpen = ul.classList.contains('hidden');
          closeAllCombos();
          if (willOpen) ul.classList.remove('hidden');
          return;
        }
        var li = e.target.closest('.pb-combo-list [data-val]');
        if (li) {
          var combo = li.closest('.pb-combo');
          var valEl = combo.querySelector('.pb-combo-value');
          var hidden = combo.querySelector('.pb-combo-input');
          hidden.value = li.getAttribute('data-val');
          valEl.textContent = li.querySelector('.block').textContent;
          valEl.classList.remove('text-faint');
          valEl.classList.add('text-ink');
          combo.querySelectorAll('.pb-combo-check').forEach(function (c) { c.classList.add('hidden'); });
          li.querySelector('.pb-combo-check').classList.remove('hidden');
          combo.querySelector('.pb-combo-list').classList.add('hidden');
        }
      });
      document.addEventListener('click', function (e) {
        if (!e.target.closest('.pb-combo')) closeAllCombos();
      });
    })();
  </script>
@endsection
