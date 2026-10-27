import assert from "node:assert/strict";
import { execFileSync, spawn } from "node:child_process";
import { createServer } from "node:http";
import { randomBytes } from "node:crypto";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { DbConnection } from "../src/bindings/evergather";
import { runTemporaryDatabaseTest } from "./temporary-database";
import { spacetimeCli as cli } from "./spacetime-config";

// Last production revision before Discord account tables were introduced.
const legacyRevision = "ac01eef32cbe";
const server = "http://127.0.0.1:3100";
const database = `space-evergather-migration-checks-${Date.now()}`;
const connections: DbConnection[] = [];
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const run = (args: string[]) =>
  execFileSync(cli, args, {
    encoding: "utf8",
    windowsHide: true,
    timeout: 120_000,
    maxBuffer: 32 * 1024 * 1024,
  });
const call = (name: string, ...args: string[]) =>
  run([
    "call",
    database,
    name,
    ...args,
    "--server",
    server,
    "--yes",
    "--no-config",
  ]);
const publish = (path: string) =>
  run([
    "publish",
    database,
    "--module-path",
    path,
    "--server",
    server,
    "--yes=skip-login,migrate,break-clients",
    "--delete-data=never",
    "--no-config",
  ]);
async function until(check: () => boolean) {
  const end = Date.now() + 15000;
  while (!check()) {
    assert(Date.now() < end, "account subscription timed out");
    await sleep(15);
  }
}
const tables = [
  "security",
  "player",
  "skill",
  "inventory",
  "tool",
  "result",
  "contract",
  "listing",
  "achievement",
  "buff",
  "leaderboard_excluded",
  "trade",
  "economy_state",
  "ui_scope",
  "catalog_reference",
];
function rows(table: string): any[] {
  const data = JSON.parse(
    run([
      "sql",
      database,
      `SELECT * FROM ${table}`,
      "--server",
      server,
      "--no-config",
      "--format",
      "json",
    ]),
    (_key: string, value: any, ctx?: { source: string }) =>
      typeof value === "number" && !Number.isSafeInteger(value)
        ? ctx!.source
        : value,
  );
  return data.flatMap((batch: any) => batch.rows);
}
const retained = () =>
  Object.fromEntries(
    tables.map((table) => [
      table,
      rows(table)
        .map((row) => JSON.stringify(row))
        .sort(),
    ]),
  );
async function connect(token?: string, accounts = true) {
  return new Promise<{ c: DbConnection; token: string }>((yes, no) => {
    const timer = setTimeout(() => no(Error("Connection timed out")), 15000);
    const c = DbConnection.builder()
      .withUri(server)
      .withDatabaseName(database)
      .withToken(token)
      .onConnect((c, _identity, token) => {
        const queries = [
          "my_player",
          "my_skills",
          "my_inventory",
          "my_tools",
          "my_results",
          "my_contracts",
          "my_achievements",
          "my_buffs",
          "my_reference_catalog",
          "my_market_listings",
        ];
        if (accounts)
          queries.push("my_discord_account", "my_discord_challenge");
        c.subscriptionBuilder()
          .onApplied(() => {
            clearTimeout(timer);
            yes({ c, token });
          })
          .onError((ctx) => {
            clearTimeout(timer);
            no(ctx.event);
          })
          .subscribe(queries.map((table) => `SELECT * FROM ${table}`));
      })
      .onConnectError((_c, e) => {
        clearTimeout(timer);
        no(e);
      })
      .build();
    connections.push(c);
  });
}
async function begin(c: DbConnection) {
  const proof = randomBytes(32).toString("hex");
  await c.reducers.beginDiscordLink({ proof });
  await until(() => [...c.db.myDiscordChallenge.iter()][0]?.proof === proof);
  return [...c.db.myDiscordChallenge.iter()][0];
}
async function complete(
  token: string,
  challenge: { challengeId: bigint; proof: string },
  discordId = "800100000000000001",
) {
  return fetch(`${server}/v1/database/${database}/call/complete_discord_link`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify([
      Number(challenge.challengeId),
      discordId,
      challenge.proof,
    ]),
  });
}
const player = (c: DbConnection) => [...c.db.myPlayer.iter()][0];
const snapshot = (c: DbConnection) =>
  JSON.stringify(
    {
      player: player(c),
      skills: [...c.db.mySkills.iter()],
      inventory: [...c.db.myInventory.iter()],
      tools: [...c.db.myTools.iter()],
      achievements: [...c.db.myAchievements.iter()],
    },
    (_k, v) => (typeof v === "bigint" ? v.toString() : v),
  );

