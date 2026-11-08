<script lang="ts">
  import manifest from '../../../../../assets/manifest.json';
  import catalog from '../../../../../content/species.json';
  import world from '../../../../../content/world.json';
  import gameRules from '../../../../../content/game-rules.json';
  import raidRules from '../../../../../content/raids.json';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import { tooltip } from './tooltip';
  import PageTurner from './PageTurner.svelte';
  import FishDetails from './FishDetails.svelte';
  import CollectionProgress from './CollectionProgress.svelte';
  import SectionTabs from './SectionTabs.svelte';
  import InfoButton from './InfoButton.svelte';
  import { matchesBookFilters } from './fish-book-ui';
  import { baselineLoadout, castChance, formatOdds, formatPercent, perfectCatchOdds } from './compendium';
  import { bestPerfectLoadout, collectionFocusContext, equippedLoadout } from './compendium-loadouts';
  import type { Game } from './game.svelte';
  type BookView = 'collection' | 'field-guide';
  let { active = true, game, requestedView, onViewChange, collectionPageSize = 12 } = $props<{
    active?: boolean; game?: Game; requestedView?: BookView; onViewChange?: (view: BookView) => void; collectionPageSize?: number;
  }>();
  const tabs: { id: BookView; label: string }[] = [
    { id: 'collection', label: 'My collection' }, { id: 'field-guide', label: 'Field guide / odds' },
  ];
  let fallbackView = $state<BookView>('field-guide');
  const view = $derived(requestedView ?? fallbackView);
  function selectView(next: BookView) {
    if (next === view) return;
    if (onViewChange) onViewChange(next); else fallbackView = next;
  }
  const pageSize = 24;
  const tiers = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let query = $state('');
  let biome = $state(0);
  let rarity = $state('');
  let page = $state(1);
  let rateMode = $state<'baseline' | 'equipped' | 'best'>('baseline');
  const equipped = $derived(equippedLoadout(game));
  const equippedReason = $derived(!game?.player ? 'Link your Discord account to see your equipped loadout.'
    : !game.ready ? 'Wait for the connection and equipment to finish loading.'
      : game.equipmentInformationUnavailable ? 'Equipment details are unavailable. Retry Tideforge to load your equipped stats.'
        : !equipped ? 'Your equipped rod details are unavailable. Review your gear in Tideforge.' : '');
  const loadout = $derived(rateMode === 'best' ? { ...bestPerfectLoadout, focus: collectionFocusContext(game) } : rateMode === 'equipped' && equipped ? equipped : baselineLoadout);
  $effect(() => { if (rateMode === 'equipped' && !equipped) rateMode = 'baseline'; });
  const focusBiomeId = $derived(biome || game?.player?.selectedBiomeId || 1);
  const focusBiome = $derived(world.biomes.find(row => row.biomeId === focusBiomeId)!);
  const focusSpecies = $derived(catalog.species.filter(row => row.biomeId === focusBiomeId && row.countsForOrdinaryCollectionCompletion));
  const focusDiscovered = $derived(focusSpecies.filter(fish => game?.collection.some((row: { playerId: bigint; speciesId: number; count: bigint }) => row.playerId === game?.player?.playerId && row.speciesId === fish.speciesId && row.count > 0n)).length);
  const focusEnabled = $derived(game?.focusPreferences.some((row: { playerId: bigint; biomeId: number; enabled: boolean }) => row.playerId === game?.player?.playerId && row.biomeId === focusBiomeId && row.enabled) ?? false);
  const focusLicensed = $derived(focusBiomeId === 1 || !!game?.licences.some((row: { biomeId: number }) => row.biomeId === focusBiomeId));
  const focusUnlocked = $derived(focusDiscovered === focusSpecies.length && focusLicensed);
  const reinforcementLevel = $derived(game?.equipmentInformationUnavailable ? undefined : equipped?.reinforcementLevel);
  const reinforcementGrade = $derived(reinforcementLevel
    ? raidRules.grades[reinforcementLevel - 1]?.name ?? 'reinforced'
    : 'unreinforced');
  const focusChanceBp = $derived(reinforcementLevel
    ? raidRules.grades[reinforcementLevel - 1]?.focusChanceBp ?? gameRules.collectionFocusBp
    : gameRules.collectionFocusBp);
  const focusReason = $derived(!game?.player ? 'Link Discord to track your collection and unlock this mode.'
    : !game.ready ? 'Wait for your collection to finish loading.'
      : (game.config?.version ?? 0) < 13 ? 'Collection Focus is awaiting the game update.'
        : game.busy ? 'Wait for the current action to finish.'
          : !focusLicensed && !focusEnabled ? 'Purchase this biome’s licence to use Collection Focus.'
            : !focusUnlocked && !focusEnabled ? `${focusDiscovered} / ${focusSpecies.length} ordinary species discovered. Discover them all in this biome to unlock.` : '');
  let selected = $state<typeof catalog.species[number] | null>(null);
  $effect(() => { active; view; game?.tideforgeIdentity; game?.player?.playerId; selected = null; });
  function selectFish(speciesId: number) { selected = catalog.species.find(row => row.speciesId === speciesId) ?? null; }
  const species = $derived(catalog.species.map(entry => ({ ...entry, perfect: perfectCatchOdds(entry, loadout), asset: manifest.fish.find(asset => asset.key === entry.spriteKey)! })));
  const fish = $derived(species.filter(entry => matchesBookFilters(entry, query, biome, rarity)));
