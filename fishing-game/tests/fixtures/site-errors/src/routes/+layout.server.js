export function load({ url }) {
  if (url.pathname === '/fatal') throw new Error('UNSAFE_FATAL_MARKER');
}
