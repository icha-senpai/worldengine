<script setup>
import { useRetainedRead } from "../bitcraft-ui/useRetainedRead";
import BitcraftPageHeader from "../bitcraft-ui/Components/BitcraftPageHeader.vue";
import SelectInput from "../bitcraft-ui/shared/SelectInput.vue";
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { connectBitcraft } from "../bitcraft";
import { CURRENT_REGIONS } from "../../bitcraft/src/relay-trading";
import Modal from "../bitcraft-ui/shared/Modal.vue";
import ClaimHistoryEvents from "../bitcraft-ui/Components/ClaimHistoryEvents.vue";
import { Star } from "@lucide/vue";
import { live } from "../bitcraft-ui/api";
import { useBitcraftReadRecovery } from "../bitcraft-ui/useBitcraftReadRecovery";
import {
  CLAIM_PERMISSIONS,
  permissionFlags,
  memberActivity,
  lastSeen,
} from "../bitcraft-ui/claim-members";
import {
  CLAIM_STAR_LIMIT,
  claimKey,
  claimSnapshot,
  starHasUpdates,
  readClaimStars,
} from "../bitcraft-ui/claim-stars";
import {
  date,
  number,
  remaining,
  timestamp,
} from "../bitcraft-ui/claim-history-display";
const route = useRoute(),
  router = useRouter();
const starStorageKey = `space.bitcraft.claim-stars:${import.meta.env.VITE_SPACETIMEDB_HOST || "local"}:${import.meta.env.VITE_BITCRAFT_DATABASE || "space-bitcraft-tools"}`;
const stars = ref(readClaimStars(window.localStorage, starStorageKey)),
  starStatus = ref([]),
  starError = ref(""),
  starLoadError = ref(""),
  starsLoading = ref(false),
  listView = ref("all");
let starRequest = 0;
const starred = (claim) =>
  stars.value.some((s) => claimKey(s) === claimKey(claim));
const statusFor = (claim) =>
  starStatus.value.find((s) => claimKey(s) === claimKey(claim));
const hasUpdates = (claim) => {
  const star = stars.value.find((s) => claimKey(s) === claimKey(claim));
  return star ? starHasUpdates(star, statusFor(claim)) : false;
};
const unreadStars = computed(() => stars.value.filter(hasUpdates).length);
function saveStars() {
  try {
    window.localStorage.setItem(starStorageKey, JSON.stringify(stars.value));
    starError.value = "";
  } catch {
    starError.value =
      "Your browser could not save this shortlist. Keep this page open or enable browser storage.";
  }
}
function toggleStar(claim) {
  if (starred(claim)) {
    stars.value = stars.value.filter((s) => claimKey(s) !== claimKey(claim));
    starStatus.value = starStatus.value.filter(
      (s) => claimKey(s) !== claimKey(claim),
    );
  } else {
    if (stars.value.length >= CLAIM_STAR_LIMIT) {
      starError.value = `You can star up to ${CLAIM_STAR_LIMIT} claims. Remove one to add another.`;
      return;
    }
    stars.value = [
      ...stars.value,
      { id: claim.id, regionId: claim.regionId, name: claim.name },
    ];
  }
  saveStars();
  void loadStars();
}
async function loadStars() {
  const request = ++starRequest;
  if (!stars.value.length) {
    starStatus.value = [];
    starsLoading.value = false;
    return;
  }
  starsLoading.value = true;
  try {
    const conn = await connectBitcraft();
    const data = JSON.parse(
      await conn.procedures.readStarredClaims({
        claims: JSON.stringify(
          stars.value.map(({ id, regionId }) => ({ id, regionId })),
        ),
      }),
    );
    if (disposed || request !== starRequest) return;
    starLoadError.value = "";
    starStatus.value = data.claims;
    let changed = false;
    stars.value = stars.value.map((star) => {
      const status = statusFor(star);
      if (!status) return star;
      const name = status.claim?.name ?? star.name;
      if (star.seenSnapshot == null) {
        changed = true;
        return {
          ...star,
          name,
          seenSnapshot: claimSnapshot(status.claim),
          seenActivityAt: status.lastActivityAt ?? "0",
        };
      }
      if (name !== star.name) {
        changed = true;
        return { ...star, name };
      }
      return star;
    });
    if (changed) saveStars();
  } catch (e) {
    if (!disposed && request === starRequest)
      starLoadError.value =
        e.message || "Your tracked shortlist is unavailable.";
  } finally {
    if (request === starRequest) starsLoading.value = false;
  }
}
function markStarViewed(claim, history) {
  const status = statusFor(claim),
    activity = history.events.filter((e) => e.claimId === claim.id)[0];
  stars.value = stars.value.map((star) => {
    if (claimKey(star) !== claimKey(claim)) return star;
    const at = [
      star.seenActivityAt ?? "0",
      status?.lastActivityAt ?? "0",
      activity?.observedAt ?? "0",
    ].reduce((a, b) => (BigInt(a) > BigInt(b) ? a : b));
    return {
      ...star,
      seenSnapshot: claimSnapshot(history.claim),
      seenActivityAt: at,
    };
  });
  if (status) {
    status.claim = history.claim;
    if (
      activity &&
      BigInt(activity.observedAt) > BigInt(status.lastActivityAt ?? "0")
    )
      status.lastActivityAt = activity.observedAt;
  }
  if (starred(claim)) saveStars();
}
const query = ref(String(route.query.q ?? "")),
  region = ref(Number(route.query.region) || 0),
  risk = ref(""),
  sort = ref("expiry"),
  page = ref(1);
