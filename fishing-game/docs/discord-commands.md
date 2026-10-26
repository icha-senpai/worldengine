# Discord commands

Discord keeps six commands. The website handles equipment, upgrades, bait,
travel, quests, collections, sales and leaderboards.

| Command | Behavior |
|---|---|
| `/fish` | Cast in the selected biome and show the saved result, rod, rewards and progression. The one-minute cooldown is shared with the website. |
| `/fishboat` | Start a ten-minute boat trip for ten independent fish, junk or treasure catches. One boat trip per player; normal fishing and netting stay available. Repeat while underway to check its deadline. |
| `/fishnet` | Set a net for four minutes and four independent catches. One net per player, alongside boats and normal fishing. No bait or rod power applies to either haul. |
| `/daily` | Collect Dockside Delivery: 100 coins, plus 250 on every seventh lifetime claim. Resets at midnight UTC; missed days keep stamps. |
| `/rod [rod_id]` | Publicly show the equipped rod, or any owned rod by ID, with its artwork, quality, power, bonus-pull chance, luck and XP bonuses. Find owned rod IDs in the website Tackle Box. |
| `/profile [player_id]` | Publicly share your profile, or another angler's public game ID from the website. The embed shows their portrait, selected earned title and matching badge artwork, level, lifetime fish, ordinary discoveries, UUR catches, current records, badges earned and UUR perfected species. Wallets and inventory are omitted for everyone, including the invoker. |

The profile's name and title link directly to that angler's website profile.
Anglers without a selected earned title show “Angler”; unavailable portraits or
badges are omitted. Other players' portraits come from the existing public
portrait projection; your own portrait uses your current Discord avatar.

Global registration replaces the old slash-command list. Startup also removes
legacy Fishbound guild commands in the configured `DISCORD_GUILD_ID` and
`DISCORD_GUILD_IDS` servers, including retired commands. Unrelated guild commands
are preserved. Discord clients may briefly cache the previous menu.

The retired commands are `/quests`, `/inventory`, `/sell`, `/leaderboard`,
`/collection`, `/biome`, `/gear`, `/shop`, `/bait`, `/focus`, `/upgrade` and `/help`.
Their game features remain available on the website. No rewards, inventory,
progression or quests are removed.

## Fish again

The green Fish again button remains. It casts for the member who clicks it and
posts a fresh channel message without pinging that member. Cooldown and rejected
casts stay private. The module's replay-safe interaction receipt prevents double
rewards, and a Discord nonce prevents duplicate posts on short delivery retries.
If public delivery fails, the cast remains saved and the member receives a private
notice directing them to the website.

## Authority and announcements

The bot sends intent to SpaceTimeDB and renders committed results. Cooldowns,
rewards and daily grants remain transactional and replay-safe. Command removal
does not remove website reducers or account linking.

Achievement announcements continue through the durable pending-delivery queue.
See [achievements](achievements.md), [measurements](species-measurements.md),
[quests](quests.md) and [rank maxima](rank-maxima-recognition.md).

## Timed haul results

The database stores a deadline and starting biome, gear, quality, luck and XP.
Changing gear, upgrading, traveling or changing bait later does not change an
underway haul. `/fishboat` and `/fishnet` use slash commands, with no automatic
repeat. Starting a trip has no upfront cost. Fish stay in inventory; junk and
scrap stack normally, treasure coins and XP are awarded at completion. Each pull
updates the same collections, records, quests, perfection and journal as `/fish`.
A haul counts as one completed fishing action; its extra pulls are not rod-power
bonus pulls. The ordinary one-minute cooldown remains independent.

A database scheduled reducer resolves rewards once even when the bot is offline.
The bot retries due trips and pending result messages after reconnecting. Results
are posted in the original channel using normal messages, avoiding interaction
expiry. Database delivery leases prevent competing bots from posting together,
and stable Discord nonces suppress duplicate posts on short ambiguous retries.
Rewards cannot duplicate on a message retry. A very late retry following an
ambiguous Discord acknowledgement can still repeat the display message, because
Discord nonce deduplication is time limited. Permission failures leave rewards
saved and the pending message available for retry. Each trip's latest status is
also shown in the Tackle box; full results are in the Journal.

## Boat again and Net again

Completed boat results have a green Boat again button; completed net results have
a green Net again button. Like Fish again, these buttons are shared: clicking
starts that kind of trip for the clicking angler, using their current equipped
boat/net and biome. No equipment or user ID is taken from the old haul message.
If that angler already has a trip of that kind underway, the backend returns its
existing deadline. Retried button interactions remain pinned to the original
trip even if it has since completed.

The click is acknowledged immediately and its confirmation/rejection is private.
The completed haul and its button stay unchanged. The new result is delivered to
the channel where the button was clicked. Static custom IDs and the global
component handler keep older buttons usable after restarts; there are no timed
message collectors or automatic repeat trips.
