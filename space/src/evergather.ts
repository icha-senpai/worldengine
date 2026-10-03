import { shallowRef, ref, watch } from "vue";
import { DbConnection } from "./bindings/evergather";
import { user, localPlay } from "./auth";
export const evergather = shallowRef<DbConnection | null>(null);
export const evergatherConnected = ref(false);
export const evergatherError = ref("");
export const revision = ref(0);
export const evergatherReady = ref(false);
let active: DbConnection | null = null;
let generation = 0;
const host = import.meta.env.VITE_SPACETIMEDB_HOST || "ws://127.0.0.1:3100";
const database = import.meta.env.VITE_EVERGATHER_DATABASE || "space-evergather";
const localTokenKey = `space:evergather:local-token:${host}:${database}`;
export function initializeEvergather() {
  watch(
    user,
    (value) => {
      const currentGeneration = ++generation;
      active?.disconnect();
      active = null;
      evergather.value = null;
      evergatherConnected.value = false;
      evergatherReady.value = false;
      revision.value++;
      if (!localPlay && !value?.id_token) return;
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
      const conn = DbConnection.builder()
        .withUri(host)
        .withDatabaseName(database)
        .withToken(token)
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
          evergatherConnected.value = true;
          evergatherError.value = "";
          const changed = () => {
            revision.value++;
          };
          for (const name of [
            "catalog",
            "listing",
            "leaderboard",
            "trade",
            "myPlayer",
            "mySkills",
            "myInventory",
            "myTools",
            "myResults",
            "myContracts",
            "myAchievements",
          ] as const) {
            const table = connection.db[name];
            table.onInsert(changed);
            table.onUpdate(changed);
            table.onDelete(changed);
          }
          connection
            .subscriptionBuilder()
            .onApplied(() => {
              if (currentGeneration === generation) {
                evergatherReady.value = true;
                changed();
              }
            })
            .onError(() => {
              if (currentGeneration === generation)
                evergatherError.value = "Your game data could not be loaded.";
            })
            .subscribe([
              "SELECT * FROM catalog",
              "SELECT * FROM listing",
              "SELECT * FROM leaderboard",
              "SELECT * FROM trade",
              "SELECT * FROM my_player",
              "SELECT * FROM my_skills",
              "SELECT * FROM my_inventory",
              "SELECT * FROM my_tools",
              "SELECT * FROM my_results",
              "SELECT * FROM my_contracts",
              "SELECT * FROM my_achievements",
            ]);
        })
        .onConnectError(() => {
          if (currentGeneration === generation)
            evergatherError.value = localPlay
              ? "Evergather could not connect. Check that the database is running and local play is enabled."
              : "Evergather could not connect. Please try signing in again.";
        })
        .onDisconnect(() => {
          if (currentGeneration === generation) {
            evergatherConnected.value = false;
            evergatherReady.value = false;
            evergather.value = null;
            revision.value++;
          }
        })
        .build();
      active = conn;
    },
    { immediate: true },
  );
}
