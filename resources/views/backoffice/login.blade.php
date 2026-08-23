@extends('backoffice.layout')
@section('title', 'Sign In')

@section('content')
  @component('backoffice.partials.shell')
    <h1 class="text-[16px] font-semibold text-head">Back Office Login</h1>
    <p class="mt-1.5 text-[13px] text-sub">Identity verified. Enter your password to continue.</p>

    <form method="POST" action="{{ route('backoffice.login.store') }}" class="mt-5">
      @csrf

      <label for="bo-login-email" class="block text-[12px] font-medium text-ink mb-1.5">Email</label>
      {{-- READ-ONLY, and the server ignores whatever arrives in it anyway (BO-D4).

           `readonly` rather than `disabled`: a disabled input is not submitted at all, and the
           field is here to tell the person which account they are signing into. The value the
           controller uses comes from the verification session either way. --}}
      <input id="bo-login-email" type="email" value="{{ $email }}" readonly
             class="pb-input !h-10 w-full bg-hover text-sub cursor-not-allowed" />

      <label for="bo-password" class="block text-[12px] font-medium text-ink mb-1.5 mt-4">Password</label>
      <input id="bo-password" name="password" type="password" required autofocus
             autocomplete="current-password" class="pb-input !h-10 w-full" />

      @error('password')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <label class="flex items-center gap-2 mt-4 text-[13px] text-ink cursor-pointer">
        <input type="checkbox" name="remember" value="1" class="accent-brand" />
        Remember me
      </label>

      <button type="submit"
              class="mt-5 w-full h-10 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
        Sign In
      </button>
    </form>

    <div class="mt-5 pt-4 border-t border-line text-center">
      <a href="{{ route('backoffice.password.request') }}"
         class="text-[12px] text-brand hover:underline">Forgot Password?</a>
    </div>
  @endcomponent
@endsection
