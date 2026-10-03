<script setup lang="ts">
defineProps<{
  workspaces: {
    key: string;
    label: string;
    count: number | string;
    sections: { key: string; label: string; count: number | string }[];
  }[];
  workspace: string;
  section: string;
  search: string;
  busy: boolean;
  canRepeat: boolean;
}>();
defineEmits<{
  workspace: [key: string];
  section: [key: string];
  "update:search": [value: string];
  repeat: [];
}>();
</script>
<template>
  <nav class="evergather-command-board" aria-label="Evergather command board">
    <div class="evergather-command-tabs">
      <div class="evergather-tab-row" aria-label="Evergather workspaces">
        <button
          v-for="item in workspaces"
          :key="item.key"
          :class="{ selected: workspace === item.key }"
          :aria-pressed="workspace === item.key"
          @click="$emit('workspace', item.key)"
        >
          {{ item.label }} · {{ item.count }}
        </button>
      </div>
      <div class="evergather-tab-row" aria-label="Evergather sections">
        <button
          v-for="item in workspaces.find((item) => item.key === workspace)
            ?.sections ?? []"
          :key="item.key"
          :class="{ selected: section === item.key }"
          :aria-pressed="section === item.key"
          @click="$emit('section', item.key)"
        >
          {{ item.label }} · {{ item.count }}
        </button>
      </div>
    </div>
    <div class="evergather-search">
      <label
        ><span class="sr-only">Search Evergather</span
        ><input
          :value="search"
          type="search"
          placeholder="Search Evergather..."
          @input="
            $emit('update:search', ($event.target as HTMLInputElement).value)
          "
      /></label>
      <button
        class="button"
        :disabled="busy || !canRepeat"
        @click="$emit('repeat')"
      >
        {{ busy ? "Repeating..." : canRepeat ? "Repeat Last" : "No Last" }}
      </button>
    </div>
  </nav>
</template>
