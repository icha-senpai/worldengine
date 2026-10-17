# BitCraft tool boundaries

Use the existing shared infrastructure when adding or changing a tool:

- `src/bitcraft-ui/api.js` keeps page props and the existing route-shaped API. Provider reads and catalog caching live in `data/client.js`; tracker payloads in `data/trackers.js`; market and barter queries in `data/market.js`; page filter defaults in `data/filters.js`.
- `data/result.ts` defines collected read results. Preserve source identity, observation time, delayed state and retry timing. A successful browser request does not make an older observation fresh.
- `trackerSetup.js` handles setup storage and profile edit permissions. Each tracker owns its normalization and domain settings. Browser storage failure must not prevent server profile saving.
- `trackerRefresh.js` handles snapshot transport, throttling, disposal and late responses. `trackerPolling.js` owns timers and visibility handling. XP samples, continuity and session calculations remain in their domain helpers.
- `useRetainedRead.js` keeps successful history results visible through failed reads and retries interrupted reads after reconnect. It must never replay writes. Pages still own selection, pagination and subscription lifetimes.
- `useMarketBooks.js` owns order-book selection, history requests and a bounded 128-entry cache shared by the market view. Market and barter rendering remains in `Market.vue`.
- `calculatorSettings.js` validates and versions local calculator settings, including older unversioned settings. `CalculatorDataContext.vue` presents source, observation/snapshot date and estimate wording consistently. Hunting animal XP remains an editable preset; measured gathering observations remain separate from static action definitions.

Keep tool-specific calculations out of transport and persistence helpers. Preserve existing API imports through the facade when moving data code, and verify affected routes as well as focused tests and the frontend build.
