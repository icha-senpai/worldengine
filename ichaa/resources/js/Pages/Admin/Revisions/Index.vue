<template>
    <ScaffoldIndexPage
        title="Revisions"
        :count="countRecords(revisions)"
        count-label="revision records"
        :items="items"
        empty-title="No revisions found"
    >
        <template #toolbar>
            <div class="space-y-4">
                <ScaffoldFilterBar
                    :fields="filterFields"
                    :form="filterForm"
                    :has-active-filters="hasActiveFilters"
                    :on-apply="applyFilters"
                    :on-clear="clearFilters"
                />

                <form
                    v-if="compareOptions.length >= 2"
                    class="panel flex flex-col gap-3 md:flex-row md:items-end"
                    @submit.prevent="compareRevisions"
                >
                    <div class="field-group flex-1">
                        <label class="field-label" for="left-revision">Left revision</label>
                        <SelectInput id="left-revision" v-model="compareForm.left" class="w-full">
                            <option value="">Choose a revision</option>
                            <option v-for="option in compareOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </SelectInput>
                    </div>

                    <div class="field-group flex-1">
                        <label class="field-label" for="right-revision">Right revision</label>
                        <SelectInput id="right-revision" v-model="compareForm.right" class="w-full">
                            <option value="">Choose a revision</option>
                            <option v-for="option in compareOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </SelectInput>
                    </div>

                    <AppButton type="submit" variant="primary" :disabled="!canCompare">
                        Compare
                    </AppButton>
                </form>
            </div>
        </template>
    </ScaffoldIndexPage>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import SelectInput from '@/Components/SelectInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import { countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    revisions: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterFields: { type: Array, default: () => [] },
    compareOptions: { type: Array, default: () => [] },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('admin.revisions.index', {
    q: props.filters.q ?? '',
    resource_type: props.filters.resource_type ?? '',
    resource_id: props.filters.resource_id ?? '',
    action: props.filters.action ?? '',
})

const compareForm = reactive({
    left: '',
    right: '',
})

const canCompare = computed(() =>
    compareForm.left !== ''
    && compareForm.right !== ''
    && compareForm.left !== compareForm.right,
)

const compareRevisions = () => {
    if (!canCompare.value) {
        return
    }

    router.get(route('admin.revisions.compare'), {
        left: compareForm.left,
        right: compareForm.right,
    })
}
</script>
