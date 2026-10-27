import {
  SenderError,
  t,
  type InferSchema,
  type ReducerCtx,
  type ViewCtx,
} from "spacetimedb/server";
import db, { discordChallenge } from "./schema";
type Schema = InferSchema<typeof db>;
type Reader = Pick<ViewCtx<Schema>, "db" | "sender">;
type Ctx = ReducerCtx<Schema>;

// Gameplay stays owned by its original identity. Browser identities are only
// capabilities to that owner; public callers cannot choose an owner directly.
export function accountOwner(ctx: Reader) {
  const session = ctx.db.accountSession.identity.find(ctx.sender);
  if (session) {
    const account = ctx.db.discordAccount.discordId.find(session.discordId);
    if (account?.owner.equals(session.owner)) return session.owner;
    return undefined;
  }
  // Once linked, the original browser must also have an active login. Otherwise
  // retaining its old anonymous token would bypass signing out.
  return ctx.db.discordAccount.owner.find(ctx.sender) ? undefined : ctx.sender;
}
export function actingOwner(ctx: Reader) {
  const owner = accountOwner(ctx);
  if (!owner)
    throw new SenderError("Sign in with Discord to open this character.");
  return owner;
}
export function ownedView<T>(
  ctx: Reader,
  read: (owner: NonNullable<ReturnType<typeof accountOwner>>) => T[],
): T[] {
  const owner = accountOwner(ctx);
  return owner ? read(owner) : [];
}

export const configureDiscordLinker = db.reducer(
  { identity: t.identity(), active: t.bool() },
  (ctx, row) => {
    if (!ctx.db.security.id.find(1)!.owner.equals(ctx.sender))
      throw new SenderError(
        "Only the database owner can configure account linking.",
      );
    if (ctx.db.discordLinker.identity.find(row.identity))
      ctx.db.discordLinker.identity.update(row);
    else ctx.db.discordLinker.insert(row);
  },
);

