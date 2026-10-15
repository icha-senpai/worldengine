<script setup>
import { CURRENT_REGIONS } from "../../../bitcraft/src/relay-trading";
import { labels, date, eventSummary } from "../claim-history-display";
defineProps({ events: { type: Array, default: () => [] }, loading: Boolean });
defineEmits(["inspect"]);
</script>
<template>
  <section aria-label="Claim timeline">
    <p v-if="!events.length" class="empty">
      {{
        loading
          ? "Loading history…"
          : "No recorded events in this tab. Earlier events are unavailable."
      }}
    </p>
    <article
      v-for="e in events"
      :key="e.key"
      class="event"
      :class="{ flagged: e.detail.possibleOutsiderTakeover }"
    >
      <header>
        <strong>{{ labels[e.kind] ?? e.kind }}</strong
        ><time>{{ date(e.observedAt) }}</time>
      </header>
      <div class="claim-link">
        <button v-if="e.claimId" @click="$emit('inspect', e)">
          {{ e.detail.claimName }}</button
        ><span
          >{{ CURRENT_REGIONS[e.regionId]
          }}<template v-if="e.detail.tier">
            · T{{ e.detail.tier }}</template
          ></span
        >
      </div>
      <p>{{ eventSummary(e) }}</p>
      <strong v-if="e.detail.possibleOutsiderTakeover" class="warning"
        >Possible outsider takeover · preceding supplies were zero</strong
      >
      <small v-if="e.detail.gap" class="warning"
        >Observed after a recording gap; exact time and intermediate changes are
        unknown.</small
      >
      <details v-if="e.claimId">
        <summary>Evidence and claim details</summary>
        <p>
          Claim {{ e.claimId }} · Totem {{ e.detail.totemId }} · Coordinates
          {{ e.detail.x ?? "?" }}, {{ e.detail.z ?? "?" }}
        </p>
        <p
          v-if="e.detail.waystones !== undefined && e.detail.waystones !== null"
        >
          Waystones: {{ e.detail.waystones }} · Banks: {{ e.detail.banks }}
        </p>
        <template v-if="e.kind === 'ownership_changed'"
          ><p>
            Owner IDs: {{ e.detail.previousOwnerId }} → {{ e.detail.ownerId }}.
            Preceding supplies: {{ e.detail.previousSupplies ?? "unknown" }}.
          </p>
          <p>
            Preceding roster: {{ e.detail.previousRosterSize }} members.
            {{
              (e.detail.previousMembers ?? [])
                .map((m) => `${m.name} (${m.id})`)
                .join(", ")
            }}{{
              e.detail.previousRosterSize >
              (e.detail.previousMembers ?? []).length
                ? " … (sample)"
                : ""
            }}
          </p>
        </template>
      </details>
    </article>
  </section>
</template>
<style scoped>
.event {
  padding: 16px;
  margin: 12px 0;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  background: var(--bg-surface);
  overflow-wrap: anywhere;
}
.event.flagged {
  border-color: var(--warning);
}
header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
}
time,
.claim-link span,
details {
  font-size: 12px;
  color: var(--text-muted);
}
.claim-link {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-top: 10px;
}
.claim-link button {
  color: var(--accent-cyan);
  background: transparent;
  border: 0;
  padding: 0;
  text-decoration: underline;
  cursor: pointer;
  text-align: left;
}
.event p {
  margin: 12px 0;
  font-size: 14px;
}
.warning {
  display: block;
  font-size: 12px;
  color: var(--warning);
  margin: 8px 0;
}
details {
  margin-top: 14px;
}
summary {
  cursor: pointer;
}
.empty {
  padding: 24px;
  text-align: center;
  color: var(--text-muted);
}
@media (max-width: 600px) {
  header {
    flex-direction: column;
    gap: 5px;
  }
}
</style>
