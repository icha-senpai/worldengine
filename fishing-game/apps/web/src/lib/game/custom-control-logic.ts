export type ControlOption<T extends string | number | boolean> = {
  value: T;
  label: string;
  disabled?: boolean;
};

export function firstEnabledIndex<T extends string | number | boolean>(options: readonly ControlOption<T>[]) {
  return options.findIndex(option => !option.disabled);
}

export function lastEnabledIndex<T extends string | number | boolean>(options: readonly ControlOption<T>[]) {
  for (let index = options.length - 1; index >= 0; index--) {
    if (!options[index].disabled) return index;
  }
  return -1;
}

export function nextEnabledIndex<T extends string | number | boolean>(
  options: readonly ControlOption<T>[],
  current: number,
  direction: -1 | 1
) {
  if (!options.length) return -1;
  for (let offset = 1; offset <= options.length; offset++) {
    const index = (current + direction * offset + options.length * 2) % options.length;
    if (!options[index].disabled) return index;
  }
  return -1;
}

export function typeaheadIndex<T extends string | number | boolean>(
  options: readonly ControlOption<T>[],
  current: number,
  buffer: string
) {
  const normalized = buffer.trim().toLocaleLowerCase();
  if (!normalized || !options.length) return -1;
  const repeated = normalized.length > 1 && [...normalized].every(character => character === normalized[0]);
  const query = repeated ? normalized[0] : normalized;
  const start = current < 0 ? -1 : current;
  for (let offset = 1; offset <= options.length; offset++) {
    const index = (start + offset + options.length) % options.length;
    const option = options[index];
    if (!option.disabled && option.label.toLocaleLowerCase().startsWith(query)) return index;
  }
  return -1;
}

export function parseNumberValue(value: string) {
  if (!value.trim()) return Number.NaN;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : Number.NaN;
}

export function stepNumberValue(value: number, min: number, max: number, step: number, direction: -1 | 1) {
  if (!Number.isFinite(value)) return direction > 0 ? min : max;
  const units = (value - min) / step;
  const nearest = Math.round(units);
  const aligned = Math.abs(units - nearest) < 1e-9 ? nearest : units;
  const next = direction > 0 ? Math.floor(aligned) + 1 : Math.ceil(aligned) - 1;
  return Math.max(min, Math.min(max, min + next * step));
}

export type FloatingRect = Pick<DOMRect, 'left' | 'top' | 'right' | 'bottom' | 'width'>;

export function positionFloatingMenu(
  anchor: FloatingRect,
  viewportWidth: number,
  viewportHeight: number,
  desiredHeight: number,
  minWidth = 220
) {
  const margin = 12;
  const gap = 4;
  const maxWidth = Math.max(0, viewportWidth - margin * 2);
  const width = Math.min(maxWidth, Math.max(anchor.width, minWidth));
  const above = Math.max(0, anchor.top - margin - gap);
  const below = Math.max(0, viewportHeight - anchor.bottom - margin - gap);
  const preferredHeight = Math.min(320, Math.max(44, desiredHeight));
  const placement = below >= Math.min(preferredHeight, 160) || below >= above ? 'below' : 'above';
  const maxHeight = Math.min(320, placement === 'below' ? below : above);
  const height = Math.min(preferredHeight, maxHeight);
  const maxLeft = Math.max(margin, viewportWidth - margin - width);
  const left = Math.min(Math.max(margin, anchor.left), maxLeft);
  const top = placement === 'below'
    ? Math.min(viewportHeight - margin - height, anchor.bottom + gap)
    : Math.max(margin, anchor.top - gap - height);
  return { left, top, width, height, maxHeight, placement };
}
