<script setup>
import { usePage } from "/src/bitcraft-ui/navigation";
const page = usePage();
defineEmits(["select"]);
</script>

<template>
  <button
    v-if="page.props.bitcraft?.player"
    type="button"
    class="text-xs text-muted underline underline-offset-2 text-left"
    @click="$emit('select', page.props.bitcraft.player.entityId)"
  >
    Use {{ page.props.bitcraft.player.username }}
  </button>
</template>
