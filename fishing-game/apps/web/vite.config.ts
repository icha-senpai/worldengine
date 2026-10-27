import adapter from '@sveltejs/adapter-node';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
	server: {
		allowedHosts: ['fishbound.test'],
		proxy: { '/v1': { target: 'http://127.0.0.1:3127', ws: true } }
	},
	plugins: [
		sveltekit({
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
});
