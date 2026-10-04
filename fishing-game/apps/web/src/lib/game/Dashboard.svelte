<script lang="ts">
  import { onMount, tick } from 'svelte';
  import * as env from '$app/env/public';
  import { Game } from './game.svelte';
  const game = new Game();
  let selected = $state<bigint[]>([]);
  let showQuote = $state(false);
  let bookBiome = $state(1);
  let bookTier = $state('');
  let bookQuery = $state('');
  let linkForm: HTMLFormElement;
  let now = $state(Date.now());
  const grades = ['Tiny', 'Small', 'Typical', 'Large', 'Trophy', 'Colossal'];
  const capacity = $derived(game.config?.inventoryCapacity ?? 100);
  const level = $derived(game.profile?.level ?? 1);
  const currentBiome = $derived(game.biomes.find(row => row.biomeId === game.player?.selectedBiomeId));
  const currentRod = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const ordinary = $derived(game.species.filter(row => row.countsForOrdinaryCollectionCompletion));
  const discoveries = $derived(game.collection.filter(row => ordinary.some(fish => fish.speciesId === row.speciesId)).length);
  const book = $derived(game.species.filter(fish => (!bookBiome || fish.biomeId === bookBiome) && (!bookTier || fish.allowedRarities.includes(bookTier)) && fish.name.toLowerCase().includes(bookQuery.trim().toLowerCase())));
  const visibleRecords = $derived(game.records.filter(record => game.species.some(fish => fish.speciesId === record.speciesId && (!bookBiome || fish.biomeId === bookBiome))).slice(0, 20));
  const remaining = $derived(game.player ? Math.max(0, Math.ceil(Number(game.player.nextCastAt.microsSinceUnixEpoch / 1000n - BigInt(now)) / 1000)) : 0);
  const quote = $derived(showQuote && game.action?.kind === 'sell' && !game.action.consumed ? game.action : null);
  const selectedOwned = $derived(selected.filter(id => game.inventory.some(fish => fish.catchId === id && !fish.favorite)));
  const speciesName = (id: number | undefined) => game.species.find(fish => fish.speciesId === id)?.name ?? 'Fish';
  const sprite = (id: number) => game.species.find(fish => fish.speciesId === id)?.spriteAsset ?? '/fish/minnow.png';
  const rankMinimums = (tier: string) => { const rule = game.rarities.find(row => row.tier === tier); return !rule || tier === 'F' ? 'Below D in length or weight' : `At least ${Number(rule.minimumLengthMillionths) / 1_000_000}× typical length and ${Number(rule.minimumWeightMillionths) / 1_000_000}× typical weight`; };
  const time = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const linkUrl = (env.PUBLIC_ACCOUNT_LINK_URL ?? '').replace(/\/$/, '') + '/auth/discord/start';
  function select(id: bigint) { selected = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id]; showQuote = false; }
  async function link() {
    await game.prepareLink();
    await tick();
    if (game.challenge && !game.challenge.consumed && !game.error) linkForm.requestSubmit();
  }
  async function preview() { await game.previewSale(selectedOwned); showQuote = !game.error; }
  async function confirm() {
    if (!quote) return;
    await game.confirmSale(quote.nonce);
    if (!game.error) { selected = []; showQuote = false; }
  }
  onMount(() => {
    game.start(env.PUBLIC_SPACETIMEDB_URI ?? '', env.PUBLIC_SPACETIMEDB_DATABASE ?? '');
    const timer = setInterval(() => { now = Date.now(); }, 1000);
    return () => { clearInterval(timer); game.stop(); };
  });
</script>

<svelte:head><title>Fishbound · Your pond</title><meta name="description" content="Your catches, collection, and records. One cast at a time." /></svelte:head>

