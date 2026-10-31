<script lang="ts">
  import type { AchievementDefinition } from '@fishing-game/generated/types';
  import manifest from '../../../../../assets/manifest.json';
  import { tooltip } from './tooltip';
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
  <button class="title-badge" class:compact style={'--title-hue:' + hue} onclick={onpreview} aria-label={'View ' + definition.name + ' badge for ' + label} use:tooltip={definition.description}>{@render contents()}</button>
{:else}<span class="title-badge" class:compact style={'--title-hue:' + hue} use:tooltip={definition?.description}>{@render contents()}</span>{/if}

<style>
  .title-badge{display:inline-flex;align-items:center;gap:9px;max-width:100%;box-sizing:border-box;padding:5px 12px 5px 6px;border:1px solid color-mix(in srgb,var(--paper-edge) 65%,hsl(var(--title-hue) 50% 50%));border-radius:4px;background:color-mix(in srgb,var(--paper-light) 88%,hsl(var(--title-hue) 50% 50%));color:var(--ink);font:21px var(--font-game);text-align:left;line-height:1.3;vertical-align:middle}.title-badge img{display:block;width:48px;height:48px;object-fit:contain;image-rendering:pixelated;flex:none}.title-badge span{overflow-wrap:anywhere}.title-badge.compact{font-size:16px;gap:5px;padding:3px 7px 3px 4px}.compact img{width:26px;height:26px}button.title-badge{cursor:zoom-in}button.title-badge:hover{filter:brightness(.97)}button.title-badge:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}
</style>
