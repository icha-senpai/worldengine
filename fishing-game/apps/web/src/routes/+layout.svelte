<script lang="ts">
	import '@fontsource/silkscreen/latin-400.css';
	import '@fontsource/silkscreen/latin-700.css';
	import '@fontsource/nunito/latin-400.css';
	import '@fontsource/nunito/latin-700.css';
	import '#lib/game/theme.css';

	let { children } = $props();
</script>

<svelte:head>
	<link rel="icon" type="image/png" href="/fish/koi.png" />
</svelte:head>

{@render children()}
