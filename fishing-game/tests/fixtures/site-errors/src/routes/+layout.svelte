<script>
  import AppLayout from '../../../../../apps/web/src/routes/+layout.svelte';
  let { children } = $props();
</script>

<AppLayout>{@render children()}</AppLayout>
