<script lang="ts">
  import { baitEffect } from './bait-effects';
  import type { Game } from './game.svelte.ts';
  import type { ShopListing } from '@fishing-game/generated/types';
  import PageTurner from './PageTurner.svelte';
  import equipmentArt from '../../../../../content/equipment-art.json';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let shelf = $state('licence');
  let page = $state(1);
  let bought = $state('');
  let buying = $state<number | null>(null);
  let buyingQuantity = $state(1);
  const quantities = [1, 5, 10];
  const baitGroup = (row: ShopListing) => {
    const bait = game.baits.find(bait => bait.baitId === row.targetId);
    return bait?.resourceItem === 'rusted_tin' ? 0 : bait?.resourceItem === 'scrap' ? 1 : (bait?.sizeRolls ?? 1) > 1 ? 2 : 3;
  };
  const offers = $derived(game.listings.filter(row => row.kind === shelf).sort((a, b) => {
    if (shelf === 'bait') {
      const group = baitGroup(a) - baitGroup(b);
      if (group) return group;
      if (baitGroup(a) < 2) {
        const quantity = (game.baits.find(bait => bait.baitId === a.targetId)?.resourceQuantity ?? 1n)
          - (game.baits.find(bait => bait.baitId === b.targetId)?.resourceQuantity ?? 1n);
        if (quantity) return quantity < 0n ? -1 : 1;
      }
    }
    return a.listingId - b.listingId;
  }));
  const packUses = (row: ShopListing) => game.baits.find(bait => bait.baitId === row.targetId)?.usesPerPurchase ?? 10n;
  const modifiers = (id: number) => game.rodBonuses.find(row => row.rodId === id);
  const artwork = (row: ShopListing) => row.kind === 'rod'
    ? modifiers(row.targetId)?.spriteAsset
    : row.kind === 'bait' ? game.baits.find(bait => bait.baitId === row.targetId)?.spriteAsset : equipmentArt.licences.find(licence => licence.biomeId === row.targetId)?.spriteAsset;
  const equipped = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const delta = (id: number) => { if (!equipped) return ''; const next = game.rodStats(id); const current = game.rodStats(equipped.rodId); const signed = (n:number) => (n>=0?'+':'')+n; return `${signed(next.power-current.power)} power · ${signed((next.luckBp-current.luckBp)/100)}% luck · ${signed((next.xpBonusBp-current.xpBonusBp)/100)}% XP vs equipped`; };
  const level = $derived(game.profile?.level ?? 1);
  const owned = (row: ShopListing) => row.kind === 'bait' ? false : row.kind === 'rod' ? game.ownedRods.some(rod => rod.rodId === row.targetId) : game.licences.some(licence => licence.biomeId === row.targetId);
  const reason = (row: ShopListing, quantity = 1) => !game.player ? 'Link Discord to buy' : owned(row) ? 'Owned' : level < row.minimumLevel ? 'Requires level ' + row.minimumLevel : row.previousBiomeId > 1 && !game.licences.some(licence => licence.biomeId === row.previousBiomeId) ? 'Previous licence needed' : game.player.coins < row.priceCoins * BigInt(quantity) ? 'Need ' + (row.priceCoins * BigInt(quantity) - game.player.coins).toString() + ' more coins' : '';
  async function buy(row: ShopListing, quantity = 1) {
    if (buying !== null || game.busy || !game.ready || reason(row, quantity)) return;
    buying = row.listingId; buyingQuantity = quantity; bought = '';
    try {
      if (await game.purchase(row.listingId, quantity)) {
        bought = row.kind === 'bait' ? `${row.name}: ${quantity} ${quantity === 1 ? 'pack' : 'packs'} · ${packUses(row) * BigInt(quantity)} uses added. Equip bait in your Tackle box.`
          : row.name + (row.kind === 'rod' ? ' is yours! Equip it in your Tackle box.' : ' is yours! Your World map has updated.');
      }
    } finally { buying = null; }
  }
</script>

