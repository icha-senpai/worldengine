<script lang="ts">
  import { onMount } from 'svelte';
  import type { Game } from './game.svelte.ts';
  let { game }: { game: Game } = $props();
  const rod = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const bonuses = $derived(game.rodBonuses.find(row => row.rodId === rod?.rodId));
  const stats = $derived(game.rodStats(rod?.rodId ?? 0));
  const bait = $derived(game.baits.find(row => row.baitId === game.baitLoadout?.baitId));
  const baitUses = $derived(game.ownedBaits.find(row => row.baitId === bait?.baitId)?.usesLeft ?? 0n);
  const owned = $derived(game.rods.filter(row => game.ownedRods.some(owned => owned.rodId === row.rodId)));
  const next = $derived(game.qualities.find(row => row.qualityLevel === (stats.quality?.qualityLevel ?? 0)+1));
  const tin = $derived(game.items.find(row => row.item === 'rusted_tin')?.quantity ?? 0n);
  const scrap = $derived(game.items.find(row => row.item === 'scrap')?.quantity ?? 0n);
  let previewing = $state(false);
  let now = $state(Date.now());
  const quote = $derived(previewing && game.upgradeQuote && !game.upgradeQuote.consumed ? game.upgradeQuote : null);
  const percent = (bp: number | undefined) => (bp ?? 0)/100;
  onMount(() => { const timer = setInterval(() => now = Date.now(),1000); return () => clearInterval(timer); });
  async function equip(event: Event) { const select = event.currentTarget as HTMLSelectElement; await game.changeLoadout(undefined,Number(select.value)); select.value = String(game.player?.equippedRodId ?? ''); previewing=false; }
  async function equipBait(event: Event) { const select = event.currentTarget as HTMLSelectElement; await game.equipBait(Number(select.value)); select.value=String(game.baitLoadout?.baitId ?? 0); }
  async function previewUpgrade() { if (!rod) return; previewing=false; await game.previewUpgrade(rod.rodId); previewing=!game.error; now=Date.now(); }
  async function confirmUpgrade() { if (!quote) return; await game.confirmUpgrade(quote.nonce); if (!game.error) previewing=false; }
