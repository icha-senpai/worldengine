# Angler portraits and title badges

The public angler profile and directory use Discord's global avatar. Animated avatar hashes use Discord CDN GIFs; static avatars use WebP. Default portraits follow Discord's modern snowflake formula or legacy discriminator formula. If portrait metadata or an image is unavailable, the existing anchor placeholder remains visible. Reduced-motion preferences use a still WebP portrait and respond to preference changes.

The additive public `angler_portrait` table stores one player ID, avatar URL, still URL and update timestamp per angler. Existing player/profile tables remain unchanged. `sync_discord_portrait(discord_user_id, avatar_hash, discriminator)` accepts only active Discord adapter or OAuth linker principals, requires an existing player, validates the hash and discriminator, and constructs fixed Discord CDN URLs itself. Browsers cannot set portraits or submit image URLs. An unchanged portrait is a no-op.

The bot refreshes the command author's avatar after successful game snapshot operations. The OAuth linker extracts avatar metadata from the same authenticated `identify` user response that verifies account ownership, and refreshes it after linking. Portrait failures are logged without undoing successful gameplay or account linking. No additional OAuth scopes or retained access tokens are introduced. `node --env-file=.env --import tsx scripts/backfill-portraits.ts fishbound-dev-local` refreshes existing users from Discord's authenticated user API and writes only portrait projections. Snowflake IDs are decoded from their exact JSON source text to avoid JavaScript rounding; credentials and raw private user data are not printed.

Equipped titles use the existing achievement definition and medal artwork. `TitleBadge.svelte` displays the title beside its actual badge and assigns a stable per-achievement colour treatment. The main profile title and earned medals open the existing accessible badge preview. Directory cards show a compact title medal and portrait; the neutral Angler title remains available. Unlocks and equipped-title permissions continue to be authoritative existing reducers.

## Checks

- `npx tsx scripts/portraits-proof.ts`: previous module to new module with a linked player, a cast and an equipped title; all 52 old tables retained; trusted bot/OAuth updates; browser denial; hash validation; missing-player denial; static/animated URLs; both default-avatar formulas; no-op replay; public subscription updates.
- `node scripts/portrait-preservation.mjs before|after DATABASE [SERVER] [FILE]`: canonical comparison of all old tables excluding scheduled maintenance and the one new portrait table. Unsafe JSON integers retain their original source text.
- `cargo test -p account-link --locked`: existing CSRF, cookie, session and OAuth tests, including authenticated animated-avatar metadata extraction.
- Script/Svelte checks, website build and Rust workspace checks; native clients rebuilt from the regenerated bindings.
- Desktop/mobile browser validation covers actual portraits, title/earned-medal previews, Escape and focus restoration, reduced-motion still portraits, failed image fallback and layout width.

Discord format references: [CDN images](https://docs.discord.com/developers/reference#image-formatting), [User resource](https://docs.discord.com/developers/resources/user). No avatar bytes or OAuth secrets are stored in the game database.

## Live acceptance — 11 October 2026

Published additively to the existing `fishbound-dev-local` identity on the isolated Fishbound host. The migration created only `angler_portrait`. A fresh cold backup in `.local/deployments/portraits-20261011-181315` verified 3,237 files and restored the same 52 retained tables and 6,513 rows on loopback port 3128. After publication and portrait refresh, every pre-existing table still matched the canonical snapshot. The temporary restore listener was closed.

All eight existing users were refreshed from Discord, including two animated avatars. `icha_`'s actual portrait and equipped Trophy Hunter medal were verified on the public site. The title preview, earned-medal preview, Escape and focus restoration worked. Reduced motion selected the still portrait; a simulated image error displayed the anchor fallback. Desktop and 390-pixel mobile layouts had no horizontal overflow or console errors. Screenshots are in `output/playwright/angler-portraits-live-*.png`.

Script and Svelte checks, the production website build, Rust workspace checks, all three OAuth tests and both native client builds passed. Bot and OAuth linker readiness were verified. The one-time refresh script follows the existing native credential-loading rule: an empty inline token falls back to the configured token file.
