<script lang="ts" generics="Props extends Record<string, unknown>">
  import type { Component } from 'svelte';

  let { active, load, componentProps }: {
    active: boolean;
    load: () => Promise<{ default: Component<Props> }>;
    componentProps: Props;
  } = $props();

  let pending = $state.raw<Promise<{ default: Component<Props> }> | null>(null);

  $effect(() => {
    if (active && !pending) {
      pending = Promise.resolve().then(load);
    }
  });
</script>

{#if pending}
  {#await pending}
    {#if active}<p role="status">Loading...</p>{/if}
  {:then module}
    {@const Panel = module.default}
    <Panel {...componentProps} />
  {:catch}
    {#if active}
      <div role="alert">
        <p>Could not load this section.</p>
        <button onclick={() => window.location.reload()}>Reload page</button>
      </div>
    {/if}
  {/await}
{/if}