const claims = ref([]),
  coverage = ref([]),
  total = ref(0),
  tracked = ref(0),
  pages = ref(1),
  loading = ref(false),
  error = ref(""),
  updated = ref("");
const showHistory = ref(false),
  selected = ref(null),
  currentClaim = ref(null),
  members = ref([]),
  group = ref("all"),
  events = ref([]),
  next = ref(""),
  historyLoading = ref(false),
  historyError = ref(""),
  moreLoaded = ref(false),
  tabButtons = ref([]);
const tabs = [
  ["all", "All history"],
  ["ownership", "Ownership"],
  ["members", "Members"],
  ["supplies", "Supplies"],
  ["structures", "Claim changes"],
  ["coverage", "Recording"],
];
const activity = ref({}),
  activityLoading = ref(false),
  activityError = ref(""),
  activityUpdated = ref(""),
  activityDelayed = ref(false),
  activityClock = ref(Date.now());
let activityRequest = 0,
  activityChecked = 0;
const roster = computed(() =>
  members.value
    .map((member) => ({
      ...member,
      flags: permissionFlags(member.permissions),
      lastSeen: lastSeen(activity.value[member.id], activityClock.value),
    }))
    .sort((a, b) => a.name.localeCompare(b.name)),
);
async function loadMemberActivity() {
  if (!showHistory.value || group.value !== "members" || !selected.value)
    return;
  const request = ++activityRequest,
    claim = selected.value;
  activityLoading.value = true;
  activityError.value = "";
  activityChecked = Date.now();
  try {
    const response = await live("relayClaimMembers", claim.id);
    if (disposed || request !== activityRequest || !showHistory.value) return;
    activity.value = memberActivity(response.data, claim.id);
    activityUpdated.value = response.refresh.updatedAt;
    activityDelayed.value = response.refresh.delayed;
  } catch (e) {
    if (!disposed && request === activityRequest)
      activityError.value =
        "Last seen is temporarily unavailable. Permissions and membership still use the recorded roster.";
  } finally {
    if (request === activityRequest) activityLoading.value = false;
  }
}
let debounce,
  interval,
  disposed = false;
const readyCount = computed(() => coverage.value.filter((r) => r.ready).length);
const dialogTitle = computed(
  () => selected.value?.name ?? "Recent tracked-claim history",
);
const urgent = (c) =>
  c.supplyStatus === "ready" &&
  (c.supplies <= 0 || (c.remainingHours !== null && c.remainingHours <= 24));
