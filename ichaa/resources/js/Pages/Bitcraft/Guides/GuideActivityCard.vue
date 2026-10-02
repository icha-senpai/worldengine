<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import TextInput from '@/Components/TextInput.vue'
import SelectInput from '@/Components/SelectInput.vue'
import { bitcraftAssetUrl } from '@/Pages/Bitcraft/bitjitaAssets'
import { estimateGathering, gatheringDefaults } from '@/Pages/Bitcraft/gatheringEstimates'
import GuideMarketPrices from './GuideMarketPrices.vue'
import { guideCardLayoutStyle } from './guideCardLayout'

const props = defineProps(nodeViewProps)
const settings = reactive({ quantity: 1, ...gatheringDefaults, market: false, region: '', ...props.node.attrs.settings })
const data = ref(null)
const loading = ref(false)
const error = ref('')
const brokenIcons = ref(new Set())
const owned = reactive({})
const selectedRecipe = ref(props.node.attrs.recipeId)
let request
const isCrafting = computed(() => props.node.attrs.activity === 'crafting')
const recipe = computed(() => data.value?.recipes?.find((value) => String(value.id) === String(selectedRecipe.value)) || data.value?.recipes?.[0])
const entry = computed(() => data.value?.entry)
const target = computed(() => data.value?.item || entry.value?.resource)
const title = computed(() => recipe.value?.recipeName || entry.value?.name || props.node.attrs.name)
const quantity = computed(() => Math.max(1, Math.min(999999, Number(settings.quantity) || 1)))
const batches = computed(() => {
    const output = recipe.value?.craftedItems?.find((item) => String(item.id) === String(props.node.attrs.itemId) && (item.kind || 'item') === props.node.attrs.kind)
    return Math.ceil(quantity.value / Math.max(1, Number(output?.quantity || recipe.value?.outputQuantity || 1)))
})
const gathering = computed(() => entry.value ? estimateGathering(entry.value, settings) : null)
const inputs = computed(() => isCrafting.value
    ? (recipe.value?.consumedItems || []).map((item) => ({ ...item, expected: Number(item.quantity) * batches.value }))
    : gathering.value?.inputs || [])
const outputs = computed(() => isCrafting.value
    ? (recipe.value?.craftedItems || []).map((item) => ({ ...item, expected: Number(item.quantity) * batches.value }))
    : gathering.value?.outputs || [])
const fields = [
    { key: 'power', label: 'Tool power', min: 1, max: 9999, step: 1 },
    { key: 'minutes', label: 'Minutes', min: 0.1, max: 1440, step: 0.1 },
    { key: 'gatheringSpeed', label: 'Gathering speed %', min: 0, max: 999, step: 0.1 },
    { key: 'skillSpeed', label: 'Skill speed %', min: 0, max: 999, step: 0.1 },
    { key: 'critChance', label: 'Crit chance %', min: 0, max: 100, step: 0.1 },
    { key: 'critMultiplier', label: 'Crit multiplier', min: 1, max: 100, step: 0.1 },
]

function setSetting(key, value) {
    settings[key] = value
    if (props.editor.isEditable) props.updateAttributes({ settings: { ...settings } })
}
function setRecipe(value) {
    selectedRecipe.value = Number(value)
    if (props.editor.isEditable) props.updateAttributes({ recipeId: Number(value) })
}
watch(() => props.node.attrs.recipeId, (value) => { selectedRecipe.value = value })
watch(() => props.node.attrs.settings, (value) => Object.assign(settings, { quantity: 1, ...gatheringDefaults, market: false, region: '', ...value }), { deep: true })
async function load() {
    request?.abort()
    const controller = new AbortController()
    request = controller
    loading.value = true
    error.value = ''
    data.value = null
    try {
        const { activity, recipeId, itemId, kind } = props.node.attrs
        const response = await fetch(route('bitcraft.guides.card-data', { activity, recipeId, ...(itemId ? { itemId } : {}), kind }), {
            headers: { Accept: 'application/json' }, signal: controller.signal,
        })
        if (!response.ok) throw new Error(response.status === 404 ? 'This recipe is not available in the current game snapshot.' : 'Unable to load this card. Try again.')
        const payload = await response.json()
        if (!controller.signal.aborted) data.value = payload
    } catch (failure) {
        if (!controller.signal.aborted) error.value = failure.message
    } finally {
        if (request === controller) loading.value = false
    }
}
watch([
    () => props.node.attrs.activity, () => props.node.attrs.recipeId,
    () => props.node.attrs.itemId, () => props.node.attrs.kind,
], load, { immediate: true })
onBeforeUnmount(() => request?.abort())
function icon(item) {
    return brokenIcons.value.has(item?.iconAssetName) ? null : bitcraftAssetUrl(item?.iconAssetName)
}
const key = (item) => `${item.kind || item.itemType || 'item'}:${item.id || item.itemId}`
const number = (value) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(Number(value) || 0)
function selectCard() {
    const position = props.getPos?.()
    if (props.editor.isEditable && typeof position === 'number') props.editor.chain().focus().setNodeSelection(position).run()
}
</script>

