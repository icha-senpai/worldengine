<script lang="ts">
  import { onMount } from 'svelte';
  let { compact = false } = $props<{ compact?: boolean }>();
  const preferenceKey = 'fishbound:pond-motion';
  let preference = $state<'auto' | 'on' | 'off'>('auto');
  let reducedMotion = $state(true);
  const moving = $derived(preference === 'on' || (preference === 'auto' && !reducedMotion));
  onMount(() => {
    const media = matchMedia('(prefers-reduced-motion: reduce)');
    const sync = () => { reducedMotion = media.matches; };
    sync();
    try {
      const saved = localStorage.getItem(preferenceKey);
      if (saved === 'on' || saved === 'off') preference = saved;
    } catch { /* The toggle still works when browser storage is unavailable. */ }
    media.addEventListener('change', sync);
    return () => media.removeEventListener('change', sync);
  });
  function toggleMotion() {
    preference = moving ? 'off' : 'on';
    try { localStorage.setItem(preferenceKey, preference); } catch { /* Session-only preference. */ }
  }
</script>

<div class="scene" class:compact class:moving>
  <div class="pond-art" aria-hidden="true">
  <svg viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice" shape-rendering="crispEdges">
    <rect width="640" height="360" fill="#efb777" />
    <rect y="80" width="640" height="65" fill="#f6c889" />
    <rect x="446" y="46" width="64" height="64" fill="#fff0ad" />
    <path d="M40 49h66v9h24v10H25V58h15zm207-19h49v8h27v10h-94V38h18z" fill="#ffe3b0" />
    <path d="M0 141V105h24v-9h44v18h42v-12h39v22h46v-18h48v22h56v-12h39v21h65v-23h44v-9h43v20h54v-15h48v17h53v-8h32v22z" fill="#568074" />
    <path d="M0 147v-16h72v-10h49v17h54v-9h51v19h62v-13h53v14h44v-19h59v11h80v-15h47v16h69v5z" fill="#315d59" />
    <rect y="147" width="640" height="213" fill="#376c72" />
    <rect y="169" width="640" height="12" fill="#497e7e" />
    <rect y="198" width="640" height="6" fill="#477c7c" />
    <rect y="257" width="640" height="8" fill="#2e6269" />
    <path d="M437 159h79v5h-79zm15 18h49v5h-49zm-19 20h68v4h-68zm25 22h42v4h-42zm-285-22h58v4h-58zm-65 55h41v4h-41zm239 26h58v4h-58zm153 23h59v4h-59zm-189 26h48v4h-48z" fill="#74a292" />
    <path d="M0 315h28v-11h48v10h32v-13h63v14h20v45H0zm495 27v-17h41v-13h62v14h42v34H480v-18z" fill="#203e37" />
    <path d="M0 308h28v-11h48v10h32v-13h63v13h20v8h-83v7H28v-7H0zm512 24v-14h24v-13h62v14h42v8h-42v6h-62v6z" fill="#63814c" />
    <path d="M30 320v-50h5v50zm12 0v-63h5v63zm14 0v-43h5v43zm527 18v-60h5v60zm15 0v-72h5v72zm15 0v-43h5v43z" fill="#799657" />
    <path d="M29 268h7v19h-7zm12-13h7v21h-7zm541 21h8v21h-8zm15-12h8v20h-8z" fill="#b38b5f" />
    <path d="M0 232h133v14H0zm0 20h133v14H0zm0 20h133v14H0zm0 20h133v14H0z" fill="#b58152" />
    <path d="M0 246h133v6H0zm0 20h133v6H0zm0 20h133v6H0zm0 20h133v8H0z" fill="#644831" />
    <path d="M110 224h14v94h-14zM18 224h14v94H18z" fill="#c59964" />
    <path d="M20 229h9v8h-9zm92 0h9v8h-9zm-92 71h9v8h-9zm92 0h9v8h-9z" fill="#473b2d" />
    <path d="M95 230l40-69h5l-40 72z" fill="#4f3930" />
    <path d="M138 163h48v2h-48zm47 1h2v60h-2z" fill="#efd7a1" />
    <rect class="bobber" x="181" y="222" width="10" height="12" fill="#f28e6d" />
    <rect class="bobber" x="181" y="222" width="10" height="5" fill="#fff1ce" />
    <path class="rings" d="M172 240h29v3h-29zm-8-4h7v3h-7zm38 0h7v3h-7z" fill="#94b9a5" />
    <path d="M517 178h23v4h-23zm6-4h10v4h-10zm-75 114h27v4h-27zm7-4h12v4h-12z" fill="#648d61" />
  </svg>
  <img class="swimmer koi" src="/fish/koi.png" alt="" />
  <img class="swimmer minnow" src="/fish/minnow.png" alt="" />
  <span class="scene-label">A good day to cast.</span>
  </div>
  <button class="motion-toggle" aria-label="Pond animation" aria-pressed={moving} onclick={toggleMotion}><span aria-hidden="true">{moving ? 'Ⅱ' : '▶'}</span> {moving ? 'Pause pond' : 'Animate pond'}</button>
</div>

<style>
  .scene{position:relative;min-height:270px;height:100%;overflow:hidden;border:4px solid var(--wood-dark);box-shadow:inset 0 0 0 3px #ffe7a6,0 5px 0 #132e29;isolation:isolate;background:#376c72}
  svg{display:block;width:100%;height:100%;position:absolute;inset:0}
  .swimmer{position:absolute;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 5px 0 #163e4855)}
  .koi{width:92px;height:70px;left:49%;top:61%}
  .minnow{width:48px;height:38px;left:73%;top:59%;opacity:.8;transform:scaleX(-1)}
  .scene-label{position:absolute;right:14px;bottom:14px;padding:6px 10px;background:#203e37e8;color:#f4dfac;font-family:var(--font-game);font-size:16px;border:2px solid #779164}
  .motion-toggle{position:absolute;left:14px;bottom:14px;min-height:36px;padding:6px 10px;background:#203e37e8;color:#f4dfac;font-family:var(--font-game);font-size:15px;border:2px solid #779164;cursor:pointer}
  .motion-toggle:hover{background:#31584b}.motion-toggle:focus-visible{outline:3px solid #ffe7a6;outline-offset:3px}
  .compact{min-height:205px}.compact .koi{width:72px;height:58px}
  @media(max-width:600px){.scene-label{font-size:13px;right:10px;bottom:10px}.motion-toggle{font-size:13px;left:10px;bottom:10px}.koi{top:54%}}
  @media(max-width:360px){.scene-label{display:none}}
  /* The user's explicit pond choice overrides the global reduced-motion rule
     for these gentle effects only. Other UI motion still follows the system. */
  .moving .koi{animation:drift 7s ease-in-out infinite !important}
  .moving .bobber,.moving .rings{animation:bob 3s ease-in-out infinite !important}
  @keyframes drift{50%{transform:translate(14px,-4px)}}@keyframes bob{50%{transform:translateY(2px)}}
</style>
