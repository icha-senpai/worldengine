<script lang="ts">
  import { page } from '$app/state';
  import ErrorScreen from '../lib/game/ErrorScreen.svelte';
</script>

<ErrorScreen status={page.status} retryPath={page.url.pathname} />
