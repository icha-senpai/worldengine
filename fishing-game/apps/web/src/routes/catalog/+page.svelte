<script lang="ts">
  import manifest from '../../../../../assets/manifest.json';
  import catalog from '../../../../../content/species.json';
  import world from '../../../../../content/world.json';
  const tiers = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let query = $state('');
  let biome = $state(0);
  let rarity = $state('');
  const species = catalog.species.map((entry) => ({ ...entry, asset: manifest.fish.find((asset) => asset.key === entry.spriteKey)! }));
  const fish = $derived(species.filter((entry) => (!biome || entry.biomeId === biome) && (!rarity || entry.allowedRarities.includes(rarity)) && (entry.name.toLowerCase().includes(query.trim().toLowerCase()) || entry.key.includes(query.trim().toLowerCase().replaceAll(' ', '-')))));
</script>

<svelte:head>
  <title>Fishbound · Fish catalog</title>
  <meta name="description" content="The public Fishbound fish compendium: names, biomes, rarities, and cast rates." />
</svelte:head>

<main>
  <header>
    <a href="/">← Your pond</a>
    <p class="eyebrow">PUBLIC FISH COMPENDIUM</p>
    <h1>Fishbound</h1>
    <p>A shared world, one cast at a time.</p>
    <p class="status">251 catchable species · Seven biomes · All ten rarity tiers.</p>
  </header>
  <section aria-labelledby="sprites">
    <h2 id="sprites">Fish catalog <span>{species.length} fish</span></h2>
    <label for="search">Find a fish</label>
    <input id="search" type="search" placeholder="Try carp, koi, trout…" bind:value={query} />
    <div class="filters"><div><label for="catalog-biome">Biome</label><select id="catalog-biome" bind:value={biome}><option value={0}>All waters</option>{#each world.biomes as b}<option value={b.biomeId}>{b.name}</option>{/each}</select></div><div><label for="catalog-rank">Rank</label><select id="catalog-rank" bind:value={rarity}><option value="">All ranks</option>{#each tiers as tier}<option value={tier}>{tier}</option>{/each}</select></div></div>
    <p class="count">Showing {fish.length} fish. Rates are per accepted cast in each fish's biome{rarity ? ' at the selected rank' : ', across all eligible ranks'}.</p>
    <div class="fish">
      {#each fish as entry (entry.speciesId)}
        <figure>
          <img src={entry.asset.url} alt={entry.name} loading="lazy" width={entry.asset.width} height={entry.asset.height} />
          <figcaption>
            {entry.name}
            <span class="detail">{world.biomes.find(b => b.biomeId === entry.biomeId)?.name}</span>
            <span class="rarity">{rarity || (entry.allowedRarities.length === 10 ? 'F through UUR' : entry.allowedRarities[0] + ' only')}</span>
            <span class="detail">About 1 in {(100_000_000 / ((rarity ? entry.ranks.find(rank => rank.rarity === rarity)!.encounterWeight : entry.encounterWeight) * world.biomes.find(b => b.biomeId === entry.biomeId)!.fishWeight)).toLocaleString(undefined, { maximumFractionDigits: 1 })} casts{rarity ? ' at ' + rarity : ' across ranks'}</span>
          </figcaption>
        </figure>
      {/each}
    </div>
  </section>
</main>

<style>
  .filters{display:flex;gap:16px;flex-wrap:wrap;margin-top:18px}.filters>div{display:flex;flex-direction:column;gap:8px}.filters label{margin:0}.filters select{border:1px solid #597d78;border-radius:8px;background:#162a30;color:#e6eee9;padding:10px;font:inherit}
  :global(body) { margin: 0; background: #0d1b21; color: #e6eee9; font-family: system-ui, sans-serif; }
  :global(*) { box-sizing: border-box; }
  main { max-width: 1240px; margin: auto; padding: 48px 24px 80px; }
  header { margin-bottom: 48px; }
  .eyebrow { color: #8dc9b4; font-size: 12px; letter-spacing: .18em; }
  h1 { font-size: clamp(40px, 6vw, 68px); letter-spacing: -.04em; margin: 10px 0; }
  h2 { font-size: 24px; margin: 0 0 24px; }
  h2 span { font-size: 13px; color: #a5bcb5; font-weight: 400; margin-left: 12px; }
  .status { color: #c8d5ad; font-size: 14px; }
  section { border-top: 1px solid #2e4549; padding-top: 28px; margin-top: 40px; }
  figure { margin: 0; border: 1px solid #2e4549; border-radius: 12px; background: #162a30; padding: 12px; text-align: center; }
  figcaption { margin-top: 12px; font-size: 12px; line-height: 1.5; overflow-wrap: anywhere; }
  label { display: block; font-size: 14px; margin-bottom: 8px; }
  input { width: min(100%, 360px); border: 1px solid #597d78; border-radius: 8px; background: #162a30; color: #e6eee9; padding: 12px; font: inherit; }
  input:focus-visible { outline: 2px solid #8dc9b4; outline-offset: 3px; }
  .count { color: #a5bcb5; font-size: 13px; margin: 16px 0 24px; }
  .fish { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
  .fish img { width: 80px; height: 80px; object-fit: contain; image-rendering: pixelated; }
  .rarity { display: block; color: #f2d47c; font-weight: 700; margin-top: 4px; }
  .detail { display: block; color: #a5bcb5; font-size: 11px; margin-top: 4px; }
</style>
