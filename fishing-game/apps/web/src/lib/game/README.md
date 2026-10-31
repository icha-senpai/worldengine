# Live game adapter

game.svelte.ts owns browser connection, scoped subscriptions, reconnection,
and inventory action calls. Dashboard.svelte renders authoritative snapshots
and confirms a module-issued sale quote. Connections start on mount and stop
on unmount; no connection exists in shared SSR scope. Raw private table queries
and website casting are not used. Generated contracts come from
@fishing-game/generated; caller identity determines private views.

## Website theme

`theme.css` provides semantic ink, paper, panel, edge, control, status, and
progress tokens. Paper controls use `--control-focus`; the always-dark exterior
uses `--site-focus`. The live dot uses `--site-success` while paper success
continues to use `--success-ink`. `--progress-fill` is the light palette accent
and dark-mode gold; Angler XP remains gold.

`GameSelect` renders the palette-aware listbox and chevron; `SearchInput` owns
its clear button; `NumberStepper` keeps numeric editing and bounds explicit.
`tooltip` renders hoverable, focusable descriptions, and expandable summaries
use app-owned chevrons. These controls preserve controlled parent state and do
not replace browser security/permission UI or normal scrolling.

Keep scenic SVGs, Dashboard biome mini-art, Gear's six fixed quality tiers,
badge/fish PNGs, and the stable title hue as identity artwork rather than
flattening them into panel colors.

Run `node --test tests/ui-theme.test.mjs tests/custom-controls.test.mjs` for the
focused semantic-token, custom-control, and raw-color regression checks.
