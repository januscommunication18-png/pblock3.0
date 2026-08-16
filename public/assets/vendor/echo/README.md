# Laravel Echo + Pusher JS — vendored

The websocket client behind live Inbox updates (inbox §27), talking to Laravel Reverb.

Vendored rather than bundled, like every other dependency here: nothing is fetched from a CDN
at runtime, and this app has no Vite/JS build step for its own scripts — the Blade layouts link
plain files with `pb_asset()`. `echo.iife.js` is the browser global build for exactly that case
(the ESM one would need a bundler); `pusher.min.js` is the protocol client Echo drives.

Regenerate after `npm update` with:

    cp node_modules/laravel-echo/dist/echo.iife.js public/assets/vendor/echo/
    cp node_modules/pusher-js/dist/web/pusher.min.js public/assets/vendor/echo/

Sources: https://github.com/laravel/echo · https://github.com/pusher/pusher-js (both MIT)
