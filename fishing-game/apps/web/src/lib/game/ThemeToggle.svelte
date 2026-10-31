<script lang="ts">
  import { onMount } from 'svelte';
  import GameSelect from './GameSelect.svelte';
  import { tooltip } from './tooltip';

  const storageKey = 'fishbound:theme';
  const paletteKey = 'fishbound:palette';
  const palettes = [
    { id: 'dockside', name: 'Dockside' },
    { id: 'ocean', name: 'Ocean' },
    { id: 'rose', name: 'Rose' },
    { id: 'cosmic', name: 'Cosmic' },
    { id: 'synthwave', name: 'Neon Synthwave' }
  ];
  let dark = $state(false);
  let palette = $state('dockside');
  let ready = $state(false);
  const paletteOptions = palettes.map(option => ({ value: option.id, label: option.name }));

  function apply(value: string | null) {
    dark = value === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  }

  function applyPalette(value: string | null) {
    palette = palettes.find((option) => option.id === value)?.id ?? 'dockside';
    document.documentElement.dataset.palette = palette;
  }

  onMount(() => {
    dark = document.documentElement.dataset.theme === 'dark';
    applyPalette(document.documentElement.dataset.palette ?? null);
    ready = true;
    const sync = (event: StorageEvent) => {
      if (event.key === storageKey) apply(event.newValue);
      if (event.key === paletteKey) applyPalette(event.newValue);
      if (event.key === null) { apply(null); applyPalette(null); }
    };
    window.addEventListener('storage', sync);
    return () => window.removeEventListener('storage', sync);
  });

  function toggle() {
    apply(dark ? 'light' : 'dark');
    try { localStorage.setItem(storageKey, dark ? 'dark' : 'light'); }
    catch { /* The theme still works when browser storage is unavailable. */ }
  }

  function choosePalette(value: string) {
    applyPalette(value);
    try { localStorage.setItem(paletteKey, palette); }
    catch { /* Keep the selection usable without browser storage. */ }
  }
</script>

<div class="appearance-controls">
  <label for="footer-theme">Theme</label>
  <GameSelect id="footer-theme" label="Theme" value={palette} options={paletteOptions} disabled={!ready} onchange={choosePalette} />
  <button
    class="theme-toggle"
    type="button"
    disabled={!ready}
    aria-label="Dark mode"
    aria-pressed={dark}
    use:tooltip={dark ? 'Switch to light mode' : 'Switch to dark mode'}
    onclick={toggle}
  >
    <span class="thumb" aria-hidden="true"></span>
    <span class="icon sun" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 1v3m0 16v3M1 12h3m16 0h3M4 4l2 2m12 12 2 2M4 20l2-2M18 6l2-2" />
      </svg>
    </span>
    <span class="icon moon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z" />
      </svg>
    </span>
  </button>
</div>

<style>
  .appearance-controls{display:flex;align-items:center;flex-wrap:wrap;gap:10px;max-width:100%;min-width:0}
  label{color:var(--site-text);font-weight:700}
  .appearance-controls :global(.game-select){width:190px;flex:1 1 160px}
  .theme-toggle{position:relative;display:inline-flex;align-items:center;justify-content:space-around;flex:none;width:92px;height:46px;padding:3px;border:2px solid var(--scroll-thumb);border-radius:7px;background:var(--site-panel);color:var(--site-text);box-shadow:0 3px 0 var(--site-base);cursor:pointer}
  .thumb{position:absolute;inset:3px auto 3px 3px;width:38px;border-radius:3px;background:var(--gold);box-shadow:inset 0 0 0 2px var(--button-highlight);transition:transform .15s ease}
  :global(html[data-theme="dark"]) .thumb{transform:translateX(44px)}
  .icon{position:relative;display:grid;place-items:center;width:38px;height:36px}
  .sun{color:var(--button-ink)}:global(html[data-theme="dark"]) .sun{color:var(--site-text)}:global(html[data-theme="dark"]) .moon{color:var(--button-ink)}svg{width:23px;height:23px}
  .theme-toggle:hover{border-color:var(--gold)}.theme-toggle:focus-visible{outline:3px solid var(--control-focus);outline-offset:4px}
  @media(prefers-reduced-motion:reduce){.thumb{transition:none}}
  .appearance-controls{--control-focus:var(--site-focus)}
</style>
