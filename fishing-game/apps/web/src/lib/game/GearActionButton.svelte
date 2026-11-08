<script lang="ts">
  import { tooltip } from './tooltip';

  let { label, message, disabled, onclick, controls }: {
    label: 'Upgrade' | 'Reinforce'; message: string; disabled: boolean;
    onclick: () => void | Promise<void>; controls?: string;
  } = $props();

  function keyboardWhenDisabled(node: HTMLElement, value: boolean) {
    function update(disabled: boolean) {
      if (disabled) node.tabIndex = 0;
      else node.removeAttribute('tabindex');
    }
    update(value);
    return { update };
  }
</script>

<span role={disabled ? 'group' : undefined} aria-label={disabled ? `${label} details` : undefined}
  use:keyboardWhenDisabled={disabled} use:tooltip={disabled ? message : undefined}>
  <button type="button" {disabled} {onclick} aria-haspopup="dialog" aria-controls={controls}
    use:tooltip={!disabled ? message : undefined}>{label}</button>
</span>

<style>
  span{display:inline-flex;max-width:100%;margin-top:14px}
  span:focus-visible,button:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}
  button{font-family:var(--font-game);font-size:20px;background:var(--selected-background);color:var(--selected-ink);border:2px solid var(--paper-edge);padding:9px 12px;cursor:pointer;max-width:100%;white-space:normal;overflow-wrap:anywhere}
  button:disabled{opacity:.5;cursor:default}
  @media(max-width:700px){span,button{width:100%}}
</style>
