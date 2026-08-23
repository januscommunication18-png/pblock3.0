{{-- The tenant's customer-facing subdomain (docs/features/workspace-subdomain.md, P72).

     ONE copy, included by both creation screens, for the reason the apps partial gives above
     it: this markup would otherwise live twice and the two would drift — and a subdomain rule
     that holds on one of the two screens is worse than one that holds on neither, because
     nobody would notice.

     HIDDEN until a customer-facing app is switched on. `data-subdomain-field` is what the
     script keys off; the apps partial does not know this exists. --}}
<div id="ws-subdomain-field"
     data-subdomain-field
     data-required-by='@json(App\Services\TenantSubdomain::requiredBy())'
     data-check-url="{{ route('workspaces.subdomain') }}"
     data-root="{{ App\Services\TenantSubdomain::root() }}"
     @class(['mt-5', 'hidden' => ! App\Services\TenantSubdomain::requiredFor((array) old('apps', config('workspace.default_apps', [])))])>

  <label class="block text-[13px] font-medium text-ink mb-1.5" for="ws-subdomain">
    Workspace Subdomain <span class="text-danger">*</span>
  </label>

  <div class="pb-group">
    <input id="ws-subdomain" name="subdomain" type="text" value="{{ old('subdomain') }}"
           placeholder="acme" class="pb-group__field" autocomplete="off" spellcheck="false"
           maxlength="{{ config('workspace.subdomain.max', 63) }}" />
    {{-- The zone as a SUFFIX, so the shape of what they are building is on screen while they
         type it. The slug field above uses the same control with the prefix on the other side. --}}
    <span class="pb-group__suffix">.{{ App\Services\TenantSubdomain::root() }}</span>
  </div>

  {{-- The three states the requirement asks for: the resulting address, "available", "taken".
       All three live in one line so the field does not change height as somebody types. --}}
  <p id="ws-subdomain-status" class="text-[12px] text-sub mt-1.5">
    Your customer-facing pages will live here, for example
    <span class="font-medium text-ink">{{ App\Services\TenantSubdomain::url('acme') }}/help</span>
  </p>

  @error('subdomain') <p class="text-[12px] text-danger mt-1">{{ $message }}</p> @enderror
</div>