const shortlist = computed(() => {
  const q = query.value.trim().toLowerCase();
  const rows = stars.value
    .map((star) => {
      const status = statusFor(star);
      return status?.claim
        ? { ...status.claim, inactive: false }
        : {
            id: star.id,
            regionId: star.regionId,
            regionName: CURRENT_REGIONS[star.regionId],
            name: star.name,
            ownerName: "",
            ownerId: "",
            tier: null,
            memberCount: 0,
            supplies: null,
            upkeepPerHour: null,
            remainingHours: null,
            estimatedExpiresAt: null,
            supplyStatus: "unknown",
            inactive: !!status,
          };
    })
    .filter(
      (c) =>
        (!region.value || c.regionId === Number(region.value)) &&
        (!q ||
          [c.name, c.ownerName, c.id, c.ownerId].some((v) =>
            v.toLowerCase().includes(q),
          )) &&
        (!risk.value ||
          (risk.value === "empty" && c.supplies !== null && c.supplies <= 0) ||
          (risk.value === "stale" && c.supplyStatus === "stale") ||
          (risk.value === "unknown" &&
            (c.supplyStatus === "unknown" || c.upkeepPerHour === null)) ||
          (["soon", "week"].includes(risk.value) &&
            c.supplyStatus === "ready" &&
            (c.supplies <= 0 ||
              (c.remainingHours !== null &&
                c.remainingHours <= (risk.value === "soon" ? 24 : 168))))),
    );
  return rows.sort((a, b) => {
    if (sort.value === "name") return a.name.localeCompare(b.name);
    if (sort.value === "tier")
      return (b.tier ?? 0) - (a.tier ?? 0) || a.name.localeCompare(b.name);
    if (sort.value === "supplies")
      return (
        (a.supplies ?? Infinity) - (b.supplies ?? Infinity) ||
        a.name.localeCompare(b.name)
      );
    const rank = (c) =>
      c.supplyStatus !== "ready"
        ? 3
        : c.supplies <= 0
          ? 0
          : c.estimatedExpiresAt === null
            ? 2
            : 1;
    return (
      rank(a) - rank(b) ||
      (a.estimatedExpiresAt ?? Infinity) - (b.estimatedExpiresAt ?? Infinity) ||
      a.name.localeCompare(b.name)
    );
  });
});
const displayTotal = computed(() =>
    listView.value === "tracked" ? shortlist.value.length : total.value,
  ),
  displayPages = computed(() =>
    listView.value === "tracked"
      ? Math.max(1, Math.ceil(shortlist.value.length / 50))
      : pages.value,
  ),
  displayClaims = computed(() =>
    listView.value === "tracked"
      ? shortlist.value.slice((page.value - 1) * 50, page.value * 50)
      : claims.value,
  );
