<script lang="ts">
  import Dashboard from '#lib/game/Dashboard.svelte';
  import Compendium from '#lib/game/Compendium.svelte';
</script>

<Dashboard initialView="compendium">
  {#snippet compendiumPanel(game, active)}
    <Compendium {game} {active} />
  {/snippet}
</Dashboard>