<template>
    <NodeViewWrapper class="guide-activity-card not-prose" :class="{ 'is-selected': editor.isEditable && selected }" :data-card-align="node.attrs.align" :data-card-width="node.attrs.width" :data-card-wrap="String(node.attrs.wrap === true && node.attrs.align !== 'center')" :style="guideCardLayoutStyle(node.attrs)" contenteditable="false">
        <header class="flex items-start gap-3" @click="selectCard">
            <span class="guide-activity-card__icon" aria-hidden="true">
                <img v-if="icon(target)" :src="icon(target)" alt="" loading="lazy" @error="brokenIcons.add(target.iconAssetName)">
                <span v-else>{{ isCrafting ? 'C' : 'G' }}</span>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-muted-2">{{ isCrafting ? 'Crafting' : 'Gathering estimate' }}</p>
                <h3 class="break-words text-base font-semibold">{{ title }}</h3>
                <p v-if="recipe" class="mt-1 text-xs text-muted-2">{{ recipe.craftingStation || 'No station required' }}<template v-if="recipe.skillName"> · {{ recipe.skillName }}</template></p>
                <p v-if="entry" class="mt-1 text-xs text-muted-2">{{ entry.skill?.name }} level {{ entry.levelRequirement }} · {{ entry.tool?.name }} level {{ entry.tool?.level }}<template v-if="entry.tool?.power"> · Required power {{ entry.tool.power }}</template></p>
            </div>
            <button v-if="editor.isEditable" type="button" class="app-btn app-btn--ghost app-btn--sm shrink-0" aria-label="Remove card" title="Remove card" @click.stop="deleteNode">×</button>
        </header>
        <p v-if="loading" class="mt-4 text-sm text-muted-2" role="status">Loading recipe...</p>
        <div v-else-if="error" class="mt-4 flex flex-wrap items-center gap-3">
            <p class="text-sm text-muted-2" role="status">{{ error }}</p>
            <button type="button" class="app-btn app-btn--ghost app-btn--sm" @click="load">Retry</button>
        </div>
        <template v-else-if="data">
            <div v-if="isCrafting" class="mt-4 flex flex-wrap items-end gap-4">
                <label v-if="data.recipes.length > 1" class="grid min-w-0 max-w-full gap-1 text-xs text-muted-2">
                    Recipe
                    <SelectInput :model-value="selectedRecipe" class="w-full" @update:model-value="setRecipe">
                        <option v-for="option in data.recipes" :key="option.id" :value="option.id">{{ option.recipeName }}</option>
                    </SelectInput>
                </label>
                <label class="grid w-36 max-w-full gap-1 text-xs text-muted-2">
                    Desired quantity
                    <TextInput :model-value="settings.quantity" type="number" min="1" max="999999" class="w-full" @update:model-value="setSetting('quantity', Number($event))" />
                </label>
                <p class="pb-2 text-sm">{{ number(batches) }} batches<template v-if="recipe.timeRequirement"> &middot; Base recipe time {{ number(recipe.timeRequirement) }}s</template></p>
            </div>
            <template v-else>
                <div class="guide-activity-card__controls mt-4">
                    <label v-for="field in fields" :key="field.key" class="grid min-w-0 gap-1 text-xs text-muted-2">
                        {{ field.label }}
                        <TextInput :model-value="settings[field.key]" type="number" :min="field.min" :max="field.max" :step="field.step" class="w-full" @update:model-value="setSetting(field.key, Number($event))" />
                    </label>
                    <label class="guide-activity-card__target grid min-w-0 gap-1 text-xs text-muted-2">
                        Target
                        <SelectInput :model-value="settings.mode" class="w-full" @update:model-value="setSetting('mode', $event)">
                            <option value="sustained">Continuous gathering</option>
                            <option value="single">One resource</option>
                        </SelectInput>
                    </label>
                </div>
                <p class="mt-3 text-sm">{{ number(gathering.actionsUsed) }} actions · {{ number(gathering.experience) }} estimated XP · {{ number(gathering.actionSeconds) }}s per action</p>
                <p class="mt-2 text-xs text-muted-2">Base stamina {{ number(entry.staminaRequirement) }} · Durability loss {{ number(entry.toolDurabilityLost) }} per action</p>
                <p v-if="settings.power < entry.tool?.power" class="mt-2 text-sm text-muted-2">Tool power is below this action's requirement.</p>
                <p v-if="gathering.depletes" class="mt-2 text-xs text-muted-2">Resource depleted after approximately {{ number(gathering.actionsUsed * gathering.actionSeconds) }}s.</p>
            </template>
            <div class="mt-4 border-t border-border pt-3">
                <h4 class="text-xs font-semibold text-muted-2">{{ isCrafting ? 'Output' : 'Expected drops' }}</h4>
                <div v-for="item in outputs" :key="key(item)" class="guide-activity-card__row">
                    <img v-if="icon(item)" :src="icon(item)" alt="" loading="lazy" @error="brokenIcons.add(item.iconAssetName)">
                    <span class="min-w-0 flex-1 break-words">{{ item.name || item.itemName }}<span v-if="!isCrafting" class="block text-xs text-muted-2">{{ number(Number(item.probability ?? 1) * 100) }}% per roll</span></span>
                    <strong>{{ number(item.expected) }}</strong>
                </div>
                <h4 v-if="inputs.length" class="mt-3 text-xs font-semibold text-muted-2">{{ isCrafting ? 'Materials' : 'Expected consumption' }}</h4>
                <div v-for="item in inputs" :key="key(item)" class="guide-activity-card__row">
                    <img v-if="icon(item)" :src="icon(item)" alt="" loading="lazy" @error="brokenIcons.add(item.iconAssetName)">
                    <span class="min-w-0 flex-1 break-words">{{ item.name || item.itemName }}</span>
                    <strong>{{ number(item.expected) }}</strong>
                    <label v-if="isCrafting" class="grid w-20 shrink-0 gap-1 text-xs text-muted-2">
                        Owned
                        <TextInput v-model.number="owned[key(item)]" type="number" min="0" max="999999999" class="w-full" :aria-label="`Owned ${item.name || item.itemName}`" />
                    </label>
                    <span v-if="isCrafting" class="w-20 shrink-0 text-right text-xs text-muted-2">Need {{ number(Math.max(0, item.expected - (Number(owned[key(item)]) || 0))) }}</span>
                </div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm">
                <input :checked="settings.market" type="checkbox" @change="setSetting('market', $event.target.checked)">
                Market prices
            </label>
            <GuideMarketPrices v-if="settings.market" :items="[...outputs, ...inputs]" :region="settings.region" @update:region="setSetting('region', $event)" />
            <p class="mt-4 text-xs text-muted-2">Game snapshot<template v-if="data.snapshot?.generatedAt"> · {{ new Date(data.snapshot.generatedAt).toLocaleString() }}</template></p>
        </template>
    </NodeViewWrapper>
</template>

<style scoped>
.guide-activity-card {
    width: min(100%, 720px);
    min-width: min(100%, 320px);
    max-width: 100%;
    container-type: inline-size;
    margin: 20px 0;
    padding: 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-surface);
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 14px;
    line-height: 1.5;
}
.guide-activity-card.is-selected { outline: 2px solid var(--accent-cyan); }
.guide-activity-card__icon { display: grid; place-items: center; width: 48px; height: 48px; flex: 0 0 48px; background: var(--bg-canvas); }
.guide-activity-card__icon img { width: 40px; height: 40px; object-fit: contain; }
.guide-activity-card__controls { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.guide-activity-card__target { grid-column: span 2; }
.guide-activity-card__row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 8px 0; font-size: 13px; }
.guide-activity-card__row img { width: 28px; height: 28px; flex: 0 0 28px; object-fit: contain; }
.guide-activity-card :deep(.input) { min-width: 0; }
@container (min-width: 520px) { .guide-activity-card__controls { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
