<template>
    <span
        class="bitcraft-tier-badge"
        :style="badgeStyle"
        :title="label"
        :aria-label="label"
    >
        <img
            v-if="badgeUrl"
            class="bitcraft-tier-badge__icon"
            :src="badgeUrl"
            :alt="shortLabel"
            loading="lazy"
        >
        <span v-else class="bitcraft-tier-badge__fallback" aria-hidden="true">
            <span class="bitcraft-tier-badge__container"></span>
            <span class="bitcraft-tier-badge__text">{{ badgeText }}</span>
        </span>
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { bitcraftTierBadgeStyle, bitcraftTierBadgeUrl } from '@/Pages/Bitcraft/bitjitaAssets.js'

const props = defineProps({
    tier: { type: [Number, String], required: true },
})

const tierNumber = computed(() => Math.trunc(Number(props.tier)))
const label = computed(() => `Tier ${Number.isFinite(tierNumber.value) ? tierNumber.value : props.tier}`)
const shortLabel = computed(() => `T${Number.isFinite(tierNumber.value) ? tierNumber.value : props.tier}`)
const badgeText = computed(() => String(Number.isFinite(tierNumber.value) ? tierNumber.value : props.tier))
const badgeUrl = computed(() => bitcraftTierBadgeUrl(props.tier))
const badgeStyle = computed(() => bitcraftTierBadgeStyle(props.tier))
</script>

<style scoped>
.bitcraft-tier-badge {
    display: inline-flex;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border: 0;
    background: transparent;
    box-shadow: none;
    line-height: 1;
    padding: 0;
    vertical-align: -3px;
}

.bitcraft-tier-badge__icon {
    display: block;
    width: 100%;
    height: 100%;
    background: var(--bitcraft-tier-accent, #636a74);
    mask: var(--bitcraft-tier-badge-mask) 0 0 / contain no-repeat;
    -webkit-mask: var(--bitcraft-tier-badge-mask) 0 0 / contain no-repeat;
}

.bitcraft-tier-badge__fallback {
    position: relative;
    display: block;
    width: 100%;
    height: 100%;
}

.bitcraft-tier-badge__container {
    display: block;
    width: 100%;
    height: 100%;
    background: var(--bitcraft-tier-accent, #413a64);
    mask: var(--bitcraft-tier-badge-mask) 0 0 / contain no-repeat;
    -webkit-mask: var(--bitcraft-tier-badge-mask) 0 0 / contain no-repeat;
}

.bitcraft-tier-badge__text {
    position: absolute;
    top: 50%;
    left: 50%;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 10px;
    font-weight: 850;
    line-height: 1;
    text-align: center;
    transform: translate(-50%, -50%);
    user-select: none;
}
</style>
