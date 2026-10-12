<script lang="ts">
  import { onMount } from 'svelte';
  import type { AnglerPortrait } from '@fishing-game/generated/types';
  let { portrait, name, compact = false }: { portrait?: AnglerPortrait; name: string; compact?: boolean } = $props();
  let reducedMotion = $state(true);
  let failed = $state(false);
  const url = $derived(portrait ? reducedMotion ? portrait.stillUrl : portrait.avatarUrl : '');
  $effect(() => { url; failed = false; });
  onMount(() => {
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const update = () => reducedMotion = preference.matches;
    update(); preference.addEventListener('change', update);
    return () => preference.removeEventListener('change', update);
  });
</script>

<div class="portrait" class:compact>
  {#if url && !failed}<img src={url} alt={name + ' Discord avatar'} width="128" height="128" loading="lazy" referrerpolicy="no-referrer" onerror={() => failed = true} />
  {:else}<span role="img" aria-label={name + ' profile placeholder'}>⚓</span>{/if}
</div>

<style>
  .portrait{display:grid;place-items:center;width:76px;height:76px;background:#456951;color:#ffe7a1;border:3px solid #c7ac6e;box-shadow:inset 0 0 0 2px #263e31;font-size:42px;flex:none;overflow:hidden}.portrait img{display:block;width:100%;height:100%;object-fit:cover}.portrait.compact{width:46px;height:46px;border-width:2px;font-size:30px}
</style>
