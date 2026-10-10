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
    <header><div><p class="eyebrow">{badge.bonus ? 'BONUS DISCOVERY' : 'ACHIEVEMENT MEDAL'} · {earned ? 'EARNED' : 'STILL TO EARN'}</p><h2 id="badge-details-title">{badge.name}</h2></div><button class="close" onclick={() => badge = null} aria-label="Close badge preview">✕</button></header>
    <div class="contents">
      <div class="art"><img src={asset} alt={badge.name + ' badge'} decoding="async" /></div>
      <p class="description">{badge.description}</p>
      <p class="title">Title: <strong>{badge.title}</strong></p>
    </div>
  {/if}
</dialog>

<style>
  dialog{width:min(640px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:0;border:4px solid var(--wood-dark,#3c2d21);background:var(--paper,#f3e7c4);color:var(--ink,#354239);box-shadow:inset 0 0 0 3px #fff0cb,0 12px 0 #132a2580;overflow:hidden}dialog[open]{display:flex;flex-direction:column}dialog::backdrop{background:#102820cf}
  header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 24px;border-bottom:2px solid #b99b6c;background:#e4d2a7;flex-shrink:0}.eyebrow{margin:0 0 6px;font-size:10px;letter-spacing:.12em;color:#79603b;font-weight:800}h2{font:30px var(--font-game);margin:0;line-height:1.2}.close{flex:none;width:42px;height:42px;border:2px solid #96794f;background:#fff0cb;color:var(--ink,#354239);font-size:20px;cursor:pointer;box-shadow:2px 3px 0 #b4996a}.close:hover{background:#f6d993}.close:focus-visible{outline:3px solid #648b74;outline-offset:3px}
  .contents{padding:20px 24px;overflow-y:auto;overscroll-behavior:contain;text-align:center}.art{display:grid;place-items:center;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;border:3px solid #6a7657;box-shadow:inset 0 0 0 3px #78916a;padding:16px}.art img{display:block;width:min(100%,360px);max-height:48dvh;aspect-ratio:1;object-fit:contain;image-rendering:pixelated}.description{font-size:14px;line-height:1.6;margin:18px 0 10px}.title{font-size:13px;color:#866033;margin:0}
  @media(max-width:600px){dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px)}header{padding:16px;gap:10px}h2{font-size:25px}.contents{padding:16px}.art{padding:10px}.description{font-size:13px}}
</style>
