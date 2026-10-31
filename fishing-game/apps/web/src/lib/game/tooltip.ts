import { positionFloatingMenu } from './custom-control-logic';

let nextTooltipId = 0;
let activeTooltipClose: (() => void) | null = null;

export function tooltip(node: HTMLElement, initialMessage?: string) {
  let message = initialMessage ?? '';
  let tooltipElement: HTMLDivElement | null = null;
  let visible = false;
  let focused = false;
  let hoveringAnchor = false;
  let hoveringTooltip = false;
  let fallbackOpen = false;
  let hideTimer: ReturnType<typeof setTimeout> | undefined;
  let touchHoldUntil = 0;
  let repositionObserver: ResizeObserver | null = null;
  const tooltipId = `game-tooltip-${++nextTooltipId}`;
  const originalDescription = node.getAttribute('aria-description');
  const hoverParent = node.parentElement ?? node;

  function updateDescription() {
    if (message) node.setAttribute('aria-description', originalDescription ? `${originalDescription} ${message}` : message);
    else if (originalDescription === null) node.removeAttribute('aria-description');
    else node.setAttribute('aria-description', originalDescription);
  }

  function addDescribedBy() {
    const ids = (node.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
    if (!ids.includes(tooltipId)) ids.push(tooltipId);
    node.setAttribute('aria-describedby', ids.join(' '));
  }

  function removeDescribedBy() {
    const ids = (node.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(id => id && id !== tooltipId);
    if (ids.length) node.setAttribute('aria-describedby', ids.join(' '));
    else node.removeAttribute('aria-describedby');
  }

  function clearHideTimer() {
    if (hideTimer !== undefined) clearTimeout(hideTimer);
    hideTimer = undefined;
  }

  function position() {
    if (!visible || !tooltipElement?.isConnected || !node.isConnected) {
      hide();
      return;
    }
    const rect = node.getBoundingClientRect();
    if (!rect.width && !rect.height) {
      hide();
      return;
    }
    const anchor = { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom, width: Math.min(320, rect.width || 180) };
    const layout = positionFloatingMenu(anchor, window.innerWidth, window.innerHeight, tooltipElement.scrollHeight || 44, 180);
    tooltipElement.style.left = `${layout.left}px`;
    tooltipElement.style.top = `${layout.top}px`;
    tooltipElement.style.width = `${layout.width}px`;
    tooltipElement.style.maxHeight = `${layout.maxHeight}px`;
  }

  function createTooltip() {
    if (tooltipElement) return;
    tooltipElement = document.createElement('div');
    tooltipElement.className = 'game-tooltip';
    tooltipElement.id = tooltipId;
    tooltipElement.setAttribute('role', 'tooltip');
    tooltipElement.setAttribute('popover', 'manual');
    tooltipElement.textContent = message;
    tooltipElement.addEventListener('pointerenter', () => {
      hoveringTooltip = true;
      clearHideTimer();
    });
    tooltipElement.addEventListener('pointerleave', () => {
      hoveringTooltip = false;
      scheduleHide();
    });
    const dialog = node.closest('dialog[open]');
    (dialog ?? document.body).append(tooltipElement);
  }

  function show() {
    clearHideTimer();
    if (!message || !node.isConnected) return;
    createTooltip();
    if (!visible) {
      if (activeTooltipClose && activeTooltipClose !== hide) activeTooltipClose();
      activeTooltipClose = hide;
      try {
        if (typeof tooltipElement?.showPopover === 'function') {
          tooltipElement.showPopover();
        } else if (tooltipElement) {
          fallbackOpen = true;
          tooltipElement.classList.add('is-fallback-open');
        }
      } catch {
        fallbackOpen = true;
        tooltipElement?.classList.add('is-fallback-open');
      }
      visible = true;
      addDescribedBy();
    }
    if (tooltipElement) tooltipElement.textContent = message;
    position();
  }

  function hide() {
    clearHideTimer();
    if (!visible) return;
    visible = false;
    try {
      if (tooltipElement?.matches(':popover-open')) tooltipElement.hidePopover();
    } catch {}
    if (fallbackOpen) tooltipElement?.classList.remove('is-fallback-open');
    fallbackOpen = false;
    touchHoldUntil = 0;
    removeDescribedBy();
    if (activeTooltipClose === hide) activeTooltipClose = null;
  }

  function scheduleHide() {
    clearHideTimer();
    const delay = Math.max(220, touchHoldUntil - Date.now());
    hideTimer = setTimeout(() => {
      if (!focused && !hoveringAnchor && !hoveringTooltip) hide();
    }, delay);
  }

  function pointerIsOnAnchor(event: PointerEvent) {
    const target = document.elementFromPoint(event.clientX, event.clientY);
    return !!target && (target === node || node.contains(target));
  }

  function onPointerEnter(event: PointerEvent) {
    if (!node.matches(':disabled') || pointerIsOnAnchor(event)) {
      hoveringAnchor = true;
      show();
    }
  }

  function onPointerMove(event: PointerEvent) {
    if (!node.matches(':disabled')) return;
    const onAnchor = pointerIsOnAnchor(event);
    if (onAnchor) {
      hoveringAnchor = true;
      show();
    } else if (hoveringAnchor) {
      hoveringAnchor = false;
      scheduleHide();
    }
  }

  function onPointerLeave(event: PointerEvent) {
    if (event.currentTarget === hoverParent && pointerIsOnAnchor(event)) return;
    hoveringAnchor = false;
    scheduleHide();
  }

  function onPointerDown(event: PointerEvent) {
    if (event.currentTarget === node || pointerIsOnAnchor(event)) {
      if (event.pointerType === 'touch') touchHoldUntil = Date.now() + 1600;
      show();
    }
  }

  function onTouchStart(event: TouchEvent) {
    if (!node.matches(':disabled') || !event.touches.length) return;
    const touch = event.touches[0];
    const target = document.elementFromPoint(touch.clientX, touch.clientY);
    if (target && (target === node || node.contains(target))) {
      touchHoldUntil = Date.now() + 1600;
      show();
    }
  }

  function onFocus() {
    focused = true;
    show();
  }

  function onBlur() {
    focused = false;
    scheduleHide();
  }

  function onKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape' || !visible) return;
    event.preventDefault();
    event.stopPropagation();
    hide();
  }

  node.addEventListener('pointerenter', onPointerEnter);
  node.addEventListener('pointerleave', onPointerLeave);
  node.addEventListener('pointerdown', onPointerDown);
  hoverParent.addEventListener('pointermove', onPointerMove);
  hoverParent.addEventListener('pointerdown', onPointerDown);
  hoverParent.addEventListener('touchstart', onTouchStart, { passive: true });
  hoverParent.addEventListener('pointerleave', onPointerLeave);
  node.addEventListener('focus', onFocus);
  node.addEventListener('blur', onBlur);
  document.addEventListener('keydown', onKeydown, true);
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', position);
  updateDescription();
  if (typeof ResizeObserver !== 'undefined') {
    repositionObserver = new ResizeObserver(position);
    repositionObserver.observe(node);
  }

  return {
    update(nextMessage?: string) {
      message = nextMessage ?? '';
      updateDescription();
      if (!message) hide();
      else if (visible && tooltipElement) {
        tooltipElement.textContent = message;
        position();
      }
    },
    destroy() {
      hide();
      repositionObserver?.disconnect();
      node.removeEventListener('pointerenter', onPointerEnter);
      node.removeEventListener('pointerleave', onPointerLeave);
      node.removeEventListener('pointerdown', onPointerDown);
      hoverParent.removeEventListener('pointermove', onPointerMove);
      hoverParent.removeEventListener('pointerdown', onPointerDown);
      hoverParent.removeEventListener('touchstart', onTouchStart);
      hoverParent.removeEventListener('pointerleave', onPointerLeave);
      node.removeEventListener('focus', onFocus);
      node.removeEventListener('blur', onBlur);
      document.removeEventListener('keydown', onKeydown, true);
      window.removeEventListener('scroll', hide, true);
      window.removeEventListener('resize', position);
      if (node.getAttribute('aria-description') === (originalDescription ? `${originalDescription} ${message}` : message)) {
        if (originalDescription === null) node.removeAttribute('aria-description');
        else node.setAttribute('aria-description', originalDescription);
      }
      tooltipElement?.remove();
    }
  };
}
