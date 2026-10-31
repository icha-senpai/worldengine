let activeCloser: (() => void) | null = null;

export function registerOpenPopover(close: () => void) {
  if (activeCloser && activeCloser !== close) activeCloser();
  activeCloser = close;
  return () => {
    if (activeCloser === close) activeCloser = null;
  };
}