</script>

<section aria-labelledby="compendium-heading">
  <h2 id="compendium-heading">The Fish Book <span>{species.length} species</span></h2>
  <SectionTabs {tabs} value={view} prefix="fish-book" label="Fish Book sections" onselect={selectView} />
  <div class="filters">
    <div class="search"><label for="catalog-search">Find a fish</label><SearchInput id="catalog-search" label="Find a fish" placeholder="Carp, koi, trout…" value={query} oninput={value => {query = value; page = 1;}} /></div>
    <div><label for="catalog-biome">Biome</label><GameSelect id="catalog-biome" label="Biome" value={biome} options={[{value:0,label:"All waters"},...world.biomes.map(b=>({value:b.biomeId,label:b.name}))]} onchange={value => {biome = value; page = 1;}} /></div>
    <div><div class="filter-heading"><label for="catalog-rank">Rank</label><InfoButton label="About fish ranks" message="Rank requires both length and weight thresholds. Descriptive size is not rank. A rank maximum is one fish at both endpoints for that rank; a perfect fish reaches both UUR endpoints." /></div><GameSelect id="catalog-rank" label="Rank" value={rarity} options={[{value:"",label:"All ranks"},...tiers.map(tier=>({value:tier,label:tier}))]} onchange={value => {rarity = value; page = 1;}} /></div>
  </div>
  <div role="tabpanel" id="fish-book-panel-collection" aria-labelledby="fish-book-tab-collection" tabindex="0" hidden={view !== 'collection'}>
  {#if view === 'collection'}
    {#if !game?.player}<p>Link Discord from Camp to track your collection.</p><a href="#camp">Open Camp</a>
    {:else if !game.ready}<p role="status">Loading your collection…</p>
    {:else}
      <div class="collection-focus">
        <label for="collection-focus-toggle"><input id="collection-focus-toggle" type="checkbox" checked={focusEnabled} disabled={!!focusReason} aria-describedby={focusReason ? 'focus-reason' : undefined} onchange={event => { if (!focusReason) game.setCollectionFocus(focusBiomeId, event.currentTarget.checked); }} /> Collection Focus · {focusBiome.name}</label>
        <p id="focus-reason">{focusReason || `${focusEnabled ? 'On' : 'Off'} · All ${focusSpecies.length} ordinary species discovered.`}</p>
        {#if reinforcementLevel === undefined}<p>Loading your equipped rod’s Focus chance…</p>
        {:else}<p>{reinforcementGrade} rod Focus chance: {focusChanceBp / 100}% for missing-rank rerolls when enabled in a mastered biome.</p>{/if}
        <p>Reinforced endpoint bonus rolls apply only to a selected ordinary UUR fish after all ten ranks of that species are recorded; special species are excluded. Known ranks can lose odds. Free to toggle; applies to Discord casts. Fihs and the Sock retain their normal odds.</p>
      </div>
      <CollectionProgress {game} {query} {biome} {rarity} pageSize={collectionPageSize} onselect={selectFish} />
    {/if}
  {/if}
  </div>
  <div role="tabpanel" id="fish-book-panel-field-guide" aria-labelledby="fish-book-tab-field-guide" tabindex="0" hidden={view !== 'field-guide'}>
  {#if view === 'field-guide'}
    <fieldset class="rate-presets"><legend>Catch rates</legend><div>
      <button aria-pressed={rateMode === 'baseline'} onclick={() => rateMode = 'baseline'}>Baseline</button>
      <button aria-pressed={rateMode === 'equipped'} disabled={!!equippedReason} aria-describedby={equippedReason ? 'equipped-rate-reason' : undefined} use:tooltip={equippedReason || undefined} onclick={() => rateMode = 'equipped'}>{game?.tideforgeAvailability === 'active' ? 'Equipped base gear' : 'Equipped'}</button>
      <button aria-pressed={rateMode === 'best'} onclick={() => rateMode = 'best'}>{game?.tideforgeAvailability === 'active' ? 'Best base gear' : 'Best for perfects'}</button>
    </div>{#if equippedReason}<small id="equipped-rate-reason">{equippedReason}{#if game?.player && game.ready} <a href="#tideforge/gear">Review gear</a>{/if}</small>{/if}</fieldset>
  <p class="loadout" aria-live="polite"><strong>{loadout.name}{rateMode === 'best' ? ' · UUR-reinforced' : ''}</strong><span>+{formatPercent(loadout.luckBp / 100)} luck · {formatPercent(loadout.power / 2)} bonus pull · {loadout.sizeRolls} size {loadout.sizeRolls === 1 ? 'attempt' : 'attempts'}</span></p>
  <p class="count">{fish.length} matching fish. Rates are per accepted cast in each fish's biome{rarity ? ' at the selected rank' : ', across all eligible ranks'}, using the loadout above.{rateMode === 'best' ? game?.tideforgeAvailability === 'active' ? ' Uses the strongest base gear and two Trophy Chums, whether or not you own them.' : ' Uses the strongest gear and two Trophy Chums, whether or not you own them.' : ''}</p>
  {#if game?.tideforgeAvailability === 'active' && rateMode !== 'baseline'}<p class="count">Base-gear estimate only. All Tideforge bonuses are excluded.</p>{/if}
  {#if rateMode !== 'baseline' && loadout.focus?.biomeIds.length}<p class="count">Rates include your enabled Collection Focus settings and current collected ranks. Focus helps missing ranks; it can lower the odds of ranks you already have, including UUR.</p>{/if}
  <p class="count perfect-note">✦ Perfect rates always refer to both exact maximum measurements at UUR, regardless of the rank filter. They include natural perfects, the UUR perfect roll, and any extra size attempts.</p>
  <div class="fish">
    {#each fish.slice((page - 1) * pageSize, page * pageSize) as entry (entry.speciesId)}
      <button class="fish-card" aria-label={`Open ${entry.name} details`} aria-haspopup="dialog" data-rank={rarity || (entry.allowedRarities.length === 1 ? entry.allowedRarities[0] : '')} onclick={() => selected = entry}>
        <span class="sprite-slot"><img src={entry.asset.url} alt="" loading="lazy" width={entry.asset.width} height={entry.asset.height} /></span>
        <span class="caption"><span class="fish-name">{entry.name}</span><span class="detail">{world.biomes.find(b => b.biomeId === entry.biomeId)?.name}</span><span class="rarity">{rarity || (entry.allowedRarities.length === 10 ? 'F through UUR' : entry.allowedRarities[0] + ' only')}</span><span class="detail">About {formatOdds(castChance(entry, rarity || undefined, loadout))} casts{rarity ? ' at ' + rarity : ' across ranks'}</span></span>
        <span class="perfect-odds"><span>✦ Perfect catch</span><strong>{entry.perfect ? formatOdds(entry.perfect.chancePerCast) + ' casts' : 'Not eligible'}</strong></span>
      </button>
    {/each}
    {#if !fish.length}<p class="count">No fish match these filters.</p>{/if}
  </div>
  <PageTurner bind:page total={fish.length} {pageSize} label="Compendium pages" />
  {/if}
  </div>
</section>
<FishDetails bind:fish={selected} {loadout} asset={selected ? manifest.fish.find(asset => asset.key === selected!.spriteKey)!.url : ''} />

<style>
  .collection-focus{margin-top:16px;padding:16px;background:var(--panel-inset);border:1px solid var(--paper-edge)}
  .collection-focus label{display:flex;align-items:center;gap:12px;line-height:1.5}.collection-focus input{width:24px;flex:none}
  .collection-focus p{margin:8px 0 0;font-size:14px;line-height:1.6;color:var(--ink)}
  .rate-presets{flex:1 0 100%;min-width:0;margin:0;padding:12px 0 0;border:0;border-top:1px dashed var(--paper-edge)}
  .rate-presets legend{font-size:16px;font-weight:700;color:var(--ink);padding:0 8px 0 0}
  .rate-presets>div{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .rate-presets button{padding:12px;border:2px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink);font-family:var(--font-game);font-size:20px;overflow-wrap:anywhere}
  .rate-presets button[aria-pressed=true]{background:var(--selected-background);color:var(--selected-ink);border-color:var(--paper-edge)}
  .rate-presets button:hover:not(:disabled){border-color:var(--control-accent)}.rate-presets button:disabled{opacity:.55}
  .rate-presets small{display:block;margin-top:10px;color:var(--ink);font-size:14px}
  .loadout{display:grid;gap:4px;padding:14px 16px;border-left:3px solid var(--control-accent);background:var(--panel-inset);font-size:16px;overflow-wrap:anywhere}.loadout span{color:var(--ink);font-size:14px}
  @media(max-width:600px){.rate-presets>div{grid-template-columns:1fr;gap:8px}.rate-presets button{font-size:18px}.rate-presets legend{font-size:15px}.loadout{font-size:15px}}
  .perfect-note{margin-top:-12px}
  .perfect-odds{display:block;padding-top:8px;margin-top:10px;border-top:1px dashed var(--paper-edge);color:var(--special-ink);font-size:14px;line-height:1.5;overflow-wrap:anywhere}
  .perfect-odds span,.perfect-odds strong{display:block}
  section{position:relative;padding:36px;background:var(--panel-texture),var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 6px 0 var(--wood-dark)}
  section::before{content:'';position:absolute;left:12px;top:12px;bottom:12px;border-left:2px dashed var(--paper-edge);pointer-events:none}
  h2{font-family:var(--font-game);font-size:36px;font-weight:500;margin:0 0 22px;line-height:1.25}h2 span{font-family:var(--font-body);font-size:16px;color:var(--ink-muted);font-weight:400;margin-left:12px;white-space:nowrap}
  .filters{display:flex;gap:16px;flex-wrap:wrap;padding:16px;background:var(--panel-inset);border:1px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight)}.filters>div{display:flex;flex-direction:column;gap:6px;min-width:0}.filters .search{flex:1;min-width:180px}.filter-heading{display:flex;align-items:center;gap:4px}
  label{font-size:16px;font-weight:700;color:var(--ink)}input{width:100%;min-width:0}
  .count{font-size:16px;color:var(--ink);margin:18px 0 22px}
  .fish{display:grid;grid-template-columns:repeat(auto-fill,minmax(244px,1fr));gap:20px}
  .fish-card{--slot-edge:var(--paper-edge);margin:0;padding:16px;background:var(--paper-light);border:2px solid var(--slot-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--panel-highlight);text-align:center;transition:transform .15s,box-shadow .15s}.fish-card:hover{transform:translateY(-3px);border-color:var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 6px 0 var(--panel-highlight)}
  .sprite-slot{display:flex;align-items:center;justify-content:center;height:150px;border:2px solid var(--paper-edge);background:var(--art-background);box-shadow:inset 0 0 0 3px var(--panel-highlight)}.sprite-slot img{width:130px;height:115px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 var(--art-shadow))}
  .caption{display:block;margin-top:12px;font-size:16px;line-height:1.5;overflow-wrap:anywhere}.fish-name{display:block;font-family:var(--font-game);font-size:24px;line-height:1.1;color:var(--ink);min-height:54px}.detail{display:block;color:var(--ink);font-size:14px;margin-top:6px}.rarity{display:inline-block;background:var(--panel-inset);border:1px solid var(--paper-edge);color:var(--ink);font-family:var(--font-game);font-size:17px;padding:2px 7px;margin:8px 0 2px}
  .fish-card[data-rank=UUR]{--slot-edge:var(--special-ink)}.fish-card[data-rank=UUR] .sprite-slot{border-color:var(--special-ink);box-shadow:inset 0 0 0 3px var(--panel-highlight);background:var(--art-background)}.fish-card[data-rank=UUR] .rarity{background:var(--rank-trophy-background);color:var(--ink);border-color:var(--special-ink)}.fish-card[data-rank=UR] .rarity,.fish-card[data-rank=SSS] .rarity{background:var(--rank-high-background);color:var(--ink);border-color:var(--control-border)}
  @media(max-width:600px){section{padding:22px 18px}section::before{left:8px}h2{font-size:31px}h2 span{font-size:13px}.filters{gap:12px;padding:12px}.filters .search{flex-basis:100%;min-width:0}.filters>div{flex:1}.filters :global(.game-select){width:100%;min-width:0}.fish{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fish-card{padding:8px}.sprite-slot{height:100px}.sprite-slot img{width:90px;height:80px}.fish-name{font-size:20px}.count{font-size:13px}.detail{font-size:12px}}
</style>
