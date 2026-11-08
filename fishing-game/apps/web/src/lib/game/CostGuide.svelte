<script lang="ts">
  import { guideResourceLabel, guideResourceOwned, guideShortages, type CostGuideResult, type GuideBalances } from './tideforge-cost-guide';
  let { result, balances }: { result: CostGuideResult; balances: GuideBalances } = $props();
  const shortages = $derived(result.plan ? guideShortages(result.plan, balances) : []);
</script>

<div class="cost-guide" role="region" aria-label="Cost guide">
  <p>Guide only. The server checks the final recipe and balances in your preview.</p>
  {#if result.problem}<p>{result.problem}</p>
  {:else if result.plan}
    <p>Coins: {result.plan.coins.toLocaleString()} required · {balances.coins.toLocaleString()} owned</p>
    {#if result.plan.inputs.length}<h4>Inputs</h4><ul>{#each result.plan.inputs as resource}
      <li>{guideResourceLabel(resource)}: {resource.amount.toLocaleString()} required · {guideResourceOwned(resource, balances).toLocaleString()} owned</li>
    {/each}</ul>{/if}
    {#if result.plan.outputs.length}<h4>Outputs and leftovers</h4><ul>{#each result.plan.outputs as resource}
      <li>{resource.amount.toLocaleString()} {guideResourceLabel(resource)}</li>
    {/each}</ul>{/if}
    {#if result.plan.nextFinishBp !== undefined}<p>Next effective strength: {result.plan.nextFinishBp / 100}%</p>{/if}
    {#if shortages.length}<h4>Before previewing</h4><ul>{#each shortages as shortage}<li>{shortage}</li>{/each}</ul>{/if}
  {/if}
</div>

<style>
  .cost-guide{display:grid;gap:8px;min-width:0;padding:14px;border:1px solid var(--paper-edge);background:var(--panel-inset);overflow-wrap:anywhere}.cost-guide p,.cost-guide h4,.cost-guide ul{margin:0}.cost-guide ul{padding-left:22px}.cost-guide h4{font-family:var(--font-game)}
</style>
