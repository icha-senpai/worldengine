<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-primary text-2xl font-light tracking-wide">Trash</h1>
                <p class="text-muted-3 text-sm font-ui mt-1">Recently removed records stay here until you restore them.</p>
            </div>
        </template>

        <form class="panel max-w-4xl mb-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-[220px_1fr_auto] md:items-end">
                <div class="field-group">
                    <label class="field-label" for="trash-type">Type</label>
                    <SelectInput id="trash-type" v-model="form.type" class="w-full">
                        <option value="">Everything</option>
                        <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </SelectInput>
                </div>

                <div class="field-group">
                    <label class="field-label" for="trash-query">Search</label>
                    <TextInput
                        id="trash-query"
                        v-model="form.q"
                        type="text"
                        class="w-full"
                        placeholder="Find a deleted record..."
                    />
                </div>

                <div class="flex items-center gap-3">
                    <AppButton type="submit" variant="primary">Apply</AppButton>
                    <AppButton v-if="hasFilters" type="button" variant="ghost" @click="clearFilters">Clear</AppButton>
                </div>
            </div>
        </form>

        <div v-if="items.length" class="space-y-3">
            <div v-for="item in items" :key="`${item.type}-${item.id}`" class="record-card record-card--split">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="resource-chip">{{ item.resource_label }}</span>
                        <span class="deleted-at">Deleted {{ formatDate(item.deleted_at) }}</span>
                    </div>
                    <h2 class="trash-title">{{ item.title }}</h2>
                    <p v-if="item.subtitle" class="trash-subtitle">{{ item.subtitle }}</p>
                </div>

                <AppButton type="button" variant="sync" @click="restoreItem(item)">
                    Restore
                </AppButton>
            </div>
        </div>

        <div v-else class="empty-state">
            <p class="empty-title">Trash is empty.</p>
            <p class="empty-copy mt-2">Deleted items will show up here once something has been moved to trash.</p>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'

const props = defineProps({
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ type: '', q: '' }) },
    typeOptions: { type: Array, default: () => [] },
})

const form = reactive({
    type: props.filters.type ?? '',
    q: props.filters.q ?? '',
})

const hasFilters = computed(() => Boolean(form.type || form.q.trim()))

const submit = () => {
    router.get(route('trash.index'), {
        type: form.type || undefined,
        q: form.q.trim() || undefined,
    }, {
        preserveState: true,
        replace: true,
    })
}

const clearFilters = () => {
    form.type = ''
    form.q = ''
    submit()
}

const restoreItem = async (item) => {
    const confirmed = await confirmDialog({
        title: 'Restore Record',
        message: `Restore "${item.title}" from trash?`,
        confirmLabel: 'Restore',
        cancelLabel: 'Cancel',
        confirmVariant: 'primary',
    })

    if (!confirmed) {
        return
    }

    router.post(route('trash.restore', {
        type: item.type,
        record: item.id,
    }), {}, {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not restore record',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

const formatDate = (value) => {
    if (!value) {
        return 'recently'
    }

    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
}
</script>
