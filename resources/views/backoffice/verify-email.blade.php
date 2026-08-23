@extends('backoffice.layout')
@section('title', 'Security Verification')

@section('content')
  @component('backoffice.partials.shell')
    <h1 class="text-[16px] font-semibold text-head">Back Office Security Verification</h1>
    <p class="mt-1.5 text-[13px] text-sub leading-relaxed">
      Enter your authorized email address to continue.
    </p>

    <form method="POST" action="{{ route('backoffice.verify.store') }}" class="mt-5">
      @csrf
      <label for="bo-email" class="block text-[12px] font-medium text-ink mb-1.5">Email Address</label>
      <input id="bo-email" name="email" type="email" required autofocus autocomplete="email"
             value="{{ old('email') }}"
             class="pb-input !h-10 w-full" placeholder="you@company.com" />

      {{-- One message for every failure (BO-D7): unknown, disabled and unauthorized are
           indistinguishable here on purpose. --}}
      @error('email')
        <p class="mt-1.5 text-[12px] text-danger">{{ $message }}</p>
      @enderror

      <button type="submit"
              class="mt-5 w-full h-10 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
        Continue
      </button>
    </form>
  @endcomponent
@endsection
