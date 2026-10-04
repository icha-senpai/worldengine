import { defineEnvVars } from '@sveltejs/kit/env';

function publicUrl(value: string | undefined) {
  if (!value) return '';
  const url = new URL(value);
  const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
  if (!['https:', 'wss:'].includes(url.protocol) && !(loopback && ['http:', 'ws:'].includes(url.protocol))) {
    throw new Error('Public service URLs require TLS except for loopback development');
  }
  if (url.username || url.password || url.search || url.hash) throw new Error('Use a plain service URL without credentials');
  return value;
}
export const variables = defineEnvVars({
  PUBLIC_SPACETIMEDB_URI: { public: true, schema: publicUrl },
  PUBLIC_SPACETIMEDB_DATABASE: { public: true, schema: (value: string | undefined) => value ?? '' },
  PUBLIC_ACCOUNT_LINK_URL: { public: true, schema: publicUrl },
});
