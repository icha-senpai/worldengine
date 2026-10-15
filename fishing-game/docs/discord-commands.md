# Discord commands

Discord keeps four commands. The website handles equipment, upgrades, bait,
travel, quests, collections, sales and leaderboards.

| Command | Behavior |
|---|---|
| `/fish` | Cast in the selected biome and show the saved result, rod, rewards and progression. The one-minute cooldown is shared with the website. |
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
