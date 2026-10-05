<script lang="ts">
  import type { ItemStack } from '@fishing-game/generated/types';

  let { items }: { items: ItemStack[] } = $props();
  const definitions: Record<string, { name: string; image: string; kind: string }> = {
    rusted_tin: { name: 'Rusted tin', image: '/items/rusted-tin.png', kind: 'Junk' },
    scrap: { name: 'Scrap', image: '/items/scrap.png', kind: 'Material' }
  };
  const stacks = $derived(items.filter(item => item.quantity > 0n).slice().sort((a, b) => a.item.localeCompare(b.item)));
</script>

<div class="item-storage" role="region" aria-label="Junk and materials">
  <div class="heading"><h3>Junk &amp; materials</h3><p>Little finds from your casts.</p></div>
  {#if stacks.length}
    <div class="item-grid">
      {#each stacks as item (item.key)}
        {@const definition = definitions[item.item]}
        <article class="item-stack" aria-label={(definition?.name ?? item.item.replaceAll('_', ' ')) + ': ' + item.quantity.toString()}>
          <div class="item-art" aria-hidden="true">
            {#if definition}<img src={definition.image} alt="" width="96" height="96" />{:else}<span>✦</span>{/if}
          </div>
          <div class="item-info"><small>{definition?.kind ?? 'Item'}</small><h4>{definition?.name ?? item.item.replaceAll('_', ' ')}</h4></div>
          <div class="quantity"><small>Owned</small><strong>×{item.quantity.toString()}</strong></div>
        </article>
      {/each}
    </div>
  {:else}
    <p class="empty-items">Rusted tins and scrap from your casts will appear here.</p>
  {/if}
</div>

<style>
  .item-storage{margin-bottom:26px;padding-bottom:24px;border-bottom:1px dashed #b99a69}
  .heading{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}
  h3,h4,p{margin:0}h3,h4{font-family:var(--font-game);font-weight:400;color:var(--wood-dark)}h3{font-size:26px}h4{font-size:24px}
  .heading p,.empty-items{font-size:12px;color:#87714f}
  .item-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
  .item-stack{display:flex;align-items:center;gap:14px;padding:12px;background:#fff1ce;border:2px solid #b89c6c;box-shadow:2px 3px 0 #cdb383;min-width:0}
  .item-art{display:grid;place-items:center;flex:none;width:80px;height:80px;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;border:2px solid #6a7657;box-shadow:inset 0 0 0 2px #78916a}
  .item-art img{width:64px;height:64px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(2px 3px 0 #203b3580)}.item-art span{font-size:30px;color:#ffe5a4}
  .item-info{min-width:0;flex:1}.item-info small,.quantity small{font-size:10px;color:#87714f;text-transform:uppercase;letter-spacing:1px}.quantity{text-align:right;flex:none}.quantity strong{display:block;font-family:var(--font-game);font-size:28px;color:#8b642c;overflow-wrap:anywhere;max-width:150px}
  @media(max-width:600px){.heading{align-items:flex-start;flex-direction:column;gap:3px}.item-grid{grid-template-columns:1fr}.item-stack{gap:12px}.item-art{width:64px;height:64px}.item-art img{width:52px;height:52px}.quantity strong{max-width:100px;font-size:25px}}
</style>
