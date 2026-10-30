export function svelteKitOutDir(database: string | undefined): string {
  return database?.startsWith('fishbound-proof-') ? '.svelte-kit-proof' : '.svelte-kit';
}