async function refresh() {
  await Promise.all([loadList(), loadStars()]);
}
let changeSubscription, changeConnection, changeTimer;
let subscribingChanges = false;
const changeRecovery = useBitcraftReadRecovery(
  subscribeChanges,
  () => !disposed && !subscribingChanges && !changeSubscription,
);
function closeChanges() {
  if (changeSubscription && !changeSubscription.isEnded())
    changeSubscription.unsubscribe();
  changeSubscription = undefined;
  changeConnection?.db.claimChanges.removeOnInsert(scheduleLiveRefresh);
  changeConnection?.db.claimChanges.removeOnUpdate(scheduleLiveRefresh);
  changeConnection?.db.claimChanges.removeOnDelete(scheduleLiveRefresh);
  changeConnection = undefined;
}
function scheduleLiveRefresh() {
  clearTimeout(changeTimer);
  changeTimer = setTimeout(() => {
    if (disposed) return;
    void refresh();
    if (showHistory.value) void loadHistory(false, false, true);
  }, 100);
}
async function subscribeChanges() {
  if (disposed || subscribingChanges || changeSubscription) return;
  subscribingChanges = true;
  try {
    const conn = await connectBitcraft();
    if (disposed) return;
    changeConnection = conn;
    conn.db.claimChanges.onInsert(scheduleLiveRefresh);
    conn.db.claimChanges.onUpdate(scheduleLiveRefresh);
    conn.db.claimChanges.onDelete(scheduleLiveRefresh);
    changeSubscription = conn
      .subscriptionBuilder()
      .onApplied(() => {
        if (disposed) return;
        changeRecovery.succeeded();
        scheduleLiveRefresh();
      })
      .onError(() => {
        closeChanges();
        if (!disposed) {
          error.value =
            "Live claim updates are delayed. Reconnecting automatically.";
          changeRecovery.failed();
        }
      })
      .subscribe(["SELECT * FROM claim_changes"]);
  } catch {
    closeChanges();
    if (!disposed) {
      error.value =
        "Live claim updates could not connect. Reconnecting automatically.";
      changeRecovery.failed();
    }
  } finally {
    subscribingChanges = false;
    changeRecovery.schedule();
  }
}
const listRead = useRetainedRead({
  loading,
  error,
  read: async () => {
    const conn = await connectBitcraft();
    return JSON.parse(
      await conn.procedures.readTrackedClaims({
        query: query.value.trim(),
        regionId: Number(region.value),
        risk: risk.value,
        sort: sort.value,
        page: page.value,
        limit: 50,
      }),
    );
  },
  apply: (data) => {
    claims.value = data.claims;
    coverage.value = data.coverage;
    total.value = data.total;
    tracked.value = data.trackedClaims;
    pages.value = data.pages;
    if (listView.value === "all") page.value = data.page;
    updated.value = new Date().toLocaleTimeString();
  },
  fallback: "Tracked claims are unavailable.",
});
async function loadList() {
  await listRead.run();
}
const historyRead = useRetainedRead({
  loading: historyLoading,
  error: historyError,
  canRead: () => showHistory.value,
  read: async (more, markRead, live, selection) => {
    const conn = await connectBitcraft();
    return JSON.parse(
      selection
        ? await conn.procedures.readTrackedClaimHistory({
            claimId: selection.id,
            regionId: selection.regionId,
            group: group.value,
            before: more ? next.value : "",
            limit: 100,
          })
        : await conn.procedures.readClaimHistory({
            query: "",
            regionId: Number(region.value),
            kind: group.value,
            before: more ? next.value : "",
            limit: 100,
          }),
    );
  },
  apply: (data, more, markRead, live, selection) => {
    if (live && moreLoaded.value) {
      const merged = new Map(
        [...events.value, ...data.events].map((event) => [event.key, event]),
      );
      events.value = [...merged.values()].sort((a, b) =>
        BigInt(a.observedAt) === BigInt(b.observedAt)
          ? b.key.localeCompare(a.key)
          : BigInt(a.observedAt) > BigInt(b.observedAt)
            ? -1
            : 1,
      );
    } else {
      events.value = more ? [...events.value, ...data.events] : data.events;
      next.value = data.next;
      moreLoaded.value = more;
    }
    currentClaim.value = data.claim ?? null;
    members.value = data.members ?? [];
    if (selection && group.value === "all" && !more && markRead)
      markStarViewed(selection, data);
  },
  fallback: "History is unavailable.",
});
async function loadHistory(more = false, markRead = false, live = false) {
  await historyRead.run(more, markRead, live, selected.value);
}
function openHistory(claim = null) {
  activityRequest++;
  activity.value = {};
  activityLoading.value = false;
  activityError.value = "";
  activityUpdated.value = "";
  activityDelayed.value = false;
  activityChecked = 0;
  selected.value = claim;
  currentClaim.value = claim && "supplies" in claim ? claim : null;
  members.value = [];
  events.value = [];
  next.value = "";
  group.value = "all";
  showHistory.value = true;
  void loadHistory(false, true);
}
function closeHistory() {
  activityRequest++;
  activityLoading.value = false;
  showHistory.value = false;
  historyRead.invalidate();
  historyLoading.value = false;
}
function inspectEvent(e) {
  openHistory({
    id: e.claimId,
    regionId: e.regionId,
    name: e.detail.claimName,
  });
}
function tabKey(event, index) {
  const keys = {
    ArrowRight: (index + 1) % tabs.length,
    ArrowLeft: (index + tabs.length - 1) % tabs.length,
    Home: 0,
    End: tabs.length - 1,
  };
  if (keys[event.key] === undefined) return;
  event.preventDefault();
  const nextIndex = keys[event.key];
  group.value = tabs[nextIndex][0];
  tabButtons.value[nextIndex]?.focus();
}
function changePage(value) {
  page.value = value;
  if (listView.value === "all") void loadList();
}
watch(listView, () => {
  page.value = 1;
  void refresh();
});
watch(displayPages, (count) => {
  if (page.value > count) page.value = count;
});
watch([query, region, risk, sort], () => {
  clearTimeout(debounce);
  debounce = setTimeout(() => {
    page.value = 1;
    void router.replace({
      query: {
        ...(query.value ? { q: query.value } : {}),
        ...(region.value ? { region: region.value } : {}),
      },
    });
    void loadList();
  }, 300);
});
watch(group, () => {
  if (showHistory.value) {
    events.value = [];
    next.value = "";
    void loadHistory(false, true);
    if (group.value === "members") void loadMemberActivity();
  }
});
onMounted(() => {
  void refresh();
  void subscribeChanges();
  interval = setInterval(() => {
    activityClock.value = Date.now();
    if (!activityLoading.value && Date.now() - activityChecked >= 60_000)
      void loadMemberActivity();
    if (!loading.value) void loadList();
    if (!starsLoading.value) void loadStars();
    if (showHistory.value && !historyLoading.value && !moreLoaded.value)
      void loadHistory();
    // Time-based risk filters and freshness can change without database writes.
    // This is a slow safety refresh; recorded changes arrive through subscriptions.
  }, 60000);
});
onBeforeUnmount(() => {
  disposed = true;
  listRead.invalidate();
  historyRead.invalidate();
  starRequest++;
  activityRequest++;
  clearTimeout(debounce);
  clearInterval(interval);
  clearTimeout(changeTimer);
  closeChanges();
});
</script>
<template>
  <main class="claims-page">
    <BitcraftPageHeader
      title="Tracked Claims"
      description="T4+ claims with both a waystone and a bank. Check supplies and open a claim’s history."
    >
      <template #actions>
        <div class="heading-actions">
          <button class="button" @click="openHistory()">Recent changes</button
          ><button
            class="button"
            :disabled="loading || starsLoading"
            @click="refresh()"
          >
            {{ loading ? "Loading…" : "Refresh" }}
          </button>
        </div>
      </template>
    </BitcraftPageHeader>
    <section class="coverage ui-panel" aria-label="Recording coverage">
      <strong>{{ tracked.toLocaleString() }} qualifying claims</strong
      ><span>{{ readyCount }} / 9 regions recording</span
      ><small v-if="updated">Checked at {{ updated }}</small>
      <div class="regions">
        <span
          v-for="r in coverage"
          :key="r.regionId"
          :class="{ ready: r.ready }"
          :title="`Recording began: ${date(r.startedAt)}`"
          >{{ r.name }} · {{ r.ready ? "Recording" : "Gap / connecting" }}</span
        >
      </div>
    </section>
    <nav class="tabs list-tabs ui-tabs" aria-label="Claim lists">
      <button
        :class="{ active: listView === 'all' }"
        :aria-pressed="listView === 'all'"
        @click="listView = 'all'"
      >
        All claims
      </button>
      <button
        :class="{ active: listView === 'tracked' }"
        :aria-pressed="listView === 'tracked'"
        @click="listView = 'tracked'"
      >
        Tracked ({{ stars.length }})<span
          v-if="unreadStars"
          class="update-badge"
          >{{ unreadStars }} updated</span
        >
      </button>
    </nav>
    <p v-if="listView === 'tracked'" class="note">
      Star claims to keep them here. This browser saves your shortlist and
      checks it every 15 seconds while this page is open. “Updated” clears when
      you open the claim’s history. Claims outside the recording scope keep
      their star and retained history.
    </p>
    <p v-if="starError || starLoadError" class="error" role="alert">
      {{ starError || starLoadError }}
    </p>
    <form class="filters" @submit.prevent="loadList()">
      <label class="ui-field-label"
        >Claim or owner<input
          v-model="query"
          maxlength="160"
          placeholder="Search name or ID…"
      /></label>
      <label class="ui-field-label"
        >Region<SelectInput v-model="region">
          <option :value="0">All current regions</option>
          <option
            v-for="(name, id) in CURRENT_REGIONS"
            :key="id"
            :value="Number(id)"
          >
            {{ name }}
          </option>
        </SelectInput></label
      >
      <label class="ui-field-label"
        >Supply status<SelectInput v-model="risk">
          <option value="">All tracked claims</option>
          <option value="soon">Within 24 hours</option>
          <option value="week">Within 7 days</option>
          <option value="empty">Out of supplies</option>
          <option value="stale">Stale readings</option>
          <option value="unknown">Unknown estimates</option>
        </SelectInput></label
      >
      <label class="ui-field-label"
        >Sort<SelectInput v-model="sort">
          <option value="expiry">Soonest expiry</option>
          <option value="supplies">Lowest supplies</option>
          <option value="name">Claim name</option>
          <option value="tier">Highest tier</option>
        </SelectInput></label
      >
    </form>
    <p class="note">
      Expiry is an estimate at unchanged upkeep. Hourly ticks, rounding,
      research and resupplies can change it. “Out of supplies” is an observed
      reading; a passed estimate alone does not prove expiry.
    </p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p
      v-if="!loading && !starsLoading && !error && !displayClaims.length"
      class="empty"
    >
      {{
        listView === "tracked"
          ? stars.length
            ? "No starred claims match these filters."
            : "No starred claims yet. Select a star beside any claim to add it here."
          : "No current tracked claims match these filters. Recent changes includes history for claims that have left tracking."
      }}
    </p>
    <div v-else class="table-wrap">
      <table aria-label="Tracked claims and supply estimates">
        <thead>
          <tr>
            <th>Claim / owner</th>
            <th>Region</th>
            <th>Supplies</th>
            <th>Upkeep / hour</th>
            <th>Time left</th>
            <th>Estimated run-out</th>
            <th>Reading</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="claim in displayClaims"
            :key="`${claim.regionId}:${claim.id}`"
            :class="{ urgent: urgent(claim) }"
          >
            <td>
              <div class="claim-title">
                <button
                  class="star-button"
                  :class="{ selected: starred(claim) }"
                  :aria-pressed="starred(claim)"
                  :aria-label="`${starred(claim) ? 'Unstar' : 'Star'} ${claim.name}`"
                  @click="toggleStar(claim)"
                >
                  <Star
                    :size="18"
                    :fill="starred(claim) ? 'currentColor' : 'none'"
                    aria-hidden="true"
                  />
                </button>
                <button class="claim-button" @click="openHistory(claim)">
                  {{ claim.name }}
                </button>
              </div>
              <small v-if="claim.inactive"
                >Outside tracked scope · Retained history available</small
              >
              <small v-else-if="claim.tier !== null"
                >T{{ claim.tier }} · {{ claim.ownerName }} ·
                {{ claim.memberCount }} members</small
              >
              <span v-if="hasUpdates(claim)" class="update-badge"
                >Updated since viewed</span
              >
            </td>
            <td>{{ claim.regionName }}</td>
            <td>{{ number(claim.supplies) }}</td>
            <td>{{ number(claim.upkeepPerHour) }}</td>
            <td>
              <strong>{{ remaining(claim) }}</strong>
            </td>
            <td>
              {{
                claim.supplies !== null && claim.supplies <= 0
                  ? "Observed empty"
                  : claim.upkeepPerHour === 0
                    ? "No upkeep"
                    : timestamp(claim.estimatedExpiresAt)
              }}
            </td>
            <td>
              <span :class="{ stale: claim.supplyStatus !== 'ready' }">{{
                claim.supplyStatus === "ready"
                  ? "Live"
                  : claim.supplyStatus === "stale"
                    ? "Stale"
                    : "Awaiting data"
              }}</span
              ><small v-if="claim.suppliesObservedAt"
                >Value observed {{ date(claim.suppliesObservedAt) }}</small
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="pagination">
      <span
        >{{ displayTotal.toLocaleString() }} matching claims · Page
        {{ page }} of {{ displayPages }}</span
      ><button
        class="button"
        :disabled="page <= 1 || loading"
        @click="changePage(page - 1)"
      >
        Previous</button
      ><button
        class="button"
        :disabled="page >= displayPages || loading"
        @click="changePage(page + 1)"
      >
        Next
      </button>
    </div>
    <Modal
      :show="showHistory"
      max-width="6xl"
      aria-labelledby="claim-history-dialog-title"
      @close="closeHistory"
    >
      <section class="claim-dialog">
        <header class="dialog-heading">
          <div>
            <h2 class="ui-dialog-title" id="claim-history-dialog-title">
              {{ dialogTitle }}
            </h2>
            <p v-if="currentClaim">
              T{{ currentClaim.tier }} · {{ currentClaim.regionName }} · Owner
              {{ currentClaim.ownerName }}
            </p>
            <p v-else-if="selected && !historyLoading">
              This claim is no longer currently tracked. Its retained history
              remains available.
            </p>
            <p v-else-if="!selected">
              Includes recently departed claims and recording gaps.
            </p>
          </div>
          <div class="dialog-actions">
            <button
              v-if="selected"
              class="button dialog-star"
              :aria-pressed="starred(selected)"
              @click="toggleStar(selected)"
            >
              <Star
                :size="18"
                :fill="starred(selected) ? 'currentColor' : 'none'"
                aria-hidden="true"
              />{{ starred(selected) ? "Starred" : "Track claim" }}
            </button>
            <button
              class="button"
              aria-label="Close claim history"
              @click="closeHistory"
            >
              Close
            </button>
          </div>
        </header>
        <div v-if="currentClaim" class="claim-stats ui-panel ui-panel--compact">
          <div>
            <small>Supplies</small
            ><strong>{{ number(currentClaim.supplies) }}</strong>
          </div>
          <div>
            <small>Upkeep / hour</small
            ><strong>{{ number(currentClaim.upkeepPerHour) }}</strong>
          </div>
          <div>
            <small>Estimated time left</small
            ><strong>{{ remaining(currentClaim) }}</strong>
          </div>
          <div>
            <small>Estimated run-out</small
            ><strong>{{ timestamp(currentClaim.estimatedExpiresAt) }}</strong>
          </div>
          <p>
            Waystones: {{ currentClaim.waystones }} · Banks:
            {{ currentClaim.banks }} · Tiles:
            {{ number(currentClaim.tileCount) }} · Treasury:
            {{ number(currentClaim.treasury) }} HEX · Coordinates:
            {{ currentClaim.x }}, {{ currentClaim.z }}
          </p>
          <p v-if="currentClaim.supplyStatus !== 'ready'" class="stale">
            {{
              currentClaim.supplyStatus === "stale"
                ? "Supply readings are stale while recording reconnects."
                : "Supply estimates are awaiting a current reading."
            }}
          </p>
          <p v-else-if="currentClaim.decayTimingStatus === 'observed'">
            Next estimated supply decay:
            {{ timestamp(currentClaim.nextDecayAt) }}. Regional timing observed
            across {{ currentClaim.decayConfirmations }} claims.
          </p>
          <p
            v-else-if="
              currentClaim.supplies > 0 && currentClaim.upkeepPerHour > 0
            "
          >
            Learning regional decay timing. Run-out currently uses hourly
            upkeep.
          </p>
        </div>
        <nav
          class="tabs ui-tabs"
          role="tablist"
          aria-label="Claim history tabs"
        >
          <button
            v-for="(tab, index) in tabs"
            :id="`claim-tab-${tab[0]}`"
            :key="tab[0]"
            :ref="
              (el) => {
                tabButtons[index] = el;
              }
            "
            role="tab"
            :aria-selected="group === tab[0]"
            aria-controls="claim-history-panel"
            :tabindex="group === tab[0] ? 0 : -1"
            :class="{ active: group === tab[0] }"
            @click="group = tab[0]"
            @keydown="tabKey($event, index)"
          >
            {{ tab[1] }}
          </button>
        </nav>
        <section
          id="claim-history-panel"
          role="tabpanel"
          :aria-labelledby="`claim-tab-${group}`"
          tabindex="0"
        >
          <details
            v-if="group === 'members' && selected && members.length"
            class="roster ui-panel ui-panel--compact"
            open
          >
            <summary>Current roster ({{ members.length }} members)</summary>
            <p class="note">
              Last seen is the player’s latest reported activity, anywhere in
              the world. Missing timestamps remain Unknown. Activity readings
              refresh about every five minutes.
            </p>
            <p v-if="activityLoading" class="note" role="status">
              Checking member activity…
            </p>
            <p v-if="activityError" class="stale" role="status">
              {{ activityError }}
            </p>
            <p v-else-if="activityDelayed" class="stale" role="status">
              Activity refresh is delayed. Showing the last available readings.
            </p>
            <small v-if="activityUpdated" class="activity-updated"
              >Activity checked
              {{ new Date(activityUpdated).toLocaleString() }}</small
            >
            <div class="table-wrap member-table">
              <table aria-label="Claim members, permissions and last seen">
                <thead>
                  <tr>
                    <th scope="col">Player</th>
                    <th scope="col">Last seen</th>
                    <th
                      v-for="permission in CLAIM_PERMISSIONS"
                      :key="permission"
                      scope="col"
                    >
                      {{ permission }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="member in roster" :key="member.id">
                    <td>
                      <strong>{{ member.name }}</strong
                      ><span
                        v-if="member.id === currentClaim?.ownerId"
                        class="owner-label"
                        >Owner</span
                      ><small>{{ member.id }}</small>
                    </td>
                    <td :title="member.lastSeen.exact">
                      <span>{{
                        member.lastSeen.at === null && activityLoading
                          ? "Loading…"
                          : member.lastSeen.label
                      }}</span
                      ><small v-if="member.lastSeen.at !== null">{{
                        member.lastSeen.exact
                      }}</small>
                    </td>
                    <td v-for="flag in member.flags" :key="flag.name">
                      <span
                        :class="{
                          granted: flag.granted,
                          denied: flag.granted === false,
                        }"
                        >{{
                          flag.granted === null
                            ? "Unknown"
                            : flag.granted
                              ? "Yes"
                              : "No"
                        }}</span
                      >
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
          <p class="note">
            Times show observations. Departures do not distinguish leaving from
            removal. Takeover flags describe evidence, not intent. Gaps can hide
            intermediate changes.
          </p>
          <p v-if="historyError" class="error" role="alert">
            {{ historyError }}
          </p>
          <ClaimHistoryEvents
            :events="events"
            :loading="historyLoading"
            @inspect="inspectEvent"
          /><button
            v-if="next"
            class="button"
            :disabled="historyLoading"
            @click="loadHistory(true)"
          >
            Load older events
          </button>
        </section>
      </section>
    </Modal>
  </main>
