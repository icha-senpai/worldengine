<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import TextInput from '@/Components/TextInput.vue'
import SelectInput from '@/Components/SelectInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TrackerRefreshStatus from './Components/TrackerRefreshStatus.vue'
import { bitcraftAssetUrl } from './bitjitaAssets'

const props = defineProps({
    filters: Object, crafts: Array, pagination: Object, skillOptions: Array, regionOptions: Array,
    refresh: Object, error: String, playerError: String, playerRefresh: Object,
})
const page = usePage()
const form = reactive({ ...props.filters })
const busy = ref(false)
const expanded = ref(null)
const selected = computed(() => page.props.bitcraft?.player)
const number = (value) => value === null || value === undefined ? '-' : Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
const toolLabel = (tool) => `Tool type ${tool.tool_type ?? tool[0]}, tier ${tool.level ?? tool[1]}`

function submit(pageNumber = 1) {
    router.get(route('bitcraft.open-crafts'), {
        ...form, page: pageNumber,
        levelUps: form.levelUps ? 1 : undefined,
        meetsLevel: form.meetsLevel ? 1 : undefined,
        mine: form.mine ? 1 : undefined,
    }, {
        preserveState: true, preserveScroll: true,
        onStart: () => { busy.value = true },
        onFinish: () => { busy.value = false },
    })
}
watch(() => props.filters, (filters) => { Object.assign(form, filters); expanded.value = null })
</script>

<template>
    <Head title="Open Crafts" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold">Open Crafts</h1>
            <p class="mt-2 text-sm text-muted">Public crafting jobs <span v-if="selected">for {{ selected.username }}</span></p>
        </template>
        <form class="craft-filters" @submit.prevent="submit()">
            <label class="craft-search">
                <span class="field-label">Item, claim or owner</span>
                <TextInput v-model="form.q" type="search" maxlength="100" class="w-full" />
            </label>
            <label>
                <span class="field-label">Skill</span>
                <SelectInput v-model="form.skill" class="w-full" @change="submit()">
                    <option value="">All skills</option>
                    <option v-for="skill in skillOptions" :key="skill.id" :value="skill.id">{{ skill.name }}</option>
                </SelectInput>
            </label>
            <label>
                <span class="field-label">Region</span>
                <SelectInput v-model="form.region" class="w-full" @change="submit()">
                    <option value="">All regions</option>
                    <option v-for="region in regionOptions" :key="region" :value="region">Region {{ region }}</option>
                </SelectInput>
            </label>
            <label>
                <span class="field-label">Sort</span>
                <SelectInput v-model="form.sort" class="w-full" @change="submit()">
                    <option value="xp">Remaining XP</option>
                    <option value="levels">Levels gained</option>
                    <option value="progress">Progress</option>
                    <option value="name">Item name</option>
                </SelectInput>
            </label>
            <AppButton type="submit" class="self-end" :disabled="busy">Search</AppButton>
            <div class="craft-toggles">
                <label><input v-model="form.levelUps" type="checkbox" :disabled="!selected" @change="submit()" /> Level-ups only</label>
                <label><input v-model="form.meetsLevel" type="checkbox" :disabled="!selected" @change="submit()" /> Level requirements met</label>
                <label><input v-model="form.mine" type="checkbox" :disabled="!selected" @change="submit()" /> My crafts</label>
            </div>
        </form>
        <div class="flex flex-wrap items-center justify-between gap-3 my-5">
            <p class="text-sm text-muted">{{ number(pagination.total) }} of {{ number(pagination.all) }} open crafts</p>
            <div class="flex items-center gap-2">
                <TrackerRefreshStatus :refresh="refresh" />
                <AppButton variant="ghost" size="sm" class="w-8 h-8" :disabled="busy" title="Refresh crafts" aria-label="Refresh crafts" @click="submit(pagination.page)">&#8635;</AppButton>
            </div>
        </div>
        <p v-if="error" class="py-4 text-sm text-danger" role="alert">{{ error }}</p>
        <p v-if="playerError" class="pb-4 text-sm text-muted" role="status">{{ playerError }}</p>
        <p v-if="playerRefresh?.delayed" class="pb-4 text-sm text-muted" role="status">Player XP refresh delayed. Level projections use the last available player snapshot.</p>
        <p v-if="!selected" class="pb-4 text-sm text-muted">No player selected. Level projections unavailable.</p>
        <div v-if="crafts.length" class="craft-table-wrap" :aria-busy="busy">
            <table class="craft-table">
                <thead><tr><th>Craft</th><th>Location</th><th>Progress</th><th>Remaining XP</th><th>Full craft XP</th><th>Projected level</th><th></th></tr></thead>
                <tbody>
                    <template v-for="craft in crafts" :key="craft.id">
                        <tr>
                            <td>
                                <div class="craft-item">
                                    <img v-if="bitcraftAssetUrl(craft.outputs[0]?.iconAssetName)" :src="bitcraftAssetUrl(craft.outputs[0]?.iconAssetName)" alt="" width="36" height="36" loading="lazy" @error="$event.target.style.visibility = 'hidden'" />
                                    <div class="min-w-0"><strong class="block break-words">{{ craft.name }}</strong><span class="text-xs text-muted">{{ number(craft.count) }} crafts / {{ craft.skill }}</span></div>
                                </div>
                            </td>
                            <td><strong class="block break-words">{{ craft.claim }}</strong><span class="text-xs text-muted">Region {{ craft.region }} / {{ craft.owner }}</span></td>
                            <td><span>{{ craft.progressPercent }}%</span><progress :value="craft.progressPercent" max="100" class="block w-20 h-1.5 mt-2" /></td>
                            <td class="tabular-nums">{{ number(craft.remainingXp) }}</td>
                            <td class="tabular-nums">{{ number(craft.fullXp) }}</td>
                            <td>
                                <template v-if="craft.afterLevel !== null">
                                    <strong>{{ craft.currentLevel }} &rarr; {{ craft.afterLevel }}</strong>
                                    <span :class="craft.levelsGained > 0 ? 'text-success' : 'text-muted'" class="block text-xs">+{{ craft.levelsGained }} level{{ craft.levelsGained === 1 ? '' : 's' }}</span>
                                    <span v-if="craft.meetsLevel === false" class="block text-xs text-danger">Level too low</span>
                                </template>
                                <span v-else class="text-muted">-</span>
                            </td>
                            <td><button type="button" class="craft-details-button" :aria-expanded="expanded === craft.id" :aria-label="`Details for ${craft.name}`" title="Craft details" @click="expanded = expanded === craft.id ? null : craft.id">{{ expanded === craft.id ? '-' : '+' }}</button></td>
                        </tr>
                        <tr v-if="expanded === craft.id" class="craft-detail-row"><td colspan="7">
                            <dl class="craft-details">
                                <div><dt>Station</dt><dd>{{ craft.building || '-' }}</dd></div>
                                <div><dt>Coordinates</dt><dd>{{ craft.x ?? '-' }}, {{ craft.z ?? '-' }}</dd></div>
                                <div><dt>Current XP</dt><dd>{{ number(craft.currentXp) }}</dd></div>
                                <div><dt>After remaining work</dt><dd>{{ craft.afterLevel === null ? '-' : `Level ${craft.afterLevel}` }}</dd></div>
                                <div><dt>Whole craft from scratch</dt><dd>{{ craft.fullLevel === null ? '-' : `Level ${craft.currentLevel} to ${craft.fullLevel} (+${craft.fullLevel - craft.currentLevel})` }}</dd></div>
                                <div><dt>Level requirements</dt><dd>{{ craft.requirements.map(requirement => `${requirement.skill} ${requirement.level}`).join(', ') || '-' }}<span v-if="craft.meetsLevel !== null" :class="craft.meetsLevel ? 'text-success' : 'text-danger'"> / {{ craft.meetsLevel ? 'Met' : 'Not met' }}</span></dd></div>
                                <div><dt>Tools required</dt><dd>{{ craft.toolRequirements.map(toolLabel).join(', ') || 'None' }}</dd></div>
                            </dl>
                        </td></tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p v-else-if="!error" class="py-10 border-y border-border text-sm text-muted text-center">No open crafts match these filters.</p>
        <div class="flex items-center justify-between gap-3 mt-5">
            <AppButton variant="ghost" size="sm" :disabled="busy || pagination.page <= 1" @click="submit(pagination.page - 1)">&larr; Previous</AppButton>
            <span class="text-xs text-muted">{{ pagination.page }} / {{ pagination.lastPage }}</span>
            <AppButton variant="ghost" size="sm" :disabled="busy || pagination.page >= pagination.lastPage" @click="submit(pagination.page + 1)">Next &rarr;</AppButton>
        </div>
        <p class="mt-5 text-xs text-muted">Base-progress estimates. Critical hits, per-action rounding and XP bonuses can change actual gains. Tool access is not verified.</p>
    </AuthenticatedLayout>
