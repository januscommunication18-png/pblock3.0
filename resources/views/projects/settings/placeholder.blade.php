@extends('projects.settings.layout')

@push('section-script')
  <script defer>
    document.addEventListener('DOMContentLoaded', function () {
      var root = document.getElementById('settings-root'); if (!root) return;
      var b = {}; try { b = JSON.parse(root.getAttribute('data-bootstrap') || '{}'); } catch (e) {}
      root.innerHTML =
        '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-16 text-center">' +
        '<span class="inline-block text-[11px] bg-amber-100 text-amber-700 rounded-md px-2 py-0.5 mb-3">Coming soon</span>' +
        '<h1 class="text-[20px] font-bold text-head">' + (b.label || 'Settings') + '</h1>' +
        '<p class="text-[13px] text-sub mt-2 max-w-md mx-auto">This project settings area is on the way.</p></div>';
    });
  </script>
@endpush
