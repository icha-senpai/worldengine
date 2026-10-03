<script setup lang="ts">
import type { Definition } from "../../spacetimedb/src/rules";
import { computed } from "vue";
const props = defineProps<{
  entries: {
    key: string;
    label: string;
    skill: string;
    requiredLevel: number;
    value: Definition;
  }[];
  professions: { key: string; label: string; level: number; count: number }[];
  selected: string;
  showLocked: boolean;
  remaining: number;
  busy: boolean;
  tools: {
    skill: string;
    name: string;
    equipped: boolean;
    durability: number;
    maxDurability: number;
  }[];
}>();
const rows = computed(() =>
  props.entries.map((entry) => ({
    ...entry,
    level: props.professions.find((row) => row.key === entry.skill)?.level ?? 1,
    tool: props.tools.find((row) => row.equipped && row.skill === entry.skill),
  })),
);
defineEmits<{
  "update:selected": [key: string];
  "update:showLocked": [value: boolean];
  act: [key: string];
}>();
</script>
<template>
  <div class="evergather-action-board">
    <aside class="evergather-professions" aria-label="Action board filters">
      <h3>Action Board</h3>
      <p>Choose a profession to focus your next action.</p>
      <button
        :class="{ selected: !selected }"
        @click="$emit('update:selected', '')"
      >
        All professions
      </button>
      <div class="evergather-profession-list">
        <button
          v-for="profession in professions"
          :key="profession.key"
          :class="{ selected: selected === profession.key }"
          @click="$emit('update:selected', profession.key)"
        >
          <span
            >{{ profession.label
            }}<small>Level {{ profession.level }}</small></span
          ><span>{{ profession.count }}</span>
        </button>
      </div>
      <label class="evergather-locked-filter"
        ><input
          type="checkbox"
          :checked="showLocked"
          @change="
            $emit(
              'update:showLocked',
              ($event.target as HTMLInputElement).checked,
            )
          "
        />Show locked</label
      >
      <p>
        {{
          remaining ? `Ready in ${remaining}s` : "Ready for your next action"
        }}
      </p>
    </aside>
    <div class="evergather-action-list">
      <button
        v-for="(entry, index) in rows"
        :key="entry.key"
        class="evergather-action-row"
        :disabled="
          busy ||
          remaining > 0 ||
          entry.requiredLevel > entry.level ||
          (entry.tool && !entry.tool.durability)
        "
        @click="$emit('act', entry.key)"
      >
        <span class="evergather-action-number">#{{ index + 1 }}</span>
        <span class="evergather-action-copy">
          <span class="evergather-action-name"
            >{{ entry.label
            }}<span class="badge">Lv {{ entry.requiredLevel }}</span></span
          >
          <span>{{ entry.value.location ?? entry.value.description }}</span>
          <span v-if="entry.tool"
            >{{ entry.tool.name }} · {{ entry.tool.durability }}/{{
              entry.tool.maxDurability
            }}
            durability</span
          >
          <span v-if="entry.value.loot?.length" class="evergather-loot-preview"
            ><span
              v-for="(item, i) in entry.value.loot.slice(0, 3)"
              :key="i"
              class="badge"
              >{{
                item.item_name ?? item.name ?? item.item_key ?? item.key
              }}</span
            ></span
          >
        </span>
        <span class="evergather-action-state">{{
          entry.requiredLevel > entry.level
            ? `Level ${entry.requiredLevel} required`
            : entry.tool && !entry.tool.durability
              ? "Repair Tool"
              : remaining
                ? `${remaining}s`
                : busy
                  ? "Starting..."
                  : "Start"
        }}</span>
      </button>
      <p v-if="!entries.length" class="empty">
        No matching actions. Try another profession or show locked content.
      </p>
    </div>
  </div>
</template>
