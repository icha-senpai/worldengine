<script lang="ts">
  import { onMount } from 'svelte';
  import type { Game } from './game.svelte.ts';
  import type { ShopListing } from '@fishing-game/generated/types';
  import PageTurner from './PageTurner.svelte';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let shelf = $state('licence');
  let page = $state(1);
  let now = $state(Date.now());
  let previewing = $state(false);
  let bought = $state('');
  const offers = $derived(game.listings.filter(row => row.kind === shelf));
  const quote = $derived(previewing && game.shopQuote && !game.shopQuote.consumed ? game.shopQuote : null);
  const level = $derived(game.profile?.level ?? 1);
  const owned = (row: ShopListing) => row.kind === 'rod' ? game.ownedRods.some(rod => rod.rodId === row.targetId) : game.licences.some(licence => licence.biomeId === row.targetId);
  const reason = (row: ShopListing) => !game.player ? 'Link Discord to buy' : owned(row) ? 'Owned' : level < row.minimumLevel ? 'Requires level ' + row.minimumLevel : row.previousBiomeId > 1 && !game.licences.some(licence => licence.biomeId === row.previousBiomeId) ? 'Previous licence needed' : game.player.coins < row.priceCoins ? 'Need ' + (row.priceCoins - game.player.coins).toString() + ' more coins' : '';
  onMount(() => { const timer = setInterval(() => now = Date.now(), 1000); return () => clearInterval(timer); });
  async function preview(row: ShopListing) { previewing = false; bought = ''; await game.previewPurchase(row.listingId); now = Date.now(); previewing = !game.error; }
  async function confirm() {
    if (!quote) return;
    const name = game.listings.find(row => row.listingId === quote.listingId)?.name ?? 'Purchase';
    await game.confirmPurchase(quote.nonce);
    if (!game.error) { bought = name + ' is yours!'; previewing = false; }
    now = Date.now();
  }
</script>

