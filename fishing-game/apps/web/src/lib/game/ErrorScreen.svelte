<script lang="ts">
  import '../../../../../assets/site-errors.css';
  import ThemeToggle from './ThemeToggle.svelte';
  import { safeRetryPath, siteErrorCopy } from './site-errors';

  let { status, retryPath = '/' }: { status: number; retryPath?: string } = $props();
  const headingId = $props.id();
  const copy = $derived(siteErrorCopy(status));
  const retry = $derived(safeRetryPath(retryPath));
</script>

<svelte:head>
  <title>{copy.status} · Fishbound</title>
  <meta name="robots" content="noindex, nofollow" />
</svelte:head>

<main class="site-error-page">
  <div class="site-error-shell">
    <header class="site-error-header">
      <a class="site-error-brand" href="/#camp"><img src="/fish/koi.png" width="46" height="36" alt="" />Fishbound</a>
      <span class="site-error-header-note">A quiet bend in the water</span>
    </header>
    <section class="site-error-card" aria-labelledby={`${headingId}-heading`}>
      <div class="site-error-copy">
        <p class="site-error-kicker">HTTP {copy.status}</p>
        <h1 id={`${headingId}-heading`}>{copy.title}</h1>
        <p class="site-error-description">{copy.description}</p>
        {#if copy.status >= 500}
          <p class="site-error-note">If a catch or purchase was interrupted, check its result in Camp before trying it again.</p>
        {/if}
        <div class="site-error-actions">
          <a class="site-error-action site-error-action-primary" href="/#camp">Back to Camp</a>
          <a class="site-error-action" href="/catalog">Open Fish Book</a>
          {#if copy.retry}<a class="site-error-action" href={retry} data-sveltekit-reload>Try this page again</a>{/if}
        </div>
      </div>
      <div class="site-error-water" aria-hidden="true"><span class="site-error-ripple"></span><img src="/fish/koi.png" alt="" width="160" height="160" /></div>
    </section>
    <footer class="site-error-footer"><span>Fishbound · Find your way back</span><ThemeToggle /></footer>
  </div>
</main>
