<script setup lang="ts">
import { useRetainedRead } from "../bitcraft-ui/useRetainedRead";
import { createTrackerPoller } from "../bitcraft-ui/trackerPolling";
import BitcraftPageHeader from "../bitcraft-ui/Components/BitcraftPageHeader.vue";
import SelectInput from "../bitcraft-ui/shared/SelectInput.vue";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { connectBitcraft } from "../bitcraft";
import { BOSSES } from "../../bitcraft/src/bosses";
import { CURRENT_REGIONS } from "../../bitcraft/src/relay-trading";
import Modal from "../bitcraft-ui/shared/Modal.vue";

const boss = ref(0),
  query = ref(""),
  sort = ref("defeats"),
  page = ref(1);
const board = ref<any>(null),
  encounter = ref<any>(null),
  selected = ref("");
const loading = ref(false),
  error = ref(""),
  detailError = ref("");
const updated = ref(""),
  detailLoading = ref(false);
let debounce: ReturnType<typeof setTimeout>;
const number = (value: any) =>
  new Intl.NumberFormat().format(Number(value ?? 0));
const date = (value: any) =>
  value && value !== "0"
    ? new Date(Number(BigInt(value) / 1000n)).toLocaleString()
    : "—";
const bossName = (id: number) => BOSSES[id]?.name ?? "All bosses";
const regions = computed(() =>
  Object.keys(CURRENT_REGIONS)
    .map(Number)
    .map(
      (regionId) =>
        board.value?.coverage.find((r: any) => r.regionId === regionId) ?? {
          regionId,
          ready: false,
          gaps: 0,
        },
    ),
);
const recording = computed(() => regions.value.filter((r) => r.ready).length);
const participants = computed(() =>
  [...(encounter.value?.participants ?? [])].sort(
    (a, b) => b.contribution - a.contribution,
  ),
);
const boardRead = useRetainedRead({
  loading,
  error,
  read: async () => {
    const conn = await connectBitcraft();
    return JSON.parse(
      await conn.procedures.readBossBoard({
        enemyType: boss.value,
        query: query.value.trim(),
        sort: sort.value,
        page: page.value,
        limit: 50,
      }),
    );
  },
  apply: (result: any) => {
    board.value = result;
    page.value = result.page;
    updated.value = new Date().toLocaleTimeString();
  },
  fallback: "Boss records are unavailable.",
});
const detailRead = useRetainedRead({
  loading: detailLoading,
  error: detailError,
  canRead: () => Boolean(selected.value),
  read: async (key: string) => {
    const conn = await connectBitcraft();
    return JSON.parse(await conn.procedures.readBossDefeat({ key }));
  },
  apply: (data: any) => {
    encounter.value = data;
    if (!data)
      detailError.value =
        "This encounter is outside the retained history window.";
  },
  fallback: "Encounter unavailable.",
});
async function load(background = false) {
  if (loading.value && background) return;
  await boardRead.run();
}
async function open(key: string) {
  selected.value = key;
  encounter.value = null;
  await detailRead.run(key);
}
function close() {
  selected.value = "";
  detailRead.invalidate();
}
function changePage(next: number) {
  page.value = next;
  void load();
}
watch([boss, sort], () => {
  page.value = 1;
  void load();
});
watch(query, () => {
  clearTimeout(debounce);
  debounce = setTimeout(() => {
    page.value = 1;
    void load();
  }, 300);
});
const polling = createTrackerPoller(() => load(true), 10000);
onMounted(() => {
  void load();
  polling.start();
});
onBeforeUnmount(() => {
  polling.stop();
  clearTimeout(debounce);
});
</script>

