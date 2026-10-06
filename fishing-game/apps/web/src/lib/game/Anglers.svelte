<script lang="ts">
  import { onMount } from 'svelte';
  import type { Game } from './game.svelte.ts';
  import PageTurner from './PageTurner.svelte';
  let { game, pageSize = 12 }: { game: Game; pageSize?: number } = $props();
  let query = $state('');
  let page = $state(1);
  let recordPage = $state(1);
  let selected = $state<bigint | null>(null);
  const profiles = $derived(game.publicProfiles.filter(row => row.displayName.toLowerCase().includes(query.toLowerCase().trim()) || row.playerId.toString() === query.trim().replace(/^#/, '')).sort((a,b) => a.displayName.localeCompare(b.displayName) || (a.playerId < b.playerId ? -1 : 1)));
  const profile = $derived(game.publicProfiles.find(row => row.playerId === selected));
  const standing = $derived(game.standings.find(row => row.playerId === selected));
  const badges = $derived(game.earnedAchievements.filter(row => row.playerId === selected).sort((a,b) => a.achievementId - b.achievementId));
  const records = $derived(game.records.filter(row => row.playerId === selected));
  const title = (id: bigint) => game.achievements.find(row => row.achievementId === game.titles.find(row => row.playerId === id)?.achievementId)?.title ?? 'Angler';
  const size = (metric: string, value: bigint) => metric === 'length' ? (Number(value) / 10).toFixed(1) + ' cm' : (Number(value) / 1000).toFixed(3) + ' kg';
  function open(id: bigint) {
    selected = id;
    recordPage = 1;
    const url = new URL(window.location.href); url.searchParams.set('angler', id.toString()); url.hash = 'anglers';
    window.history.pushState(null, '', url);
  }
  onMount(() => {
    const read = () => { const value = new URL(window.location.href).searchParams.get('angler'); selected = value && /^\d{1,20}$/.test(value) ? BigInt(value) : null; };
    read(); window.addEventListener('popstate', read); window.addEventListener('hashchange', read);
    return () => { window.removeEventListener('popstate', read); window.removeEventListener('hashchange', read); };
  });
</script>

<section aria-labelledby="anglers-heading"><p class="eyebrow">STORIES FROM THE OTHER BANK</p><h2 id="anglers-heading">The angler book</h2><p class="note">Browse public discoveries, records, badges and titles.</p>
  {#if profile}
    <article class="profile" aria-label="Public angler profile"><div class="portrait" aria-hidden="true">⚓</div><div class="identity"><p class="eyebrow">{title(profile.playerId)}</p><h3>{profile.displayName}</h3><small>Angler #{profile.playerId.toString()} · Level {profile.level}</small></div><dl><div><dt>Lifetime fish</dt><dd>{profile.fishCount.toLocaleString()}</dd></div><div><dt>Ordinary discoveries</dt><dd>{profile.discoveries} / 249</dd></div><div><dt>UUR catches</dt><dd>{standing?.uurCount.toLocaleString() ?? '0'}</dd></div><div><dt>Records held</dt><dd>{standing?.recordsHeld ?? 0}</dd></div></dl>
      <div class="badge-row"><h4>Earned badges · {badges.length}</h4>{#if !badges.length}<p>No badges yet. The journey has just started.</p>{:else}{#each badges as badge}{@const definition = game.achievements.find(row => row.achievementId === badge.achievementId)}<span class:bonus={definition?.bonus} title={definition?.description}>✦ {definition?.name ?? 'Badge'}</span>{/each}{/if}</div>
      {#if records.length}<details><summary>Current species records · {records.length}</summary><ul>{#each records.slice((recordPage - 1) * pageSize, recordPage * pageSize) as record}<li>{game.species.find(row => row.speciesId === record.speciesId)?.name ?? 'Fish'} · {record.metric} · {size(record.metric, record.measurement)} · {record.rarity} rank</li>{/each}</ul><PageTurner bind:page={recordPage} total={records.length} {pageSize} label="Public profile record pages" /></details>{/if}
      <a class="profile-link" href={'?angler=' + profile.playerId + '#anglers'}>Link to this profile ↗</a>
    </article>
  {:else if selected && game.ready}<p class="empty">That angler ID is not in the book. Find another angler below.</p>{/if}
  <div class="search"><label for="angler-search">Find an angler by name or ID</label><input id="angler-search" type="search" bind:value={query} oninput={() => page = 1} placeholder="Name or #ID…" /></div>
  <div class="anglers">{#each profiles.slice((page - 1) * pageSize, page * pageSize) as row (row.playerId)}<button class:chosen={selected === row.playerId} aria-pressed={selected === row.playerId} onclick={() => open(row.playerId)}><span class="mini-seal" aria-hidden="true">⚓</span><span><strong>{row.displayName}</strong><small>{title(row.playerId)} · #{row.playerId.toString()}</small><small>Level {row.level} · {row.discoveries} discoveries</small></span><span aria-hidden="true">→</span></button>{/each}</div>
  {#if !profiles.length}<p class="empty">{game.ready ? 'No anglers match this page.' : 'The angler book is loading…'}</p>{/if}
  <PageTurner bind:page total={profiles.length} {pageSize} label="Angler book pages" />
</section>

<style>
  section{background:var(--paper,#f3e7c4);color:var(--ink,#354239);padding:28px;border:3px solid #aa885e;box-shadow:inset 0 0 0 3px #fff3d2,0 5px 0 #182b25}h2,h3,h4{font-family:var(--font-game);font-weight:400}h2{font-size:30px;margin:5px 0}h3{font-size:30px;margin:4px 0;overflow-wrap:anywhere}h4{font-size:22px;margin:8px 0}.eyebrow{font-size:11px;letter-spacing:.12em;color:#896744}.note{font-size:13px}.profile{border:2px solid #8a946a;background:#fff1ce;padding:22px;margin:20px 0;display:flex;gap:18px;align-items:center;flex-wrap:wrap}.portrait{display:grid;place-items:center;width:64px;height:76px;background:#456951;color:#ffe7a1;border:3px solid #c7ac6e;font-size:38px;flex:none}.identity{flex:1;min-width:0}.identity small{font-size:12px;color:#766246}dl{display:flex;gap:22px;flex-wrap:wrap;margin:0;width:100%;border-block:1px dashed #bba577;padding:16px 0}dt{font-size:11px;color:#806745}dd{margin:6px 0 0;font-family:var(--font-game);font-size:25px}.badge-row{width:100%;font-size:12px}.badge-row span{display:inline-block;margin:4px 5px 4px 0;padding:7px 10px;background:#e2ebca;border:1px solid #879b6b;color:#496344}.badge-row span.bonus{background:#e7dbed;color:#6b4b78;border-color:#af95ba}details{width:100%;font-size:13px}summary{cursor:pointer;font-weight:600}li{margin:8px 0}.profile-link{font-size:12px;color:#52714a}.search{display:grid;gap:6px;max-width:350px;margin:20px 0}label{font-size:12px;font-weight:600}input{background:#fff4d9;color:#344237;border:2px solid #a28a60;padding:10px;max-width:100%;font:inherit}.anglers{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.anglers button{display:flex;gap:12px;align-items:center;text-align:left;background:#e8d9b2;color:#344237;border:2px solid #ad9468;padding:15px;min-width:0;cursor:pointer}.anglers button.chosen{background:#d9e4bd;border-color:#698259}.anglers button>span:nth-child(2){flex:1;min-width:0}.mini-seal{font-size:25px;color:#7b8760}strong{display:block;overflow-wrap:anywhere;font-family:var(--font-game);font-size:22px;font-weight:400}small{display:block;font-size:11px;line-height:1.6;color:#746246}.empty{padding:24px;text-align:center}button:focus-visible,input:focus-visible{outline:3px solid #648b74;outline-offset:3px}@media(max-width:950px){.anglers{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){section{padding:17px}.anglers{grid-template-columns:1fr}.profile{padding:15px;gap:12px}dl{gap:16px}dl>div{width:calc(50% - 8px)}h3{font-size:25px}}
</style>
