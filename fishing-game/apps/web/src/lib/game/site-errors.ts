export function siteErrorCopy(input: number) {
  const status = Number.isInteger(input) && input >= 400 && input <= 599 ? input : 500;
  if (status === 404) return { status, title: "That spot isn't on the map.", description: "We couldn't find this page. It may have moved, or the link may be incomplete.", retry: false };
  if (status === 401) return { status, title: "Let's get you back to Camp.", description: 'Head back to Camp to link your Discord account, then try opening this page again.', retry: false };
  if (status === 403) return { status, title: 'This part of the shore is closed.', description: "You can't open this page with your current access. Head back to Camp or choose another page.", retry: false };
  if (status === 408 || status === 504) return { status, title: 'The tide took too long.', description: 'This page took too long to answer. Try again in a moment, or head back to Camp.', retry: true };
  if (status === 410) return { status, title: 'This trail has washed away.', description: 'That page is no longer available. Head back to Camp or open the Fish Book.', retry: false };
  if (status === 429) return { status, title: 'One moment, angler.', description: 'Too many requests reached this page. Give it a moment before trying again.', retry: true };
  if (status === 502 || status === 503) return { status, title: 'The dock is taking a breather.', description: "Fishbound couldn't reach this part of the site right now. Try again shortly.", retry: true };
  if (status >= 500) return { status, title: "We've hit a snag.", description: 'Something went wrong while loading this page. Try again in a moment, or head back to Camp.', retry: true };
  return { status, title: "We couldn't open this page.", description: 'Head back to Camp or choose another page to keep exploring.', retry: false };
}

export function safeRetryPath(path: unknown): string {
  if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//') || /[\\\u0000-\u001f\u007f]/.test(path)) return '/';
  try {
    const url = new URL(path, 'https://fishbound.invalid');
    return url.origin === 'https://fishbound.invalid' ? url.pathname : '/';
  } catch {
    return '/';
  }
}
