<script lang="ts">
  import type { Game } from './game.svelte.ts';
  import PageTurner from './PageTurner.svelte';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let page = $state(1);
  let filter = $state('all');
  const earned = $derived(game.earnedAchievements.filter(row => row.playerId === game.player?.playerId));
  const normal = $derived(game.achievements.filter(row => !row.bonus));
  const earnedNormal = $derived(earned.filter(row => normal.some(definition => definition.achievementId === row.achievementId)));
  const selected = $derived(game.titles.find(row => row.playerId === game.player?.playerId)?.achievementId ?? 0);
  const available = $derived(game.achievements.filter(row => earned.some(badge => badge.achievementId === row.achievementId)));
  const entries = $derived(game.achievements.filter(row => filter === 'all' || (filter === 'bonus' ? row.bonus : filter === 'earned' ? earned.some(badge => badge.achievementId === row.achievementId) : !earned.some(badge => badge.achievementId === row.achievementId))));
  const marks = ['✦','♧','≈','≈','▥','⚓','◇','❧','▤','❧','≈','◈','♜','↑','✧','✶','?','♧'];
</script>

<section aria-labelledby="achievement-heading">
  <div class="heading"><div><p class="eyebrow">A LITTLE HISTORY TO WEAR</p><h2 id="achievement-heading">Your badge book</h2><p>{earnedNormal.length} / {normal.length} milestones · {earned.length - earnedNormal.length} bonus badges</p></div><div class="title-picker"><label for="angler-title">Wear a title</label><select id="angler-title" value={selected} disabled={!game.ready || game.busy || !game.player} onchange={event => game.equipTitle(Number(event.currentTarget.value))}><option value={0}>Angler · no title</option>{#each available as row}<option value={row.achievementId}>{row.title}</option>{/each}</select><small>Your title appears on your public profile.</small></div></div>
  <p class="note">Badges and titles celebrate your journey. They give no coins, XP or fishing bonuses. Earned badges stay yours when you sell fish. Fihs and the Sock are separate bonus discoveries.</p>
  <div class="filter"><label for="badge-filter">Pages to show</label><select id="badge-filter" bind:value={filter} onchange={() => page = 1}><option value="all">All badges</option><option value="earned">Earned</option><option value="locked">Still to earn</option><option value="bonus">Bonus discoveries</option></select></div>
  <div class="badges">
    {#each entries.slice((page - 1) * pageSize, page * pageSize) as row (row.achievementId)}
      {@const badge = earned.find(badge => badge.achievementId === row.achievementId)}
      {@const current = badge ? row.target : game.achievementProgress.find(progress => progress.achievementId === row.achievementId)?.current ?? 0n}
      <article class:earned={!!badge} class:bonus={row.bonus}><div class="seal" aria-hidden="true">{marks[row.achievementId - 1] ?? '✦'}</div><div><small>{row.bonus ? 'BONUS BADGE' : badge ? 'EARNED BADGE' : 'WAITING TO BE EARNED'}</small><h3>{row.name}</h3><p>{row.description}</p><p class="title">Title: {row.title}</p><progress value={Number(current)} max={Number(row.target)} aria-label={row.name + ' progress'}></progress><span class="progress-label">{badge ? '✓ Earned' : current.toLocaleString() + ' / ' + row.target.toLocaleString()}</span>{#if badge}<time datetime={new Date(Number(badge.completedAt.microsSinceUnixEpoch / 1000n)).toISOString()}>{new Date(Number(badge.completedAt.microsSinceUnixEpoch / 1000n)).toLocaleDateString()}</time>{/if}</div></article>
    {/each}
  </div>
  {#if !entries.length}<p class="empty">No badges on this page yet. Your next cast starts another story.</p>{/if}
  <PageTurner bind:page total={entries.length} {pageSize} label="Badge book pages" />
</section>

<style>
  section{background:var(--paper,#f3e7c4);color:var(--ink,#354239);padding:28px;border:3px solid #aa885e;box-shadow:inset 0 0 0 3px #fff3d2,0 5px 0 #182b25}h2,h3{font-family:var(--font-game);font-weight:400;margin:5px 0}h2{font-size:30px}h3{font-size:23px}.eyebrow{font-size:11px;letter-spacing:.15em;color:#896744}.heading{display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap}.title-picker{display:grid;gap:7px;min-width:240px;max-width:100%}label{font-size:13px;font-weight:600}select{padding:10px;border:2px solid #a48a60;background:#fff1cf;color:#344237;width:100%;max-width:100%;font:inherit}small{font-size:11px;color:#796545}.note{font-size:13px;line-height:1.65;max-width:900px}.filter{display:grid;gap:6px;max-width:240px;margin:18px 0}.badges{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}article{padding:18px;border:2px dashed #b7a27c;background:#e5d8b8;display:flex;align-items:flex-start;gap:12px;min-width:0}article.earned{border-style:solid;border-color:#78916a;background:#f8edcc}.seal{flex:none;width:42px;height:50px;display:grid;place-items:center;background:#baac8c;color:#ece2cb;font-family:var(--font-game);font-size:31px;border:2px solid #897b60;clip-path:polygon(0 0,100% 0,100% 80%,50% 100%,0 80%)}.earned .seal{background:#456c52;color:#ffe199;border-color:#c0a45f}.bonus .seal{background:#655678;color:#ebd9ff}article>div:last-child{min-width:0;flex:1}article p{font-size:12px;line-height:1.5;margin:9px 0}.title{color:#866033;font-style:italic}progress{display:block;width:100%;height:7px;accent-color:#567d55}.progress-label{font-size:11px;color:#60724e}time{display:block;font-size:10px;color:#82715a;margin-top:5px}.empty{padding:30px;text-align:center}select:focus-visible{outline:3px solid #648b74;outline-offset:3px}@media(max-width:950px){.badges{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){section{padding:17px}.badges{grid-template-columns:1fr}.heading{gap:12px}.title-picker{width:100%}article{padding:15px}}
</style>
