# Live game adapter

game.svelte.ts owns browser connection, scoped subscriptions, reconnection,
and inventory action calls. Dashboard.svelte renders authoritative snapshots
and confirms a module-issued sale quote. Connections start on mount and stop
on unmount; no connection exists in shared SSR scope. Raw private table queries
and website casting are not used. Generated contracts come from
@fishing-game/generated; caller identity determines private views.
