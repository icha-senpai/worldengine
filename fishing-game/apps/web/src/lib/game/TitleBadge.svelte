<script lang="ts">
  import type { AchievementDefinition } from '@fishing-game/generated/types';
  import manifest from '../../../../../assets/manifest.json';
  let { definition, compact = false, onpreview }: { definition?: AchievementDefinition; compact?: boolean; onpreview?: () => void } = $props();
  const artwork = $derived(manifest.achievements.find(asset => asset.key === 'badge-' + definition?.achievementId)?.url ?? '');
  // Stable per-achievement hues; the medal art gives every title its own emblem.
  const hue = $derived(definition ? (definition.achievementId * 137.508) % 360 : 95);
  const label = $derived(definition?.title ?? 'Angler');
</script>

{#snippet contents()}
  {#if artwork}<img src={artwork} alt="" width="48" height="48" loading="lazy" decoding="async" />{/if}
  <span>{label}</span>
{/snippet}

{#if definition && onpreview}
  <button class="title-badge" class:compact style={'--title-hue:' + hue} onclick={onpreview} aria-label={'View ' + definition.name + ' badge for ' + label} title={definition.description}>{@render contents()}</button>
{:else}<span class="title-badge" class:compact style={'--title-hue:' + hue} title={definition?.description}>{@render contents()}</span>{/if}

<style>
  .title-badge{display:inline-flex;align-items:center;gap:9px;max-width:100%;box-sizing:border-box;padding:5px 12px 5px 6px;border:1px solid hsl(var(--title-hue) 35% 45%);border-radius:4px;background:hsl(var(--title-hue) 42% 91%);color:hsl(var(--title-hue) 40% 25%);font:18px var(--font-game);text-align:left;line-height:1.3;vertical-align:middle}.title-badge img{display:block;width:48px;height:48px;object-fit:contain;image-rendering:pixelated;flex:none}.title-badge span{overflow-wrap:anywhere}.title-badge.compact{font-size:13px;gap:5px;padding:3px 7px 3px 4px}.compact img{width:26px;height:26px}button.title-badge{cursor:zoom-in}button.title-badge:hover{filter:brightness(.97)}button.title-badge:focus-visible{outline:3px solid #648b74;outline-offset:3px}
</style>
