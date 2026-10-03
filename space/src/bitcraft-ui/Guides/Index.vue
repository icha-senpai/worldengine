<script setup>
import { route } from "/src/bitcraft-ui/navigation";
import { computed } from "vue";
import { Head, useForm } from "/src/bitcraft-ui/navigation";
import ScaffoldIndexPage from "/src/bitcraft-ui/shared/scaffold/ScaffoldIndexPage.vue";
import AppButton from "/src/bitcraft-ui/shared/ui/AppButton.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";
import InputError from "/src/bitcraft-ui/shared/InputError.vue";
import EditorAccess from "./EditorAccess.vue";

const props = defineProps({
  guides: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
});

const search = useForm({ q: props.filters.q ?? "" });
const items = computed(() =>
  props.guides.data.map((guide) => ({
    id: guide.id,
    title: guide.title,
    subtitle: guide.summary,
    href: route("bitcraft.guides.show", guide.id),
    badges: [
      ...(guide.category ? [{ label: "Category", value: guide.category }] : []),
      ...(props.canManage
        ? [
            {
              label: "Status",
              value: guide.published_at ? "Published" : "Draft",
            },
          ]
        : []),
    ],
    meta: [
      { label: "Author", value: guide.author?.name ?? "Admin" },
      {
        label: "Updated",
        value: new Date(guide.updated_at).toLocaleDateString(),
      },
    ],
  })),
);

function applySearch() {
  search.get(route("bitcraft.guides.index"), {
    preserveState: true,
    preserveScroll: true,
  });
}

function clearSearch() {
  search.q = "";
  applySearch();
}
</script>

<template>
  <div>
    <Head title="BitCraft Guides" />
    <ScaffoldIndexPage
      title="BitCraft Guides"
      :count="guides.total"
      count-label="guides"
      :items="items"
      :pagination="guides"
      :create-href="canManage ? route('bitcraft.guides.create') : ''"
      create-label="New guide"
      :empty-title="
        filters.q ? 'No guides match your search.' : 'No guides published yet.'
      "
      :empty-cta-href="canManage ? route('bitcraft.guides.create') : ''"
      empty-cta-label="New guide"
    >
      <template #toolbar>
        <form
          class="mb-5 flex flex-wrap items-end gap-3"
          @submit.prevent="applySearch"
        >
          <div class="min-w-0 flex-1 basis-64">
            <label for="guide-search" class="field-label">Search guides</label>
            <TextInput
              id="guide-search"
              v-model="search.q"
              type="search"
              maxlength="255"
              class="w-full"
            />
            <InputError :message="search.errors.q" class="mt-2" />
          </div>
          <AppButton type="submit" variant="ghost" :disabled="search.processing"
            >Search</AppButton
          >
          <AppButton
            v-if="search.q || filters.q"
            variant="ghost"
            :disabled="search.processing"
            @click="clearSearch"
            >Clear</AppButton
          >
          <EditorAccess v-if="!canManage" />
        </form>
      </template>
    </ScaffoldIndexPage>
  </div>
</template>
