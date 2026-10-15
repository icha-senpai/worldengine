<script setup>
import SitePlayerPicker from "./Components/SitePlayerPicker.vue";
import AppDialogHost from "./shared/ui/AppDialogHost.vue";
</script>
<template>
  <div class="bitcraft-page-layout">
    <SitePlayerPicker />
    <div v-if="$slots.header" class="bitcraft-page-header">
      <slot name="header" />
    </div>
    <slot />
    <AppDialogHost />
  </div>
</template>
