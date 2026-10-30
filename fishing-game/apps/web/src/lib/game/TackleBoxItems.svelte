<script lang="ts">
  import type { ItemStack } from '@fishing-game/generated/types';
  import policy from '../../../../../content/raids.json';
  import { itemSlot } from './raid-items';
  import { raidArtwork } from './raid-art';

  let { items }: { items: ItemStack[] } = $props();
  const definitions: Record<string, { name: string; image?: string; kind: string }> = {
    rusted_tin: { name: 'Rusted tin', image: '/items/rusted-tin.png', kind: 'Junk' },
    scrap: { name: 'Scrap', image: '/items/scrap.png', kind: 'Material' },
    raid_supplies: { name: 'Expedition Chum', image: raidArtwork('supply') ?? '', kind: 'Raid supply' }
  };
  const raidMaterial = (slot: number) => {
    const material = policy.materials.find(row => slot >= row.slotBase && slot < row.slotBase + policy.grades.length);
    if (!material) return undefined;
    const grade = slot - material.slotBase;
    return {
      name: `${material.name} · ${policy.grades[grade].name}`,
      kind: 'Raid material',
      image: raidArtwork(material.key, grade)
    };
  };
  const stacks = $derived(items.filter(item => item.quantity > 0n).slice().sort((a, b) => a.item.localeCompare(b.item)));
</script>

<div class="item-storage" role="region" aria-label="Junk and materials">
  <div class="heading"><h3>Junk &amp; materials</h3><p>Little finds from your casts.</p></div>
  {#if stacks.length}
    <div class="item-grid">
      {#each stacks as item (item.key)}
        {@const definition = definitions[item.item]}
        {@const raidDefinition = raidMaterial(itemSlot(item.key))}
        {@const displayName = definition?.name ?? raidDefinition?.name ?? item.item.replaceAll('_', ' ')}
        {@const image = definition?.image || raidDefinition?.image}
        <article class="item-stack" aria-label={displayName + ': ' + item.quantity.toString()}>
          <div class="item-art" aria-hidden="true">
            {#if image}<img src={image} alt="" width="96" height="96" />{:else}<span>{item.item === 'raid_supplies' ? '⚓' : '?'}</span>{/if}
          </div>
          <div class="item-info"><small>{definition?.kind ?? raidDefinition?.kind ?? 'Item'}</small><h4>{displayName}</h4></div>
          <div class="quantity"><small>Owned</small><strong>×{item.quantity.toString()}</strong></div>
        </article>
      {/each}
    </div>
  {:else}
    <p class="empty-items">Rusted tins and scrap from your casts will appear here.</p>
  {/if}
</div>

<style>
  .item-storage{margin-bottom:26px;padding-bottom:24px;border-bottom:1px dashed var(--paper-edge)}
  .heading{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}
  h3,h4,p{margin:0}h3,h4{font-family:var(--font-game);font-weight:400;color:light-dark(var(--wood-dark),var(--ink))}h3{font-size:30px}h4{font-size:28px;overflow-wrap:anywhere}
  .heading p,.empty-items{font-size:16px;color:var(--ink-muted)}
  .item-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px}
  .item-stack{display:grid;align-items:center;gap:14px;padding:18px;background:var(--paper-light);border:2px solid var(--paper-edge);box-shadow:2px 3px 0 var(--panel-highlight);min-width:0;grid-template-columns:80px minmax(0,1fr)}
  .item-art{display:grid;place-items:center;flex:none;width:80px;height:80px;background:var(--art-background);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight)}
  .item-art img{width:64px;height:64px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(2px 3px 0 var(--art-shadow))}.item-art span{font-size:34px;color:var(--art-ink)}
  .item-info{min-width:0;flex:1}.item-info small,.quantity small{font-size:14px;color:var(--ink-muted);text-transform:uppercase;letter-spacing:1px}.quantity{text-align:left;flex:none;max-width:100%;grid-column:1/-1;display:flex;align-items:baseline;gap:12px}.quantity strong{display:block;font-family:var(--font-game);font-size:32px;color:var(--ink);overflow-wrap:anywhere;max-width:100%}
  @media(max-width:600px){.heading{align-items:flex-start;flex-direction:column;gap:3px}.item-grid{grid-template-columns:1fr}.item-stack{gap:12px;flex-wrap:wrap;grid-template-columns:64px minmax(0,1fr)}.item-art{width:64px;height:64px}.item-art img{width:52px;height:52px}.quantity strong{max-width:100%;font-size:27px}}
</style>
