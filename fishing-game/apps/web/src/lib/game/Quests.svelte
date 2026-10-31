<script lang="ts">
  import type { Game } from './game.svelte';
  import type { QuestDefinition } from '@fishing-game/generated/types';
  import { formatLength, formatWeight } from './measurements';
  import RaidBoard from './RaidBoard.svelte';
  import SectionTabs from './SectionTabs.svelte';
  import GameSelect from './GameSelect.svelte';
  let { game }: { game: Game } = $props();
  type QuestSection = 'raids' | 'community' | 'dailies' | 'quests';
  const questTabs: { id: QuestSection; label: string }[] = [
    { id: 'raids', label: 'Raids' },
    { id: 'community', label: 'Community Expeditions' },
    { id: 'dailies', label: 'Dailies' },
    { id: 'quests', label: 'Quests' }
  ];
  let questSection = $state<QuestSection>('raids');
  const raidMode = $derived(questSection === 'community' ? 'community' : 'raids');
  function openActiveRaid() { questSection = 'raids'; }
  let choices = $state<Record<number, number>>({});
  let catches = $state<Record<number, string>>({});
  let pending = $state<{ definition: QuestDefinition; baitId: number; catchId: bigint | undefined; week: bigint; cycle: bigint } | null>(null);
  let confirmation: HTMLDialogElement;
  const storyChains = $derived([...new Set([...game.questDefinitions].sort((a, b) => a.questId - b.questId).filter(q => !q.weekly && !q.goal.startsWith('repeat_')).map(q => q.chain))]);
  const status = (id: number) => game.quests.find(row => row.questId === id);
  const baitChoice = (quest: QuestDefinition) => choices[quest.questId] ?? (quest.baitChoices.length === 1 ? quest.baitChoices[0] : 0);
  const speciesName = (id: number) => game.species.find(row => row.speciesId === id)?.name ?? 'Fish';
  const eligibleFish = (quest: QuestDefinition) => game.inventory.filter(fish => !fish.favorite && ['F', 'D', 'C'].includes(fish.rarity) && status(quest.questId)?.deliverySpeciesIds.includes(fish.speciesId));
  function rewards(quest: QuestDefinition, baitId = 0) {
    const parts = [];
    if (quest.coins) parts.push(`${quest.coins} gold`);
    if (quest.tin) parts.push(`${quest.tin} rusted tin`);
    if (quest.scrap) parts.push(`${quest.scrap} scrap`);
    if (quest.baitPacks) parts.push(`${quest.baitPacks} pack${quest.baitPacks === 1 ? '' : 's'} of ${game.baits.find(b => b.baitId === baitId)?.name ?? (quest.baitChoices.length === 1 ? game.baits.find(b => b.baitId === quest.baitChoices[0])?.name : 'your chosen bait')}`);
    if (quest.achievementId) parts.push(`“${game.achievements.find(a => a.achievementId === quest.achievementId)?.title ?? 'Story complete'}” title & badge`);
    return parts.join(' · ');
  }
  function review(definition: QuestDefinition) {
    const row = status(definition.questId);
    if (!row) return;
    pending = { definition, baitId: baitChoice(definition), catchId: definition.goal === 'delivery' && catches[definition.questId] ? BigInt(catches[definition.questId]) : undefined, week: row.week, cycle: row.cycle };
    confirmation.showModal();
  }
  async function claim() {
    if (!pending) return;
    const { definition, baitId, catchId, week, cycle } = pending;
    if (await game.claimQuest(definition.questId, baitId, catchId, week, cycle)) { confirmation.close(); pending = null; }
  }
</script>