</script>
<div class="gear" role="region" aria-labelledby="gear-title">
  <div class="heading"><h3 id="gear-title">Gear</h3><p>Ready for the next cast.</p></div>
  <div class="slots">
    <article class="rod-slot" aria-label="Equipped rod" data-quality={stats.quality?.qualityLevel ?? 0}>
      <div class="art">{#if bonuses}<img src={bonuses.spriteAsset} alt={rod?.name ?? 'Fishing rod'} width="160" height="160" />{:else}<span aria-hidden="true">🎣</span>{/if}</div>
      <div class="details"><small>EQUIPPED ROD</small><span class="quality">{stats.quality?.name ?? 'Common'}</span><h4>{rod?.name ?? 'Loading your rod…'}</h4>
        {#if rod}<dl><div><dt>Power</dt><dd>{stats.power}</dd></div><div><dt>Bonus pull</dt><dd>{stats.power/2}%</dd></div><div><dt>Luck with bait</dt><dd>+{percent(stats.luckBp+(bait?.luckBp ?? 0))}%</dd></div><div><dt>Fishing XP</dt><dd>+{percent(stats.xpBonusBp+(bait?.xpBonusBp ?? 0))}%</dd></div></dl>{/if}
        <label for="gear-rod">Switch rod</label><select id="gear-rod" value={rod?.rodId ?? ''} disabled={!game.ready || game.busy || !owned.length} onchange={equip}>
          {#each owned as option (option.rodId)}<option value={option.rodId} disabled={option.minimumLevel > (game.profile?.level ?? 1)}>{game.rodStats(option.rodId).quality?.name ?? 'Common'} {option.name}</option>{/each}
        </select>
        {#if next}<div class="recipe"><p>Next: <strong>{next.name}</strong></p><p class:short={tin < next.tinCost}>{tin.toString()} / {next.tinCost.toString()} rusted tin</p><p class:short={scrap < next.scrapCost}>{scrap.toString()} / {next.scrapCost.toString()} scrap</p><p>Power +{next.powerBonus-(stats.quality?.powerBonus ?? 0)} · luck +{percent(next.luckBp-(stats.quality?.luckBp ?? 0))}% · XP +{percent(next.xpBonusBp-(stats.quality?.xpBonusBp ?? 0))}%</p>
        <button disabled={!game.ready || game.busy || tin < next.tinCost || scrap < next.scrapCost} onclick={previewUpgrade}>Preview upgrade to {next.name}</button></div>{:else if stats.quality}<p class="mastered">Prismatic · fully upgraded</p>{/if}
      </div>
    </article>
    <article class="bait-slot" aria-label="Equipped bait">
      <div class="art bait-art">{#if bait}<img src={bait.spriteAsset} alt={bait.name} width="100" height="100" />{:else}<span aria-hidden="true">—</span>{/if}</div>
      <div class="details"><small>BAIT SLOT</small><h4>{bait?.name ?? 'No bait equipped'}</h4>
        {#if bait}<p class="uses">{baitUses.toString()} uses remaining</p><p>{bait.resourceItem ? '+1 ' + (bait.resourceItem === 'scrap' ? 'scrap' : 'rusted tin') + ' alongside each cast.' : bait.xpBonusBp ? '+' + percent(bait.xpBonusBp) + '% fishing XP.' : '+' + percent(bait.luckBp) + '% luck for larger, heavier fish.'}</p>{/if}
        <label for="gear-bait">Switch bait</label><select id="gear-bait" value={bait?.baitId ?? 0} disabled={!game.ready || game.busy} onchange={equipBait}><option value={0}>No bait</option>
          {#each game.baits as option (option.baitId)}{@const uses = game.ownedBaits.find(row => row.baitId === option.baitId)?.usesLeft ?? 0n}<option value={option.baitId} disabled={uses === 0n}>{option.name} · {uses.toString()} uses</option>{/each}
        </select><p class="note">One use per cast, including a bonus pull. When it runs out, fishing continues without bait.</p><a href="#trader">Buy bait at the Camp Trader →</a>
      </div>
    </article>
  </div>
  {#if quote}{@const target = game.rods.find(row => row.rodId === quote.rodId)}{@const quality = game.qualities.find(row => row.qualityLevel === quote.fromQuality+1)}<div class="upgrade-offer" role="region" aria-label="Confirm rod upgrade"><strong>Craft a {quality?.name} {target?.name}?</strong><p>Spend {quote.tinCost.toString()} rusted tin and {quote.scrapCost.toString()} scrap. Guaranteed success; this upgrade stays with this rod.</p><div><button disabled={game.busy || !game.ready || quote.expiresAt.microsSinceUnixEpoch <= BigInt(now)*1000n} onclick={confirmUpgrade}>Confirm upgrade</button><button class="secondary" onclick={() => previewing=false}>Cancel</button></div></div>{/if}
  <p class="note">Power adds a chance of one extra fish, junk or treasure pull. Luck favors larger, heavier catches. Any owned rod can fish in waters unlocked by your level and licence.</p>
</div>
<style>
  .gear{margin-bottom:26px;padding-bottom:24px;border-bottom:1px dashed #b99a69;color:var(--wood-dark,#493126)}
  .heading{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}h3,h4,p{margin:0}h3,h4{font-family:var(--font-game);font-weight:400}h3{font-size:26px}h4{font-size:28px;margin:6px 0 12px}.heading p,.note{font-size:12px;color:#87714f;line-height:1.8}.note{margin-top:15px}
  .slots{display:grid;grid-template-columns:3fr 2fr;gap:16px}article{display:flex;gap:18px;align-items:center;padding:18px;background:#fff1ce;border:2px solid #b89c6c;box-shadow:2px 3px 0 #cdb383;min-width:0}.art{display:grid;place-items:center;flex:none;width:160px;height:160px;background:repeating-linear-gradient(0deg,#ffffff05 0 3px,#00000004 3px 6px),#e6d7ac;border:3px solid #977343;box-shadow:inset 0 0 0 3px #cbb281}.art img{width:100%;height:100%;padding:8px;object-fit:contain;image-rendering:pixelated}.art span{font-size:64px}.details{flex:1;min-width:0}.details small{font-size:10px;letter-spacing:1px;color:#806543}dl{display:flex;gap:16px;flex-wrap:wrap;margin:0 0 16px}dl div{display:grid;gap:4px}dt{font-size:10px;color:#806543}dd{font-family:var(--font-game);font-size:23px;margin:0;color:#4c713b}label{display:block;font-size:11px;margin-bottom:6px}select{font:inherit;width:100%;max-width:260px;background:#efe0b8;color:#493126;padding:9px;border:2px solid #947348;cursor:pointer}select:disabled{opacity:.6;cursor:default}select:focus-visible{outline:3px solid #4c713b;outline-offset:3px}.bait-slot{background:#eadfc1;border-style:dashed;box-shadow:none}.bait-slot p{font-size:12px;line-height:1.8;margin-top:12px;color:#87714f}
  @media(max-width:1000px){.slots{grid-template-columns:1fr 1fr}article{flex-direction:column;align-items:flex-start}.art{align-self:center}.bait-slot{justify-content:center}.details{width:100%}}
  @media(max-width:600px){.slots{grid-template-columns:1fr}.heading{align-items:flex-start;flex-direction:column;gap:4px}article{flex-direction:row;gap:12px;padding:12px}.art{width:110px;height:110px}h4{font-size:25px}dl{gap:10px}dd{font-size:20px}dt{font-size:9px}.note{font-size:11px}}
  @media(max-width:360px){.rod-slot,.bait-slot{flex-direction:column}.bait-art{align-self:center}.art{width:140px;height:140px}}

  .quality{display:inline-block;margin-left:10px;padding:3px 7px;border:1px solid #947348;font-size:11px;background:#e4d4a8}.rod-slot[data-quality="1"] .quality{background:#c8deaf}.rod-slot[data-quality="2"] .quality{background:#b5d6e8}.rod-slot[data-quality="3"] .quality{background:#d6b8e0}.rod-slot[data-quality="4"] .quality{background:#edc988}.rod-slot[data-quality="5"] .quality{background:#e8a7b5}.rod-slot[data-quality="6"] .quality{background:linear-gradient(110deg,#c6e6de,#ddc8ec,#edd8ae)}.recipe{margin-top:14px;border-top:1px dashed #b99a69;padding-top:10px}.recipe p{font-size:11px;line-height:1.7}.short{color:#9d4036}.recipe button{margin-top:10px}.bait-art{width:100px;height:100px}.uses,.mastered{font-family:var(--font-game);font-size:22px;color:#4c713b}.bait-slot p{margin:8px 0}.bait-slot a{font-size:12px;color:#4c713b;display:block;margin-top:14px}button{font-family:var(--font-game);font-size:17px;background:#59714b;color:#fff0c8;border:2px solid #4c3928;padding:9px 12px;cursor:pointer}button:disabled{opacity:.5;cursor:default}button:focus-visible,a:focus-visible{outline:3px solid #b47f35;outline-offset:3px}.upgrade-offer{margin-top:18px;padding:16px;border:2px solid #738551;background:#dfd8ac}.upgrade-offer strong{font-family:var(--font-game);font-size:24px}.upgrade-offer p{font-size:12px;line-height:1.8;margin:10px 0}.upgrade-offer>div{display:flex;gap:10px;flex-wrap:wrap}.secondary{background:#e9d9b1;color:#4c3928}@media(max-width:700px){.slots{grid-template-columns:1fr}.rod-slot{flex-direction:column}.art{align-self:center}.details{width:100%}}
</style>
