import { error } from '@sveltejs/kit';

export function GET() {
  error(403, 'Fixture API error');
}
