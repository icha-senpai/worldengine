import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { sveltekit } from '@sveltejs/kit/vite';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const fixture = fileURLToPath(new URL('.', import.meta.url));
export default defineConfig({
  root: fixture,
  plugins: [sveltekit({
    outDir: `${root}/.local/site-errors/kit`,
    files: {
      assets: `${root}/assets`,
      appTemplate: `${root}/apps/web/src/app.html`,
      errorTemplate: `${root}/apps/web/src/error.html`,
    },
  })],
  server: { host: '127.0.0.1', port: 5183, strictPort: true, fs: { allow: [root] } },
});
