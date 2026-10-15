<script setup>
defineProps({
  title: { type: String, required: true },
  eyebrow: { type: String, default: "BitCraft tools" },
  description: { type: String, default: "" },
});
</script>

<template>
  <header class="bitcraft-tool-header">
    <div class="bitcraft-tool-header__copy">
      <p class="bitcraft-tool-header__eyebrow">
        <slot name="eyebrow">{{ eyebrow }}</slot>
      </p>
      <h1 class="bitcraft-tool-header__title">{{ title }}</h1>
      <p
        v-if="description || $slots.description"
        class="bitcraft-tool-header__description"
      >
        <slot name="description">{{ description }}</slot>
      </p>
      <div v-if="$slots.meta" class="bitcraft-tool-header__meta">
        <slot name="meta" />
      </div>
    </div>
    <div v-if="$slots.actions" class="bitcraft-tool-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.bitcraft-tool-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin: 0 0 28px;
  padding-bottom: 22px;
  border-bottom: 1px solid var(--border-color);
  font-family: var(--font-ui);
  font-size: 14px;
  font-weight: 400;
  line-height: 1.5;
  color: var(--text-muted);
}
.bitcraft-tool-header__copy {
  display: grid;
  gap: 6px;
  flex: 1 1 320px;
  min-width: 0;
}
.bitcraft-tool-header__eyebrow {
  margin: 0;
  color: var(--text-muted-3);
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 700;
  line-height: 1.5;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}
.bitcraft-tool-header__title {
  margin: 0;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 24px;
  font-weight: 650;
  line-height: 1.04;
  letter-spacing: -0.025em;
  overflow-wrap: anywhere;
}
.bitcraft-tool-header__description,
.bitcraft-tool-header__meta {
  margin: 0;
  max-width: 72ch;
  color: var(--text-muted);
  font-family: var(--font-ui);
  font-size: 14px;
  font-weight: 400;
  line-height: 1.5;
  letter-spacing: normal;
}
.bitcraft-tool-header__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
}
</style>
