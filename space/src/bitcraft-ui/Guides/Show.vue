<script setup>
import { route } from "/src/bitcraft-ui/navigation";
import { Head, Link } from "/src/bitcraft-ui/navigation";
import AuthenticatedLayout from "/src/bitcraft-ui/Layout.vue";
import AppButton from "/src/bitcraft-ui/shared/ui/AppButton.vue";
import GuideContent from "./GuideContent.vue";
import BitcraftPageHeader from "../Components/BitcraftPageHeader.vue";

defineProps({
  guide: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
});

const formatDate = (value) => new Date(value).toLocaleDateString();
</script>

<template>
  <AuthenticatedLayout>
    <Head :title="guide.title" />
    <template #header>
      <BitcraftPageHeader :title="guide.title" eyebrow="BitCraft Guides">
        <template #eyebrow>
          <Link :href="route('bitcraft.guides.index')" class="hover:underline"
            >Back to guides</Link
          >
        </template>
        <template #meta>
          <div class="flex flex-wrap items-center gap-2">
            <span v-if="guide.category" class="tag max-w-full break-words">{{
              guide.category
            }}</span>
            <span v-if="!guide.published_at" class="tag">Draft</span>
            <span>{{ guide.author?.name ?? "Admin" }}</span>
            <span v-if="guide.published_at"
              >Published {{ formatDate(guide.published_at) }}</span
            >
            <span>Updated {{ formatDate(guide.updated_at) }}</span>
          </div>
        </template>
        <template v-if="canManage" #actions>
          <AppButton
            :href="route('bitcraft.guides.edit', guide.id)"
            variant="primary"
            >Edit guide</AppButton
          >
        </template>
      </BitcraftPageHeader>
    </template>

    <article
      class="guide-article mx-auto w-full max-w-5xl border-t border-border pt-6"
    >
      <p v-if="guide.summary" class="mb-6 break-words text-lg text-muted-2">
        {{ guide.summary }}
      </p>
      <GuideContent :content="guide.content" />
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
