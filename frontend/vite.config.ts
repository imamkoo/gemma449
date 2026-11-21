import { defineConfig } from 'vite'
import react, { reactCompilerPreset } from '@vitejs/plugin-react'
import babel from '@rolldown/plugin-babel'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  plugins: [
    tailwindcss(),
    react(),
    babel({ presets: [reactCompilerPreset()] }),
    VitePWA({
      registerType: 'prompt',
      injectRegister: false,
      includeAssets: [
        'favicon-32.png',
        'apple-touch-icon.png',
        'icons/icon-192.png',
        'icons/icon-512.png',
        'icons/maskable-512.png',
        'assets/images/logos/logo-gemma449.png',
      ],
      manifest: {
        name: 'GEMMA449 — Gerakan Menulis Al-Qur\'an Sumedang',
        short_name: 'GEMMA449',
        description: 'GEMMA449 — Gerakan Menulis Al-Qur\'an Sumedang. Pendaftaran mushaf dan kontribusi nyata untuk Kabupaten Sumedang.',
        lang: 'id',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        background_color: '#f8fafc',
        theme_color: '#1b5e20',
        orientation: 'portrait',
        categories: ['social', 'lifestyle', 'productivity'],
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [
          /^\/api(\/|$)/,
          /^\/admin(\/|$)/,
          /^\/livewire(\/|$)/,
          /^\/up$/,
        ],
        // Gambar tidak di-precache saat install; di-cache on-demand lewat runtimeCaching.
        globPatterns: ['**/*.{js,css,html,svg,woff2}'],
        globIgnores: ['**/icons/icon-1024.png'],
        cleanupOutdatedCaches: true,
        clientsClaim: true,
        runtimeCaching: [
          {
            urlPattern: ({ url }) => /\/api\/(districts|batches|universities)/.test(url.pathname),
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'gemma449-catalog',
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 * 7 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            // Harga bisa diubah admin kapan saja — jangan sajikan cache 7 hari.
            // NetworkFirst: selalu ambil harga terbaru saat online, cache hanya untuk offline.
            urlPattern: ({ url }) => /\/api\/price-categories/.test(url.pathname),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'gemma449-prices',
              networkTimeoutSeconds: 3,
              expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ url }) => /\/api\/school-options(\/?$|\?)/.test(url.pathname + url.search),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'gemma449-schools',
              networkTimeoutSeconds: 4,
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: /\/assets\/images\/.*\.(png|svg|webp|jpg|jpeg)$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'gemma449-images',
              expiration: { maxEntries: 80, maxAgeSeconds: 60 * 60 * 24 * 30 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ url }) => /\/api\/(register|midtrans|registrations\/status|school-options\/match)/.test(url.pathname),
            handler: 'NetworkOnly',
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
})
