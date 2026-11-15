<script setup>
import SitePlayerPicker from "./Components/SitePlayerPicker.vue";
import AppDialogHost from "./shared/ui/AppDialogHost.vue";
const releaseWorld =
  import.meta.env.VITE_BITCRAFT_DATABASE === "space-bitcraft-release";
</script>
<template>
  <div class="bitcraft-page-layout">
    <p v-if="releaseWorld" class="text-sm text-muted-2">Release world</p>
    <SitePlayerPicker />
    <div v-if="$slots.header" class="bitcraft-page-header">
      <slot name="header" />
    </div>
    <slot />
    <AppDialogHost />
  </div>
</template>
