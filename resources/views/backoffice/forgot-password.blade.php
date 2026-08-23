@extends('backoffice.layout')
@section('title', 'Forgot Password')

@section('content')
  @component('backoffice.partials.shell')
    <h1 class="text-[16px] font-semibold text-head">Reset your password</h1>
    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">
      Enter your Back Office email address and we&rsquo;ll send you a link to set a new password.
    </p>

    <form method="POST" action="{{ route('backoffice.password.email') }}" class="mt-5">
      @csrf
      <label for="bo-forgot-email" class="block text-[12px] font-medium text-ink mb-1.5">Email Address</label>
      <input id="bo-forgot-email" name="email" type="email" required autofocus autocomplete="email"
             value="{{ old('email', $email ?? '') }}" class="pb-input !h-10 w-full" />

      @error('email')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <button type="submit"
              class="mt-5 w-full h-10 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
        Send Password Reset Link
      </button>
    </form>

    <div class="mt-5 pt-4 border-t border-line text-center">
      <a href="{{ route('backoffice.verify.show') }}"
         class="text-[12px] text-sub hover:text-ink hover:underline">Back to Back Office sign-in</a>
    </div>
  @endcomponent
@endsection
