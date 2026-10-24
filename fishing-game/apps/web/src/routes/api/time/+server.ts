import { json } from '@sveltejs/kit';

// The web service and authoritative database run on the same host clock.
export function GET() {
  return json({ serverTimeMs: Date.now() }, {
    headers: { 'Cache-Control': 'no-store' },
  });
}
