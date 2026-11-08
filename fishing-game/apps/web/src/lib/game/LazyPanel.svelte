<script lang="ts" generics="Props extends Record<string, unknown>">
  import type { Component } from 'svelte';
  import '../../../../../assets/site-errors.css';
  import { safeRetryPath } from './site-errors';

  let { active, load, componentProps }: {
    active: boolean;
    load: () => Promise<{ default: Component<Props> }>;
    componentProps: Props;
  } = $props();

  let pending = $state.raw<Promise<{ default: Component<Props> }> | null>(null);
  let loading = $state(false);
  let failures = $state(0);

  function startLoad() {
    if (loading) return;
    loading = true;
    pending = Promise.resolve().then(load).then(module => {
      loading = false;
      return module;
    }, error => {
      loading = false;
      failures++;
      throw error;
    });
  }
  $effect(() => {
    if (active && !pending) {
      startLoad();
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
      <div class="site-error-card site-error-compact" role="alert">
        <img class="site-error-compact-art" src="/fish/koi.png" alt="" width="56" height="56" />
        <h2>We hit a snag loading this section.</h2>
        <p>You can retry this section without leaving your page.</p>
        <button onclick={startLoad}>Retry section</button>
        {#if failures > 1}<p>If the section still cannot load, reloading the page may be necessary.</p><button onclick={() => window.location.assign(safeRetryPath(window.location.pathname) + window.location.hash)}>Reload page</button>{/if}
      </div>
    {/if}
  {/await}
{/if}
