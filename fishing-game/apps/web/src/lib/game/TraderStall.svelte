<script lang="ts">
  let { onclick } = $props<{ onclick: () => void }>();
</script>

<button class="stall" {onclick} aria-label="Visit the camp trader">
  <svg viewBox="0 0 220 150" aria-hidden="true" shape-rendering="crispEdges">
    <rect x="24" y="35" width="8" height="110" fill="#493126"/><rect x="188" y="35" width="8" height="110" fill="#493126"/>
    <path d="M12 45 32 12H188L208 45Z" fill="#5f8662"/><path d="M48 12H72L64 45H36ZM96 12H120V45H88ZM144 12H168L184 45H156Z" fill="#ecd6a2"/>
    <rect x="12" y="45" width="196" height="12" fill="#395d45"/>
    <rect x="88" y="72" width="44" height="44" fill="#dca979"/><rect x="80" y="65" width="60" height="12" fill="#493126"/><rect x="92" y="47" width="36" height="22" fill="#755638"/>
    <rect x="96" y="83" width="5" height="5" fill="#332820"/><rect x="119" y="83" width="5" height="5" fill="#332820"/><rect x="101" y="98" width="18" height="5" fill="#5f3c28"/>
    <rect x="77" y="110" width="66" height="27" fill="#506b4c"/><rect x="33" y="115" width="153" height="32" fill="#805a3b"/><rect x="33" y="118" width="153" height="5" fill="#b28a54"/>
    <rect x="42" y="87" width="27" height="29" fill="#eee1b8"/><rect x="47" y="94" width="16" height="3" fill="#9b794f"/><rect x="47" y="102" width="12" height="3" fill="#9b794f"/>
    <path d="M152 114V69L174 53" stroke="#d1a761" stroke-width="5" fill="none"/><path d="M176 54V101" stroke="#ddcc97" stroke-width="2" fill="none"/>
    <rect x="160" y="127" width="13" height="8" fill="#e0b45f"/>
  </svg>
  <span><small>A LITTLE SHOP BY THE WATER</small><strong>The Camp Trader</strong><span>Licences, rods, and your next adventure.</span><b>Browse the stall →</b></span>
</button>

<style>
  .stall{width:100%;display:flex;align-items:center;gap:28px;text-align:left;padding:20px 30px;margin-top:22px;background:var(--paper,#efe1b7);color:var(--ink,#493126);border:3px solid #b58c52;box-shadow:0 5px 0 #122b27;cursor:pointer;font-family:var(--font-body)}
  svg{width:190px;flex-shrink:0;image-rendering:pixelated}.stall>span{display:flex;flex-direction:column;gap:6px}small{font-size:10px;letter-spacing:1.5px;color:#766445}strong{font-family:var(--font-game);font-size:32px}b{font-family:var(--font-game);font-size:19px;margin-top:8px;color:#416143}.stall:hover{background:#f5e9c7}.stall:focus-visible{outline:3px solid #e7bb57;outline-offset:4px}
  @media(max-width:600px){.stall{padding:16px;gap:14px}svg{width:95px}strong{font-size:25px}.stall>span>span{font-size:12px}small{font-size:8px;letter-spacing:1px}b{font-size:17px}}
</style>