<section class="trader" aria-labelledby="trader-title">
  <header><div><p class="eyebrow">THE CAMP TRADER</p><h2 id="trader-title">A new stretch of water awaits.</h2><p>“A good rod, a proper licence… and a little luck. What'll it be, angler?”</p></div><div class="pouch"><small>YOUR COIN POUCH</small><strong>{game.player?.coins.toString() ?? '—'}</strong></div></header>
  <div class="shelves" aria-label="Trader shelves"><button aria-pressed={shelf === 'licence'} onclick={() => { shelf = 'licence'; page = 1; }}>▤ Biome licences</button><button aria-pressed={shelf === 'rod'} onclick={() => { shelf = 'rod'; page = 1; }}>🎣 Fishing rods</button></div>
  <p class="note">Licences are permanent. Buy each water's licence and a suitable rod, then equip and travel on the World map. Meadow Pond and your Twig Rod are free.</p>
  {#if !game.player}<p class="note">Link your Discord account from Camp to shop with your fishing coins.</p>{/if}
  {#if bought}<p class="success" role="status">✓ {bought} {shelf === 'rod' ? 'Equip it on the World map when you are ready.' : 'Your World map has updated.'}</p>{/if}
  {#if quote}{@const listing = game.listings.find(row => row.listingId === quote.listingId)}<div class="offer" role="region" aria-label="Confirm trader purchase"><div><strong>Buy {listing?.name} for {quote.quotedCoins.toString()} coins?</strong><p>This will leave {(game.player && game.player.coins >= quote.quotedCoins ? game.player.coins - quote.quotedCoins : 0n).toString()} coins in your pouch. Offer expires at {new Date(Number(quote.expiresAt.microsSinceUnixEpoch / 1000n)).toLocaleTimeString()}.</p></div><div><button disabled={game.busy || !game.ready || quote.expiresAt.microsSinceUnixEpoch <= BigInt(now) * 1000n} onclick={confirm}>Confirm · {quote.quotedCoins.toString()} coins</button><button class="secondary" onclick={() => previewing = false}>Cancel</button></div></div>{/if}
  <div class="goods">{#each offers.slice((page - 1) * pageSize, page * pageSize) as row (row.listingId)}{@const status = reason(row)}<article class:owned={owned(row)}><div class="item-art" aria-hidden="true">{row.kind === 'licence' ? '▤' : '🎣'}</div><div><small>LEVEL {row.minimumLevel} · {game.biomes.find(b => b.biomeId === row.biomeId)?.name}</small><h3>{row.name}</h3><p>{row.kind === 'licence' ? 'A permanent pass to these waters.' : 'Power ' + game.rods.find(r => r.rodId === row.targetId)?.power + ' · equip on the World map.'}</p></div><div class="price"><strong>{row.priceCoins.toString()} <small>coins</small></strong><button disabled={!!status || game.busy || !game.ready} onclick={() => preview(row)}>{status || 'View offer'}</button></div></article>{/each}</div>
  {#if !offers.length}<p class="note">The trader is unpacking the stall. Offers will appear when the camp connects.</p>{/if}
  <PageTurner bind:page total={offers.length} {pageSize} label="Trader shelf pages" />
  <p class="note">A little short? Sell catches from your Tackle box or collect <code>/daily</code> in Discord.</p>
</section>

<style>
  .trader{background:var(--paper,#efe1b7);color:var(--ink,#493126);border:3px solid #b58c52;padding:30px;box-shadow:0 5px 0 #122b27}header{display:flex;justify-content:space-between;gap:20px;border-bottom:1px dashed #b49462;padding-bottom:20px}h2,h3,strong,button{font-family:var(--font-game)}h2{font-size:34px;margin:5px 0 10px}header p{line-height:1.6}.eyebrow{font-size:10px;letter-spacing:2px;color:#7c6241}.pouch{min-width:125px;background:#dfcca0;border:2px solid #b69966;padding:16px;align-self:start;text-align:center}.pouch small{display:block;font-size:9px;letter-spacing:1px}.pouch strong{font-size:32px}.shelves{display:flex;gap:10px;margin:22px 0 14px}button{border:2px solid #4c3928;background:#59714b;color:#fff0c8;padding:10px 14px;font-size:18px;cursor:pointer;box-shadow:0 3px 0 #4b3828}button:hover:not(:disabled){background:#6c845b}button:focus-visible{outline:3px solid #b47f35;outline-offset:3px}button:disabled{opacity:.58;cursor:default;box-shadow:none}.shelves button{background:#b69b68;color:#483427}.shelves button[aria-pressed=true]{background:#596f49;color:#fff0c8}.note{font-size:12px;line-height:1.8;color:#746044}.goods{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:22px}article{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#f8edcf;border:2px solid #c8ad77;padding:18px}.item-art{background:#e6d5a9;border:2px solid #b79559;width:50px;height:55px;display:grid;place-items:center;font-size:34px;flex-shrink:0}article>div:nth-child(2){flex:1;min-width:140px}article small{font-size:10px;color:#796443}h3{font-size:23px;margin:4px 0}article p{font-size:11px;line-height:1.6;margin:6px 0}.price{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border-top:1px dashed #c8ad77;padding-top:12px}.price strong{font-size:23px}.price button{font-size:15px;max-width:210px}.owned{border-color:#759065;background:#e9e7c6}.offer{background:#dfd8ac;border:2px solid #738551;padding:18px;display:flex;justify-content:space-between;gap:20px;margin-top:20px}.offer strong{font-size:22px}.offer p{font-size:12px;line-height:1.7}.offer>div:last-child{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.secondary{background:#e9d9b1;color:#4c3928}.success{background:#d6dfb4;border:1px solid #849565;padding:14px;font-size:14px}
  @media(max-width:700px){.trader{padding:18px}.goods{grid-template-columns:1fr}header{flex-direction:column;gap:12px}h2{font-size:29px}.pouch{min-width:0;display:flex;align-items:center;gap:20px;padding:10px 14px}.shelves{gap:8px}.shelves button{flex:1;font-size:16px;padding:10px 8px}.offer{flex-direction:column;gap:8px}.price button{max-width:190px}}
</style>
