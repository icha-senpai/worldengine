<script setup lang="ts">
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
let disposed = false,
  request = 0,
  detailRequest = 0;
let poll: ReturnType<typeof setInterval>,
  debounce: ReturnType<typeof setTimeout>;
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
async function load(background = false) {
  if (loading.value && background) return;
  const current = ++request;
  loading.value = true;
  try {
    const conn = await connectBitcraft();
    const result = JSON.parse(
      await conn.procedures.readBossBoard({
        enemyType: boss.value,
        query: query.value.trim(),
        sort: sort.value,
        page: page.value,
        limit: 50,
      }),
    );
    if (disposed || current !== request) return;
    board.value = result;
    page.value = result.page;
    error.value = "";
    updated.value = new Date().toLocaleTimeString();
  } catch (e: any) {
    if (!disposed && current === request)
      error.value = e.message || "Boss records are unavailable.";
  } finally {
    if (current === request) loading.value = false;
  }
}
async function open(key: string) {
  selected.value = key;
  encounter.value = null;
  detailError.value = "";
  detailLoading.value = true;
  const current = ++detailRequest;
  try {
    const conn = await connectBitcraft();
    const data = JSON.parse(await conn.procedures.readBossDefeat({ key }));
    if (disposed || current !== detailRequest) return;
    encounter.value = data;
    if (!data)
      detailError.value =
        "This encounter is outside the retained history window.";
  } catch (e: any) {
    if (!disposed && current === detailRequest)
      detailError.value = e.message || "Encounter unavailable.";
  } finally {
    if (current === detailRequest) detailLoading.value = false;
  }
}
function close() {
  selected.value = "";
  detailRequest++;
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
function visibleRefresh() {
  if (!document.hidden) void load(true);
}
onMounted(() => {
  void load();
  poll = setInterval(visibleRefresh, 10000);
  document.addEventListener("visibilitychange", visibleRefresh);
});
onBeforeUnmount(() => {
  disposed = true;
  request++;
  detailRequest++;
  clearInterval(poll);
  clearTimeout(debounce);
  document.removeEventListener("visibilitychange", visibleRefresh);
});
</script>

<template>
  <div class="boss-board">
    <header class="boss-heading">
      <div>
        <p class="eyebrow">BitCraft · Combat records</p>
        <h1>Boss Leaderboard</h1>
        <p class="muted">
          Confirmed boss defeats and the players who contributed when they died.
        </p>
      </div>
      <button class="button quiet" :disabled="loading" @click="load()">
        {{ loading ? "Refreshing…" : "Refresh" }}
      </button>
    </header>
    <p v-if="error" class="notice error" role="alert">
      {{ error }} Showing the last successful results.
    </p>
    <div class="boss-stats">
      <div>
        <span>Recorded defeats</span
        ><strong>{{ number(board?.totals.defeats) }}</strong>
      </div>
      <div>
        <span>Contributors</span
        ><strong>{{ number(board?.totals.players) }}</strong>
      </div>
      <div>
        <span>Regions recording</span
        ><strong>{{ recording }} / {{ regions.length }}</strong>
      </div>
      <div>
        <span>Tracking began</span
        ><strong class="stat-date">{{
          date(board?.tracking.startedAt)
        }}</strong>
      </div>
    </div>
    <p class="notice">
      Rankings count defeats you contributed to, starting when our recorder
      began. Final blows are credited only when the fatal attack is observed in
      the death transaction. Missing final blows remain unknown.
    </p>
    <section class="boss-panel" aria-labelledby="rankings-title">
      <div class="panel-title">
        <h2 id="rankings-title">Rankings</h2>
        <span class="muted">{{
          updated
            ? `Updated ${updated} · every 10s while visible`
            : "Connecting…"
        }}</span>
      </div>
      <div class="boss-filters">
        <label
          >Boss<select v-model.number="boss">
            <option :value="0">All bosses</option>
            <option v-for="(b, id) in BOSSES" :key="id" :value="Number(id)">
              {{ b.name }} · T{{ b.tier }}
            </option>
          </select></label
        >
        <label
          >Rank by<select v-model="sort">
            <option value="defeats">Defeats contributed to</option>
            <option value="finalBlows">Confirmed final blows</option>
            <option value="contribution">Contribution</option>
          </select></label
        >
        <label class="search"
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
    <section class="boss-panel" aria-labelledby="recent-title">
      <div class="panel-title">
        <h2 id="recent-title">Recent defeats</h2>
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
    <details class="boss-panel coverage">
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
          <h2>
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
  max-width: 1500px;
  margin: 0 auto;
  padding: 30px;
  color: #e2e8f0;
}
.boss-heading,
.panel-title,
.pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}
h1 {
  font-size: 30px;
  font-weight: 700;
  margin: 4px 0 8px;
}
h2 {
  font-size: 19px;
  font-weight: 650;
}
.eyebrow {
  color: #a5b4fc;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 11px;
}
.muted {
  color: #94a3b8;
  font-size: 13px;
}
.notice {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 14px 17px;
  margin: 20px 0;
  font-size: 13px;
  line-height: 1.6;
}
.error {
  border-color: #f87171;
  color: #fecaca;
}
.boss-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 24px;
}
.boss-stats > div,
.boss-panel {
  background: #111827;
  border: 1px solid #293548;
  border-radius: 12px;
}
.boss-stats > div {
  padding: 18px;
}
.boss-stats span {
  display: block;
  color: #94a3b8;
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
  padding: 20px;
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
  font-size: 12px;
  color: #cbd5e1;
}
label.search {
  flex: 1;
  min-width: 190px;
}
select,
input {
  background: #0b1220;
  border: 1px solid #475569;
  border-radius: 7px;
  color: #e2e8f0;
  padding: 9px 12px;
  font-size: 13px;
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
  color: #94a3b8;
  font-weight: 500;
  font-size: 12px;
}
th,
td {
  padding: 13px 12px;
  border-bottom: 1px solid #293548;
  white-space: nowrap;
}
td.strong {
  font-weight: 700;
  color: #c7d2fe;
}
a {
  color: #c7d2fe;
}
a:hover {
  text-decoration: underline;
}
.empty {
  text-align: center;
  padding: 34px 16px;
  white-space: normal;
  color: #94a3b8;
}
.pagination {
  margin-top: 16px;
}
.pagination > div {
  display: flex;
  gap: 8px;
}
.button {
  border: 1px solid #475569;
  border-radius: 7px;
  padding: 8px 12px;
  color: #e2e8f0;
  font-size: 12px;
  cursor: pointer;
  background: #1e293b;
}
.button:hover {
  background: #334155;
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
  border-top: 1px solid #293548;
}
.status-dot {
  width: 7px;
  height: 7px;
  background: #f59e0b;
  border-radius: 50%;
}
.status-dot.ready {
  background: #4ade80;
}
.coverage-error {
  flex-basis: 100%;
  color: #fbbf24;
}
.encounter {
  padding: 24px;
  background: #111827;
  color: #e2e8f0;
}
.encounter p {
  margin: 12px 0;
  overflow-wrap: anywhere;
}
@media (max-width: 750px) {
  .boss-board {
    padding: 18px 12px;
  }
  .boss-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .boss-panel {
    padding: 14px;
  }
  .boss-filters label {
    flex: 1 1 100%;
    min-width: 0;
  }
  select,
  input {
    min-width: 0;
    width: 100%;
  }
  .pagination {
    flex-wrap: wrap;
  }
  h1 {
    font-size: 25px;
  }
}
</style>
