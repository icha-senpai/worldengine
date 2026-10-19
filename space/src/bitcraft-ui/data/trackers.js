import { connectBitcraft } from "../../bitcraft";
import { live, catalog, mapLimited } from "./client";
import { activityTracker, inventoryTracker, passiveTracker } from "../trackers";
import { collectedActivitySamples } from "../activitySamples";
import { xpSourceAction, resolveXpSource } from "../xpSource";
import {
  relayPlayer,
  relayInventory,
  relayCrafts,
  withRelayCraftTimers,
  relaySkills,
  storageInventories,
  mergeInventories,
  inventoryQuantities,
} from "../relay";
async function lookupPlayerResponse(character) {
  if (!character) return null;
  try {
    const playerResponse = await lookupRelayPlayer(character);
    if (playerResponse?.data.player) {
      const skills = await live(
        "relaySkills",
        playerResponse.data.player.entityId,
      );
      if (!skills.refresh.delayed)
        return {
          ...skills,
          data: {
            player: relaySkills(skills.data, playerResponse.data.player),
          },
        };
    }
  } catch {
    /* Use the searchable player API during relay recovery. */
  }
  if (/^\d+$/.test(character)) return await live("player", character);
  const data = (await live("players", "", character)).data;
  const selected =
    (data.players ?? []).find(
      (player) =>
        String(player.username).toLowerCase() === character.toLowerCase(),
    ) ??
    data.players?.[0] ??
    null;
  return selected ? await live("player", selected.entityId) : null;
}
export async function lookupPlayer(character) {
  return (await lookupPlayerResponse(character))?.data.player ?? null;
}
async function lookupRelayPlayer(character) {
  if (!character) return null;
  if (/^\d+$/.test(character)) {
    const response = await live("relayPlayer", character);
    return { ...response, data: { player: relayPlayer(response.data) } };
  }
  const response = await live("relayPlayers", "", character);
  const selected =
    response.data.find(
      (row) => row.username?.toLowerCase() === character.toLowerCase(),
    ) ?? response.data[0];
  return selected
    ? lookupRelayPlayer(String(selected.entity_id ?? selected.entityId))
    : null;
}
async function trackerPlayer(tool, character) {
  if (tool === "inventory")
    try {
      const response = await lookupRelayPlayer(character);
      if (response?.data.player && !response.refresh.delayed) return response;
    } catch {
      /* Use the player APIs when the relay is unavailable. */
    }
  try {
    const response = await lookupPlayerResponse(character);
    return response?.data.player || tool === "activity"
      ? response
      : await lookupRelayPlayer(character);
  } catch (error) {
    if (tool === "activity") throw error;
    return await lookupRelayPlayer(character);
  }
}
export async function enrichTrackerItems(payload) {
  const raw = (payload.inventories ?? [])
    .flatMap((row) => row.items ?? row.pockets ?? [])
    .map((row) => row.contents ?? row.item ?? row)
    .concat(
      (payload.craftResults ?? []).flatMap(
        (row) =>
          row.craftedItem ??
          row.crafted_item ??
          row.craftedItems ??
          row.outputs ??
          [],
      ),
    );
  const keys = [
    ...new Set(
      raw
        .map(
          (row) =>
            `${["cargo", 1, "1"].includes(row.item_type ?? row.itemType ?? row.kind) ? "cargo" : "item"}:${row.item_id ?? row.itemId ?? row.id}`,
        )
        .filter((key) => /^(item|cargo):\d+$/.test(key)),
    ),
  ];
  const items = await mapLimited(keys, (key) => catalog("item-info", key));
  return {
    ...payload,
    items: {
      ...(payload.items ?? {}),
      ...Object.fromEntries(
        items.filter((row) => row?.kind === "item").map((row) => [row.id, row]),
      ),
    },
    cargos: {
      ...(payload.cargos ?? {}),
      ...Object.fromEntries(
        items
          .filter((row) => row?.kind === "cargo")
          .map((row) => [row.id, row]),
      ),
    },
  };
}
export async function trackerInventory(player, filters = {}) {
  let base;
  try {
    const response = await live("relayInventories", player.entityId);
    if (!response.refresh.delayed)
      base = {
        ...response,
        data: await enrichTrackerItems(relayInventory(response.data)),
      };
  } catch {
    /* Continue with the player APIs. */
  }
  if (!base) {
    const response = await live("inventories", player.entityId);
    base = { ...response, data: await enrichTrackerItems(response.data) };
  }
  const stores = [];
  if ([true, "true", "1"].includes(filters.includeHousing))
    stores.push(["relayHousing", player.entityId, "housing"]);
  if (/^\d{1,24}$/.test(String(filters.storageClaimId ?? "")))
    stores.push([
      "relayClaimInventory",
      String(filters.storageClaimId),
      "claim",
    ]);
  if (!stores.length) return base;
  const responses = await Promise.all(
    stores.map(async ([resource, id, kind]) => {
      const result = await live(resource, id);
      return {
        ...result,
        data: { inventories: storageInventories(result.data, kind) },
      };
    }),
  );
  return {
    ...base,
    data: await enrichTrackerItems(
      mergeInventories(base.data, ...responses.map((row) => row.data)),
    ),
    refresh: {
      ...base.refresh,
      delayed:
        base.refresh.delayed || responses.some((row) => row.refresh.delayed),
    },
    error: base.error || responses.find((row) => row.error)?.error,
  };
}
async function passiveSnapshot(player) {
  const response = await trackerPassive(player);
  const ids = [
    ...new Set(
      (response.data.craftResults ?? [])
        .map((row) => String(row.recipeId ?? row.recipe_id))
        .filter((id) => /^\d+$/.test(id)),
    ),
  ];
  const times = {};
  for (let index = 0; index < ids.length; index += 6)
    Object.assign(
      times,
      await catalog("recipe-times", ids.slice(index, index + 6).join(",")),
    );
  return {
    tracker: passiveTracker(player, response.data, times),
    error: response.error,
    sampledAt: response.refresh.updatedAt ?? new Date().toISOString(),
    refresh: response.refresh,
  };
}
async function trackerPassive(player) {
  let failure;
  try {
    const [response, timing] = await Promise.all([
      live("relayCrafts", player.entityId),
      live("relaySkills", player.entityId).catch(() => null),
    ]);
    const ids = [
      ...new Set(
        (response.data.crafts ?? [])
          .filter((row) => row.is_passive && !row.completed)
          .map((row) => String(row.claim_entity_id ?? ""))
          .filter((id) => /^\d+$/.test(id)),
      ),
    ];
    const claims = Object.fromEntries(
      await mapLimited(ids, async (id) => [
        id,
        await live("relayClaim", id)
          .then((result) => result.data)
          .catch(() => ({})),
      ]),
    );
    if (response.refresh.delayed) throw new Error("Craft refresh delayed.");
    return {
      ...response,
      data: await enrichTrackerItems(
        withRelayCraftTimers(
          relayCrafts(response.data, claims),
          timing?.data?.passiveCrafts,
        ),
      ),
    };
  } catch (error) {
    failure = error;
  }
  try {
    const response = await live("passive", player.entityId);
    return { ...response, data: await enrichTrackerItems(response.data) };
  } catch (error) {
    throw failure ?? error;
  }
}
export async function trackerSnapshot(tool, filters) {
  const sampledAt = new Date().toISOString();
  try {
    const playerResponse = await trackerPlayer(tool, filters.character),
      player = playerResponse?.data.player;
    if (!player)
      throw new Error(`No BitCraft player matched '${filters.character}'.`);
    if (tool === "activity") {
      const [definitions, response, history] = await Promise.all([
        catalog("skills"),
        live("levels"),
        connectBitcraft()
          .then((conn) =>
            conn.procedures.collectionHistory({
              playerId: String(player.entityId),
            }),
          )
          .then(JSON.parse)
          .catch(() => null),
      ]);
      const activity =
        filters.background === "1"
          ? null
          : await live("relaySession", player.entityId)
              .then((row) => ({ ...row.data, delayed: row.refresh.delayed }))
              .catch(() => null);
      const crafts =
        filters.background === "1" ||
        [false, "false", "0"].includes(filters.showCrafts)
          ? { tracker: null, refresh: { delayed: false } }
          : await passiveSnapshot(player).catch(() => ({
              tracker: null,
              refresh: { delayed: true },
            }));
      const action = xpSourceAction(activity);
      const sourceCard =
        action &&
        ["Craft", "Extract"].includes(action.action_type) &&
        /^\d+$/.test(String(action.recipe_id))
          ? await catalog(
              "card",
              String(action.recipe_id),
              action.action_type === "Extract" ? "gathering" : "crafting",
            ).catch(() => null)
          : null;
      return {
        tracker: {
          ...activityTracker(
            player,
            definitions,
            Array.isArray(response.data)
              ? response.data
              : (response.data.levels ?? []),
            filters,
          ),
          activity,
          xpSource: resolveXpSource(action, sourceCard, player.entityId),
          passiveCrafts: crafts.tracker?.crafts ?? [],
          passiveCraftGroups: crafts.tracker?.groups ?? [],
          craftsDelayed: crafts.refresh.delayed,
          xpCalendar: history?.calendar ?? null,
        },
        error: playerResponse.error || response.error,
        sampledAt: playerResponse.refresh.updatedAt ?? sampledAt,
        sampleSourceKey: playerResponse.sourceKey,
        xpHistory: collectedActivitySamples(
          history,
          String(player.entityId),
          playerResponse.sourceKey,
        ),
        refresh: {
          ...playerResponse.refresh,
          delayed: playerResponse.refresh.delayed || response.refresh.delayed,
        },
      };
    }
    if (tool === "inventory") {
      const [response, items] = await Promise.all([
        trackerInventory(player, filters),
        catalog("inventory-items"),
      ]);
      return {
        ...inventoryTracker(player, response.data, items, filters),
        error: response.error,
        sampledAt: response.refresh.updatedAt ?? sampledAt,
        refresh: response.refresh,
      };
    }
    return await passiveSnapshot(player);
  } catch (error) {
    return {
      tracker: null,
      options:
        tool === "inventory"
          ? await catalog("inventory-items")
              .then((items) =>
                items.map((item) => ({
                  ...item,
                  key: `${item.kind}:${item.id}`,
                })),
              )
              .catch(() => [])
          : [],
      error: error.message,
      sampledAt,
      refresh: {
        delayed: true,
        retryAfter: error.retryAfter ?? 0,
      },
    };
  }
}
