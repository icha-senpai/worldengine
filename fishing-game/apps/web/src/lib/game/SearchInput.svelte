<script lang="ts">
  let generatedId = $props.id();
  let {
    value,
    oninput,
    label,
    id,
    placeholder = '',
    maxlength,
    disabled = false
  }: {
    value: string;
    oninput: (value: string) => void;
    label: string;
    id?: string;
    placeholder?: string;
    maxlength?: number;
    disabled?: boolean;
  } = $props();
  const controlId = $derived(id ?? generatedId);
  let input: HTMLInputElement;

  function clear() {
    if (disabled || !value) return;
    oninput('');
    input?.focus();
  }
</script>

<span class="game-search">
  <input
    bind:this={input}
    id={controlId}
    type="search"
    {placeholder}
    {maxlength}
    {disabled}
    value={value}
    aria-label={label}
    oninput={event => oninput(event.currentTarget.value)}
  />
  {#if value}<button class="game-search-clear" type="button" aria-label={`Clear ${label}`} {disabled} onclick={clear}><span aria-hidden="true"></span></button>{/if}
</span>

<style>
  .game-search{position:relative;display:block;width:100%;min-width:0;max-width:100%}
  .game-search input{width:100%;min-width:0;padding-inline-end:2.75rem}
  .game-search input::-webkit-search-cancel-button{appearance:none;display:none}
  .game-search input::-ms-clear{display:none;width:0;height:0}
  .game-search-clear{position:absolute;right:.55rem;top:50%;display:grid;place-items:center;width:30px;height:30px;transform:translateY(-50%);padding:0;border:0;background:transparent;color:var(--ink);box-shadow:none}
  .game-search-clear span{display:block;width:16px;height:16px;background-color:var(--ink);-webkit-mask:var(--search-clear-icon) center/contain no-repeat;mask:var(--search-clear-icon) center/contain no-repeat}
  .game-search-clear:hover:not(:disabled){background:var(--panel-inset);transform:translateY(-50%)}
  .game-search-clear:focus-visible{outline:3px solid var(--control-focus);outline-offset:2px}
</style>
