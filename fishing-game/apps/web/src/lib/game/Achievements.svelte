<script lang="ts">
  import type { Game } from './game.svelte.ts';
  import manifest from '../../../../../assets/manifest.json';
  import GameSelect from './GameSelect.svelte';
  import PageTurner from './PageTurner.svelte';
  import BadgeDetails from './BadgeDetails.svelte';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let page = $state(1);
  let filter = $state('all');
  let scope = $state('all');
  let biome = $state('all');
  let rank = $state('all');
  let enlargedBadge = $state<Game['achievements'][number] | null>(null);
  const collections = $derived(new Map(game.achievementCollections.map(row => [row.achievementId, row])));
  const badgeAssets = new Map<string, string>((manifest.achievements as {key: string; url: string}[]).map(asset => [asset.key, asset.url]));
  const earned = $derived(game.earnedAchievements.filter(row => row.playerId === game.player?.playerId));
  const normal = $derived(game.achievements.filter(row => !row.bonus));
  const earnedNormal = $derived(earned.filter(row => normal.some(definition => definition.achievementId === row.achievementId)));
  const selected = $derived(game.titles.find(row => row.playerId === game.player?.playerId)?.achievementId ?? 0);
  const available = $derived(game.achievements.filter(row => earned.some(badge => badge.achievementId === row.achievementId)));
  const titleOptions = $derived([
    { value: 0, label: 'Angler · no title' },
    ...available.map(row => ({ value: row.achievementId, label: row.title }))
  ]);
  const filterOptions = [
    { value: 'all', label: 'All badges' }, { value: 'earned', label: 'Earned' },
    { value: 'locked', label: 'Still to earn' }, { value: 'bonus', label: 'Bonus discoveries' }
  ];
  const scopeOptions = [
    { value: 'all', label: 'All collections and milestones' }, { value: 'milestones', label: 'Milestones and bonuses' },
    { value: 'biomes', label: 'Biome collections' }, { value: 'world', label: 'All biomes collections' }
  ];
  const biomeOptions = $derived([
    { value: 'all', label: 'Any waters' }, { value: '0', label: 'All biomes' },
    ...game.biomes.map(row => ({ value: String(row.biomeId), label: row.name }))
  ]);
  const rankOptions = $derived([
    { value: 'all', label: 'Any collection' }, { value: 'any', label: 'Species at any rank' },
    ...game.rarities.map(row => ({ value: row.tier, label: `${row.tier} rank` })),
    { value: 'every', label: 'Every rank' }
  ]);
  const entries = $derived(game.achievements.filter(row => {
    const badge = earned.some(badge => badge.achievementId === row.achievementId);
    if (filter === 'bonus' && !row.bonus || filter === 'earned' && !badge || filter === 'locked' && badge) return false;
    const collection = collections.get(row.achievementId);
    if (scope === 'milestones' && collection || scope === 'biomes' && (!collection || collection.biomeId === 0) || scope === 'world' && (!collection || collection.biomeId !== 0)) return false;
    if (biome !== 'all' && collection?.biomeId !== Number(biome)) return false;
    if (rank !== 'all' && (!collection || (rank === 'any' ? collection.allRanks || !!collection.rarity : rank === 'every' ? !collection.allRanks : collection.rarity !== rank))) return false;
    return true;
  }));
  const marks = ['✦','♧','≈','≈','▥','⚓','◇','❧','▤','❧','≈','◈','♜','↑','✧','✶','?','♧'];
</script>

