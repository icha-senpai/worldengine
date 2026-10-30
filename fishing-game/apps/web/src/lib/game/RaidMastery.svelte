<script lang="ts">
  import type { Game } from './game.svelte.ts';
  import CastCooldown from './CastCooldown.svelte';
  import RaidArt from './RaidArt.svelte';
  import { clearedGradeCount, gradeCleared, gradeSelectable } from './raid-progress';

  let { game, speciesId }: { game: Game; speciesId: number } = $props();
  const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  const badge = $derived(game.raidBadges.find(row =>
    row.playerId === game.player?.playerId && row.speciesId === speciesId));
  const attempt = $derived(game.raidAttempts.find(row =>
    row.speciesId === speciesId && ['prepared', 'deployed', 'running'].includes(row.status)));
  const species = $derived(game.species.find(row => row.speciesId === speciesId));
  const special = $derived(!!species && !species.countsForOrdinaryCollectionCompletion);
  const clearCount = $derived(clearedGradeCount(badge?.clearedGrades));
  const discovered = $derived((game.collection.find(row => row.speciesId === speciesId)?.count ?? 0n) > 0n);
  const specialCleared = $derived((badge?.specialCompletions ?? 0n) > 0n);
  const specialState = $derived(attempt ? 'active' : specialCleared ? 'cleared' : discovered ? 'available' : 'locked');
  const activeDeadline = (row: NonNullable<typeof attempt>) =>
    row.status === 'prepared' ? row.deploymentDeadline
      : row.status === 'deployed' ? row.startsAt
        : row.endsAt;
</script>

<section class="raid-mastery" aria-label={`${species?.name ?? 'Fish'} raid mastery`}>
  {#if !game.raidsSupported}
    <p class="waiting">Raid seals await the module update.</p>
  {:else if special}
    <div class="special-seal" class:cleared={specialCleared} class:active={specialState === 'active'} class:available={specialState === 'available'} class:locked={specialState === 'locked'} aria-label={`${specialState} special raid seal`}>
      <span aria-hidden="true">{specialCleared ? '✦' : discovered ? '◇' : '◆'}</span><strong>{specialState === 'locked' ? 'Undiscovered' : specialState === 'available' ? 'Available' : specialState === 'active' ? 'Active' : 'Special raid seal cleared'}</strong>
      <small>{badge?.specialCompletions ?? 0n} completed encounters</small>
      {#if attempt}<small>{attempt.status} · <CastCooldown nextCastAt={activeDeadline(attempt).microsSinceUnixEpoch} formatMinutes={true} zeroLabel="Settling…" /></small>{/if}
    </div>
    <p>One exceptional encounter; no ten-grade material ladder.</p>
  {:else}
    <p class="seal-summary">{clearCount} / 10 grade seals</p>
    <div class="seals" aria-label="Raid seals by grade">
      {#each ranks as rank, grade}
        {@const cleared = gradeCleared(badge?.clearedGrades, grade)}
        {@const active = attempt?.grade === grade}
        {@const available = discovered && !cleared && gradeSelectable(badge?.clearedGrades, grade)}
        <span data-rank={rank} class:cleared class:available class:locked={!cleared && !available}
          class:uur={discovered && grade === 9 && clearCount === 10}
          class:active title={`${rank} ${cleared ? 'raid clear' : available ? 'available' : 'locked'}${active ? ` · ${attempt?.status}` : ''}`}
          aria-label={`${rank}: ${cleared ? 'cleared' : available ? 'available' : 'locked'}${active ? `, ${attempt?.status}` : ''}`}>
          <RaidArt kind="seal" grade={grade} size={28} />
          <b>{rank}</b>
          {#if cleared}<i class="clear-mark" aria-label="Cleared">✓</i>{/if}
          {#if active}<small>{attempt?.status} · <CastCooldown nextCastAt={activeDeadline(attempt).microsSinceUnixEpoch} formatMinutes={true} zeroLabel="Settling…" /></small>{/if}
        </span>
      {/each}
    </div>
  {/if}
</section>

<style>
  .raid-mastery{display:grid;gap:7px;margin-top:12px;padding:10px;background:var(--panel-inset);border:1px solid var(--paper-edge)}
  .raid-mastery p,.raid-mastery small{font-size:12px;line-height:1.45;color:var(--ink-muted);margin:0}.seal-summary{font-family:var(--font-game)}
  .seals{display:grid;grid-template-columns:repeat(5,minmax(30px,1fr));gap:4px;width:100%;max-width:280px;min-width:0}.seals span{position:relative;display:grid;grid-template-rows:28px auto;place-items:center;align-content:center;min-height:48px;background:var(--panel-inset);color:var(--ink-muted);text-align:center}.seals b,.seals i,.seals small{position:relative;z-index:1}.seals b{font-size:11px}.seals small{font-size:8px;line-height:1.1}.seals .clear-mark{position:absolute;right:3px;top:1px;font-size:10px;font-style:normal}
  .seals .locked{opacity:.38;filter:grayscale(1)}.seals .available{color:var(--ink);outline:1px solid var(--control-focus);filter:brightness(.86)}.seals .cleared{background:var(--success-ink);color:var(--paper-light);filter:none}
  .seals [data-rank="UR"],.seals [data-rank="SSS"]{background:var(--rank-high-background);color:var(--ink)}.seals [data-rank="UUR"]{background:var(--rank-trophy-background);color:var(--ink)}
  .seals .uur{outline:2px solid var(--special-ink);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 0 0 1px var(--special-ink)}
  .seals .active{outline:2px solid var(--control-focus);outline-offset:1px;filter:brightness(1.15)}
  .special-seal{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;align-items:center}.special-seal span{grid-row:span 2;font-size:24px}.special-seal strong{font-family:var(--font-game);font-weight:400}.special-seal small{grid-column:2}.special-seal.cleared{color:var(--success-ink)}.special-seal.active{outline:2px solid var(--control-focus)}.special-seal.available{outline:1px solid var(--paper-edge)}.special-seal.locked{opacity:.45;filter:grayscale(1)}.waiting{padding:6px}
  @media(max-width:480px){.seals{grid-template-columns:repeat(5,minmax(26px,1fr));gap:3px}.seals span{min-height:36px}.seals b{font-size:9px}}
</style>