function challenge(ctx: Ctx, id: bigint) {
  const row = ctx.db.discordChallenge.challengeId.find(id);
  if (!row || ctx.timestamp.microsSinceUnixEpoch >= row.expiresAt)
    throw new SenderError("Account-link request expired. Start again.");
  return row;
}
function attach(
  ctx: Ctx,
  row: ReturnType<typeof challenge>,
  discordId: string,
) {
  const existing = ctx.db.discordAccount.discordId.find(discordId);
  const owner =
    existing?.owner ?? actingOwner({ db: ctx.db, sender: row.browser });
  if (!existing) {
    if (ctx.db.discordAccount.owner.find(owner))
      throw new SenderError(
        "This character already belongs to another Discord account.",
      );
    ctx.db.discordAccount.insert({ discordId, owner, linkedAt: ctx.timestamp });
  }
  const session = { identity: row.browser, owner, discordId };
  if (ctx.db.accountSession.identity.find(row.browser))
    ctx.db.accountSession.identity.update(session);
  else ctx.db.accountSession.insert(session);
  ctx.db.discordChallenge.challengeId.update({
    ...row,
    verifiedDiscordId: discordId,
    status: "linked",
  });
}
export const beginDiscordLink = db.reducer(
  { proof: t.string() },
  (ctx, { proof }) => {
    if (!/^[a-f0-9]{64}$/.test(proof))
      throw new SenderError("Invalid account-link proof.");
    const now = ctx.timestamp.microsSinceUnixEpoch;
    const old = ctx.db.discordChallenge.browser.find(ctx.sender);
    if (old && now - old.createdAt < 3_000_000n)
      throw new SenderError("Wait a moment before starting another login.");
    if (old) ctx.db.discordChallenge.challengeId.delete(old.challengeId);
    // Prune expired attempts during login, without changing any player state.
    for (const pending of ctx.db.discordChallenge.iter())
      if (pending.expiresAt <= now)
        ctx.db.discordChallenge.challengeId.delete(pending.challengeId);
    ctx.db.discordChallenge.insert({
      challengeId: 0n,
      browser: ctx.sender,
      proof,
      createdAt: now,
      expiresAt: now + 600_000_000n,
      status: "pending",
      verifiedDiscordId: "",
    });
  },
);
export const completeDiscordLink = db.reducer(
  { challengeId: t.u64(), discordId: t.string(), proof: t.string() },
  (ctx, args) => {
    if (!ctx.db.discordLinker.identity.find(ctx.sender)?.active)
      throw new SenderError("Account-link service is not authorized.");
    if (
      !/^[1-9][0-9]{0,19}$/.test(args.discordId) ||
      BigInt(args.discordId) > 18_446_744_073_709_551_615n
    )
      throw new SenderError("Invalid verified Discord account.");
    const row = challenge(ctx, args.challengeId);
    if (row.proof !== args.proof)
      throw new SenderError("Account-link proof does not match.");
    if (row.status !== "pending") {
      if (
        row.verifiedDiscordId === args.discordId &&
        ["linked", "choose"].includes(row.status)
      )
        return;
      throw new SenderError("Account-link request was already used.");
    }
    const existing = ctx.db.discordAccount.discordId.find(args.discordId);
    const current = accountOwner({ db: ctx.db, sender: row.browser });
    if (
      existing &&
      current &&
      !existing.owner.equals(current) &&
      ctx.db.player.owner.find(current)
    ) {
      ctx.db.discordChallenge.challengeId.update({
        ...row,
        status: "choose",
        verifiedDiscordId: args.discordId,
      });
      return;
    }
    attach(ctx, row, args.discordId);
  },
);
export const validateDiscordChallenge = db.procedure(
  { challengeId: t.u64(), proof: t.string() },
  t.bool(),
  (ctx, args) =>
    ctx.withTx((tx) => {
      if (!tx.db.discordLinker.identity.find(ctx.sender)?.active) return false;
      const row = tx.db.discordChallenge.challengeId.find(args.challengeId);
      return Boolean(
        row &&
        row.status === "pending" &&
        row.expiresAt > tx.timestamp.microsSinceUnixEpoch &&
        row.proof === args.proof,
      );
    }),
);
export const confirmDiscordCharacter = db.reducer(
  { challengeId: t.u64(), useDiscordCharacter: t.bool() },
  (ctx, args) => {
    const row = challenge(ctx, args.challengeId);
    if (!row.browser.equals(ctx.sender) || row.status !== "choose")
      throw new SenderError(
        "This account choice does not belong to this browser.",
      );
    if (args.useDiscordCharacter) attach(ctx, row, row.verifiedDiscordId);
    else
      ctx.db.discordChallenge.challengeId.update({
        ...row,
        status: "cancelled",
        proof: "",
      });
  },
);
export const signOutDiscord = db.reducer((ctx) => {
  ctx.db.accountSession.identity.delete(ctx.sender);
  const pending = ctx.db.discordChallenge.browser.find(ctx.sender);
  if (pending) ctx.db.discordChallenge.challengeId.delete(pending.challengeId);
});
export const myDiscordChallenge = db.view(
  { name: "my_discord_challenge", public: true },
  t.option(discordChallenge.rowType),
  (ctx) => ctx.db.discordChallenge.browser.find(ctx.sender) ?? undefined,
);
export const myDiscordAccount = db.view(
  { name: "my_discord_account", public: true },
  t.array(
    t.row("DiscordAccountStatus", {
      id: t.u32().primaryKey(),
      linked: t.bool(),
      needsSignIn: t.bool(),
      discordId: t.string(),
      characterName: t.string(),
    }),
  ),
  (ctx) => {
    const owner = accountOwner(ctx);
    const account = owner ? ctx.db.discordAccount.owner.find(owner) : undefined;
    return [
      {
        id: 1,
        linked: Boolean(account),
        needsSignIn: !owner,
        discordId: account?.discordId ?? "",
        characterName: owner
          ? (ctx.db.player.owner.find(owner)?.name ?? "")
          : "",
      },
    ];
  },
);
