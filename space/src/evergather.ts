import { shallowRef, ref, watch, type Ref } from "vue";
import { DbConnection } from "./bindings/evergather";
import { user, localPlay } from "./auth";
import { definitionTables, panelDefinitionQueries } from "./evergather/catalog";
import { createConnectionRecovery } from "./connectionRecovery";
import { recordPageLifecycle } from "./pageLifecycle";
import { spacetimeHost } from "./spacetimeHost";
export const evergather = shallowRef<DbConnection | null>(null);
export const evergatherConnected = ref(false);
export const evergatherError = ref("");
export const revision = ref(0);
export const definitionRevision = ref(0);
export const evergatherReady = ref(false);
export const evergatherPanelReady = ref(false);
export const evergatherReconnecting = ref(false);
export const gameWorkspace = ref("gather");
export const gamePanel = ref("actions");
export const marketPage = ref(1);
export function setEvergatherPanel(workspace: string, panel: string) {
  gameWorkspace.value = workspace;
  gamePanel.value = panel;
}
let initialized = false;
let active: DbConnection | null = null;
let generation = 0;
const host = spacetimeHost;
const database = import.meta.env.VITE_EVERGATHER_DATABASE || "space-evergather";
const localTokenKey = `space:evergather:local-token:${host}:${database}`;
export function initializeEvergather(enabled: Ref<boolean>) {
  if (initialized) return;
  initialized = true;
  let stopPanelWatch: (() => void) | undefined;
  let connectionPending = false;
  let retryInitial = false;
  const reconnectRevision = ref(0);
  const recovery = createConnectionRecovery(
    () => {
      reconnectRevision.value++;
    },
    () =>
      enabled.value &&
      retryInitial &&
      (localPlay || Boolean(user.value?.id_token)) &&
      !connectionPending &&
      !active?.isReconnecting,
  );
  const stopConnectionWatch = watch(
    [user, enabled, reconnectRevision],
    ([value, isEnabled]) => {
      recovery.cancel();
      stopPanelWatch?.();
      stopPanelWatch = undefined;
      const currentGeneration = ++generation;
      active?.disconnect();
      active = null;
      connectionPending = false;
      retryInitial = false;
      evergather.value = null;
      evergatherConnected.value = false;
      evergatherReady.value = false;
      evergatherPanelReady.value = false;
      revision.value++;
      definitionRevision.value++;
      if (!isEnabled || (!localPlay && !value?.id_token)) {
        recovery.reset();
        evergatherReconnecting.value = false;
        return;
      }
      let token = value?.id_token;
      if (localPlay) {
        try {
          token = localStorage.getItem(localTokenKey) ?? undefined;
        } catch {
          evergatherError.value =
            "Allow browser storage to save your local character.";
          return;
        }
      }
      connectionPending = true;
      retryInitial = true;
      let coreApplied = false,
        restoringScope = false;
      const synchronized = () => {
        if (currentGeneration !== generation || !coreApplied || restoringScope)
          return;
        evergatherReady.value = true;
        evergatherConnected.value = true;
        evergatherReconnecting.value = false;
        evergatherError.value = "";
        revision.value++;
      };
      const conn = DbConnection.builder()
        .withUri(host)
        .withDatabaseName(database)
        .withToken(token)
        .withAutomaticReconnect()
        .withTokenProvider(async () => {
          const next = localPlay
            ? localStorage.getItem(localTokenKey)
            : user.value?.id_token;
          if (!next) throw new Error("Sign in again to reconnect.");
          return next;
        })
        .onConnect((connection, _identity, issuedToken) => {
          if (currentGeneration !== generation) {
            connection.disconnect();
            return;
          }
          if (localPlay) {
            try {
              localStorage.setItem(localTokenKey, issuedToken);
            } catch {
              evergatherError.value =
                "Allow browser storage to save your local character.";
              connection.disconnect();
              return;
            }
          }
          evergather.value = connection;
          connectionPending = false;
          retryInitial = false;
          recovery.reset();
          recordPageLifecycle("evergather-connected");
          evergatherConnected.value = true;
          evergatherError.value = "";
          const changed = () => {
            revision.value++;
          };
          for (const name of [
            "topLeaderboard",
            "trade",
            "myPlayer",
            "mySkills",
            "myInventory",
            "myTools",
            "myResults",
            "myContracts",
            "myAchievements",
            "myBuffs",
          ] as const) {
            const table = connection.db[name];
            table.onInsert(changed);
            table.onUpdate(changed);
            table.onDelete(changed);
          }
          const definitionsChanged = () => {
            definitionRevision.value++;
            changed();
          };
          for (const name of definitionTables) {
            const table = connection.db[name];
            table.onInsert(definitionsChanged);
            table.onUpdate(definitionsChanged);
            table.onDelete(definitionsChanged);
          }
          const marketMetadata = new Map<string, string>();
          const definitionHasMetadata = (row: {
            itemKey: string;
            rarity: string;
            metadata: string;
          }) =>
            definitionTables.some(
              (name) =>
                connection.db[name].key.find(
                  `item_metadata:${row.itemKey}:${row.rarity}`,
                )?.payload === row.metadata,
            );
          const marketChanged = (
            _ctx: unknown,
            row: { itemKey: string; rarity: string; metadata: string },
          ) => {
            const key = `${row.itemKey}:${row.rarity}`;
            if (marketMetadata.get(key) !== row.metadata) {
              marketMetadata.set(key, row.metadata);
              if (!definitionHasMetadata(row)) definitionRevision.value++;
            }
            changed();
          };
          for (const name of [
            "marketPageListings",
            "myMarketListings",
          ] as const) {
            const table = connection.db[name];
            table.onInsert(marketChanged);
            table.onUpdate((ctx, _old, row) => marketChanged(ctx, row));
            table.onDelete((_ctx, row) => {
              const remaining = [
                ...connection.db.marketPageListings.iter(),
                ...connection.db.myMarketListings.iter(),
              ].some(
                (other) =>
                  other.itemKey === row.itemKey && other.rarity === row.rarity,
              );
              if (!remaining) {
                marketMetadata.delete(`${row.itemKey}:${row.rarity}`);
                if (!definitionHasMetadata(row)) definitionRevision.value++;
              }
              changed();
            });
          }
          const summaryChanged = () => {
            const summary = [...connection.db.marketSummary.iter()][0];
            if (summary && marketPage.value > summary.pages)
              marketPage.value = Math.max(1, summary.pages);
            changed();
          };
          connection.db.marketSummary.onInsert(summaryChanged);
          connection.db.marketSummary.onUpdate(summaryChanged);
          connection.db.marketSummary.onDelete(summaryChanged);
          let panelGeneration = 0;
          let panelScope = "";
          let panelSubscription: { unsubscribe(): void } | undefined;
          stopPanelWatch = watch(
            [
              gameWorkspace,
              gamePanel,
              marketPage,
              evergatherReady,
              evergatherConnected,
            ],
            async ([workspace, panel, page, ready, connected]) => {
              if (!ready || !connected) return;
              const scope = JSON.stringify([workspace, panel, page]);
              if (scope === panelScope) return;
              const requested = ++panelGeneration;
              evergatherPanelReady.value = false;
              evergatherError.value = "";
              panelSubscription?.unsubscribe();
              panelSubscription = undefined;
              try {
                await connection.reducers.setUiScope({
                  workspace,
                  panel,
                  page,
                });
                if (
                  requested !== panelGeneration ||
                  currentGeneration !== generation
                )
                  return;
                const queries = [
                  ...panelDefinitionQueries(workspace, panel),
                  ...(workspace === "trade" && panel === "marketplace"
                    ? [
                        `SELECT * FROM market_page_listings WHERE page = ${page}`,
                        "SELECT * FROM my_market_listings",
                        "SELECT * FROM market_summary",
                        "SELECT * FROM trade",
                      ]
                    : workspace === "progress" && panel === "leaderboards"
                      ? ["SELECT * FROM top_leaderboard"]
                      : []),
                ];
                if (!queries.length) {
                  panelScope = scope;
                  evergatherPanelReady.value = true;
                  changed();
                  return;
                }
                panelSubscription = connection
                  .subscriptionBuilder()
                  .onApplied(() => {
                    if (
                      requested === panelGeneration &&
                      currentGeneration === generation
                    ) {
                      panelScope = scope;
                      evergatherPanelReady.value = true;
                      changed();
                    }
                  })
                  .onError(() => {
                    if (
                      requested === panelGeneration &&
                      currentGeneration === generation
                    )
                      evergatherError.value = "This panel could not be loaded.";
                  })
                  .subscribe(queries);
              } catch {
                if (
                  requested === panelGeneration &&
                  currentGeneration === generation
                )
                  evergatherError.value = "This panel could not be loaded.";
              }
            },
          );
          connection
            .subscriptionBuilder()
            .onApplied(() => {
              if (currentGeneration === generation) {
                coreApplied = true;
                synchronized();
                changed();
              }
            })
            .onError(() => {
              if (currentGeneration === generation)
                evergatherError.value = "Your game data could not be loaded.";
            })
            .subscribe([
              "SELECT * FROM core_definitions",
              "SELECT * FROM my_reference_catalog",
              "SELECT * FROM my_player",
              "SELECT * FROM my_skills",
              "SELECT * FROM my_inventory",
              "SELECT * FROM my_tools",
              "SELECT * FROM my_results",
              "SELECT * FROM my_contracts",
              "SELECT * FROM my_achievements",
              "SELECT * FROM my_buffs",
            ]);
        })
        .onAutomaticReconnect((connection, _identity, issuedToken) => {
          if (currentGeneration !== generation) return;
          if (localPlay) localStorage.setItem(localTokenKey, issuedToken);
          coreApplied = false;
          restoringScope = true;
          recordPageLifecycle("evergather-reconnected");
          // UI scope belongs to the server ConnectionId. Restore it before the
          // SDK sends its subscription replay; this is an idempotent view setting.
          void connection.reducers
            .setUiScope({
              workspace: gameWorkspace.value,
              panel: gamePanel.value,
              page: marketPage.value,
            })
            .then(() => {
              restoringScope = false;
              synchronized();
            })
            .catch(() => {
              if (currentGeneration !== generation) return;
              restoringScope = false;
              evergatherReconnecting.value = false;
              evergatherError.value =
                "Your view could not be restored. Reconnect before taking an action.";
            });
        })
        .onConnectError((_ctx, _error, nextAttempt) => {
          if (currentGeneration === generation) {
            connectionPending = false;
            evergatherReconnecting.value =
              nextAttempt !== undefined || retryInitial;
            recordPageLifecycle("evergather-connect-failed");
            evergatherError.value = localPlay
              ? "Evergather could not connect. Check that the database is running and local play is enabled."
              : "Evergather could not connect. Please try signing in again.";
            if (retryInitial && nextAttempt === undefined) recovery.schedule();
          }
        })
        .onDisconnect((_ctx, _error, nextAttempt) => {
          if (currentGeneration === generation) {
            connectionPending = false;
            recordPageLifecycle("evergather-disconnected");
            evergatherConnected.value = false;
            coreApplied = false;
            evergatherReconnecting.value = nextAttempt !== undefined;
            if (nextAttempt === undefined)
              evergatherError.value =
                "Your connection closed. Refresh or sign in again before taking an action.";
          }
        })
        .build();
      active = conn;
    },
    { immediate: true },
  );
  import.meta.hot?.dispose(() => {
    recovery.stop();
    stopConnectionWatch();
    generation++;
    stopPanelWatch?.();
    active?.disconnect();
  });
}
