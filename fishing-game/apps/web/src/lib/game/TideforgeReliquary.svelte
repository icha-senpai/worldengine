<script lang="ts">
  import type { Game } from './game.svelte';
  import type { TideforgeIntent } from './tideforge-intents';
  import { canConsume, gearLabel, signatureDescription, signatureName, signatures } from './tideforge-ui';
  import GameSelect from './GameSelect.svelte';
  let { game, onchange, onpreview }: { game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void } = $props();
  let target = $state('');
  const known = $derived(new Set(game.tideforgeLibrary.map(row => row.signatureId)));
  const candidates = $derived(game.gearInstances.filter(item => {
    const signature = game.tideforgeGear.find(row => row.instanceId === item.instanceId)?.nativeSignature;
    return !!signature && !known.has(signature) && canConsume(game, item);
  }));
  const chosen = $derived(candidates.find(row => row.instanceId.toString() === target));
  function options(kinds: string[]) {
    return [{ value: '', label: 'None' }, ...signatures.filter(row => kinds.includes(row.kind) && known.has(row.id))
      .map(row => ({ value: row.id, label: row.name }))];
  }
  async function select(field: 'rodSignature' | 'haulSignature' | 'watersSignature', value: string) {
    onchange();
    const current = game.tideforgeLoadout;
    await game.selectTideforgeSignatures({
      rodSignature: field === 'rodSignature' ? value || undefined : current?.rodSignature,
      haulSignature: field === 'haulSignature' ? value || undefined : current?.haulSignature,
      watersSignature: field === 'watersSignature' ? value || undefined : current?.watersSignature,
    });
  }
</script>
<div class="tideforge-reliquary">
  <p>Learned signatures can be selected without using up another item. Boat or net accepts signatures for either kind.</p>
  <div class="fields">
    <label>Rod <GameSelect label="Rod signature" value={game.tideforgeLoadout?.rodSignature ?? ''} options={options(['rod'])} onchange={value => select('rodSignature', value)} /></label>
    <label>Boat or net <GameSelect label="Boat or net signature" value={game.tideforgeLoadout?.haulSignature ?? ''} options={options(['boat', 'net'])} onchange={value => select('haulSignature', value)} /></label>
    <label>Waters <GameSelect label="Waters signature" value={game.tideforgeLoadout?.watersSignature ?? ''} options={options(['waters'])} onchange={value => select('watersSignature', value)} /></label>
  </div>
  {#if !known.size}<p>No signatures learned yet.</p>{:else}<div>{#each [...known] as signature}<p><strong>{signatureName(signature)}</strong> · {signatureDescription(signature)}</p>{/each}</div>{/if}
  <article>
    <h3>Extract a signature</h3>
    <p>Extraction permanently removes the selected item. Protected, favorite, equipped and active-trip items are unavailable. Already learned signatures cannot be extracted again.</p>
    <label>Gear to extract from <GameSelect label="Gear to extract from" value={target} options={[{ value: '', label: 'Choose gear' }, ...candidates.map(item => ({ value: item.instanceId.toString(), label: `${gearLabel(game, item)} · ${signatureName(game.tideforgeGear.find(row => row.instanceId === item.instanceId)!.nativeSignature!)}` }))]} onchange={value => { target = value; onchange(); }} /></label>
    {#if !candidates.length}<p>No new signatures are available to extract.</p>{/if}
    <button class="tideforge-action" disabled={!chosen || !game.ready || game.busy || !!game.pendingTideforgeCommit} onclick={() => { if (chosen) onpreview({ action: 'extract_signature', instance_id: chosen.instanceId.toString() }); }}>Preview extraction</button>
  </article>
</div>
<style>
  .tideforge-reliquary{display:grid;gap:14px}.fields{display:flex;gap:12px;flex-wrap:wrap}.fields label,article label{display:grid;gap:6px;min-width:160px}article{display:grid;gap:12px;padding:18px;border:2px solid var(--paper-edge);background:var(--panel-inset)}h3{font-family:var(--font-game);margin:0}p{margin:0}button{justify-self:start;white-space:normal;max-width:100%}
</style>
