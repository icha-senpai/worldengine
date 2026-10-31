<script lang="ts">
  import type { Game } from './game.svelte.ts';
  let { badge = $bindable(null), asset = '', earned = false } = $props<{
    badge?: Game['achievements'][number] | null; asset?: string; earned?: boolean;
  }>();
  let dialog: HTMLDialogElement;
  $effect(() => {
    if (badge && dialog && !dialog.open) dialog.showModal();
    else if (!badge && dialog?.open) dialog.close();
  });
  $effect(() => {
    if (!badge) return;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  });
  function dismissBackdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) badge = null;
  }
</script>

<dialog bind:this={dialog} aria-labelledby="badge-details-title" onclose={() => badge = null} oncancel={() => badge = null} onpointerdown={dismissBackdrop}>
  {#if badge}
    <header><div><p class="eyebrow">{badge.bonus ? 'BONUS BADGE' : 'ACHIEVEMENT MEDAL'} · {earned ? 'EARNED' : 'STILL TO EARN'}</p><h2 id="badge-details-title">{badge.name}</h2></div><button class="close" onclick={() => badge = null} aria-label="Close badge preview">✕</button></header>
    <div class="contents">
      <div class="art"><img src={asset} alt={badge.name + ' badge'} decoding="async" /></div>
      <p class="description">{badge.description}</p>
      <p class="title">Title: <strong>{badge.title}</strong></p>
    </div>
  {/if}
</dialog>

<style>
  dialog{width:min(800px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:0;border:4px solid var(--wood-dark);background:var(--paper);color:var(--ink);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:hidden}dialog[open]{display:flex;flex-direction:column}dialog::backdrop{background:var(--dialog-backdrop)}
  header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 24px;border-bottom:2px solid var(--paper-edge);background:var(--panel-inset);flex-shrink:0}.eyebrow{margin:0 0 6px;font-size:14px;letter-spacing:.12em;color:var(--ink-muted);font-weight:800}h2{font:34px var(--font-game);margin:0;line-height:1.2}.close{flex:none;width:42px;height:42px;border:2px solid var(--paper-edge);background:var(--paper-light);color:var(--ink);font-size:24px;cursor:pointer;box-shadow:2px 3px 0 var(--wood-dark)}.close:hover{background:var(--panel-inset)}.close:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}
  .contents{padding:20px 24px;overflow-y:auto;overscroll-behavior:contain;text-align:center}.art{display:grid;place-items:center;background:var(--panel-texture),var(--art-background);border:3px solid var(--art-edge);box-shadow:inset 0 0 0 3px var(--art-highlight);padding:16px}.art img{display:block;width:min(100%,360px);max-height:48dvh;aspect-ratio:1;object-fit:contain;image-rendering:pixelated}.description{font-size:17px;line-height:1.6;margin:18px 0 10px}.title{font-size:16px;color:var(--ink);margin:0}
  @media(max-width:600px){dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px)}header{padding:16px;gap:10px}h2{font-size:27px}.contents{padding:16px}.art{padding:10px}.description{font-size:15px}}
</style>
