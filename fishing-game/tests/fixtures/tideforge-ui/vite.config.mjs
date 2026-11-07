import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const fixture = fileURLToPath(new URL('./fixture-game.svelte.ts', import.meta.url));
export default defineConfig({
  root: fileURLToPath(new URL('.', import.meta.url)),
  publicDir: `${root}/assets`,
  plugins: [{
    name: 'tideforge-fixture-alias',
    enforce: 'pre',
    resolveId(source, importer) {
      if (source === '$app/env/public') return '\0tideforge-fixture-env';
      if (source === './game.svelte' && importer?.replaceAll('\\', '/').endsWith('/game/Dashboard.svelte')) return fixture;
    },
    load(id) {
      if (id === '\0tideforge-fixture-env') return 'export const PUBLIC_DEPLOY_COMMIT="fixture"; export const PUBLIC_SPACETIMEDB_URI=""; export const PUBLIC_SPACETIMEDB_DATABASE=""; export const PUBLIC_ACCOUNT_LINK_URL="";';
    },
    configureServer(server) {
      server.middlewares.use('/api/time', (request, response, next) => {
        if (request.method !== 'GET') return next();
        response.setHeader('Content-Type', 'application/json');
        response.setHeader('Cache-Control', 'no-store');
        response.end(JSON.stringify({ serverTimeMs: Date.now() }));
      });
    },
  }, svelte({ configFile: false, compilerOptions: { runes: true } })],
  server: { host: '127.0.0.1', port: 5182, strictPort: true, fs: { allow: [root] } },
});
