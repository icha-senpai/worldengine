import * as env from '$app/env/public';
import { createDevLogin } from '../../../lib/server/dev-login';
import { link, readIdentity } from '../../../lib/server/dev-login-runtime';

const handler = createDevLogin({
  development: import.meta.env.DEV,
  uri: env.PUBLIC_SPACETIMEDB_URI ?? '',
  database: env.PUBLIC_SPACETIMEDB_DATABASE ?? '',
  readIdentity,
  link,
});

export const fallback = ({ request }: { request: Request }) => handler(request);
