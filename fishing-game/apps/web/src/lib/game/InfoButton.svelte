<script lang="ts">
  import { Info } from '@lucide/svelte';
  import { tooltip } from './tooltip';

  let { label, message }: { label: string; message: string } = $props();
</script>

<button type="button" class="info-button" aria-label={label} use:tooltip={message}>
  <Info size={18} strokeWidth={1.8} aria-hidden="true" focusable="false" />
</button>

<style>
  .info-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    vertical-align: middle;
    flex: none;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    box-shadow: none;
    background: transparent;
    border-radius: 6px;
    color: var(--ink-muted);
    cursor: help;
  }
  .info-button:hover { color: var(--ink); background: var(--panel-inset); }
  .info-button:focus-visible { outline: 3px solid var(--site-focus); outline-offset: 2px; }
  @media (pointer: coarse) {
    .info-button { width: 44px; height: 44px; }
  }
</style>