<main>
  <nav aria-label="Main navigation"><a class="brand" href="/">◈ Fishbound</a><a href="#waters">Biomes</a><a href="/catalog">All 251 fish ↗</a><span class:live={game.ready} class="connection"><span></span>{game.status}</span></nav>
  <header>
    <div><p class="eyebrow">{currentBiome?.name ?? 'SEVEN WATERS TO EXPLORE'}</p><h1>A little patience.<br />A great catch.</h1><p class="intro">Cast in Discord. Keep your favorites here.<br />There’s always something worth waiting for.</p></div>
    <div class="pond" aria-hidden="true"><span class="ripple r1"></span><span class="ripple r2"></span><span class="ripple r3"></span><img src="/fish/koi.png" alt="" /><span class="float"></span><p>ONE CAST. EVERY MINUTE.</p></div>
  </header>

  {#if game.error}<p class="notice error" role="alert">{game.error}</p>{/if}
  {#if !game.ready && env.PUBLIC_SPACETIMEDB_DATABASE}<button class="secondary" onclick={() => game.reconnect()}>Reconnect</button>{/if}

  {#if !game.player}
    <section class="welcome" aria-labelledby="welcome"><div><p class="eyebrow">YOUR COLLECTION STARTS HERE</p><h2 id="welcome">Meet your next favorite fish.</h2><p>Use <code>/fish</code> in Discord, then link your account to see your catches, collection, and personal records.</p><p class="muted">251 species across seven biomes, starting with 28 in Meadow Pond. Your discoveries stay with you, even after a sale.</p></div>
      <div class="login"><button disabled={!game.ready || game.busy || !env.PUBLIC_ACCOUNT_LINK_URL} onclick={link}>{game.busy ? 'Preparing…' : 'Link Discord account →'}</button><small>{!env.PUBLIC_ACCOUNT_LINK_URL ? 'Discord sign-in will be available soon.' : game.ready ? 'One account, shared between Discord and this browser.' : 'Account linking becomes available when the pond is connected.'}</small></div>
    </section>
  {:else}
    <section class="stats" aria-label="Your progress">
      <div><span>ANGLER LEVEL</span><strong>{game.profile?.level ?? 1}</strong><small>{game.player.totalXp.toString()} total XP</small></div>
      <div><span>YOUR COINS</span><strong>{game.player.coins.toString()}</strong><small>Saved catches fund your journey</small></div>
      <div><span>DISCOVERIES</span><strong>{discoveries}<em> / {ordinary.length}</em></strong><small>Ordinary book · two bonus discoveries</small></div>
      <div><span>NEXT CAST</span><strong>{remaining ? remaining + 's' : 'Ready'}</strong><small>Use /fish in Discord</small></div>
    </section>

    <section aria-labelledby="inventory"><div class="section-heading"><div><p class="eyebrow">KEEP THE GOOD ONES</p><h2 id="inventory">Your catches <span>{game.inventory.length} / {capacity}</span></h2></div><button class="secondary" disabled={!selectedOwned.length || selectedOwned.length > 50 || game.busy || !game.ready} onclick={preview}>Preview sale ({selectedOwned.length})</button></div>
      {#if quote}
        <div class="sale" role="status"><div><strong>Sell {quote.catchIds.length} selected {quote.catchIds.length === 1 ? 'catch' : 'catches'} for {quote.quotedCoins.toString()} coins?</strong><p>{quote.catchIds.map(id => { const fish = game.inventory.find(row => row.catchId === id); return '#' + id + ' ' + (fish ? speciesName(fish.speciesId) : '(catch changed)'); }).join(' · ')}</p><small>Discoveries and records remain. Favorites are protected. Preview expires at {time(quote.expiresAt.microsSinceUnixEpoch)}.</small></div><div class="sale-actions"><button disabled={game.busy || !game.ready || quote.expiresAt.microsSinceUnixEpoch <= BigInt(now) * 1000n} onclick={confirm}>Confirm sale · {quote.quotedCoins.toString()} coins</button><button class="secondary" onclick={() => { showQuote = false; }}>Cancel</button></div></div>
      {/if}
      {#if !game.inventory.length}<p class="empty">Your next catch could be the keeper. Try <code>/fish</code> in Discord.</p>{:else}<div class="catch-grid">
        {#each game.inventory as fish (fish.catchId)}
          <article class="catch" class:favorite={fish.favorite}><div class="catch-tools"><label><input type="checkbox" checked={selected.includes(fish.catchId)} disabled={fish.favorite || game.busy || !game.ready} onchange={() => select(fish.catchId)} /><span class="sr-only">Select catch #{fish.catchId.toString()}</span></label><button class="star" class:active={fish.favorite} disabled={game.busy || !game.ready} onclick={() => { showQuote = false; game.favorite(fish); }} aria-label={(fish.favorite ? 'Unfavorite' : 'Favorite') + ' catch #' + fish.catchId}>{fish.favorite ? '★' : '☆'}</button></div><div class="fish-art"><img src={sprite(fish.speciesId)} alt={speciesName(fish.speciesId)} /><span>{fish.rarity} RANK</span></div><p class="grade">{grades[fish.sizeGrade]}</p><h3>{speciesName(fish.speciesId)}</h3><p class="measure">{(fish.lengthMm / 10).toFixed(1)} cm <span>·</span> {(Number(fish.weightG) / 1000).toFixed(3)} kg</p><p class="relative-size">{(fish.lengthMm / (game.species.find(row => row.speciesId === fish.speciesId)?.typicalLengthMm ?? fish.lengthMm)).toFixed(2)}× typical length<br />{(Number(fish.weightG) / Number(game.species.find(row => row.speciesId === fish.speciesId)?.typicalWeightG ?? fish.weightG)).toFixed(2)}× typical weight</p><div class="catch-footer"><span>#{fish.catchId.toString()}</span><span>{fish.saleValueCoins.toString()} coins</span></div></article>
        {/each}
      </div>{/if}
    </section>

    <section aria-labelledby="collection"><div class="section-heading"><div><p class="eyebrow">EVERY DISCOVERY COUNTS</p><h2 id="collection">Collection book</h2></div><span class="muted">{discoveries} of {ordinary.length} ordinary species discovered</span></div>
      <div class="filters"><div><label for="book-biome">Biome</label><select id="book-biome" bind:value={bookBiome}><option value={0}>All waters</option>{#each game.biomes as biome}<option value={biome.biomeId}>{biome.name}</option>{/each}</select></div><div><label for="book-rank">Rank</label><select id="book-rank" bind:value={bookTier}><option value="">All ranks</option>{#each ['F','D','C','B','A','S','SS','SSS','UR','UUR'] as tier}<option value={tier}>{tier}</option>{/each}</select></div><div><label for="book-search">Find a fish</label><input id="book-search" type="search" placeholder="Name…" bind:value={bookQuery} /></div></div>
      <p class="muted">Showing {book.length} species. Ordinary ranks come from length and weight relative to each species; both must meet the rank minimum. Fihs and Nidalees Lost Sock are bonus discoveries.</p>{#if bookTier}<p class="muted">{bookTier}: {rankMinimums(bookTier)}.</p>{/if}<div class="collection-grid">
      {#each book as fish (fish.speciesId)}{@const discovery = game.collection.find(row => row.speciesId === fish.speciesId)}{@const caughtAtRank = !!discovery && (!bookTier || discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)] > 0n)}<article class="collection-entry" class:undiscovered={!caughtAtRank}><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /><div><small>{bookTier ? bookTier + ' RANK' : fish.allowedRarities.length === 10 ? 'F THROUGH UUR' : fish.allowedRarities[0] + ' ONLY'}{fish.countsForOrdinaryCollectionCompletion ? '' : ' · BONUS'}</small><h3>{fish.name}</h3><p>{game.biomes.find(b => b.biomeId === fish.biomeId)?.name}</p>{#if discovery}<p>{bookTier ? discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)].toString() + ' caught at ' + bookTier : discovery.count.toString() + ' caught across ranks'} · best across ranks {(discovery.bestLengthMm / 10).toFixed(1)} cm / {(Number(discovery.bestWeightG) / 1000).toFixed(3)} kg</p>{:else}<p>Waiting for your first discovery</p>{/if}<div class="rank-progress" aria-label="Ranks caught">{#each fish.allowedRarities as rank}<span title={rankMinimums(rank)} class:earned={!!discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n}>{rank}{discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n ? ' ✓' : ' ○'}</span>{/each}</div></div><span class="discovered" aria-label={caughtAtRank ? 'Discovered at selected rank' : 'Undiscovered at selected rank'}>{caughtAtRank ? '✓' : '○'}</span></article>{/each}
      {#if !book.length}<p class="muted">No fish match these filters.</p>{/if}
    </div></section>

    <section class="journal" aria-labelledby="journal"><div><p class="eyebrow">THE LAST FEW CASTS</p><h2 id="journal">Fishing journal</h2>{#if !game.recent.length}<p class="muted">A fresh page. Go make a little fishing history.</p>{:else}<ol>{#each game.recent.slice(0, 8) as catchResult (catchResult.recentId)}<li><span>{catchResult.outcome === 'fish' ? '🎣' : catchResult.outcome === 'junk' ? '🥫' : '🪙'}</span><div><strong>{catchResult.outcome === 'fish' ? speciesName(catchResult.speciesId) + ' · ' + catchResult.rarity + ' rank' : catchResult.outcome === 'junk' ? 'Rusted tin' : 'Treasure cache'}</strong><small>{time(catchResult.caughtAt.microsSinceUnixEpoch)}</small></div><span class="xp">+{catchResult.xpGranted.toString()} XP</span></li>{/each}</ol>{/if}</div><aside><p class="eyebrow">YOUR ITEMS</p><h2>A few useful finds</h2>{#if !game.items.length}<p class="muted">Scrap and rusted tins from your casts appear here.</p>{:else}{#each game.items as item (item.key)}<p class="item"><span>{item.item === 'scrap' ? 'Scrap' : 'Rusted tin'}</span><strong>{item.quantity.toString()}</strong></p>{/each}{/if}<p class="muted">Earn stronger rods as you level up to explore all seven biomes. Bait and upgrade recipes come later.</p></aside></section>
  {/if}

  {#if game.biomes.length}
    <section aria-labelledby="waters"><p class="eyebrow">SEVEN WATERS, ONE JOURNEY</p><h2 id="waters">Explore the biomes</h2>
      {#if game.player}<p class="muted">Equipped: {currentRod?.name} · power {currentRod?.power}. Rods are earned free at their unlock level. Travel keeps your cast cooldown.</p>
        <div class="filters"><label>Equip an earned rod<select value={game.player.equippedRodId} disabled={game.busy || !game.ready} onchange={event => game.changeLoadout(undefined, Number(event.currentTarget.value))}>{#each game.rods as rod}<option value={rod.rodId} disabled={rod.minimumLevel > level || rod.power < (currentBiome?.requiredPower ?? 0)}>{rod.name} · level {rod.minimumLevel} · power {rod.power}</option>{/each}</select></label></div>
      {/if}
      <div class="biome-grid">{#each game.biomes as biome (biome.biomeId)}
        {@const pool = game.species.filter(fish => fish.biomeId === biome.biomeId)}
        {@const rod = game.rods.find(row => row.minimumLevel <= level && row.power >= biome.requiredPower)}
        {@const active = currentBiome?.biomeId === biome.biomeId}
        <article class:active><small>LEVEL {biome.minimumLevel} · POWER {biome.requiredPower}</small><h3>{biome.name}</h3><p>{biome.description}</p><p>{pool.length} species · {biome.fishWeight.toString()}% fish / {biome.junkWeight.toString()}% junk / {biome.treasureWeight.toString()}% treasure</p>
          <button class="secondary" disabled={!game.player || game.busy || !game.ready || level < biome.minimumLevel || !rod || active} onclick={() => { if (rod) game.changeLoadout(biome.biomeId, currentRod && currentRod.power >= biome.requiredPower ? currentRod.rodId : rod.rodId); }}>{active ? 'Current waters' : level < biome.minimumLevel ? 'Unlock at level ' + biome.minimumLevel : 'Equip & travel'}</button>
        </article>
      {/each}</div>
    </section>
  {/if}
  {#if game.species.length}<section aria-labelledby="records"><p class="eyebrow">A CATCH WORTH BRAGGING ABOUT</p><h2 id="records">Species records</h2><p class="muted">Up to 20 records from the collection's selected waters.</p><div class="record-grid">{#each visibleRecords as record (record.key)}<article><small>{speciesName(record.speciesId)} · {record.rarity} · {record.metric === 'length' ? 'LONGEST' : 'HEAVIEST'}</small><strong>{record.metric === 'length' ? (Number(record.measurement) / 10).toFixed(1) + ' cm' : (Number(record.measurement) / 1000).toFixed(3) + ' kg'}</strong><span>{record.displayName}</span></article>{/each}</div>{#if !visibleRecords.length}<p class="muted">The first record is yours to set.</p>{/if}</section>{/if}

  <footer><span>Fishbound · One cast at a time.</span><div>{#if game.lastSync}<span>Updated {game.lastSync.toLocaleTimeString()}</span>{/if}{#if game.player}<button class="text-button" disabled={game.busy || !game.ready} onclick={() => game.logout()}>Unlink this browser</button>{/if}</div></footer>
  <form method="POST" action={linkUrl} bind:this={linkForm} hidden><input name="challenge_id" value={game.challenge?.challengeId.toString() ?? ''} /><input name="proof" value={game.challenge?.proof.toString() ?? ''} /></form>
</main>

<style>
  .relative-size{font-size:10px;color:#a8bdb2;line-height:1.7}
  .rank-progress{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px}.rank-progress span{font-size:9px;color:#758f88;border:1px solid #334a4a;padding:3px;border-radius:3px}.rank-progress span.earned{color:#edcf88;border-color:#897a51}.collection-entry>div{min-width:0;flex:1}
  .filters{display:flex;flex-wrap:wrap;gap:16px;margin:20px 0}.filters>div,.filters label{display:flex;flex-direction:column;gap:8px;font-size:12px;color:#b4c5bb;min-width:0}.filters select,.filters input{max-width:100%;border:1px solid #4c6260;border-radius:6px;background:#192a2d;color:#edf1e8;padding:10px;font:inherit}.biome-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:16px}.biome-grid article{padding:22px;border:1px solid #34494c;border-radius:9px;background:#1b2b2f}.biome-grid article.active{border-color:#d0bc82}.biome-grid small{font-size:10px;color:#c4cfa8}.biome-grid p{font-size:12px;color:#a8bdb2;line-height:1.7}
  :global(body){margin:0;background:#101c20;color:#edf1e8;font-family:system-ui,-apple-system,sans-serif} :global(*){box-sizing:border-box} :global(button),:global(input){font:inherit} :global(a){color:inherit} :global(button:focus-visible),:global(a:focus-visible),:global(input:focus-visible){outline:2px solid #f1ca77;outline-offset:4px}
  main{max-width:1240px;margin:auto;padding:0 40px} nav{min-height:90px;display:flex;align-items:center;gap:32px;border-bottom:1px solid #304045;font-size:13px} nav a{text-decoration:none} .brand{font-family:Georgia,serif;font-size:26px;margin-right:auto;color:#efd49a} .connection{display:flex;align-items:center;gap:8px;color:#93a5a7} .connection>span{width:7px;height:7px;background:#6d7c7e;border-radius:50%}.connection.live{color:#a8d2b9}.connection.live>span{background:#a8d2b9}
  header{display:grid;grid-template-columns:1.2fr 1fr;align-items:center;gap:36px;padding:62px 0 55px} .eyebrow{font-size:10px;font-weight:700;letter-spacing:.18em;color:#b5c6ab;margin:0 0 16px}h1{font-family:Georgia,serif;font-weight:400;font-size:clamp(38px,5.1vw,62px);letter-spacing:-.04em;line-height:1.1;margin:0 0 20px}.intro{color:#b0c0bd;font-size:15px;line-height:1.7}h2{font-family:Georgia,serif;font-weight:400;font-size:29px;margin:0 0 18px}h3{font-size:16px;font-weight:550;margin:8px 0}h2>span{font-family:system-ui;font-size:13px;color:#99abab;margin-left:12px}.muted{color:#97abaa;font-size:13px;line-height:1.7}code{color:#eed291;font-size:14px}
  .pond{position:relative;height:285px;border-radius:50%;background:radial-gradient(ellipse,#334e46 0,#223737 38%,#142529 64%,transparent 73%);overflow:hidden}.ripple{position:absolute;left:50%;top:48%;transform:translate(-50%,-50%) rotate(-8deg);border:1px solid #74988438;border-radius:50%}.r1{width:80%;height:65%}.r2{width:57%;height:43%}.r3{width:32%;height:22%}.pond img{position:absolute;width:135px;height:135px;object-fit:contain;image-rendering:pixelated;left:33%;top:22%;transform:rotate(-18deg);filter:drop-shadow(10px 18px 8px #0005)}.float{position:absolute;width:9px;height:24px;background:linear-gradient(#e4c294 50%,#cf7160 50%);border-radius:5px;left:69%;top:37%;transform:rotate(14deg)}.pond p{position:absolute;bottom:12px;left:0;width:100%;text-align:center;font-size:9px;letter-spacing:.22em;color:#849e93}
  button{border:1px solid #cdb778;border-radius:6px;padding:12px 18px;background:#e8cd8d;color:#17262a;cursor:pointer;font-size:12px;font-weight:650}button:disabled{opacity:.5;cursor:default}.secondary{background:transparent;border-color:#4c6260;color:#cbd8cd}.welcome{background:#1b2d30;border:1px solid #344849;border-radius:10px;display:grid;grid-template-columns:1.6fr 1fr;padding:32px;gap:40px}.welcome p{font-size:14px;line-height:1.7}.welcome h2{margin-bottom:12px}.login{display:flex;flex-direction:column;justify-content:center;gap:16px;max-width:280px}.login small{font-size:11px;line-height:1.6;color:#a2b4b0}.notice{padding:16px;border:1px solid #946c59;border-radius:6px;background:#352920;color:#efcbb0;font-size:13px}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid #3a4d4d;border-radius:10px;background:#1a2b2e}.stats>div{padding:25px;border-right:1px solid #3a4d4d}.stats>div:last-child{border:0}.stats span{font-size:9px;letter-spacing:.14em;color:#b0c2b0}.stats strong{font-family:Georgia,serif;font-weight:400;font-size:37px;display:block;margin:12px 0}.stats em{font-size:20px;font-style:normal;color:#97aaa2}.stats small{font-size:11px;color:#94a8a6}section+section{margin-top:48px}section{scroll-margin-top:30px}.section-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.section-heading h2{margin-bottom:0}.section-heading .eyebrow{margin-bottom:10px}
  .catch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}.catch{border:1px solid #34494c;border-radius:9px;padding:16px;background:#1b2b2f}.catch.favorite{border-color:#897a51}.catch-tools{display:flex;justify-content:space-between;align-items:center;height:23px}.catch-tools input{accent-color:#e8cd8d;width:16px;height:16px}.star{background:transparent;border:0;color:#92a4a2;padding:0;font-size:24px;line-height:1}.star.active{color:#edcf88}.fish-art{height:125px;display:flex;align-items:center;justify-content:center;position:relative;background:radial-gradient(ellipse,#35504a55,transparent 70%)}.fish-art img{width:115px;height:100px;object-fit:contain;image-rendering:pixelated}.fish-art>span{position:absolute;right:0;bottom:8px;border:1px solid #576d5c;color:#bbccab;padding:3px 5px;border-radius:3px;font-size:8px;letter-spacing:.08em}.grade{color:#b7c7b9;font-size:10px;margin:8px 0 3px}.measure{font-size:12px;color:#b0c1be}.measure span{margin:0 5px;color:#657d78}.catch-footer{border-top:1px solid #34454a;display:flex;justify-content:space-between;padding-top:12px;margin-top:18px;font-size:10px;color:#a7bab1}.catch-footer span:last-child{color:#e0c690}.empty{background:#1b2b2f;border:1px dashed #49635f;padding:28px;border-radius:8px;color:#adbfba;font-size:14px}.sale{border:1px solid #a18e60;border-radius:8px;padding:22px;margin-bottom:20px;background:#28372e}.sale strong{font-size:15px;color:#ebd299}.sale p{font-size:12px;color:#bbc9bb;line-height:1.7}.sale small{color:#a8bbad;font-size:11px}.sale-actions{display:flex;gap:12px;margin-top:20px}
  .collection-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.collection-entry{display:flex;align-items:center;gap:18px;border:1px solid #31474a;border-radius:8px;padding:16px;background:#192a2d}.collection-entry>img{height:56px;width:60px;object-fit:contain;image-rendering:pixelated}.collection-entry small{font-size:9px;letter-spacing:.1em;color:#b9cbb1}.collection-entry h3{margin:6px 0;font-size:14px}.collection-entry p{font-size:11px;color:#9cafaa;margin:4px 0}.discovered{margin-left:auto;color:#a3c9a5}.undiscovered>img{filter:grayscale(1);opacity:.3}.undiscovered h3{color:#a1b4ac}.undiscovered .discovered{color:#526e65}
  .journal{display:grid;grid-template-columns:1.4fr 1fr;gap:48px}.journal ol{list-style:none;padding:0;margin:0}.journal li{display:flex;gap:15px;align-items:center;padding:14px 0;border-bottom:1px solid #2d4345}.journal li strong{font-size:12px}.journal li small{display:block;font-size:10px;color:#849b98;margin-top:5px}.xp{margin-left:auto;color:#a8c3a3;font-size:11px}.journal aside{border-left:1px solid #334a4a;padding-left:32px}.item{display:flex;justify-content:space-between;font-size:13px;border-bottom:1px solid #304746;padding:12px 0}.item strong{color:#d8c38f}.record-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}.record-grid article{border:1px solid #324949;padding:20px;border-radius:8px}.record-grid small{font-size:9px;color:#b7c9b6;letter-spacing:.06em}.record-grid strong{display:block;font-family:Georgia,serif;font-size:25px;font-weight:400;margin:12px 0}.record-grid span{color:#95aca5;font-size:11px}footer{border-top:1px solid #304646;margin-top:65px;padding:26px 0 35px;display:flex;justify-content:space-between;gap:16px;color:#839a94;font-size:10px}footer>div{display:flex;gap:24px;align-items:center}.text-button{color:#a5b7ab;background:transparent;border:0;font-size:10px;padding:0}.sr-only{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}
  @media(max-width:760px){main{padding:0 20px}nav{min-height:70px;gap:18px;flex-wrap:wrap}.brand{font-size:22px}nav>a:not(.brand){font-size:11px}.connection{font-size:10px}header{grid-template-columns:1fr;padding:38px 0 32px;gap:10px}.pond{height:210px}.pond img{height:110px;width:110px;left:36%}.welcome{grid-template-columns:1fr;padding:24px;gap:20px}.login{max-width:none}.stats{grid-template-columns:1fr 1fr}.stats>div:nth-child(2){border-right:0}.stats>div:nth-child(-n+2){border-bottom:1px solid #3a4d4d}.stats>div{padding:20px}.stats small{font-size:10px}.section-heading{align-items:flex-start;flex-wrap:wrap}.collection-grid{grid-template-columns:1fr}.catch-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.catch{padding:12px}.catch h3{font-size:14px}.fish-art{height:105px}.fish-art img{width:95px;height:80px}.measure{font-size:10px}.journal{grid-template-columns:1fr;gap:30px}.journal aside{border-left:0;border-top:1px solid #334a4a;padding:25px 0 0}.sale-actions{flex-wrap:wrap}footer{flex-direction:column}footer>div{justify-content:space-between}.record-grid{grid-template-columns:1fr 1fr}.record-grid article{padding:15px}}
</style>

