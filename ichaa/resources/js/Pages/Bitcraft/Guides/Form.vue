<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import InputError from '@/Components/InputError.vue'
import RichDocumentValue from '@/Components/scaffold/RichDocumentValue.vue'
import { emptyRichDocument } from '@/lib/tiptap/documents'

const RichTextEditor = defineAsyncComponent(() => import('@/Components/scaffold/RichTextEditor.vue'))
const props = defineProps({ guide: { type: Object, default: null } })
const mode = ref('write')
const form = useForm({
    title: props.guide?.title ?? '',
    summary: props.guide?.summary ?? '',
    category: props.guide?.category ?? '',
    content: props.guide?.content ?? emptyRichDocument(),
    is_published: Boolean(props.guide?.published_at),
})
const backHref = computed(() => props.guide
    ? route('bitcraft.guides.show', props.guide.id)
    : route('bitcraft.guides.index'))
const saveLabel = computed(() => form.is_published
    ? (props.guide?.published_at ? 'Save changes' : 'Publish guide')
    : 'Save draft')
const contentError = computed(() => Object.entries(form.errors)
    .find(([key]) => key === 'content' || key.startsWith('content.'))?.[1])

function save() {
    const options = { preserveScroll: true }
    if (props.guide) {
        form.put(route('bitcraft.guides.update', props.guide.id), options)
    } else {
        form.post(route('bitcraft.guides.store'), options)
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="guide ? 'Edit guide' : 'New guide'" />
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow"><span>BitCraft Guides</span></div>
                    <h1 class="page-hero__title">{{ guide ? 'Edit guide' : 'New guide' }}</h1>
                </div>
            </div>
        </template>

        <form class="guide-form mx-auto flex w-full max-w-6xl flex-col gap-6" @submit.prevent="save">
            <div>
                <label for="guide-title" class="field-label">Title</label>
                <TextInput id="guide-title" v-model="form.title" class="w-full" required maxlength="255" :disabled="form.processing" />
                <InputError :message="form.errors.title" class="mt-2" />
            </div>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div>
                    <label for="guide-summary" class="field-label">Summary</label>
                    <TextareaInput id="guide-summary" v-model="form.summary" class="w-full" rows="3" maxlength="1000" :disabled="form.processing" />
                    <InputError :message="form.errors.summary" class="mt-2" />
                </div>
                <div>
                    <label for="guide-category" class="field-label">Category</label>
                    <TextInput id="guide-category" v-model="form.category" class="w-full" maxlength="100" :disabled="form.processing" />
                    <InputError :message="form.errors.category" class="mt-2" />
                </div>
            </div>
            <section class="min-w-0">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <label for="guide-content" class="field-label">Content</label>
                    <div class="flex gap-1" role="tablist" aria-label="Guide editor mode">
                        <button id="guide-write-tab" type="button" role="tab" class="app-btn app-btn--sm app-btn--ghost" :class="{ 'app-btn--selected-accent': mode === 'write' }" :aria-selected="mode === 'write'" aria-controls="guide-write-panel" @click="mode = 'write'">Write</button>
                        <button id="guide-preview-tab" type="button" role="tab" class="app-btn app-btn--sm app-btn--ghost" :class="{ 'app-btn--selected-accent': mode === 'preview' }" :aria-selected="mode === 'preview'" aria-controls="guide-preview-panel" @click="mode = 'preview'">Preview</button>
                    </div>
                </div>
                <div id="guide-write-panel" v-show="mode === 'write'" role="tabpanel" aria-labelledby="guide-write-tab" :inert="form.processing || undefined">
                    <RichTextEditor v-model="form.content" input-id="guide-content" aria-label="Guide content" described-by="guide-content-error" />
                </div>
                <div id="guide-preview-panel" v-if="mode === 'preview'" class="guide-preview min-h-64 border-y border-border py-5" role="tabpanel" aria-labelledby="guide-preview-tab">
                    <RichDocumentValue :content="form.content" />
                </div>
                <InputError id="guide-content-error" :message="contentError" class="mt-2" />
            </section>
            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-border py-5">
                <div>
                    <label class="flex items-center gap-3 text-sm">
                        <input v-model="form.is_published" type="checkbox" class="rounded border-border text-cyan focus:ring-cyan" :disabled="form.processing" />
                        Published
                    </label>
                    <InputError :message="form.errors.is_published" class="mt-2" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton :href="backHref" variant="ghost" :disabled="form.processing">Cancel</AppButton>
                    <AppButton type="submit" :disabled="form.processing">{{ form.processing ? 'Saving...' : saveLabel }}</AppButton>
                </div>
            </div>
        </form>
    </AuthenticatedLayout>
</template>

<style scoped>
.guide-form {
    min-width: 0;
}

.guide-preview {
    overflow-wrap: anywhere;
    overflow-x: auto;
}

@media (max-width: 639px) {
    .guide-form :deep(.editor-toolbar__row .editor-tool-group),
    .guide-form :deep(.editor-toolbar__row .editor-tool) {
        width: auto;
    }
}
</style>
