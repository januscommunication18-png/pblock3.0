@extends('backoffice.layout')
@section('title', 'Set a New Password')

@section('content')
  @component('backoffice.partials.shell')
    <h1 class="text-[16px] font-semibold text-head">Set a new password</h1>
    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">
      After saving you&rsquo;ll return to the Back Office sign-in and verify your email again.
    </p>

    <form method="POST" action="{{ route('backoffice.password.update') }}" class="mt-5">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}" />

      <label for="bo-reset-email" class="block text-[12px] font-medium text-ink mb-1.5">Email</label>
      <input id="bo-reset-email" name="email" type="email" required value="{{ old('email', $email) }}"
             class="pb-input !h-10 w-full" />
      @error('email')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <label for="bo-new-password" class="block text-[12px] font-medium text-ink mb-1.5 mt-4">New Password</label>
      <input id="bo-new-password" name="password" type="password" required autocomplete="new-password"
             class="pb-input !h-10 w-full" />
      {{-- The rule is stated BEFORE they type it, not after the server rejects it. --}}
      <p class="mt-1.5 text-[12px] text-faint">
        At least 12 characters, with upper and lower case, a number and a symbol.
      </p>
      @error('password')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <label for="bo-confirm" class="block text-[12px] font-medium text-ink mb-1.5 mt-4">Confirm Password</label>
      <input id="bo-confirm" name="password_confirmation" type="password" required
             autocomplete="new-password" class="pb-input !h-10 w-full" />

      <button type="submit"
              class="mt-5 w-full h-10 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
        Update Password
      </button>
    </form>
  @endcomponent
@endsection
