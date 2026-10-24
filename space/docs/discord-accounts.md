# Evergather Discord accounts

Evergather stays at `/evergather` inside Space. The shared Fishbound account-link
service requests Discord's `identify` scope only. Joining a guild, installing a
bot, and playing Fishbound are not required. Fishbound and Evergather retain
separate progress and database permissions.

## Character behavior

- Link an existing browser character once, then sign in with the same Discord
  account on another device to continue that character.
- Existing character IDs, gold, skills, inventory, tools, rewards and market
  escrow retain their original owners. Accounts grant access to those owners;
  they do not copy or reroll characters.
- If the browser has a guest character and Discord already has another
  character, the player chooses which one to use. Neither is merged or deleted.
  Choosing the guest cancels linking. Signing out of the Discord character
  restores the original unlinked guest on that browser, if one exists.
- Signing out revokes the current browser session, including access through its
  original anonymous token if that character is linked. Other devices stay
  signed in. Browser tokens remain credentials: protect site data and use HTTPS.
- An unlinked guest still depends on that browser's saved identity. Link before
  clearing site data or changing browsers. Accounts do not recover an anonymous
  character whose original browser identity has already been lost.

## Activation

Publish the additive Evergather module and generated client together. The default
empty frontend setting keeps new views unsubscribed until activation, so existing
installations can continue using guest characters during a staged rollout.

In the linker's environment (Linux: `fishing-game/.local/production-services.env`):

```dotenv
EVERGATHER_WEBSITE_URL=https://bitcraft.ichaa.dev/evergather
EVERGATHER_SPACETIMEDB_URI=http://127.0.0.1:3100
EVERGATHER_DATABASE=space-evergather
EVERGATHER_LINKER_TOKEN_FILE=.local/service-evergatherLinker.token
EVERGATHER_ALLOWED_ORIGINS=
```

In Space's web environment (Linux: `space/.runtime/production-web.env`):

```dotenv
VITE_EVERGATHER_LOCAL_PLAY=true
VITE_EVERGATHER_ACCOUNT_LINK_URL=https://fishbound.ichaa.dev
```

Keep `configure_local_play true` enabled for anonymous guest/bootstrap identities.
Linked character access is separately enforced by the account/session mapping.
The old optional SpacetimeAuth configuration is not required for this flow.

`dataverse-update` builds the module, website and shared linker, checks retained
state, authorizes the separate Evergather linker identity, then restarts services
and publishes the website. It uses the existing database-owner CLI configuration.
The first configured update creates the token file with private permissions.
Never reuse the Fishbound database runtime's token: the Evergather token must be
issued by the Space runtime on port 3100. Token values are never browser settings.
Keep these ignored server environment files out of Git.

Discord still returns to the existing Fishbound `/auth/discord/callback`. The
server-side OAuth session remembers Evergather and redirects to its allowed
website after verification. No additional Discord callback registration is
needed. Do not add the Space origin to Fishbound's `ACCOUNT_LINK_ALLOWED_ORIGINS`;
the separate Evergather allowlist prevents mixing applications.

For local development, use `https://space.test/evergather` as the Evergather website
and the local linker's existing origin for `VITE_EVERGATHER_ACCOUNT_LINK_URL`.
Keep the registered local Discord callback unchanged. Authorize after publishing:

```powershell
# From space; the file contains the shared linker's service settings.
node --env-file=../fishing-game/.env --import tsx scripts/configure-discord-linker.ts
```

Relative linker token paths resolve from `fishing-game`, matching the service.
Restart the local linker and Vite after changing their environments. No production
or retained development database is modified by the isolated account tests.

## Verification

`npm run test:discord-accounts` publishes the pre-account module from Git revision `ac01eef32cbe` to a disposable
database, creates a legacy character, upgrades to the working module and compares
all retained fields. It checks trusted-service authorization, challenge proof,
callback replay, cross-browser gameplay, private views, conflicting guests,
logout and login again. The helper removes its database and owned artifacts.

For manual browser QA, set `DISCORD_BROWSER_PROOF=1` for that command. It keeps
the isolated fixture alive, runs Vite at `http://127.0.0.1:5186/evergather` and a
mock verified OAuth callback at port 5187. Finish with a request to
`http://127.0.0.1:5187/finish`; it also expires after ten minutes. The mock is
loopback-only, never uses real Discord credentials, and grants only fixture data.

The linker's Rust tests cover Discord code exchange, single-use cookie/state,
origin checks and Evergather HTTP authorization/body/error handling.

