// Compatibility facade: pages keep their existing imports and response shapes.
import { connectBitcraft } from "../bitcraft";
import { pageState, route } from "./navigation";
import { live, catalog } from "./data/client";
import {
  lookupPlayer,
  trackerInventory,
  enrichTrackerItems,
  trackerSnapshot,
} from "./data/trackers";
import { marketPage, orderBook, emptyMarket } from "./data/market";
import { filtersFor } from "./data/filters";
import { openCraftRows, filterCrafts } from "./openCrafts";
import { levelProgress } from "./trackers";
import { inventoryQuantities } from "./relay";
import { relayCrafts } from "./relay";
export { live, catalog } from "./data/client";
export { lookupPlayer, trackerSnapshot } from "./data/trackers";
export { marketPage, storedMarketPage, emptyMarket } from "./data/market";

export async function saveGuide(id, form) {
  const conn = await connectBitcraft();
  const guideId = id
    ? BigInt(id)
    : crypto.getRandomValues(new BigUint64Array(1))[0] || 1n;
  await conn.reducers.saveGuide({
    id: guideId,
    title: form.title,
    summary: form.summary,
    category: form.category,
    content: JSON.stringify(form.content),
    published: Boolean(form.is_published),
  });
  return String(guideId);
}
export async function pageProps(
  tool,
  query = {},
  setup = true,
  id = "",
  editing = false,
  options = {},
) {
  if (
    query.profile &&
    ["activity", "inventory", "passive-crafts", "tasks"].includes(tool)
  ) {
    const { readWidget } = await import("./widgets");
    const presentation = query.presentation;
    const character = query.character;
    query = {
      ...(await readWidget(tool, String(query.profile))),
      profile: query.profile,
      ...(presentation ? { presentation } : {}),
      ...(character ? { character } : {}),
    };
  }
  const filters = filtersFor(tool, query, setup);
  if (["activity", "inventory", "passive-crafts"].includes(tool))
    return {
      filters,
      snapshot: await trackerSnapshot(tool, filters),
      [tool === "activity" ? "pollUrl" : "snapshotUrl"]: route(
        `bitcraft.${tool === "inventory" ? "inventory-tracker" : tool}.snapshot`,
        filters,
      ),
    };
  if (tool === "tasks") return { filters };
  if (tool === "tool-rates") {
    const entries = await catalog("entries");
    let observations = null,
      nearby = null,
      nearbyError = null;
    if (pageState.props.bitcraft.player) {
      const player = await lookupPlayer(
        pageState.props.bitcraft.player.entityId,
      ).catch(() => null);
      if (player) {
        if ([true, "true", "1"].includes(query.nearby)) {
          try {
            const result = await live("relayNearby", player.entityId);
            nearby = { ...result.data, refresh: result.refresh };
          } catch (error) {
            nearbyError = error.message;
          }
        }
        const conn = await connectBitcraft();
        const history = await conn.procedures
          .collectionHistory({ playerId: player.entityId })
          .then(JSON.parse)
          .catch(() => null);
        const skills = await catalog("skills");
        observations = {
          username: player.username,
          rates: (history?.rates ?? []).map((row) => ({
            ...row,
            name:
              skills.find((skill) => Number(skill.id) === row.skillId)?.name ??
              `Skill ${row.skillId}`,
          })),
        };
      }
    }
    return { ...entries, observations, nearby, nearbyError };
  }
  if (tool === "hunting-calculator") {
    try {
      const response = await live("levels");
      const levels = Array.isArray(response.data)
        ? response.data
        : (response.data.levels ?? []);
      let playerSkill = null;
      if (pageState.props.bitcraft.player) {
        const player = await lookupPlayer(
          pageState.props.bitcraft.player.entityId,
        ).catch(() => null);
        const skill = player?.experience?.find(
          (row) => Number(row.skill_id ?? row.skillId) === 9,
        );
        if (skill?.quantity != null)
          playerSkill = {
            username: player.username,
            ...levelProgress(Number(skill.quantity), levels),
          };
      }
      return {
        levels,
        playerSkill,
        refresh: response.refresh,
        error: response.error,
      };
    } catch (error) {
      return { levels: [], error: error.message };
    }
  }
  if (["market", "barter-stalls"].includes(tool))
    return {
      filters,
      ...(await marketPage(tool, filters, options)),
      ...(tool === "barter-stalls"
        ? {
            tool: {
              key: tool,
              routeName: "bitcraft.barter-stalls",
              title: "Barter Stalls",
              subtitle:
                "Find player barter trades by item, claim, empire, and region.",
              claimIdLabel: "Claim / stall ID",
              claimSearchLabel: "Claim search",
              claimSectionTitle: "Stalls",
              claimSectionSubtitle: "Claims with barter stations",
              claimEmptyLabel:
                "Search a name or region to find claims with barter stations.",
              tradeBuildingSingular: "barter stall",
              tradeBuildingPlural: "barter stalls",
              buildingSectionTitle: "Barter Stalls",
              buildingSectionSubtitle: "Barter stations inside the claim",
              buildingEmptyLabel: "Pick a claim to find barter stalls.",
              listingSectionTitle: "Barter Listings",
              clearLabel: "Clear Stall",
              claimLinkLabel: "Use this stall ->",
            },
          }
        : {}),
    };
  if (tool === "crafting") {
    const [items, detail, snapshot] = await Promise.all([
      filters.q ? catalog("targets", "", filters.q) : [],
      filters.itemId
        ? catalog("detail", `${filters.itemKind}:${filters.itemId}`)
        : null,
      catalog("metadata"),
    ]);
    let inventory = null;
    if (detail && pageState.props.bitcraft.player) {
      try {
        const player = await lookupPlayer(
          pageState.props.bitcraft.player.entityId,
        );
        const response = await trackerInventory(player, filters);
        inventory = {
          username: player.username,
          quantities: inventoryQuantities(response.data),
          refresh: response.refresh,
          error: response.error,
        };
      } catch (error) {
        inventory = { quantities: {}, error: error.message };
      }
    }
    return {
      filters,
      items,
      detail,
      snapshot,
      inventory,
      error:
        filters.itemId && !detail
          ? "That item is not available in the current catalog."
          : null,
    };
  }
  if (tool === "open-crafts") {
    try {
      let [response, skills] = await Promise.all([
        live("crafts"),
        catalog("skills"),
      ]);
      let player = null,
        levels = [],
        playerError = null;
      if (pageState.props.bitcraft.player)
        try {
          player = await lookupPlayer(pageState.props.bitcraft.player.entityId);
          const result = await live("levels");
          levels = Array.isArray(result.data)
            ? result.data
            : (result.data.levels ?? []);
        } catch (error) {
          playerError = error.message;
        }
      if (!player)
        for (const flag of ["levelUps", "meetsLevel", "mine"])
          filters[flag] = false;
      if (player) {
        try {
          const relay = await live("relayCrafts", player.entityId);
          if (!relay.refresh.delayed) {
            const claimIds = [
              ...new Set(
                (relay.data.crafts ?? [])
                  .map((job) => job.claim_entity_id)
                  .filter(Boolean),
              ),
            ];
            const claims = Object.fromEntries(
              await Promise.all(
                claimIds.map(async (id) => [
                  id,
                  await live("relayClaim", String(id))
                    .then((result) => result.data)
                    .catch(() => ({})),
                ]),
              ),
            );
            const raw = relayCrafts(relay.data, claims, false);
            const existing = new Map(
              (response.data.craftResults ?? []).map((job) => [
                String(job.entityId),
                job,
              ]),
            );
            const jobs = await Promise.all(
              raw.craftResults.map(async (job) => {
                const previous = existing.get(job.entityId) ?? {};
                let recipe = previous;
                if (!recipe.experiencePerProgress) {
                  const output = job.craftedItem[0];
                  if (output) {
                    const detail = await live(
                      [1, "1", "Cargo", "cargo"].includes(output.item_type)
                        ? "cargoItem"
                        : "item",
                      String(output.item_id),
                    )
                      .then((result) => result.data)
                      .catch(() => null);
                    recipe =
                      detail?.craftingRecipes?.find(
                        (row) => String(row.id) === String(job.recipeId),
                      ) ?? {};
                  }
                }
                const defined = Object.fromEntries(
                  Object.entries(job).filter(
                    ([, value]) => value !== undefined,
                  ),
                );
                return {
                  ...previous,
                  ...defined,
                  experiencePerProgress: recipe?.experiencePerProgress,
                  levelRequirements: recipe?.levelRequirements ?? [],
                  toolRequirements: recipe?.toolRequirements ?? [],
                };
              }),
            );
            const known = new Set(jobs.map((job) => job.entityId));
            const enriched = await enrichTrackerItems({ craftResults: jobs });
            response = {
              ...response,
              data: {
                ...response.data,
                craftResults: [
                  ...(response.data.craftResults ?? []).filter(
                    (job) =>
                      !known.has(String(job.entityId)) &&
                      String(job.ownerEntityId) !== String(player.entityId),
                  ),
                  ...jobs,
                ],
                items: [
                  ...(response.data.items ?? []),
                  ...Object.values(enriched.items ?? {}),
                ],
                cargos: [
                  ...(response.data.cargos ?? []),
                  ...Object.values(enriched.cargos ?? {}),
                ],
              },
            };
          }
        } catch {
          /* Preserve the public craft directory during recovery. */
        }
      }
      return {
        filters,
        ...filterCrafts(
          openCraftRows(response.data, player, levels, skills),
          filters,
          player,
        ),
        refresh: response.refresh,
        error: response.error,
        playerError,
        playerRefresh: {},
      };
    } catch (error) {
      return {
        filters,
        crafts: [],
        pagination: { page: 1, lastPage: 1, total: 0, all: 0 },
        skillOptions: [],
        regionOptions: [],
        refresh: { delayed: Boolean(error.retryAfter) },
        error: error.message,
        playerError: null,
        playerRefresh: {},
      };
    }
  }
  if (tool === "guides") {
    const conn = await connectBitcraft();
    const canManage = await conn.procedures.guideAccess({});
    if (editing && !canManage)
      throw new Error(
        `Guide editing needs a local administrator grant. Run: npm run bitcraft:admin -- ${conn.identity.toHexString()} Admin`,
      );
    if (editing && !id) return { guide: null };
    const rows = await new Promise((resolve, reject) => {
      let subscription;
      subscription = conn
        .subscriptionBuilder()
        .onApplied(() => {
          const rows = [
            ...(canManage
              ? conn.db.editableGuides
              : conn.db.publishedGuides
            ).iter(),
          ].map((row) => ({
            ...row,
            metadata: [...conn.db.visibleGuideMetadata.iter()].find(
              (meta) => meta.id === row.id,
            ),
          }));
          queueMicrotask(() => subscription.unsubscribe());
          resolve(rows);
        })
        .onError((_ctx, error) => reject(error))
        .subscribe([
          canManage
            ? "SELECT * FROM editable_guides"
            : "SELECT * FROM published_guides",
          "SELECT * FROM visible_guide_metadata",
        ]);
    });
    const guides = rows
      .map((row) => ({
        ...row,
        author: { name: row.metadata?.authorName ?? "Admin" },
        published_at: row.published
          ? row.metadata?.publishedAt
            ? new Date(Number(row.metadata.publishedAt / 1000n)).toISOString()
            : null
          : null,
        updated_at: row.metadata?.updatedAt
          ? new Date(Number(row.metadata.updatedAt / 1000n)).toISOString()
          : null,
        id: String(row.id),
        content: JSON.parse(row.content),
      }))
      .sort((a, b) =>
        String(b.updated_at ?? "").localeCompare(String(a.updated_at ?? "")),
      );
    if (id) {
      const guide = guides.find((guide) => guide.id === String(id));
      if (!guide) throw new Error("This guide is unavailable.");
      return { guide, canManage };
    }
    const filtered = guides.filter((guide) =>
      [guide.title, guide.summary, guide.category]
        .join(" ")
        .toLowerCase()
        .includes(filters.q.toLowerCase()),
    );
    const page = Math.max(
      1,
      Math.min(
        Number(filters.page) || 1,
        Math.max(1, Math.ceil(filtered.length / 12)),
      ),
    );
    return {
      filters,
      guides: {
        data: filtered.slice((page - 1) * 12, page * 12),
        total: filtered.length,
        current_page: page,
        last_page: Math.max(1, Math.ceil(filtered.length / 12)),
        prev_page_url:
          page > 1
            ? route("bitcraft.guides.index", { q: filters.q, page: page - 1 })
            : null,
        next_page_url:
          page * 12 < filtered.length
            ? route("bitcraft.guides.index", { q: filters.q, page: page + 1 })
            : null,
      },
      canManage,
    };
  }
  throw new Error("This BitCraft tool is unavailable.");
}
export async function bitcraftFetch(input, options = {}) {
  const url = new URL(input, location.origin),
    filters = Object.fromEntries(url.searchParams);
  if (options.signal?.aborted) throw new DOMException("Aborted", "AbortError");
  try {
    let data;
    if (url.pathname === "/bitcraft/players/search")
      data = {
        players:
          (await live("players", "", filters.q ?? "")).data.players ?? [],
      };
    else if (url.pathname === "/bitcraft/market/order-book")
      data = await orderBook(filters, { history: filters.history !== "0" });
    else if (url.pathname === "/bitcraft/barter-stalls/listings") {
      const result = await marketPage("barter-stalls", {
        ...filters,
        hasOrders: false,
      });
      data = {
        item: result.market.items.find(
          (item) => String(item.id) === String(filters.itemId),
        ),
        listings: result.market.listings,
        cache: result.cache,
        refresh: result.refresh,
        error: result.error,
      };
    } else if (url.pathname === "/bitcraft/crafting/branch") {
      const detail = await catalog(
        "branch",
        `${filters.itemKind ?? "item"}:${filters.itemId}`,
      );
      data = { recipes: detail?.recipeTree ?? [], item: detail?.item };
    } else if (url.pathname.endsWith("/snapshot")) {
      const tool = url.pathname.split("/")[2];
      data = await trackerSnapshot(tool, filters);
    } else if (url.pathname === "/bitcraft/guides/items") {
      data = {
        items: await catalog("items", "", filters.q ?? ""),
        available: true,
      };
    } else if (url.pathname === "/bitcraft/guides/card-options") {
      const items =
        filters.activity === "gathering"
          ? (await catalog("entries")).entries
              .filter((row) =>
                row.name
                  .toLowerCase()
                  .includes((filters.q ?? "").toLowerCase()),
              )
              .slice(0, 60)
          : await catalog("targets", "", filters.q ?? "");
      data = { items, available: true };
    } else if (url.pathname === "/bitcraft/guides/card-data") {
      data =
        filters.activity === "gathering"
          ? await catalog("card", filters.recipeId ?? "", "gathering")
          : await catalog(
              "card-item",
              `${filters.kind ?? "item"}:${filters.itemId}`,
              filters.recipeId ?? "",
            );
      if (!data)
        return Response.json(
          { error: "This recipe is unavailable." },
          { status: 404 },
        );
    } else throw new Error("This data request is unavailable.");
    if (options.signal?.aborted)
      throw new DOMException("Aborted", "AbortError");
    return Response.json(data);
  } catch (error) {
    if (options.signal?.aborted)
      throw new DOMException("Aborted", "AbortError");
    return Response.json(
      {
        error: error.message,
        refresh: {
          delayed: Boolean(error.retryAfter),
          retryAfter: error.retryAfter ?? 0,
        },
      },
      {
        status: error.retryAfter ? 429 : 502,
        headers: error.retryAfter
          ? { "Retry-After": String(error.retryAfter) }
          : {},
      },
    );
  }
}
