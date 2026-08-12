/* Tailwind 3 runs through PostCSS. Used by the Vite pipeline (the stock welcome page) and
   available to the CLI build; the app's own pages link the pre-built
   public/assets/css/tailwind.css instead. */
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
