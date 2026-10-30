<script lang="ts">
  import { baitEffect } from './bait-effects';
  import { equipmentComparison } from './trader-equipment';
  import type { Game } from './game.svelte.ts';
  import type { ShopListing } from '@fishing-game/generated/types';
  import PageTurner from './PageTurner.svelte';
  import RaidArt from './RaidArt.svelte';
  import equipmentArt from '../../../../../content/equipment-art.json';
  import raidPolicy from '../../../../../content/raids.json';
  import { raidArtwork } from './raid-art';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let shelf = $state('licence');
  let page = $state(1);
  let bought = $state('');
  let buying = $state<number | null>(null);
  let buyingQuantity = $state(1);
  let pending = $state.raw<{ listing: ShopListing; quantity: number; playerId: bigint; usesPerPack: bigint } | null>(null);
  let purchaseError = $state('');
  let dialog: HTMLDialogElement;
  let cancelButton = $state<HTMLButtonElement>();
  const totalCost = $derived(pending ? pending.listing.priceCoins * BigInt(pending.quantity) : 0n);
  const supplyBalance = $derived(game.items.find(row => row.item === 'raid_supplies')?.quantity ?? 0n);
  const balanceAfter = $derived(game.player && game.player.coins >= totalCost ? game.player.coins - totalCost : null);
  const purchaseProblem = $derived.by(() => {
    if (!pending) return '';
    const current = game.listings.find(row => row.listingId === pending!.listing.listingId);
    if (!current) return 'This item is no longer available. Close this confirmation and check the trader again.';
    const original = pending.listing;
    if (current.priceCoins !== original.priceCoins || current.catalogVersion !== original.catalogVersion
      || current.name !== original.name || current.kind !== original.kind || current.targetId !== original.targetId
      || current.kind === 'bait' && packUses(current) !== pending.usesPerPack) {
      return 'This offer changed. Close this confirmation and review the updated offer.';
    }
    return reason(current, pending.quantity);
  });
  $effect(() => {
    if (pending && (!game.ready || game.player?.playerId !== pending.playerId)) pending = null;
  });
  $effect(() => {
    if (pending && dialog && !dialog.open) { dialog.showModal(); cancelButton?.focus(); }
    else if (!pending && dialog?.open) dialog.close();
  });
  $effect(() => {
    if (!pending) return;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  });
  const quantities = [1, 5, 10];
  const raidSupply = (row: ShopListing) => row.kind === 'raid_supply';
  const quantityOffer = (row: ShopListing) => row.kind === 'bait' || raidSupply(row);
  const quantityLabel = (row: ShopListing, quantity: number) =>
    `${quantity} ${raidSupply(row) ? quantity === 1 ? 'crate' : 'crates' : quantity === 1 ? 'pack' : 'packs'}`;
  const quantityUnits = (row: ShopListing, quantity: number) =>
    raidSupply(row) ? BigInt(raidPolicy.supply.unitsPerPurchase * quantity) : packUses(row) * BigInt(quantity);
  const baitGroup = (row: ShopListing) => {
    const bait = game.baits.find(bait => bait.baitId === row.targetId);
    return bait?.resourceItem === 'rusted_tin' ? 0 : bait?.resourceItem === 'scrap' ? 1 : (bait?.sizeRolls ?? 1) > 1 ? 2 : 3;
  };
  const offers = $derived(game.listings.filter(row => row.kind === shelf || shelf === 'bait' && raidSupply(row)).sort((a, b) => {
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
  const packUses = (row: ShopListing) => raidSupply(row) ? 5n : game.baits.find(bait => bait.baitId === row.targetId)?.usesPerPurchase ?? 10n;
  const modifiers = (id: number) => game.rodBonuses.find(row => row.rodId === id);
  const haulGear = (row: ShopListing) => row.kind === 'boat' || row.kind === 'net';
  const haulBonuses = (row: ShopListing) => {
    const gear = game.haulEquipment.find(gear => gear.equipmentId === row.targetId);
    const stats = game.haulStats(row.targetId);
    return gear ? `${stats.quality?.name ?? 'Common'} · +${stats.luckBp / 100}% luck · +${stats.xpBonusBp / 100}% fishing XP · no bait required.` : 'Loading equipment bonuses…';
  };
  const artwork = (row: ShopListing) => row.kind === 'raid_supply'
    ? raidArtwork('supply')
    : row.kind === 'rod'
    ? modifiers(row.targetId)?.spriteAsset
    : haulGear(row) ? game.haulEquipment.find(gear => gear.equipmentId === row.targetId)?.spriteAsset
    : row.kind === 'bait' ? game.baits.find(bait => bait.baitId === row.targetId)?.spriteAsset : equipmentArt.licences.find(licence => licence.biomeId === row.targetId)?.spriteAsset;
  const level = $derived(game.profile?.level ?? 1);
  const owned = (row: ShopListing) => row.kind === 'bait' || raidSupply(row) ? false : row.kind === 'rod' ? game.ownedRods.some(rod => rod.rodId === row.targetId) : haulGear(row) ? game.ownedHaulEquipment.some(gear => gear.equipmentId === row.targetId) : game.licences.some(licence => licence.biomeId === row.targetId);
  const reason = (row: ShopListing, quantity = 1) => !game.player ? 'Link Discord to buy' : raidSupply(row) && !game.raidsSupported ? 'Raid supplies await the module update' : owned(row) ? 'Owned' : level < row.minimumLevel ? 'Requires level ' + row.minimumLevel : row.previousBiomeId > 1 && !game.licences.some(licence => licence.biomeId === row.previousBiomeId) ? 'Previous licence needed' : game.player.coins < row.priceCoins * BigInt(quantity) ? 'Need ' + (row.priceCoins * BigInt(quantity) - game.player.coins).toString() + ' more coins' : '';
  function requestPurchase(row: ShopListing, quantity = 1) {
    if (pending || buying !== null || game.busy || !game.ready || reason(row, quantity) || !game.player) return;
    purchaseError = ''; bought = '';
    pending = { listing: { ...row }, quantity, playerId: game.player.playerId, usesPerPack: packUses(row) };
  }
  async function confirmPurchase() {
    if (!pending || purchaseProblem || buying !== null || game.busy || !game.ready) return;
    const selection = pending;
    purchaseError = '';
    if (await buy(selection.listing, selection.quantity)) pending = null;
    else purchaseError = game.error || 'The purchase could not be confirmed. Check your coin pouch and try again.';
  }
  function cancelPurchase(event?: Event) {
    if (buying !== null) { event?.preventDefault(); return; }
    pending = null;
  }
  function dismissBackdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) cancelPurchase();
  }
  async function buy(row: ShopListing, quantity = 1) {
    if (buying !== null || game.busy || !game.ready || reason(row, quantity)) return false;
    buying = row.listingId; buyingQuantity = quantity; bought = '';
    try {
      if (await game.purchase(row.listingId, quantity)) {
        bought = raidSupply(row) ? `${row.name}: ${quantity} ${quantity === 1 ? 'crate' : 'crates'} · ${quantityUnits(row, quantity)} units added for boat raids.`
          : row.kind === 'bait' ? `${row.name}: ${quantity} ${quantity === 1 ? 'pack' : 'packs'} · ${packUses(row) * BigInt(quantity)} uses added. Equip bait in your Tackle box.`
          : row.name + (row.kind === 'rod' || haulGear(row) ? ' is yours! Equip it in your Tackle box.' : ' is yours! Your World map has updated.');
        return true;
      }
      return false;
    } finally { buying = null; }
  }
</script>

<section class="trader" aria-labelledby="trader-title">
  <header><div><p class="eyebrow">THE CAMP TRADER</p><h2 id="trader-title">A new stretch of water awaits.</h2><p>“A good rod, a proper licence… and a little luck. What'll it be, angler?”</p></div><div class="pouch"><small>YOUR COIN POUCH</small><strong>{game.player?.coins.toString() ?? '—'}</strong></div></header>
  <div class="shelves" aria-label="Trader shelves"><button aria-pressed={shelf === 'licence'} onclick={() => { shelf = 'licence'; page = 1; }}>▤ Biome licences</button><button aria-pressed={shelf === 'rod'} onclick={() => { shelf = 'rod'; page = 1; }}>🎣 Fishing rods</button><button aria-pressed={shelf === 'boat'} onclick={() => { shelf = 'boat'; page = 1; }}>🚣 Boats</button><button aria-pressed={shelf === 'net'} onclick={() => { shelf = 'net'; page = 1; }}>🕸 Nets</button><button aria-pressed={shelf === 'bait'} onclick={() => { shelf = 'bait'; page = 1; }}>🪱 Bait</button><button aria-pressed={shelf === 'raid_supply'} onclick={() => { shelf = 'raid_supply'; page = 1; }}>⚓ Raid Supplies</button></div>
  <p class="note">Licences are permanent. Buy each water's licence, then travel on the World map with any owned rod. Boats and nets are permanent gear; equip them in your Tackle box. Neither uses bait. Bait comes in packs of 10 uses for rod fishing. Meadow Pond and your Twig Rod, Boat and Net are free.</p>
  {#if shelf === 'raid_supply'}<p class="note">Expedition Chum is a raid supply only; it never enters your fishing bait loadout.</p>{/if}
  {#if !game.player}<p class="note">Link your Discord account from Camp to shop with your fishing coins.</p>{/if}
  {#if bought}<p class="success" role="status">✓ {bought}</p>{/if}
  <div class="goods">
    {#each offers.slice((page - 1) * pageSize, page * pageSize) as row (row.listingId)}
      {@const status = reason(row)}
      {@const comparison = raidSupply(row) ? '' : equipmentComparison(game, row)}
      <article class:owned={owned(row)} aria-label={row.name} aria-busy={buying === row.listingId}>
        {#if raidSupply(row)}
          <div class="item-art" aria-hidden="true"><RaidArt kind="supply" size={96} /></div>
          <div class="listing-details"><small>RAID SUPPLY · EXPEDITION CHUM</small><h3>{row.name}</h3><p>{raidPolicy.supply.unitsPerPurchase} units per crate · raid supplies only; no fishing bait slot or cast bonuses.</p><p class="stock">Stored: {supplyBalance} units</p></div>
        {:else}
        <div class="item-art" aria-hidden="true">{#if artwork(row)}<img src={artwork(row)} alt="" width="96" height="96" />{:else}▤{/if}</div>
        <div><small>{(row.kind === 'rod' || haulGear(row)) && row.minimumLevel === 1 ? 'GOLD PURCHASE' : 'LEVEL ' + row.minimumLevel} · {game.biomes.find(b => b.biomeId === row.biomeId)?.name}</small><h3>{row.name}</h3>{#if row.kind === 'bait'}<p class="stock">In your tackle box: {(game.ownedBaits.find(b => b.baitId === row.targetId)?.usesLeft ?? 0n).toString()} uses</p>{/if}<p>{row.kind === 'licence' ? 'A permanent pass to these waters.' : row.kind === 'bait' ? packUses(row) + ' uses per pack · ' + baitEffect(game.baits.find(b => b.baitId === row.targetId)) : haulGear(row) ? haulBonuses(row) : 'Power ' + game.rodStats(row.targetId).power + ' · ' + game.rodStats(row.targetId).power/2 + '% bonus pull · +' + game.rodStats(row.targetId).luckBp/100 + '% luck · +' + game.rodStats(row.targetId).xpBonusBp/100 + '% fishing XP'}</p>{#if comparison}<p class="comparison">{comparison}</p>{/if}</div>
        {/if}
        {#if quantityOffer(row)}
          <div class="bait-purchases" role="group" aria-label={'Buy ' + row.name}>
            {#each quantities as quantity}
              {@const blocked = reason(row, quantity)}
              <button disabled={!!blocked || game.busy || !game.ready || buying !== null} title={blocked || undefined} onclick={() => requestPurchase(row, quantity)} aria-haspopup="dialog" aria-label={'Buy ' + quantityLabel(row, quantity) + ' of ' + row.name + ' · ' + row.priceCoins * BigInt(quantity) + ' coins'}><span>{buying === row.listingId && buyingQuantity === quantity ? 'Buying…' : 'Buy ' + quantity}</span><small>{quantityUnits(row, quantity)} {raidSupply(row) ? 'units' : 'uses'}</small><small>{(row.priceCoins * BigInt(quantity)).toString()} coins</small></button>
            {/each}
          </div>
          {#if status}<p class="purchase-status">{status}</p>{/if}
        {:else}
          <div class="price"><strong>{row.priceCoins.toString()} <small>coins</small></strong><button disabled={!!status || game.busy || !game.ready || buying !== null} onclick={() => requestPurchase(row)} aria-haspopup="dialog">{buying === row.listingId ? 'Buying…' : status || 'Buy'}</button></div>
        {/if}
      </article>
    {/each}
  </div>
  {#if !offers.length}<p class="note">The trader is unpacking the stall. Offers will appear when the camp connects.</p>{/if}
  <PageTurner bind:page total={offers.length} {pageSize} label="Trader shelf pages" />
  <p class="note">A little short? Sell catches from your Tackle box or collect <code>/daily</code> in Discord.</p>
</section>

<dialog class="purchase-dialog" bind:this={dialog} aria-labelledby="purchase-title" aria-describedby="purchase-description" aria-busy={buying !== null} onclose={() => pending = null} oncancel={cancelPurchase} onpointerdown={dismissBackdrop}>
  {#if pending}
    <p class="eyebrow">THE CAMP TRADER</p>
    <h2 id="purchase-title">Confirm purchase</h2>
    <p id="purchase-description">Review your purchase. Coins are spent when you confirm.</p>
    <div class="purchase-item">
      {#if artwork(pending.listing)}<img src={artwork(pending.listing)} alt="" width="90" height="90" />{/if}
      {#if raidSupply(pending.listing)}
        <div><h3>{pending.listing.name}</h3><p>{quantityLabel(pending.listing, pending.quantity)} · {quantityUnits(pending.listing, pending.quantity)} Expedition Chum units for raids only. No fishing bait or cast bonuses.</p></div>
      {:else}
      <div><h3>{pending.listing.name}</h3><p>{pending.listing.kind === 'bait' ? `${pending.quantity} ${pending.quantity === 1 ? 'pack' : 'packs'} · ${pending.usesPerPack * BigInt(pending.quantity)} uses` : pending.listing.kind === 'licence' ? '1 licence · permanent access' : `1 ${pending.listing.kind} · permanent gear`}</p></div>
      {/if}
    </div>
    <dl><div><dt>Total cost</dt><dd>{totalCost.toLocaleString()} coins</dd></div><div><dt>Coins after purchase</dt><dd>{balanceAfter?.toLocaleString() ?? '—'}</dd></div></dl>
    {#if purchaseProblem || purchaseError}<p class="purchase-error" role="alert">{purchaseProblem || purchaseError}</p>{/if}
    <div class="purchase-actions">
      <button class="cancel-purchase" bind:this={cancelButton} disabled={buying !== null} onclick={() => cancelPurchase()}>Cancel</button>
      <button aria-label="Confirm purchase" disabled={!!purchaseProblem || game.busy || !game.ready || buying !== null} onclick={confirmPurchase}>{buying !== null ? 'Buying…' : 'Confirm · ' + totalCost.toLocaleString() + ' coins'}</button>
    </div>
  {/if}
</dialog>

<style>
  .purchase-dialog{width:min(640px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:28px;border:4px solid var(--wood-dark);background:var(--paper);color:var(--ink);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:auto;overscroll-behavior:contain}
  .purchase-dialog::backdrop{background:var(--dialog-backdrop)}.purchase-dialog h2{font-size:30px;overflow-wrap:anywhere}.purchase-dialog p{font-size:16px;line-height:1.6}
  .purchase-item{display:flex;align-items:center;gap:18px;margin:22px 0;padding:16px;background:var(--paper-light);border:1px solid var(--paper-edge)}.purchase-item>div{min-width:0}.purchase-item img{flex:none;width:90px;height:90px;object-fit:contain;image-rendering:pixelated}.purchase-item h3{margin:0}.purchase-item p{margin:6px 0 0}
  .purchase-dialog dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:22px 0}.purchase-dialog dt{font-size:14px;color:var(--ink)}.purchase-dialog dd{margin:6px 0 0;font-family:var(--font-game);font-size:24px;overflow-wrap:anywhere}
  .purchase-error{padding:12px;background:var(--panel-inset);color:var(--ink);border:1px solid var(--paper-edge);overflow-wrap:anywhere}.purchase-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap}.purchase-actions button{font-size:20px;max-width:100%;overflow-wrap:anywhere}.cancel-purchase{background:var(--panel-inset);color:var(--ink)}.cancel-purchase:hover:not(:disabled){background:var(--paper-light)}
  @media(max-width:600px){.purchase-dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px);padding:18px}.purchase-dialog h2{font-size:26px}.purchase-item{align-items:flex-start;padding:12px;gap:12px}.purchase-item img{width:64px;height:64px}.purchase-dialog dl{grid-template-columns:1fr;gap:14px}.purchase-dialog dd{font-size:22px}.purchase-actions{flex-direction:column-reverse}.purchase-actions button{width:100%;font-size:18px}}
  .bait-purchases{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;width:100%;border-top:1px dashed var(--paper-edge);padding-top:12px}
  .bait-purchases button{min-width:0;padding:10px 4px;font-size:20px;display:flex;flex-direction:column;align-items:center;gap:4px}
  .bait-purchases button small{font-family:var(--font-body);font-size:14px;color:inherit}
  .purchase-status{width:100%;color:var(--ink);margin:0}
  .trader{background:var(--paper,#efe1b7);color:var(--ink,#493126);border:3px solid var(--paper-edge);padding:36px;box-shadow:0 5px 0 var(--site-base)}header{display:flex;justify-content:space-between;gap:20px;border-bottom:1px dashed var(--paper-edge);padding-bottom:20px}h2,h3,strong,button{font-family:var(--font-game)}h2{font-size:38px;margin:5px 0 10px}header p{line-height:1.6}.eyebrow{font-size:14px;letter-spacing:2px;color:var(--ink)}.pouch{min-width:170px;background:var(--panel-inset);border:2px solid var(--paper-edge);padding:16px;align-self:start;text-align:center}.pouch small{display:block;font-size:13px;letter-spacing:1px}.pouch strong{font-size:36px}.shelves{display:flex;flex-wrap:wrap;gap:10px;margin:22px 0 14px}button{border:2px solid var(--paper-edge);background:var(--selected-background);color:var(--selected-ink);padding:10px 14px;font-size:21px;cursor:pointer;box-shadow:0 3px 0 var(--wood-dark)}button:hover:not(:disabled){background:var(--selected-hover)}button:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}button:disabled{opacity:.58;cursor:default;box-shadow:none}.shelves button{background:var(--panel-inset);color:var(--ink)}.shelves button[aria-pressed=true]{background:var(--selected-background);color:var(--selected-ink)}.note{font-size:16px;line-height:1.8;color:var(--ink)}.goods{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;margin-top:22px}article{display:flex;align-items:center;gap:20px;flex-wrap:wrap;background:var(--paper-light);border:2px solid var(--paper-edge);padding:24px;min-width:0} .item-art{background:var(--art-background);color:var(--art-ink);border:2px solid var(--art-edge);box-shadow:inset 0 0 0 2px var(--art-highlight);width:120px;height:120px;display:grid;place-items:center;font-size:38px;flex-shrink:0}.item-art img{width:100%;height:100%;object-fit:contain;padding:4px;image-rendering:pixelated}.comparison{color:var(--success-ink)}article>div:nth-child(2){flex:1;min-width:140px}article small{font-size:14px;color:var(--ink)}h3{font-size:24px;margin:4px 0;overflow-wrap:anywhere}article p{font-size:14px;line-height:1.6;margin:6px 0}.price{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border-top:1px dashed var(--paper-edge);padding-top:12px;flex-wrap:wrap}.price strong{font-size:27px}.price button{font-size:18px;max-width:280px}.owned{border-color:var(--paper-edge);background:var(--paper-light)}.success{background:var(--panel-inset);border:1px solid var(--paper-edge);padding:14px;font-size:17px}
  @media(max-width:700px){.trader{padding:18px}.goods{grid-template-columns:minmax(0,1fr)}header{flex-direction:column;gap:12px}h2{font-size:31px}.pouch{min-width:0;display:flex;align-items:center;gap:20px;padding:10px 14px;flex-wrap:wrap;max-width:100%}.shelves{gap:8px}.shelves button{flex:1;font-size:18px;padding:10px 8px}.price button{max-width:190px}}
  .shelves button:hover:not(:disabled){background:var(--panel-highlight)}
  .shelves button[aria-pressed=true]:hover{background:var(--selected-hover)}
</style>