await runTemporaryDatabaseTest(database, async (scope) => {
  try {
    const old = scope.fixture("legacy-economy-module-");
    const files = execFileSync(
      "git",
      [
        "-C",
        "..",
        "ls-tree",
        "-r",
        "--name-only",
        legacyRevision,
        "--",
        "space/spacetimedb",
      ],
      { encoding: "utf8" },
    )
      .trim()
      .split(/\r?\n/);
    for (const file of files) {
      // Git commands run at the repo root, but this script runs inside Space.
      const destination = resolve(
        old,
        file.replace(/^space\/spacetimedb\//, ""),
      );
      assert(
        destination.startsWith(old + "/") || destination.startsWith(old + "\\"),
      );
      mkdirSync(dirname(destination), { recursive: true });
      writeFileSync(
        destination,
        execFileSync("git", ["show", `${legacyRevision}:${file}`], {
          maxBuffer: 32 * 1024 * 1024,
        }),
      );
    }
    publish(old);
    call("configure_local_play", "true");
    let original = await connect(undefined, false);
    await original.c.reducers.createCharacter({ name: "Legacy character" });
    await original.c.reducers.performAction({
      kind: "gathering_actions",
      key: "fishing_1",
    });
    await original.c.reducers.claimAchievement({ key: "account_level_1" });
    const oldToken = original.token;
    const before = retained();
    original.c.disconnect();
    publish("spacetimedb");
    assert.deepEqual(
      retained(),
      before,
      "Additive migration must preserve every retained field, identity, wallet, XP, inventory and saved reward",
    );
    console.log("PASS old-module migration preserves all retained fields");
    original = await connect(oldToken);
    const linker = await connect();
    const outsider = await connect();
    execFileSync(
      process.execPath,
      ["--import", "tsx", "scripts/configure-discord-linker.ts"],
      {
        env: {
          ...process.env,
          EVERGATHER_SPACETIMEDB_URI: server,
          EVERGATHER_DATABASE: database,
          EVERGATHER_LINKER_TOKEN: linker.token,
        },
        stdio: "pipe",
        windowsHide: true,
        timeout: 30000,
      },
    );
    const proof = await begin(original.c);
    const validate = async (token: string, value = proof) => {
      const response = await fetch(
        `${server}/v1/database/${database}/call/validate_discord_challenge`,
        {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
          },
          body: `[${value.challengeId},${JSON.stringify(value.proof)}]`,
        },
      );
      assert(response.ok, "Challenge validation transport must succeed");
      return response.json();
    };
    assert.equal(
      await validate(outsider.token),
      false,
      "Only the scoped linker can validate a proof",
    );
    assert.equal(
      await validate(linker.token, { ...proof, proof: "0".repeat(64) }),
      false,
    );
    assert.equal(
      await validate(linker.token, { ...proof, challengeId: 0n }),
      false,
    );
    assert.equal(await validate(linker.token), true);
    assert.equal(
      await validate(linker.token),
      true,
      "Validation is read-only and does not consume the challenge",
    );
    assert.equal(
      (await complete(outsider.token, proof)).ok,
      false,
      "Untrusted browser cannot verify Discord identity",
    );
    assert.equal(
      (await complete(linker.token, { ...proof, proof: "a".repeat(64) })).ok,
      false,
      "Wrong challenge proof must fail",
    );
    await assert.rejects(
      outsider.c.reducers.configureDiscordLinker({
        identity: outsider.c.identity!,
        active: true,
      }),
      /database owner/,
    );
    const saved = snapshot(original.c);
    assert.equal((await complete(linker.token, proof)).ok, true);
    assert.equal(
      await validate(linker.token),
      false,
      "Used challenges cannot allocate another OAuth session",
    );
    await until(
      () => [...original.c.db.myDiscordAccount.iter()][0]?.linked === true,
    );
    assert.equal(
      snapshot(original.c),
      saved,
      "First linking must not reroll or move the existing character",
    );
    assert.equal(
      (await complete(linker.token, proof)).ok,
      true,
      "Verified callback replay is harmless",
    );
    assert.equal(
      (await complete(linker.token, proof, "800100000000000002")).ok,
      false,
      "Replay cannot replace the verified account",
    );
    console.log(
      "PASS trusted, proof-bound, idempotent linking preserves the existing character",
    );

    const phone = await connect();
    assert.equal((await complete(linker.token, await begin(phone.c))).ok, true);
    await until(
      () => player(phone.c)?.owner.equals(player(original.c).owner) === true,
    );
    assert.equal(
      snapshot(phone.c),
      snapshot(original.c),
      "Independent browser receives the same complete private state",
    );
    assert.equal(
      player(outsider.c),
      undefined,
      "Unlinked browser sees no other player's private data",
    );
    await phone.c.reducers.performAction({
      kind: "gathering_actions",
      key: "fishing_1",
    });
    await until(() => snapshot(phone.c) === snapshot(original.c));
    assert(
      player(phone.c).owner.equals(original.c.identity!),
      "Phone activity remains owned by the original character",
    );
    console.log(
      "PASS phone login and live gameplay share original ownership, inventory and XP",
    );

    const other = await connect();
    await other.c.reducers.createCharacter({ name: "Browser guest" });
    const guestOwner = other.c.identity!.toHexString();
    const guestsBefore = rows("player")
      .map((row) => JSON.stringify(row))
      .sort();
    const conflict = await begin(other.c);
    assert.equal((await complete(linker.token, conflict)).ok, true);
    await until(
      () => [...other.c.db.myDiscordChallenge.iter()][0]?.status === "choose",
    );
    assert.equal(
      player(other.c).name,
      "Browser guest",
      "OAuth callback must not silently switch an existing character",
    );
    await assert.rejects(
      outsider.c.reducers.confirmDiscordCharacter({
        challengeId: conflict.challengeId,
        useDiscordCharacter: true,
      }),
      /this browser/,
    );
    await other.c.reducers.confirmDiscordCharacter({
      challengeId: conflict.challengeId,
      useDiscordCharacter: false,
    });
    assert.equal(player(other.c).name, "Browser guest");
    await sleep(3100);
    const choice = await begin(other.c);
    assert.equal((await complete(linker.token, choice)).ok, true);
    await other.c.reducers.confirmDiscordCharacter({
      challengeId: choice.challengeId,
      useDiscordCharacter: true,
    });
    await until(() => player(other.c)?.name === "Legacy character");
    assert.deepEqual(
      rows("player")
        .map((row) => JSON.stringify(row))
        .sort(),
      guestsBefore,
      "Choosing an account never deletes or merges either character",
    );
    await other.c.reducers.signOutDiscord({});
    await until(() => player(other.c)?.owner.toHexString() === guestOwner);
    console.log(
      "PASS conflict choice, cancellation and guest recovery preserve both characters",
    );

    await original.c.reducers.signOutDiscord({});
    await until(() => player(original.c) === undefined);
    assert.equal(original.c.db.myInventory.count(), 0n);
    assert.equal(original.c.db.myTools.count(), 0n);
    await assert.rejects(
      original.c.reducers.performAction({
        kind: "gathering_actions",
        key: "fishing_1",
      }),
      /Sign in with Discord/,
    );
    assert.equal(
      player(phone.c).name,
      "Legacy character",
      "Signing out affects only the current browser",
    );
    assert.equal(
      (await complete(linker.token, await begin(original.c))).ok,
      true,
    );
    await until(() => player(original.c)?.name === "Legacy character");
    console.log(
      "PASS logout revokes private access while retaining other device sessions and allowing login again",
    );
    const newcomer = await connect();
    assert.equal(
      (
        await complete(
          linker.token,
          await begin(newcomer.c),
          "800100000000000003",
        )
      ).ok,
      true,
    );
    await newcomer.c.reducers.createCharacter({ name: "New account" });
    const newPhone = await connect();
    assert.equal(
      (
        await complete(
          linker.token,
          await begin(newPhone.c),
          "800100000000000003",
        )
      ).ok,
      true,
    );
    await until(() => player(newPhone.c)?.name === "New account");
    assert.equal(snapshot(newPhone.c), snapshot(newcomer.c));
    await assert.rejects(
      newPhone.c.reducers.createCharacter({ name: "Duplicate" }),
      /already have/,
    );
    console.log(
      "PASS new Discord accounts create one character shared across devices",
    );
    if (process.env.DISCORD_BROWSER_PROOF === "1") {
      let finish!: () => void;
      const finished = new Promise<void>((resolve) => {
        finish = resolve;
      });
      const mock = createServer(async (req, res) => {
        if (req.url === "/finish") {
          res.end("finished");
          finish();
          return;
        }
        try {
          assert.equal(req.url, "/auth/discord/start");
          assert.equal(req.method, "POST");
          assert.equal(req.headers.origin, "http://127.0.0.1:5186");
          let body = "";
          for await (const part of req) body += part;
          const form = new URLSearchParams(body);
          assert.equal(form.get("application"), "evergather");
          const response = await complete(linker.token, {
            challengeId: BigInt(form.get("challenge_id")!),
            proof: form.get("proof")!,
          });
          assert(
            response.ok,
            `Mock verified OAuth completion failed: ${response.status}`,
          );
          res.writeHead(303, { Location: "http://127.0.0.1:5186/evergather" });
          res.end();
        } catch (error) {
          console.error(error);
          res.writeHead(500);
          res.end("Mock OAuth failed");
        }
      });
      await new Promise<void>((resolve) =>
        mock.listen(5187, "127.0.0.1", resolve),
      );
      const vite = spawn(
        process.execPath,
        [
          "node_modules/vite/bin/vite.js",
          "--host",
          "127.0.0.1",
          "--port",
          "5186",
          "--strictPort",
        ],
        {
          env: {
            ...process.env,
            VITE_SPACETIMEDB_HOST: "ws://127.0.0.1:3100",
            VITE_EVERGATHER_DATABASE: database,
            VITE_EVERGATHER_LOCAL_PLAY: "true",
            VITE_EVERGATHER_ACCOUNT_LINK_URL: "http://127.0.0.1:5187",
          },
          stdio: "inherit",
          windowsHide: true,
        },
      );
      const timeout = setTimeout(finish, 10 * 60_000);
      try {
        console.log(
          "BROWSER READY http://127.0.0.1:5186/evergather (isolated fixture; finish at http://127.0.0.1:5187/finish)",
        );
        await finished;
      } finally {
        clearTimeout(timeout);
        vite.kill();
        await new Promise<void>((resolve) => mock.close(() => resolve()));
      }
    }
  } finally {
    for (const connection of connections) connection.disconnect();
    await sleep(150);
  }
});
