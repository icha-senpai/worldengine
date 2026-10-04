<script lang="ts">
  let { page = $bindable(1), total, pageSize = 12, label = 'Book pages' } = $props<{
    page?: number; total: number; pageSize?: number; label?: string;
  }>();
  const pages = $derived(Math.max(1, Math.ceil(total / pageSize)));
  $effect(() => { if (page > pages) page = pages; });
</script>

<nav class="page-turner" aria-label={label}>
  <button disabled={page <= 1} onclick={() => page--} aria-label="Previous page">← Previous</button>
  <span aria-live="polite">Page {page} of {pages}<small>{total ? (page - 1) * pageSize + 1 : 0}–{Math.min(page * pageSize, total)} of {total}</small></span>
  <button disabled={page >= pages} onclick={() => page++} aria-label="Next page">Next →</button>
</nav>

<style>
  .page-turner{display:flex;justify-content:center;align-items:center;gap:22px;margin-top:24px;padding-top:18px;border-top:1px dashed #b99a69}
  button{font-family:var(--font-game);font-size:17px;padding:8px 14px;border:2px solid #7e6446;background:#e4d4a9;color:var(--ink);box-shadow:inset 0 0 0 2px #fff1cc,0 3px 0 #9d835f}
  button:hover:not(:disabled){background:#f4dfac}button:disabled{opacity:.45}
  span{text-align:center;font-family:var(--font-game);font-size:17px;color:#644c31}small{display:block;font-family:var(--font-body);font-size:11px;color:#79664a}
  @media(max-width:600px){.page-turner{gap:12px}button{font-size:15px;padding:8px 10px}span{font-size:15px}}
</style>