{#snippet questChain(chain: string)}
  {@const definitions = game.questDefinitions.filter(q => q.chain === chain).sort((a, b) => a.questId - b.questId)}
  {@const weekly = definitions[0]?.weekly ?? false}
  {@const repeating = definitions[0]?.goal.startsWith('repeat_') ?? false}
  {@const completed = definitions.filter(q => status(q.questId)?.claimed).length}
  {@const available = definitions.filter(q => { const row = status(q.questId); return row?.unlocked && !row.claimed; }).length}
  {#if definitions.length}
    <details class="chain" name="quest-chains">
      <summary class="chain-summary">
        <span class="chain-title"><span class="eyebrow">{repeating ? 'REPEAT AS OFTEN AS YOU LIKE' : weekly ? 'PICK ONE EACH WEEK' : definitions[0].giver}</span><strong>{weekly ? 'Weekly contracts' : chain}</strong></span>
        <span class="summary-meta"><span class:finished={completed === definitions.length} class="status-pill">{repeating ? `${definitions.length} jobs` : weekly ? completed ? 'Reward collected' : `${definitions.length} choices` : `${completed} / ${definitions.length} complete`}</span>{#if !weekly && !repeating && !completed && !available}<small>Story locked</small>{/if}<span class="chevron" aria-hidden="true">⌄</span></span>
      </summary>
      <div class="chain-body">
        {#if repeating}<p>No claim limit, cooldown, or deadline. Jobs track together automatically; claiming resets only that job. Each new run needs fresh catches, and extra progress does not carry into the next run.</p>{/if}
        {#if weekly}<p>All three objectives track automatically. Choose one reward each week; you can change your mind until you claim. No fish handover or streaks.</p><p class="reset">Resets Monday at 00:00 UTC{#if game.questCalendar} · next reset {new Date(Number(game.questCalendar.nextWeekAt.microsSinceUnixEpoch / 1000n)).toLocaleString()}{/if}.</p>{/if}
        <div class="quest-list">
          {#each definitions as quest, index (quest.questId)}
            {@const progress = status(quest.questId)}
            {@const delivered = quest.goal === 'delivery'}
            {@const claimed = progress?.claimed ?? false}
            {@const unlocked = progress?.unlocked ?? false}
            {@const ready = !!progress && (delivered ? !!catches[quest.questId] : progress.current >= quest.target)}
            <details class="quest" class:locked={!unlocked} class:claimed name={'quest-steps-' + chain}>
              <summary class="step-summary">
                <span class="step-title"><small>{repeating ? `RUN ${(progress?.cycle ?? 0n) + 1n}` : weekly ? 'CONTRACT' : `PART ${index + 1}`}</small><strong>{quest.name}</strong></span>
                <span class="summary-meta"><span class="step-status" class:finished={claimed}>{claimed ? weekly ? 'Reward collected' : '✓ Complete' : !unlocked ? 'Locked' : delivered ? 'Delivery' : `${progress?.current ?? 0n} / ${quest.target}`}</span><span class="chevron" aria-hidden="true">⌄</span></span>
              </summary>
              <div class="quest-body">
                <p class="dialogue">“{claimed && !quest.weekly ? quest.conclusion : quest.dialogue}”</p>
                <p class="objective">{quest.objective}</p>
                {#if delivered}<p class="species">Choose: {(progress?.deliverySpeciesIds ?? []).map(speciesName).join(' · ') || 'Loading species…'}</p>{/if}
                {#if !delivered}<div class="progress"><progress value={Number(progress?.current ?? 0n)} max={Number(quest.target)} aria-label={`${quest.name} progress`}></progress><span>{progress?.current.toString() ?? '0'} / {quest.target.toString()}</span></div>{/if}
                <p class="reward"><strong>Reward:</strong> {rewards(quest, baitChoice(quest))}</p>
                {#if !claimed}
                  {#if quest.baitChoices.length > 1}<label>Bait reward<GameSelect label="Bait reward" value={baitChoice(quest)} options={[{value:0,label:"Choose your bait"},...quest.baitChoices.map(id=>({value:id,label:game.baits.find(b=>b.baitId===id)?.name??`Bait ${id}`}))]} disabled={!unlocked || game.busy} onchange={value => { choices[quest.questId] = value; }} /></label>{/if}
                  {#if delivered && unlocked}<label>Fish to hand over<GameSelect label="Fish to hand over" value={catches[quest.questId] ?? ""} options={[{value:"",label:"Choose a catch"},...eligibleFish(quest).map(fish=>({value:fish.catchId.toString(),label:"#"+fish.catchId+" · "+speciesName(fish.speciesId)+" · "+fish.rarity+" · "+formatLength(fish.lengthMm)+" · "+formatWeight(fish.weightG)}))]} disabled={game.busy} onchange={value => { catches[quest.questId] = value; }} /></label><small>Favorites are excluded. If you have no eligible fish, keep fishing in {game.biomes.find(b => b.biomeId === quest.biomeId)?.name ?? 'this water'}.</small>{/if}
                  <button class="game-button" disabled={!game.ready || game.busy || !unlocked || !ready || (quest.baitChoices.length > 0 && !baitChoice(quest))} onclick={() => review(quest)}>{!unlocked ? 'Finish the previous step' : delivered ? 'Review delivery' : ready ? 'Claim reward' : 'In progress'}</button>
                {:else}<p class="completed">✓ {quest.weekly ? 'Weekly reward collected' : 'Completed'}</p>{/if}
              </div>
            </details>
          {/each}
        </div>
      </div>
    </details>
  {/if}
{/snippet}

<section class="quest-board" aria-label="Quests and Dockside contracts">
  <SectionTabs tabs={questTabs} value={questSection} prefix="quest" label="Quest sections" onselect={value => questSection = value} />
  {#if questSection === 'raids' || questSection === 'community'}
    <div role="tabpanel" id={`quest-panel-${questSection}`} aria-labelledby={`quest-tab-${questSection}`} tabindex="0">
      <RaidBoard {game} mode={raidMode} onOpenActive={openActiveRaid} />
    </div>
  {:else if questSection === 'dailies'}
    <div class="daily-section" role="tabpanel" id="quest-panel-dailies" aria-labelledby="quest-tab-dailies" tabindex="0">
      <h2 id="dailies-heading">Dailies</h2>
      {#if game.dockside}
        <article class="daily"><div><h3>Daily supply parcel</h3><p>Missed days never erase stamps.</p><p><strong>{game.dockside.stamps}/7 stamps</strong> · +250 gold on the seventh stamp.</p></div><button class="game-button" disabled={!game.ready || game.busy || !game.dockside.available} onclick={() => game.claimDockside()}>{game.dockside.available ? `Collect ${game.dockside.nextReward} gold` : 'Collected today'}</button></article>
      {:else}
        <p class="muted">Loading your daily supply parcel…</p>
      {/if}
    </div>
  {:else}
    <div class="quest-content" role="tabpanel" id="quest-panel-quests" aria-labelledby="quest-tab-quests" tabindex="0">
      <h2>Quests</h2>
      <section class="dockside" aria-labelledby="dockside-heading">
        <h2 id="dockside-heading">Dockside Delivery</h2>
        {@render questChain('Dockside Contracts')}
        {@render questChain('Dockside Jobs')}
      </section>
      <section class="stories" aria-labelledby="stories-heading">
        <div class="board-intro"><p class="eyebrow">A FEW FAMILIAR FACES</p><h2 id="stories-heading">Stories from the dock</h2><p>Open a story, then a part to read and claim. Your progress counts automatically. Only confirmed deliveries take a fish from your tackle box.</p></div>
        {#each storyChains as chain}{@render questChain(chain)}{/each}
      </section>
      {#if !game.questDefinitions.length}<p class="empty">The quest board is being prepared.</p>{/if}
    </div>
  {/if}
</section>

<dialog bind:this={confirmation} oncancel={() => pending = null} aria-labelledby="quest-confirm-title">
  {#if pending}
    <h2 id="quest-confirm-title">{pending.catchId ? 'Hand over this fish?' : 'Claim this reward?'}</h2>
    <p>{pending.definition.name}</p>
    {#if pending.catchId}{@const fish = game.inventory.find(f => f.catchId === pending?.catchId)}<p><strong>Catch #{pending.catchId}: {fish ? speciesName(fish.speciesId) : 'Unavailable fish'}</strong>{#if fish} · {fish.rarity} · {formatLength(fish.lengthMm)} · {formatWeight(fish.weightG)}{/if}</p><p>This removes the specimen from your tackle box. Your discoveries, journal, records, and collection marks remain.</p>{/if}
    {#if pending.definition.goal.startsWith('repeat_')}<p>This completes the current run. Only this job resets; its next run needs fresh catches.</p>{/if}
    {#if pending.definition.weekly}<p>This uses your one contract reward for this week.</p>{/if}
    <p><strong>You receive:</strong> {rewards(pending.definition, pending.baitId)}</p>
    {#if game.error}<p role="alert">{game.error}</p>{/if}
    <div class="actions"><button class="game-button game-button--secondary" disabled={game.busy} onclick={() => {confirmation.close(); pending = null;}}>Cancel</button><button class="game-button" disabled={game.busy || !game.ready} onclick={claim}>{game.busy ? 'Confirming…' : pending.catchId ? 'Deliver fish' : 'Confirm reward'}</button></div>
  {/if}
</dialog>

<style>
  .quest-board{color:var(--ink);background:var(--paper);padding:clamp(1rem,3vw,2.5rem);border:3px solid var(--paper-edge);box-shadow:inset 0 0 0 3px var(--paper-light),0 5px 0 var(--site-base)}
  .board-intro{max-width:850px}.eyebrow{color:var(--ink-muted);font-weight:800;letter-spacing:.08em;font-size:.8rem}.board-intro .eyebrow{margin-bottom:.3rem}
  h2,h3,.chain-title>strong{font-family:var(--font-game);line-height:1.5}h2{font-size:clamp(1.5rem,3vw,2.125rem);font-weight:400;margin:0 0 1rem}h3{font-size:1.05rem;margin:0 0 .6rem}
  .daily{display:flex;align-items:center;justify-content:space-between;gap:1.5rem;padding:1.2rem;background:var(--paper-light);border:2px solid var(--paper-edge);border-radius:6px;margin-bottom:1rem}.daily p{margin:.4rem 0}
  .stories{margin-top:2rem;padding-top:1.5rem;border-top:2px solid var(--paper-edge)}
  .chain{margin-top:.8rem;border:2px solid var(--wood);border-radius:6px;background:var(--paper-light);overflow:hidden}
  summary{display:flex;align-items:center;justify-content:space-between;gap:1rem;cursor:pointer;list-style:none;min-height:64px}summary::-webkit-details-marker{display:none}summary::marker{content:''}summary:focus-visible{outline:3px solid var(--control-focus);outline-offset:-4px}
  .chain-summary{padding:1.1rem 1.2rem;background:var(--panel-inset)}.chain-title,.step-title{display:grid;gap:.3rem;min-width:0}.chain-title>strong{font-size:1.05rem;overflow-wrap:anywhere}.chain-title .eyebrow{letter-spacing:.03em}
  .summary-meta{display:flex;align-items:center;justify-content:flex-end;gap:.65rem;flex-shrink:0}.summary-meta>small{font-size:.8rem}.status-pill{font-size:.85rem;font-weight:800;border:1px solid var(--paper-edge);background:var(--paper-light);border-radius:20px;padding:.2rem .65rem;white-space:nowrap}
  .chevron{font-size:1.5rem;line-height:1;flex:none;transition:transform .15s}.chain[open]>.chain-summary .chevron,.quest[open]>.step-summary .chevron{transform:rotate(180deg)}
  .chain-body{padding:1rem 1.2rem}.chain-body>p:first-child{margin-top:0}.quest-list{display:grid;grid-template-columns:minmax(0,1fr);gap:.6rem}
  .quest{background:var(--paper-light);border:1px solid var(--paper-edge);border-radius:5px;overflow:hidden}.quest.locked{border-style:dashed}.quest.claimed{border-color:var(--success-ink)}
  .step-summary{padding:.8rem 1rem}.step-title small{font-size:.7rem;color:var(--ink-muted);font-weight:800;letter-spacing:.06em}.step-title strong{font-size:1.05rem;overflow-wrap:anywhere}.step-status{font-size:.85rem;font-weight:800;white-space:nowrap}.finished{color:var(--success-ink)}
  .quest-body{display:grid;grid-template-columns:minmax(0,1fr);min-width:0;gap:1rem;padding:0 1rem 1rem;border-top:1px solid var(--paper-edge)}.quest-body p{margin:0}.quest-body .dialogue{margin-top:1rem;font-style:italic}.objective{font-weight:800}.reward{padding-top:.8rem;border-top:1px solid var(--paper-edge)}
  .progress{display:flex;align-items:center;gap:.75rem}.progress progress{flex:1;min-width:0;accent-color:var(--progress-fill)}.progress span{white-space:nowrap}label{display:grid;gap:.4rem;font-weight:800}.quest-board :global(.game-select){width:100%;min-width:0}
  .quest-body button{min-width:0;max-width:100%;overflow-wrap:anywhere}
  .completed{font-weight:800;color:var(--success-ink)}.reset,small,.species{font-size:.9rem}
  .actions{display:flex;gap:.8rem;justify-content:flex-end;flex-wrap:wrap}dialog{width:min(620px,calc(100vw - 2rem));max-height:85vh;overflow:auto;padding:1.8rem;color:var(--ink);background:var(--paper-light);border:4px solid var(--wood-dark);border-radius:8px}dialog::backdrop{background:var(--dialog-backdrop)}
  @media(max-width:600px){.daily{align-items:stretch;flex-direction:column}.chain-summary{padding:1rem;align-items:flex-start;flex-wrap:wrap;gap:.7rem}.chain-title{flex:1 1 100%}.chain-summary>.summary-meta{width:100%;justify-content:space-between}.chain-title>strong{font-size:1rem}.chain-body{padding:.75rem}.step-summary{padding:.8rem;gap:.65rem}.step-summary>.summary-meta{gap:.4rem}.step-status{font-size:.75rem}.step-title strong{font-size:1rem}.quest-body{padding:0 .8rem .8rem}}
  @media(prefers-reduced-motion:reduce){.chevron{transition:none}}
</style>
