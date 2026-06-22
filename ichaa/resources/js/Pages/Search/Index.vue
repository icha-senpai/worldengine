<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-primary text-2xl font-light tracking-wide">Search</h1>
                <p class="text-muted-3 text-sm font-ui mt-1">Cross-domain lookup for entities, documents, secrets, glossary terms, and synced Notion notes.</p>
            </div>
        </template>

        <form @submit.prevent="submit" class="panel max-w-3xl mb-5">
            <label class="field-label">Query</label>
            <div class="flex gap-3 mt-2">
                <TextInput v-model="query" type="text" class="flex-1" placeholder="Search the archive..." />
                <AppButton type="submit" variant="primary">Search</AppButton>
            </div>
        </form>

        <div v-if="!term" class="empty-state">
            <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">Enter a term to start searching</p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <section v-for="group in groups" :key="group.title" class="panel">
                <h3 class="panel-label">{{ group.title }}</h3>

                <ul v-if="group.items.length" class="space-y-2">
                    <li v-for="item in group.items" :key="item.label" class="list-row">
                        <Link v-if="item.href" :href="item.href" class="list-link text-primary hover:text-cyan transition-colors">
                            {{ item.label }}
                        </Link>
                        <span v-else class="list-link text-primary">{{ item.label }}</span>
                        <p v-if="item.meta" class="prose-wrap text-muted-3 text-sm mt-1.5">{{ item.meta }}</p>
                        <p v-if="item.noteExcerpt" class="prose-wrap text-muted-2 text-sm mt-1.5">
                            <span class="text-muted-3 font-ui uppercase tracking-wider text-[10px]">Notion note</span>
                            {{ item.noteExcerpt }}
                        </p>
                    </li>
                </ul>

                <p v-else class="text-muted-3 text-sm font-ui">No matches.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    results: { type: Object, default: () => ({}) },
    term: { type: String, default: '' },
})

const query = ref(props.term)

watch(() => props.term, (value) => {
    query.value = value
})

const submit = () => {
    router.get(route('search'), query.value ? { q: query.value } : {}, { preserveState: true, replace: true })
}

const groups = computed(() => [
    {
        title: 'Entities',
        items: (props.results.entities ?? []).map((entity) => ({
            label: entity.name,
            meta: `${formatLabel(entity.entity_type)} · ${formatLabel(entity.status)}`,
            href: route('entities.show', entity.id),
            noteExcerpt: entity.notion_note_excerpt,
        })),
    },
    {
        title: 'Documents',
        items: (props.results.documents ?? []).map((document) => ({
            label: document.title,
            meta: `${formatLabel(document.document_type)} · ${formatLabel(document.document_status)}`,
            href: route('documents.show', document.id),
            noteExcerpt: document.notion_note_excerpt,
        })),
    },
    {
        title: 'Secrets',
        items: (props.results.secrets ?? []).map((secret) => ({
            label: secret.title,
            meta: `${formatLabel(secret.secret_type)} · ${formatLabel(secret.exposure_risk)}`,
            href: route('secrets.show', secret.id),
            noteExcerpt: secret.notion_note_excerpt,
        })),
    },
    {
        title: 'Glossary',
        items: (props.results.glossary ?? []).map((term) => ({
            label: term.term,
            meta: term.usage_context,
            href: route('glossary.show', term.id),
            noteExcerpt: term.notion_note_excerpt,
        })),
    },
])
</script>
