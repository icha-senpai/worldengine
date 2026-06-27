<template>
    <div>
        <ScaffoldShowPage
            :title="title"
            :subtitle="subtitle"
            :back-label="backLabel"
            :back-href="backHref"
            :edit-href="editHref"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="showHref"
            :destroy-href="destroyHref"
            :destroy-label="destroyLabel"
            :destroy-confirm="destroyConfirm"
            :badge="badge"
            :hero-meta="heroMeta"
            :sections="sections"
        >
            <template v-if="editDrawer" #edit-drawer>
                <ResourceForm
                    embedded
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import ResourceForm from '@/Pages/ScaffoldResources/Form.vue'

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    backHref: { type: [String, Object], required: true },
    backLabel: { type: String, required: true },
    showHref: { type: [String, Object], required: true },
    editHref: { type: [String, Object], default: null },
    destroyHref: { type: [String, Object], default: null },
    destroyLabel: { type: String, default: 'Move to Trash' },
    destroyConfirm: { type: String, default: 'Move this item to trash?' },
    badge: { type: String, default: '' },
    heroMeta: { type: Array, default: () => [] },
    sections: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})
</script>