</template>
<style scoped>
.claims-page {
  width: 100%;
  color: var(--text-primary);
}
.dialog-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 20px;
}
.dialog-heading p {
  color: var(--text-muted);
  margin: 8px 0;
}
.heading-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}
.coverage {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin: 24px 0;
}
.coverage small {
  margin-left: auto;
}
.regions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  width: 100%;
}
.regions span {
  font-size: 12px;
  padding: 5px 9px;
  border-radius: 6px;
  background: color-mix(in srgb, var(--warning) 15%, transparent);
  color: var(--warning);
}
.regions span.ready {
  background: color-mix(in srgb, var(--success) 15%, transparent);
  color: var(--success);
}
.filters {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 14px;
}
.filters label {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.filters .app-select {
  width: 100%;
  min-width: 0;
}
.filters input {
  width: 100%;
  min-width: 0;
  padding: 10px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-canvas-2);
  color: inherit;
}
.note {
  font-size: 12px;
  line-height: 1.6;
  color: var(--text-muted);
  margin: 18px 0;
}
.error {
  color: var(--danger);
}
.empty {
  padding: 30px;
  text-align: center;
}
.table-wrap {
  overflow-x: auto;
  border: 1px solid var(--border-color);
  border-radius: 10px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
th,
td {
  text-align: left;
  padding: 13px 12px;
  vertical-align: top;
  border-bottom: 1px solid var(--border-color);
}
th {
  white-space: nowrap;
  font-size: 12px;
  color: var(--text-muted);
  background: var(--bg-surface);
}
td:first-child {
  min-width: 230px;
  max-width: 320px;
}
td small {
  display: block;
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 6px;
}
.claim-button {
  background: transparent;
  border: 0;
  padding: 0;
  color: var(--accent-cyan);
  font-weight: 600;
  text-align: left;
  cursor: pointer;
  overflow-wrap: anywhere;
}
.claim-button:hover {
  text-decoration: underline;
}
.claim-title,
.dialog-actions,
.dialog-star {
  display: flex;
  align-items: center;
  gap: 8px;
}
.dialog-actions {
  flex-wrap: wrap;
  justify-content: flex-end;
}
.star-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border: 1px solid var(--border-color);
  border-radius: 7px;
  color: var(--text-muted);
  background: transparent;
  cursor: pointer;
}
.star-button.selected,
.dialog-star[aria-pressed="true"] {
  color: var(--accent-cyan-2);
}
.star-button:hover {
  background: color-mix(in srgb, var(--accent-cyan) 15%, transparent);
}
.update-badge {
  display: inline-block;
  margin: 6px 0 0;
  padding: 3px 6px;
  border-radius: 5px;
  color: var(--success);
  background: color-mix(in srgb, var(--success) 15%, transparent);
  font-size: 11px;
}
.list-tabs {
  margin: 0 0 18px;
}
.list-tabs .update-badge {
  margin: 0 0 0 8px;
}
tr.urgent {
  background: color-mix(in srgb, var(--warning) 10%, transparent);
}
.urgent td:nth-child(5) {
  color: var(--accent-cyan-2);
}
.stale {
  color: var(--warning);
}
.button {
  padding: 9px 14px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-surface);
  color: inherit;
  cursor: pointer;
}
.button:disabled {
  opacity: 0.5;
  cursor: default;
}
.pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 18px 0;
  font-size: 13px;
}
.pagination span {
  margin-right: auto;
}
.claim-dialog {
  padding: 24px;
  color: var(--text-primary);
}
.dialog-heading h2 {
  overflow-wrap: anywhere;
}
.claim-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin: 18px 0;
}
.claim-stats small,
.claim-stats strong {
  display: block;
}
.claim-stats small {
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 6px;
}
.claim-stats strong {
  font-size: 15px;
}
.claim-stats p {
  grid-column: 1/-1;
  margin: 0;
  font-size: 12px;
}
.tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border-color);
}
.tabs button {
  white-space: nowrap;
  cursor: pointer;
}
.roster {
  margin-top: 16px;
}
.roster summary {
  cursor: pointer;
}
.member-table {
  margin-top: 12px;
}
.member-table table {
  min-width: 680px;
}
.member-table td:first-child {
  min-width: 160px;
}
.member-table td:nth-child(2) {
  min-width: 160px;
}
.member-table .granted {
  color: var(--success);
}
.member-table .denied,
.activity-updated {
  color: var(--text-muted);
}
.owner-label {
  display: inline-block;
  font-size: 10px;
  color: var(--accent-cyan-2);
  margin-left: 8px;
}
@media (max-width: 850px) {
  .filters {
    grid-template-columns: 1fr 1fr;
  }
  .claim-stats {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 600px) {
  .claim-dialog {
    padding: 16px;
  }
  .heading-actions {
    width: 100%;
  }
  .coverage small {
    margin-left: 0;
  }
  .filters {
    grid-template-columns: 1fr;
  }
  .table-wrap {
    max-width: 100%;
  }
  .pagination span {
    width: 100%;
  }
}
</style>
