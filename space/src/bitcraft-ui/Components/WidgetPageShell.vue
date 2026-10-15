<template>
  <AuthenticatedLayout v-if="showSetupLayout" class="bitcraft-widget-layout">
    <template #header>
      <BitcraftPageHeader
        :title="title"
        :description="description"
        eyebrow="Bitcraft Widget Setup"
      />
    </template>

    <slot />
  </AuthenticatedLayout>

  <slot v-else />
</template>

<script setup>
import { computed } from "vue";
import AuthenticatedLayout from "/src/bitcraft-ui/Layout.vue";
import BitcraftPageHeader from "./BitcraftPageHeader.vue";

const props = defineProps({
  setup: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: "" },
});

const showSetupLayout = computed(() => props.setup);
</script>

<style scoped>
:global(.bitcraft-widget-layout main) {
  max-width: 72rem;
}
</style>
