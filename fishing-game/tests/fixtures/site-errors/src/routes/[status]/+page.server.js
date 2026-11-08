import { error } from '@sveltejs/kit';

export function load({ params, url, setHeaders }) {
  if (params.status === 'throw') throw new Error('UNSAFE_THROW_MARKER');
  if (params.status === 'recover' && !url.searchParams.has('fail')) return;
  const status = params.status === 'recover' ? 503 : Number(params.status);
  if (status === 429) setHeaders({ 'retry-after': '120' });
  if ([401, 403, 404, 408, 410, 429, 500, 502, 503, 504].includes(status)) {
    error(status, 'UNSAFE_EXPECTED_MARKER');
  }
  error(404, 'UNSAFE_EXPECTED_MARKER');
}