<section aria-labelledby="achievement-heading">
  <div class="heading"><div><p class="eyebrow">A LITTLE HISTORY TO WEAR</p><h2 id="achievement-heading">Your badge book</h2><p>{earnedNormal.length} / {normal.length} milestones · {earned.length - earnedNormal.length} bonus badges</p></div><div class="title-picker"><label for="angler-title">Wear a title</label><GameSelect id="angler-title" label="Wear a title" value={selected} options={titleOptions} disabled={!game.ready || game.busy || !game.player} onchange={value => game.equipTitle(value)} /><small>Your title appears on your public profile.</small></div></div>
  <p class="note">Badges and titles celebrate your journey. They give no coins, XP or fishing bonuses. Earned badges stay yours when you sell fish. Fihs and the Sock are separate bonus discoveries.</p>
  <p class="note">Collections count every species you have caught over your lifetime. Rank collections need each species at that rank; every-rank badges need each species at all ten ranks.</p>
  <div class="filters">
    <div><label for="badge-filter">Pages to show</label><GameSelect id="badge-filter" label="Pages to show" value={filter} options={filterOptions} onchange={value => {filter = value; page = 1;}} /></div>
    <div><label for="badge-scope">Collection</label><GameSelect id="badge-scope" label="Collection" value={scope} options={scopeOptions} onchange={value => {scope = value; page = 1;}} /></div>
    <div><label for="badge-biome">Waters</label><GameSelect id="badge-biome" label="Waters" value={biome} options={biomeOptions} onchange={value => {biome = value; page = 1;}} /></div>
    <div><label for="badge-rank">Rank collection</label><GameSelect id="badge-rank" label="Rank collection" value={rank} options={rankOptions} onchange={value => {rank = value; page = 1;}} /></div>
  </div>
  <div class="badges">
    {#each entries.slice((page - 1) * pageSize, page * pageSize) as row (row.achievementId)}
      {@const badge = earned.find(badge => badge.achievementId === row.achievementId)}
      {@const collection = collections.get(row.achievementId)}
      {@const artwork = badgeAssets.get('badge-' + row.achievementId)}
      {@const current = badge ? row.target : game.achievementProgress.find(progress => progress.achievementId === row.achievementId)?.current ?? 0n}
      <article class:earned={!!badge} class:bonus={row.bonus}>{#if artwork}<button type="button" class="seal illustrated" aria-label={'Enlarge ' + row.name + ' badge'} onclick={() => enlargedBadge = row}><img src={artwork} alt="" width="80" height="80" loading="lazy" decoding="async" /></button>{:else}<div class="seal" aria-hidden="true">{marks[row.achievementId - 1] ?? (collection?.allRanks ? '♜' : '❧')}</div>{/if}<div><small>{row.bonus ? 'BONUS BADGE' : badge ? 'EARNED BADGE' : 'WAITING TO BE EARNED'}</small>{#if collection}<p class="category">{collection.biomeId === 0 ? 'All biomes' : game.biomes.find(biome => biome.biomeId === collection.biomeId)?.name} · {collection.allRanks ? 'Every rank' : collection.rarity || 'Any rank'}</p>{/if}<h3>{row.name}</h3><p>{row.description}</p><p class="title">Title: {row.title}</p><progress value={Number(current)} max={Number(row.target)} aria-label={row.name + ' progress'}></progress><span class="progress-label">{badge ? '✓ Earned' : current.toLocaleString() + ' / ' + row.target.toLocaleString() + (collection ? collection.allRanks ? ' species/rank pairs' : ' species' : '')}</span>{#if badge}<time datetime={new Date(Number(badge.completedAt.microsSinceUnixEpoch / 1000n)).toISOString()}>{new Date(Number(badge.completedAt.microsSinceUnixEpoch / 1000n)).toLocaleDateString()}</time>{/if}</div></article>
    {/each}
  </div>
  {#if !entries.length}<p class="empty">No badges on this page yet. Your next cast starts another story.</p>{/if}
  <PageTurner bind:page total={entries.length} {pageSize} label="Badge book pages" />
</section>

<BadgeDetails bind:badge={enlargedBadge} asset={badgeAssets.get('badge-' + enlargedBadge?.achievementId) ?? ''} earned={earned.some(row => row.achievementId === enlargedBadge?.achievementId)} />

<style>
  section{background:var(--paper);color:var(--ink);padding:32px;border:3px solid var(--paper-edge);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 5px 0 var(--site-base)}h2,h3{font-family:var(--font-game);font-weight:400;margin:5px 0}h2{font-size:34px}h3{font-size:27px;overflow-wrap:anywhere}.eyebrow{font-size:14px;letter-spacing:.15em;color:var(--ink-muted)}.heading{display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap}.title-picker{display:grid;gap:7px;min-width:240px;max-width:100%}label{font-size:16px;font-weight:600}section :global(.game-select){width:100%;max-width:100%}small{font-size:14px;color:var(--ink-muted)}.note{font-size:16px;line-height:1.65;max-width:900px}.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.filters>div{display:grid;gap:6px}.category{color:var(--success-ink);font-weight:600}.badges{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}article{padding:18px;border:2px dashed var(--paper-edge);background:var(--panel-inset);display:flex;align-items:flex-start;gap:12px;min-width:0}article.earned{border-style:solid;border-color:var(--success-ink);background:var(--paper-light)}.seal{flex:none;width:42px;height:50px;display:grid;place-items:center;background:var(--panel-inset);color:var(--ink-muted);font-family:var(--font-game);font-size:35px;border:2px solid var(--paper-edge);clip-path:polygon(0 0,100% 0,100% 80%,50% 100%,0 80%)}.earned .seal{background:var(--success-ink);color:var(--paper-light);border-color:var(--paper-edge)}.seal.illustrated{cursor:zoom-in;appearance:none;width:80px;height:80px;padding:0;background:transparent;border:0;clip-path:none}.seal.illustrated img{display:block;width:100%;height:100%;object-fit:contain;image-rendering:pixelated}article:not(.earned) .illustrated img{filter:grayscale(1);opacity:.5}.bonus .seal:not(.illustrated){background:var(--special-ink);color:var(--paper-light)}article>div:last-child{min-width:0;flex:1}article p{font-size:16px;line-height:1.5;margin:9px 0}.title{color:var(--ink);font-style:italic}progress{display:block;width:100%;height:7px;accent-color:var(--progress-fill)}.progress-label{font-size:14px;color:var(--success-ink)}time{display:block;font-size:14px;color:var(--ink-muted);margin-top:5px}.empty{padding:30px;text-align:center}.seal.illustrated:focus-visible{outline:3px solid var(--control-focus);outline-offset:4px}@media(max-width:950px){.filters{grid-template-columns:repeat(2,minmax(0,1fr))}.badges{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.filters{grid-template-columns:1fr}section{padding:17px}.badges{grid-template-columns:1fr}.heading{gap:12px}.title-picker{width:100%}article{padding:15px}}
</style>