<section class="trader" aria-labelledby="trader-title">
  <header><div><p class="eyebrow">THE CAMP TRADER</p><h2 id="trader-title">A new stretch of water awaits.</h2><p>“A good rod, a proper licence… and a little luck. What'll it be, angler?”</p></div><div class="pouch"><small>YOUR COIN POUCH</small><strong>{game.player?.coins.toString() ?? '—'}</strong></div></header>
  <div class="shelves" aria-label="Trader shelves"><button aria-pressed={shelf === 'licence'} onclick={() => { shelf = 'licence'; page = 1; }}>▤ Biome licences</button><button aria-pressed={shelf === 'rod'} onclick={() => { shelf = 'rod'; page = 1; }}>🎣 Fishing rods</button><button aria-pressed={shelf === 'bait'} onclick={() => { shelf = 'bait'; page = 1; }}>🪱 Bait</button></div>
  <p class="note">Licences are permanent. Buy each water's licence, then travel on the World map with any owned rod. Bait comes in packs of 10 uses; buy 1, 5 or 10 packs and equip it in your Tackle box. Meadow Pond and your Twig Rod are free.</p>
  {#if !game.player}<p class="note">Link your Discord account from Camp to shop with your fishing coins.</p>{/if}
  {#if bought}<p class="success" role="status">✓ {bought}</p>{/if}
  <div class="goods">
    {#each offers.slice((page - 1) * pageSize, page * pageSize) as row (row.listingId)}
      {@const status = reason(row)}
      <article class:owned={owned(row)} aria-label={row.name} aria-busy={buying === row.listingId}>
        <div class="item-art" aria-hidden="true">{#if artwork(row)}<img src={artwork(row)} alt="" width="96" height="96" />{:else}▤{/if}</div>
        <div><small>{row.kind === 'rod' && row.minimumLevel === 1 ? 'GOLD PURCHASE' : 'LEVEL ' + row.minimumLevel} · {game.biomes.find(b => b.biomeId === row.biomeId)?.name}</small><h3>{row.name}</h3>{#if row.kind === 'bait'}<p class="stock">In your tackle box: {(game.ownedBaits.find(b => b.baitId === row.targetId)?.usesLeft ?? 0n).toString()} uses</p>{/if}<p>{row.kind === 'licence' ? 'A permanent pass to these waters.' : row.kind === 'bait' ? packUses(row) + ' uses per pack · ' + baitEffect(game.baits.find(b => b.baitId === row.targetId)) : 'Power ' + game.rodStats(row.targetId).power + ' · ' + game.rodStats(row.targetId).power/2 + '% bonus pull · +' + game.rodStats(row.targetId).luckBp/100 + '% luck · +' + game.rodStats(row.targetId).xpBonusBp/100 + '% fishing XP'}</p>{#if row.kind === 'rod' && delta(row.targetId)}<p class="comparison">{delta(row.targetId)}</p>{/if}</div>
        {#if row.kind === 'bait'}
          <div class="bait-purchases" role="group" aria-label={'Buy ' + row.name}>
            {#each quantities as quantity}
              {@const blocked = reason(row, quantity)}
              <button disabled={!!blocked || game.busy || !game.ready || buying !== null} title={blocked || undefined} onclick={() => buy(row, quantity)} aria-label={'Buy ' + quantity + (quantity === 1 ? ' pack' : ' packs') + ' of ' + row.name + ' · ' + row.priceCoins * BigInt(quantity) + ' coins'}><span>{buying === row.listingId && buyingQuantity === quantity ? 'Buying…' : 'Buy ' + quantity}</span><small>{packUses(row) * BigInt(quantity)} uses</small><small>{(row.priceCoins * BigInt(quantity)).toString()} coins</small></button>
            {/each}
          </div>
          {#if status}<p class="purchase-status">{status}</p>{/if}
        {:else}
          <div class="price"><strong>{row.priceCoins.toString()} <small>coins</small></strong><button disabled={!!status || game.busy || !game.ready || buying !== null} onclick={() => buy(row)}>{buying === row.listingId ? 'Buying…' : status || 'Buy'}</button></div>
        {/if}
      </article>
    {/each}
  </div>
  {#if !offers.length}<p class="note">The trader is unpacking the stall. Offers will appear when the camp connects.</p>{/if}
  <PageTurner bind:page total={offers.length} {pageSize} label="Trader shelf pages" />
  <p class="note">A little short? Sell catches from your Tackle box or collect <code>/daily</code> in Discord.</p>
</section>

<style>
  .bait-purchases{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;width:100%;border-top:1px dashed #c8ad77;padding-top:12px}
  .bait-purchases button{min-width:0;padding:10px 4px;font-size:18px;display:flex;flex-direction:column;align-items:center;gap:4px}
  .bait-purchases button small{font-family:var(--font-body);font-size:10px;color:#fff0c8}
  .purchase-status{width:100%;color:#746044;margin:0}
  .trader{background:var(--paper,#efe1b7);color:var(--ink,#493126);border:3px solid #b58c52;padding:30px;box-shadow:0 5px 0 #122b27}header{display:flex;justify-content:space-between;gap:20px;border-bottom:1px dashed #b49462;padding-bottom:20px}h2,h3,strong,button{font-family:var(--font-game)}h2{font-size:34px;margin:5px 0 10px}header p{line-height:1.6}.eyebrow{font-size:10px;letter-spacing:2px;color:#7c6241}.pouch{min-width:125px;background:#dfcca0;border:2px solid #b69966;padding:16px;align-self:start;text-align:center}.pouch small{display:block;font-size:9px;letter-spacing:1px}.pouch strong{font-size:32px}.shelves{display:flex;flex-wrap:wrap;gap:10px;margin:22px 0 14px}button{border:2px solid #4c3928;background:#59714b;color:#fff0c8;padding:10px 14px;font-size:18px;cursor:pointer;box-shadow:0 3px 0 #4b3828}button:hover:not(:disabled){background:#6c845b}button:focus-visible{outline:3px solid #b47f35;outline-offset:3px}button:disabled{opacity:.58;cursor:default;box-shadow:none}.shelves button{background:#b69b68;color:#483427}.shelves button[aria-pressed=true]{background:#596f49;color:#fff0c8}.note{font-size:12px;line-height:1.8;color:#746044}.goods{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:22px}article{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#f8edcf;border:2px solid #c8ad77;padding:18px}.item-art{background:#e6d5a9;border:2px solid #b79559;width:96px;height:96px;display:grid;place-items:center;font-size:34px;flex-shrink:0}.item-art img{width:100%;height:100%;object-fit:contain;padding:4px;image-rendering:pixelated}.comparison{color:#4d713a}article>div:nth-child(2){flex:1;min-width:140px}article small{font-size:10px;color:#796443}h3{font-size:21px;margin:4px 0;overflow-wrap:anywhere}article p{font-size:11px;line-height:1.6;margin:6px 0}.price{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border-top:1px dashed #c8ad77;padding-top:12px}.price strong{font-size:23px}.price button{font-size:15px;max-width:210px}.owned{border-color:#759065;background:#e9e7c6}.success{background:#d6dfb4;border:1px solid #849565;padding:14px;font-size:14px}
  @media(max-width:700px){.trader{padding:18px}.goods{grid-template-columns:1fr}header{flex-direction:column;gap:12px}h2{font-size:29px}.pouch{min-width:0;display:flex;align-items:center;gap:20px;padding:10px 14px}.shelves{gap:8px}.shelves button{flex:1;font-size:16px;padding:10px 8px}.price button{max-width:190px}}
</style>
