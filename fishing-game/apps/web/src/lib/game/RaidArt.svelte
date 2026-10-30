<script lang="ts">
  import { raidArtwork } from './raid-art';

  let {
    kind,
    grade,
    size = 32,
    alt = '',
    loading = 'lazy'
  }: {
    kind: string;
    grade?: number;
    size?: number;
    alt?: string;
    loading?: 'eager' | 'lazy';
  } = $props();

  const source = $derived(raidArtwork(kind, grade));
</script>

{#if source}
  <img src={source} {alt} width={size} height={size} {loading} />
{/if}

<style>
  img{display:inline-block;max-width:100%;object-fit:contain;image-rendering:pixelated;vertical-align:middle;flex:none}
</style>
