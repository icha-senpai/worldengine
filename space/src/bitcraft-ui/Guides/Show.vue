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
      <aside
        v-if="
          Number(guide.id) === 16 &&
          Date.parse(guide.updated_at) < Date.parse('2026-11-10T16:09:27Z')
        "
        class="ui-panel mb-6 space-y-3 p-4"
        aria-label="November 10 claim rules"
      >
        <strong>November 10 claim rules</strong>
        <p>
          The placement limits, upkeep examples and research table below predate
          this release. Tile upkeep now uses progressive brackets. Stability is
          max(1, exp(5.5 × (5.65 − average claimed neighbours per tile)));
          building maintenance is added afterwards. Claims received 10% more
          tiles. Use Claim History for current supply estimates.
        </p>
        <p>
          Settlement Research replaces Codexes for advancement. Homesteads and
          Townships have different unlocks; choosing Homestead cannot be
          reversed without restarting the claim. Banks and marketplaces are
          regional.
        </p>
        <a
          href="https://bitcraftonline.com/news/patch-notes-starfell-reawakened"
          class="underline"
          target="_blank"
          rel="noopener noreferrer"
          >Read the November 10 patch notes</a
        >
      </aside>
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
