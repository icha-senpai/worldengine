<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import RichDocumentValue from '@/Components/scaffold/RichDocumentValue.vue'

defineProps({
    guide: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
})

const formatDate = (value) => new Date(value).toLocaleDateString()
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="guide.title" />
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy min-w-0">
                    <Link :href="route('bitcraft.guides.index')" class="text-sm text-cyan hover:underline">Back to guides</Link>
                    <h1 class="page-hero__title mt-3 break-words">{{ guide.title }}</h1>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted-2">
                        <span v-if="guide.category" class="tag max-w-full break-words">{{ guide.category }}</span>
                        <span v-if="!guide.published_at" class="tag">Draft</span>
                        <span>{{ guide.author?.name ?? 'Admin' }}</span>
                        <span v-if="guide.published_at">Published {{ formatDate(guide.published_at) }}</span>
                        <span>Updated {{ formatDate(guide.updated_at) }}</span>
                    </div>
                </div>
                <div v-if="canManage" class="page-hero__actions">
                    <AppButton :href="route('bitcraft.guides.edit', guide.id)" variant="primary">Edit guide</AppButton>
                </div>
            </div>
        </template>

        <article class="guide-article mx-auto w-full max-w-5xl border-t border-border pt-6">
            <p v-if="guide.summary" class="mb-6 break-words text-lg text-muted-2">{{ guide.summary }}</p>
            <RichDocumentValue :content="guide.content" />
        </article>
    </AuthenticatedLayout>
</template>

<style scoped>
.guide-article {
    min-width: 0;
    overflow-wrap: anywhere;
}

.guide-article :deep(.rich-document-value) {
    overflow-x: auto;
}

.guide-article :deep(img) {
    max-width: 100%;
    height: auto;
}
</style>