</template>

<style scoped>
.craft-filters { display: grid; grid-template-columns: minmax(180px, 2fr) repeat(3, minmax(110px, 1fr)) auto; gap: 14px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color); }
.craft-toggles { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 12px 22px; font-size: 13px; }
.craft-toggles label { display: flex; align-items: center; gap: 8px; }
.craft-table-wrap { overflow-x: auto; border-block: 1px solid var(--border-color); }
.craft-table { width: 100%; min-width: 920px; border-collapse: collapse; font-size: 13px; }
.craft-table th { text-align: left; color: var(--text-muted); font-size: 11px; font-weight: 600; }
.craft-table th, .craft-table td { padding: 14px 12px; border-bottom: 1px solid var(--border-color); }
.craft-table td:first-child { width: 30%; }
.craft-table td:nth-child(2) { width: 22%; overflow-wrap: anywhere; }
.craft-item { display: flex; align-items: center; gap: 10px; }
.craft-item img { flex: 0 0 36px; object-fit: contain; }
.craft-details-button { width: 28px; height: 28px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 18px; }
.craft-details { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.craft-details dt { color: var(--text-muted); font-size: 11px; margin-bottom: 4px; }
.craft-details dd { overflow-wrap: anywhere; }
.craft-detail-row { background: var(--bg-surface); }
progress { accent-color: var(--accent-cyan); }
@media (max-width: 900px) { .craft-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .craft-search { grid-column: 1 / -1; } }
</style>
