<template>
    <AppButton
        type="button"
        variant="sync"
        :size="compact ? 'sm' : 'md'"
        :disabled="form.processing"
        @click="runSync"
    >
        {{ form.processing ? processingLabel : label }}
    </AppButton>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AppButton from '@/Components/ui/AppButton.vue'

const props = defineProps({
    resource: { type: String, required: true },
    label: { type: String, default: 'Sync from Notion' },
    processingLabel: { type: String, default: 'Syncing...' },
    compact: { type: Boolean, default: false },
})

const form = useForm({})

const runSync = () => {
    form.post(route('notion.sync', { resource: props.resource }), {
        preserveScroll: true,
    })
}
</script>