<template>
  <div class="boss-board">
    <BitcraftPageHeader
      title="Boss Leaderboard"
      eyebrow="BitCraft · Combat records"
      description="Confirmed boss defeats and the players who contributed when they died."
    >
      <template #actions>
        <button class="button quiet" :disabled="loading" @click="load()">
          {{ loading ? "Refreshing…" : "Refresh" }}
        </button>
      </template>
    </BitcraftPageHeader>
    <p v-if="error" class="notice error ui-notice" role="alert">
      {{ error }} Showing the last successful results.
    </p>
    <div class="boss-stats">
      <div class="ui-panel">
        <span>Recorded defeats</span
        ><strong>{{ number(board?.totals.defeats) }}</strong>
      </div>
      <div class="ui-panel">
        <span>Contributors</span
        ><strong>{{ number(board?.totals.players) }}</strong>
      </div>
      <div class="ui-panel">
        <span>Regions recording</span
        ><strong>{{ recording }} / {{ regions.length }}</strong>
      </div>
      <div class="ui-panel">
        <span>Tracking began</span
        ><strong class="stat-date">{{
          date(board?.tracking.startedAt)
        }}</strong>
      </div>
    </div>
    <p class="notice ui-notice">
      Rankings count defeats you contributed to, starting when our recorder
      began. Final blows are credited only when the fatal attack is observed in
      the death transaction. Missing final blows remain unknown.
    </p>
    <section class="boss-panel ui-panel" aria-labelledby="rankings-title">
      <div class="panel-title">
        <h2 class="ui-section-title" id="rankings-title">Rankings</h2>
        <span class="muted">{{
          updated
            ? `Updated ${updated} · every 10s while visible`
            : "Connecting…"
        }}</span>
      </div>
      <div class="boss-filters">
        <label class="ui-field-label"
          >Boss<SelectInput v-model.number="boss">
            <option :value="0">All bosses</option>
            <option v-for="(b, id) in BOSSES" :key="id" :value="Number(id)">
              {{ b.name }} · T{{ b.tier }}
            </option>
          </SelectInput></label
        >
        <label class="ui-field-label"
          >Rank by<SelectInput v-model="sort">
            <option value="defeats">Defeats contributed to</option>
            <option value="finalBlows">Confirmed final blows</option>
            <option value="contribution">Contribution</option>
          </SelectInput></label
        >
        <label class="search ui-field-label"
          >Player<input
            v-model="query"
            placeholder="Search name or player ID"
            type="search"
        /></label>
      </div>
      <div class="table-scroll">
        <table v-if="board?.rows.length">
          <thead>
            <tr>
              <th>Rank</th>
              <th>Player</th>
              <th>Defeats contributed to</th>
              <th>Final blows</th>
              <th>Contribution</th>
              <th>Last defeat</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in board?.rows ?? []" :key="row.key">
              <td>{{ row.rank }}</td>
              <td>
                <a
                  :href="`https://bitjita.com/players/${row.playerId}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  >{{ row.name }}</a
                >
              </td>
              <td class="strong">{{ number(row.defeats) }}</td>
              <td>{{ number(row.finalBlows) }}</td>
              <td>{{ number(Math.round(row.contribution)) }}</td>
              <td>{{ date(row.lastDefeatAt) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="empty">
          {{
            loading
              ? "Loading boss records…"
              : query
                ? "No matching contributors."
                : "No confirmed boss defeats recorded yet. The recorder runs even when this page is closed."
          }}
        </p>
      </div>
      <div class="pagination">
        <span class="muted"
          >{{ number(board?.total) }}
          {{ board?.total === 1 ? "player" : "players" }} · Page {{ page }} of
          {{ board?.pages ?? 1 }}</span
        >
        <div>
          <button
            class="button quiet"
            :disabled="loading || page <= 1"
            @click="changePage(page - 1)"
          >
            Previous</button
          ><button
            class="button quiet"
            :disabled="loading || page >= (board?.pages ?? 1)"
            @click="changePage(page + 1)"
          >
            Next
          </button>
        </div>
      </div>
      <p class="muted explanation">
        Contribution is the game’s combat credit, not raw damage or a
        percentage. The same defeat can credit several players.
      </p>
    </section>
    <section class="boss-panel ui-panel" aria-labelledby="recent-title">
      <div class="panel-title">
        <h2 class="ui-section-title" id="recent-title">Recent defeats</h2>
        <span class="muted">{{ bossName(boss) }} · latest 25</span>
      </div>
      <div class="table-scroll">
        <table v-if="board?.recent.length">
          <thead>
            <tr>
              <th>Boss</th>
              <th>Region</th>
              <th>Time of death</th>
              <th>Contributors</th>
              <th>Final blow</th>
              <th><span class="sr-only">Details</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in board?.recent ?? []" :key="row.key">
              <td>{{ bossName(row.enemyType) }}</td>
              <td>{{ CURRENT_REGIONS[row.regionId] }} (R{{ row.regionId }})</td>
              <td>{{ date(row.diedAt) }}</td>
              <td>{{ row.participants }}</td>
              <td>{{ row.killerName || "Unknown" }}</td>
              <td>
                <button class="button quiet" @click="open(row.key)">
                  View encounter
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="empty">No encounters in the retained history yet.</p>
      </div>
      <p class="muted explanation">
        Encounter details are retained for up to
        {{ board?.detailDays ?? 30 }} days within a bounded history budget.
        Leaderboard totals survive detail expiry.
      </p>
    </section>
    <details class="boss-panel coverage ui-panel">
      <summary>
        Recording coverage · {{ recording }} of {{ regions.length }} regions
        connected
      </summary>
      <p class="muted">
        Connection gaps can miss defeats. Existing bodies on reconnect are
        excluded from new counts.
      </p>
      <ul>
        <li v-for="r in regions" :key="r.regionId">
          <span :class="['status-dot', { ready: r.ready }]" /><strong
            >{{ CURRENT_REGIONS[r.regionId] }} (R{{ r.regionId }})</strong
          ><span>{{
            r.ready
              ? "Recording"
              : r.startedAt
                ? "Recording paused"
                : "Waiting for recorder"
          }}</span
          ><span class="muted"
            >{{ r.gaps }} gaps · Last contact {{ date(r.heartbeatAt) }}</span
          ><span v-if="r.error" class="coverage-error">{{ r.error }}</span>
        </li>
      </ul>
    </details>
    <Modal :show="Boolean(selected)" max-width="3xl" @close="close">
      <div class="encounter">
        <div class="panel-title">
          <h2 class="ui-dialog-title">
            {{ encounter ? bossName(encounter.enemyType) : "Boss encounter" }}
          </h2>
          <button class="button quiet" @click="close">Close</button>
        </div>
        <p v-if="detailLoading" class="muted">Loading encounter…</p>
        <p v-if="detailError" role="alert">{{ detailError }}</p>
        <template v-if="encounter"
          ><p class="muted">
            {{ CURRENT_REGIONS[encounter.regionId] }} ·
            {{ date(encounter.deathAtMicros) }}
          </p>
          <p class="muted">Boss instance {{ encounter.enemyId }}</p>
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Contributor at death</th>
                  <th>Contribution</th>
                  <th>Final blow</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in participants" :key="p.id">
                  <td>
                    <a
                      :href="`https://bitjita.com/players/${p.id}`"
                      target="_blank"
                      rel="noopener noreferrer"
                      >{{ p.name }}</a
                    >
                  </td>
                  <td>{{ number(Math.round(p.contribution)) }}</td>
                  <td>{{ encounter.killerId === p.id ? "Confirmed" : "—" }}</td>
                </tr>
                <tr v-if="!participants.length">
                  <td colspan="3">
                    No player contributions were available for this defeat.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!encounter.killerId" class="muted">
            The final blow was not available in the death transaction.
          </p>
        </template>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.boss-board {
  width: 100%;
  color: var(--text-primary);
}
.panel-title,
.pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}
.muted {
  color: var(--text-muted);
  font-size: 13px;
}
.notice {
  margin: 20px 0;
  font-size: 13px;
  line-height: 1.6;
}
.error {
  border-color: var(--danger-3);
  color: var(--danger-2);
}
.boss-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 24px;
}
.boss-stats span {
  display: block;
  color: var(--text-muted);
  font-size: 12px;
}
.boss-stats strong {
  display: block;
  font-size: 27px;
  margin-top: 8px;
}
.boss-stats .stat-date {
  font-size: 15px;
  line-height: 1.5;
}
.boss-panel {
  margin: 20px 0;
  min-width: 0;
}
.panel-title {
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.boss-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 20px;
}
label {
  display: flex;
  flex-direction: column;
  gap: 7px;
}
label.search {
  flex: 1;
  min-width: 190px;
}
input {
  background: var(--bg-canvas-2);
  border: 1px solid var(--border-color-2);
  border-radius: 7px;
  color: var(--text-primary);
  padding: 9px 12px;
  font-size: 13px;
  min-width: 180px;
}
.app-select {
  min-width: 180px;
}
.table-scroll {
  position: relative;
  overflow-x: auto;
}
table {
  width: 100%;
  text-align: left;
  border-collapse: collapse;
  font-size: 13px;
}
th {
  color: var(--text-muted);
  font-weight: 500;
  font-size: 12px;
}
th,
td {
  padding: 13px 12px;
  border-bottom: 1px solid var(--border-color);
  white-space: nowrap;
}
td.strong {
  font-weight: 700;
  color: var(--accent-cyan-2);
}
a {
  color: var(--accent-cyan-2);
}
a:hover {
  text-decoration: underline;
}
.empty {
  text-align: center;
  padding: 34px 16px;
  white-space: normal;
  color: var(--text-muted);
}
.pagination {
  margin-top: 16px;
}
.pagination > div {
  display: flex;
  gap: 8px;
}
.button {
  border: 1px solid var(--border-color-2);
  border-radius: 7px;
  padding: 8px 12px;
  color: var(--text-primary);
  font-size: 12px;
  cursor: pointer;
  background: var(--bg-surface);
}
.button:hover {
  background: var(--control-hover);
}
.button:disabled {
  opacity: 0.4;
  cursor: default;
}
.explanation {
  margin-top: 15px;
  line-height: 1.5;
}
.coverage summary {
  cursor: pointer;
  font-size: 14px;
}
.coverage p {
  margin: 14px 0;
}
.coverage ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.coverage li {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 12px 0;
  font-size: 12px;
  border-top: 1px solid var(--border-color);
}
.status-dot {
  width: 7px;
  height: 7px;
  background: var(--warning);
  border-radius: 50%;
}
.status-dot.ready {
  background: var(--success);
}
.coverage-error {
  flex-basis: 100%;
  color: var(--warning);
}
.encounter {
  padding: 24px;
  background: var(--bg-surface);
  color: var(--text-primary);
}
.encounter p {
  margin: 12px 0;
  overflow-wrap: anywhere;
}
@media (max-width: 750px) {
  .boss-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .boss-filters label {
    flex: 1 1 100%;
    min-width: 0;
  }
  .app-select,
  input {
    min-width: 0;
    width: 100%;
  }
  .pagination {
    flex-wrap: wrap;
  }
}
</style>
