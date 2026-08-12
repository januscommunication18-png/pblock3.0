/** @type {import('tailwindcss').Config} */

/*
 * Project Block — Tailwind build config.
 * ------------------------------------------------------------------
 * Replaces the Tailwind Play CDN (`cdn.tailwindcss.com`), which compiled in the browser on
 * every page load. Nothing is fetched from a CDN any more: `npm run build:css` compiles this
 * into public/assets/css/tailwind.css, which the layouts link with pb_asset().
 *
 * Tailwind 3, deliberately — the same major the Play CDN served, so the compiled output is
 * what the app has always been styled against. v4 renames shadow-sm/rounded-sm/blur-sm to a
 * different step and changes the defaults for bare `border`, `ring` and `outline`, all of
 * which this UI leans on; moving major version is a separate job with a visual sweep.
 *
 * The theme below was `tailwind.config = {...}` in public/assets/js/tailwind.config.js, the
 * runtime config the CDN read. It moved here unchanged — same tokens, same values, one home.
 * ------------------------------------------------------------------
 */
export default {
  /*
   * Where class names live.
   *
   * The JS files matter as much as the Blade ones: this is a hybrid Vue-in-Blade app whose
   * components are string templates, so most of the UI's classes only ever appear inside
   * public/assets/js. Miss that glob and the app loads with almost no styling.
   *
   * Safe to scan as plain text because every class in this codebase is a literal — nothing
   * builds a class name by concatenation, which is the one pattern Tailwind cannot see.
   */
  content: [
    './resources/views/**/*.blade.php',
    './public/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'sans-serif'] },
      colors: {
        // Text
        head: '#0f0f10',   // headings / near-black
        ink: '#23272f',    // primary body text
        sub: '#6b7280',    // secondary text
        faint: '#9ca3af',  // muted / placeholder

        // Surfaces & borders
        line: '#e5e7eb',   // hairline borders
        stroke: '#d1d5db', // input borders (slightly stronger)
        hover: '#f3f4f6',  // hover fill
        sel: '#eef1f4',    // selected fill
        canvas: '#ffffff', // page background

        // Brand
        brand: '#1b5f8a',        // primary CTA (Project Block navy Continue)
        'brand-dark': '#164d70', // primary hover / pressed
        link: '#2563eb',         // hyperlinks (Sign in / Resend)

        // Status
        success: '#22c55e',
        warning: '#f59e0b',
        danger: '#ef4444',
      },
      borderRadius: {
        card: '10px',
      },
    },
  },
  plugins: [],
};
