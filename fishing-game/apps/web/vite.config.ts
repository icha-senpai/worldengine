import { fileURLToPath } from 'node:url';
import adapter from '@sveltejs/adapter-node';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig, loadEnv } from 'vite';
import { svelteKitOutDir } from './vite-cache.ts';

export default defineConfig(({ mode }) => {
	const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), 'PUBLIC_');
	const database = process.env.PUBLIC_SPACETIMEDB_DATABASE ?? env.PUBLIC_SPACETIMEDB_DATABASE;
	return {
		server: {
			allowedHosts: ['fishbound.test'],
			proxy: { '/v1': { target: 'http://127.0.0.1:3127', ws: true } }
		},
		plugins: [
			sveltekit({
				outDir: svelteKitOutDir(database),
				files: { assets: '../../assets' },
				csp: {
					mode: 'auto',
					directives: {
					// The SpacetimeDB SDK builds table serializers with new Function(); first-party eval is required.
					'script-src': ['self', 'unsafe-eval'],
					'object-src': ['none'],
					'base-uri': ['self'],
					'frame-ancestors': ['none']
				}
				},
				compilerOptions: {
					// Force runes mode for the project, except for libraries. Can be removed in svelte 6.
					runes: ({ filename }) =>
						filename.split(/[/\\]/).includes('node_modules') ? undefined : true
				},

				adapter: adapter({ out: process.env.FISHBOUND_WEB_BUILD_DIR ?? 'build' })
			})
		]
	};
});
