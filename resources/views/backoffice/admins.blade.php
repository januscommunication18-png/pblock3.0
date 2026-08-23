@extends('backoffice.layout')
@section('title', 'Administrators')

@section('content')
  <div class="max-w-[1180px] mx-auto px-5 sm:px-8 py-8">
    <h1 class="text-[18px] font-semibold text-head">Administrators</h1>
    <p class="mt-1 text-[13px] text-sub max-w-[640px]">
      Back Office accounts are created without a password — each new administrator is emailed a
      link to set their own.
    </p>

    @if (session('status'))
      <p class="mt-4 text-[13px] text-ink bg-white border border-line rounded-lg px-4 py-3">{{ session('status') }}</p>
    @endif
    @error('admin')
      <p class="mt-4 text-[13px] text-danger bg-white border border-danger/30 rounded-lg px-4 py-3">{{ $message }}</p>
    @enderror

    <div class="mt-6 bg-white border border-line rounded-xl overflow-x-auto">
      <table class="w-full text-[13px]" style="min-width:820px">
        <thead>
          <tr class="text-left text-[12px] text-faint border-b border-line">
            <th class="py-2 pl-4 font-medium">Name</th>
            <th class="py-2 font-medium">Email</th>
            <th class="py-2 font-medium">Role</th>
            <th class="py-2 font-medium">Password</th>
            <th class="py-2 font-medium">Last sign-in</th>
            <th class="py-2 pr-4 font-medium text-right">Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($admins as $a)
            <tr class="border-b border-line last:border-0">
              <td class="py-2 pl-4 text-ink font-medium">{{ $a['name'] }}</td>
              <td class="py-2 text-sub _moretogether-break">{{ $a['email'] }}</td>
              <td class="py-2">
                <form method="POST" action="{{ route('backoffice.admins.update', $a['id']) }}" class="inline">
                  @csrf @method('PATCH')
                  <select name="role" onchange="this.form.submit()"
                          class="pb-input !h-8 text-[12px]"
                          @disabled($a['is_last_super_admin'])>
                    @foreach ($roles as $r)
                      <option value="{{ $r['value'] }}" @selected($r['value'] === $a['role'])>{{ $r['label'] }}</option>
                    @endforeach
                  </select>
                </form>
              </td>
              <td class="py-2 text-sub">{{ $a['has_password'] ? 'Set' : 'Not set yet' }}</td>
              <td class="py-2 text-sub whitespace-nowrap">{{ $a['last_login'] ?? '—' }}</td>
              <td class="py-2 pr-4 text-right">
                {{-- The last active Super Admin cannot be disabled or downgraded (§8). Disabled
                     in the UI AND refused by the model (BO-D6) — the control is a courtesy, the
                     model is the rule. --}}
                <form method="POST" action="{{ route('backoffice.admins.update', $a['id']) }}" class="inline">
                  @csrf @method('PATCH')
                  <input type="hidden" name="is_active" value="{{ $a['is_active'] ? 0 : 1 }}" />
                  <button type="submit" @disabled($a['is_active'] && $a['is_last_super_admin'])
                          @class(['h-7 px-2.5 rounded-md border text-[12px] font-semibold',
                                  'border-stroke text-ink hover:bg-hover' => ! ($a['is_active'] && $a['is_last_super_admin']),
                                  'border-line text-faint cursor-not-allowed' => $a['is_active'] && $a['is_last_super_admin']])
                          title="{{ $a['is_active'] && $a['is_last_super_admin'] ? 'The only active Super Admin cannot be disabled' : '' }}">
                    {{ $a['is_active'] ? 'Disable' : 'Enable' }}
                  </button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="mt-6 bg-white border border-line rounded-xl px-4 py-4 max-w-[560px]">
      <div class="text-[13px] font-medium text-ink">Add an administrator</div>
      <form method="POST" action="{{ route('backoffice.admins.store') }}" class="mt-3 space-y-3">
        @csrf
        <div>
          <label for="new-name" class="block text-[12px] font-medium text-ink mb-1">Name</label>
          <input id="new-name" name="name" required value="{{ old('name') }}" class="pb-input !h-9 w-full" />
          @error('name')<p class="mt-1 text-[12px] text-danger">{{ $message }}</p>@enderror
        </div>
        <div>
          <label for="new-email" class="block text-[12px] font-medium text-ink mb-1">Email</label>
          <input id="new-email" name="email" type="email" required value="{{ old('email') }}" class="pb-input !h-9 w-full" />
          @error('email')<p class="mt-1 text-[12px] text-danger">{{ $message }}</p>@enderror
        </div>
        <div>
          <label for="new-role" class="block text-[12px] font-medium text-ink mb-1">Role</label>
          <select id="new-role" name="role" class="pb-input !h-9 w-full">
            @foreach ($roles as $r)
              <option value="{{ $r['value'] }}">{{ $r['label'] }}</option>
            @endforeach
          </select>
        </div>
        <button type="submit"
                class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
          Add administrator
        </button>
      </form>
    </div>
  </div>
@endsection
