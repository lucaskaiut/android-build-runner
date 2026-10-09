/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const apiTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://nginx:80'
const allowedHosts = ['builds.noxtecnologias.com.br']

const proxy = {
  '/api': { target: apiTarget, changeOrigin: false },
  '/sanctum': { target: apiTarget, changeOrigin: false },
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
    },
  },
  server: {
    allowedHosts,
    proxy,
  },
  preview: {
    allowedHosts,
    proxy,
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.ts',
  },
})
