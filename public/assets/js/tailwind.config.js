/* Tailwind runtime config — must load right after the Tailwind CDN,
   before the page renders.
   ------------------------------------------------------------------
   Design tokens for Project Block — the single source of truth for
   colors + font across every page.
   In the Laravel + Vue build these become tailwind.config.js theme tokens
   (or CSS custom properties) so components stay consistent.
   ------------------------------------------------------------------ */
tailwind.config = {
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
        brand: '#1b5f8a',       // primary CTA (Project Block navy Continue)
        'brand-dark': '#164d70',// primary hover / pressed
        link: '#2563eb',        // hyperlinks (Sign in / Resend)

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
};
