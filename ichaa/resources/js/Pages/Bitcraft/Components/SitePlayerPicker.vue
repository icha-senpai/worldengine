<script setup>
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'

const page = usePage()
const open = ref(false)
const query = ref('')
const players = ref([])
const searched = ref(false)
const loading = ref(false)
const error = ref('')
const form = useForm({ entityId: null })
let searchId = 0

async function search() {
    if (query.value.trim().length < 2) return
    const id = ++searchId
    loading.value = true
    error.value = ''
    players.value = []
    try {
        const { data } = await axios.get(route('bitcraft.players.search'), { params: { q: query.value.trim() } })
        if (id !== searchId) return
        players.value = data.players
        searched.value = true
    } catch (exception) {
        if (id !== searchId) return
        error.value = exception.response?.data?.error ?? 'Player search is unavailable. Try again shortly.'
    } finally {
        if (id === searchId) loading.value = false
    }
}

function select(player) {
    form.entityId = player?.entityId ?? null
    form.put(route('bitcraft.player.update'), {
        preserveScroll: true,
        preserveState: 'errors',
        onSuccess: () => { open.value = false },
    })
}

function show() {
    form.clearErrors()
    open.value = true
}
</script>

<template>
    <div class="site-player-bar">
        <div class="min-w-0">
            <span class="text-xs text-muted">Site player</span>
            <strong class="block text-sm break-words">{{ page.props.bitcraft?.player?.username ?? 'No player selected' }}</strong>
        </div>
        <AppButton variant="ghost" size="sm" class="shrink-0" @click="show">{{ page.props.bitcraft?.player ? 'Change player' : 'Select player' }}</AppButton>
    </div>
    <Modal :show="open" max-width="lg" @close="open = false">
        <section class="p-5 sm:p-6" aria-labelledby="site-player-title">
            <div class="flex items-center justify-between gap-3 mb-5">
                <h2 id="site-player-title" class="text-lg font-semibold">Site player</h2>
                <AppButton variant="ghost" size="sm" @click="open = false">Close</AppButton>
            </div>
            <form class="flex gap-2" @submit.prevent="search">
                <label class="min-w-0 flex-1">
                    <span class="field-label">Player name</span>
                    <TextInput v-model="query" type="search" maxlength="80" class="w-full" autofocus />
                </label>
                <AppButton type="submit" class="self-end" :disabled="loading || query.trim().length < 2">{{ loading ? 'Searching...' : 'Search' }}</AppButton>
            </form>
            <p v-if="error || form.errors.entityId" class="text-sm text-danger mt-3" role="alert">{{ error || form.errors.entityId }}</p>
            <p v-else-if="searched && !players.length" class="text-sm text-muted mt-4" role="status">No matching players.</p>
            <ul class="mt-4 max-h-80 overflow-y-auto divide-y divide-border">
                <li v-for="player in players" :key="player.entityId">
                    <button type="button" class="player-result" :disabled="form.processing" @click="select(player)">
                        <strong class="break-words">{{ player.username }}</strong>
                        <span class="text-xs text-muted break-all">{{ player.entityId }}</span>
                    </button>
                </li>
            </ul>
            <AppButton v-if="page.props.bitcraft?.player" variant="ghost" class="mt-5" :disabled="form.processing" @click="select(null)">Clear player</AppButton>
        </section>
    </Modal>
</template>

<style scoped>
.site-player-bar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid var(--border-color); }
.player-result { display: flex; flex-direction: column; gap: 4px; width: 100%; padding: 12px 8px; text-align: left; border-radius: 4px; }
.player-result:hover, .player-result:focus-visible { background: var(--bg-surface-2); }
</style>
