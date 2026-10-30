<script lang="ts" generics="Id extends string">
  let {
    tabs,
    value,
    prefix,
    label,
    onselect
  }: {
    tabs: { id: Id; label: string }[];
    value: Id;
    prefix: string;
    label: string;
    onselect: (id: Id) => void;
  } = $props();

  function tabKey(event: KeyboardEvent, id: Id) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const index = tabs.findIndex(tab => tab.id === id);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
      : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    onselect(tabs[next].id);
    document.getElementById(`${prefix}-tab-${tabs[next].id}`)?.focus();
  }
</script>

<div class="section-tabs" role="tablist" aria-label={label}>
  {#each tabs as tab}
    <button
      id={`${prefix}-tab-${tab.id}`}
      role="tab"
      aria-selected={value === tab.id}
      aria-controls={`${prefix}-panel-${tab.id}`}
      tabindex={value === tab.id ? 0 : -1}
      onclick={() => onselect(tab.id)}
      onkeydown={event => tabKey(event, tab.id)}
    >{tab.label}</button>
  {/each}
</div>

<style>
  .section-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 22px;padding:10px;background:var(--wood-dark);border:2px solid var(--paper-edge);box-shadow:0 4px 0 var(--site-base);min-width:0;max-width:100%;box-sizing:border-box}
  .section-tabs button{min-width:0;max-width:100%;padding:10px 16px;border:2px solid var(--paper-edge);background:var(--wood);color:var(--site-text);box-shadow:none;font-family:var(--font-game);font-size:21px;font-weight:500;line-height:1.3;cursor:pointer;transition:transform .12s,background .12s;white-space:normal;overflow-wrap:break-word;word-break:normal}
  .section-tabs button:hover:not(:disabled):not([aria-selected=true]){background:var(--nav-hover);transform:translateY(-1px)}
  .section-tabs button:active:not(:disabled){transform:translateY(2px)}
  .section-tabs button[aria-selected=true]{background:var(--paper);color:var(--ink);border-color:var(--paper-edge);box-shadow:inset 0 -3px 0 var(--panel-highlight)}
  .section-tabs button:disabled{opacity:.45;filter:none;box-shadow:none}
  .section-tabs button:focus-visible{outline:3px solid var(--control-focus);outline-offset:2px}
  @media(max-width:600px){.section-tabs{gap:4px;padding:8px}.section-tabs button{flex:1 1 auto;min-height:44px;padding:10px 8px;font-size:17px}}
</style>
