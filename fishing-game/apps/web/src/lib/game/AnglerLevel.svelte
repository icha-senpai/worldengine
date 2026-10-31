<script lang="ts">
  import { xpToNextLevel } from './progression';
  let { totalXp, levelCap, rulesVersion }: { totalXp: bigint; levelCap: number; rulesVersion: number } = $props();
  const progress = $derived.by(() => {
    let level = 1;
    let earned = totalXp;
    // Match game-rules::progression::xp_to_next and level_for_xp.
    const requiredFor = (level: number) => xpToNextLevel(level, rulesVersion);
    while (level < levelCap && earned >= requiredFor(level)) {
      earned -= requiredFor(level);
      level += 1;
    }
    const required = requiredFor(level);
    const capped = level >= levelCap;
    return { level, earned, required, capped, remaining: required - earned };
  });
</script>

<span>✦ ANGLER LEVEL</span>
<strong>{progress.level}</strong>
<progress
  value={progress.capped ? 1 : Number(progress.earned)}
  max={progress.capped ? 1 : Number(progress.required)}
  aria-label={progress.capped ? 'Angler XP: max level reached' : `XP toward level ${progress.level + 1}`}
  aria-valuetext={progress.capped ? 'Max level reached' : `${progress.earned} of ${progress.required} XP; ${progress.remaining} XP remaining`}
></progress>
<small>{progress.capped ? 'Max level reached' : `${progress.remaining.toLocaleString()} XP to level ${progress.level + 1}`}</small>
<small class="total-xp">{totalXp.toLocaleString()} total XP</small>

<style>
  span{font-family:var(--font-game);font-size:19px;color:var(--site-text)}
  strong{font-family:var(--font-game);font-weight:500;font-size:42px;display:block;margin:8px 0;line-height:1.15;color:var(--site-text);text-shadow:2px 2px 0 var(--site-base)}
  small{font-size:14px;color:var(--site-text);display:block;line-height:1.4}
  .total-xp{margin-top:4px;color:var(--site-muted)}
  progress{display:block;width:100%;height:8px;accent-color:var(--gold);border:1px solid var(--site-base);margin:8px 0;background:var(--site-base)}
  progress::-webkit-progress-bar{background:var(--site-base)}
  progress::-webkit-progress-value{background:var(--gold)}
  progress::-moz-progress-bar{background:var(--gold)}
  @media(max-width:600px){strong{font-size:36px}}
</style>
