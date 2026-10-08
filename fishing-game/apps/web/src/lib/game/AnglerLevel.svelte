<script lang="ts">
  let { totalXp, levelCap }: { totalXp: bigint; levelCap: number } = $props();
  const progress = $derived.by(() => {
    let level = 1;
    let earned = totalXp;
    // Match game-rules::progression::xp_to_next and level_for_xp.
    const requiredFor = (level: number) => BigInt(80 + 25 * level + 5 * level * level);
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
  span{font-family:var(--font-game);font-size:16px;color:#f3d898}
  strong{font-family:var(--font-game);font-weight:500;font-size:38px;display:block;margin:8px 0;line-height:1.15;text-shadow:2px 2px 0 #392b23}
  small{font-size:11px;color:#ecddbc;display:block;line-height:1.4}
  .total-xp{margin-top:4px;color:#d0bd97}
  progress{display:block;width:100%;height:8px;accent-color:#b7ce79;border:1px solid #392d23;margin:8px 0;background:#392d23}
  progress::-webkit-progress-bar{background:#392d23}
  progress::-webkit-progress-value{background:#b7ce79}
  progress::-moz-progress-bar{background:#b7ce79}
  @media(max-width:600px){strong{font-size:34px}}
</style>
