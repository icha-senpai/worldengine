<script lang="ts">
  import LazyPanel from '../../../apps/web/src/lib/game/LazyPanel.svelte';
  import LazySuccess from './LazySuccess.svelte';
  const failures = Number(new URLSearchParams(window.location.search).get('failures') ?? 1);
  let calls = 0;
  let release: (() => void) | undefined;
  const fixture = {
    calls: () => calls,
    release: () => release?.(),
  };
  (window as unknown as { lazyFixture: typeof fixture }).lazyFixture = fixture;
  function load() {
    calls++;
    if (calls <= failures) return Promise.reject(new Error('fixture failure'));
    return new Promise<{ default: typeof LazySuccess }>(resolve => {
      release = () => resolve({ default: LazySuccess });
    });
  }
</script>

<label>Other section input <input aria-label="Other section input" /></label>
<LazyPanel active={true} {load} componentProps={{}} />
