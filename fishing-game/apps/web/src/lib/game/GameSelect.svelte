<script lang="ts" generics="T extends string | number | boolean">
  import { onDestroy, tick } from 'svelte';
  import type { ControlOption } from './custom-control-logic';
  import { firstEnabledIndex, lastEnabledIndex, nextEnabledIndex, positionFloatingMenu, typeaheadIndex } from './custom-control-logic';
  import { registerOpenPopover } from './custom-control-popovers';

  let generatedId = $props.id();
  let {
    options,
    value,
    onchange,
    label,
    id,
    disabled = false,
    placeholder = 'Choose…'
  }: {
    options: readonly ControlOption<T>[];
    value?: T;
    onchange: (value: T) => void | Promise<void>;
    label: string;
    id?: string;
    disabled?: boolean;
    placeholder?: string;
  } = $props();

  let root: HTMLSpanElement;
  let trigger: HTMLButtonElement;
  let menu: HTMLDivElement;
  let open = $state(false);
  let fallbackOpen = $state(false);
  let activeIndex = $state(-1);
  let menuStyle = $state('');
  let typeBuffer = '';
  let typeTimer: ReturnType<typeof setTimeout> | undefined;
  let releasePopover: (() => void) | null = null;
  let resizeObserver: ResizeObserver | null = null;
  let listenersAttached = false;
  let lastValue: T | undefined;
  let lastOptions: readonly ControlOption<T>[] | undefined;
  let metadataInitialized = false;
  let menuParent: Node | null = null;
  let menuNextSibling: Node | null = null;

  const controlId = $derived(id ?? generatedId);
  const listboxId = $derived(`${controlId}-listbox`);
  const selected = $derived(options.find(option => Object.is(option.value, value)));
  const activeOptionId = $derived(open && activeIndex >= 0 && !options[activeIndex]?.disabled
    ? `${controlId}-option-${activeIndex}`
    : undefined);

  function clearTypeahead() {
    if (typeTimer !== undefined) clearTimeout(typeTimer);
    typeTimer = undefined;
    typeBuffer = '';
  }

  function detachPositioning() {
    if (!listenersAttached) return;
    document.removeEventListener('pointerdown', closeOnOutsidePointer, true);
    document.removeEventListener('keydown', closeOnKeyboardExit, true);
    window.removeEventListener('resize', reposition);
    window.removeEventListener('scroll', reposition, true);
    window.visualViewport?.removeEventListener('resize', reposition);
    resizeObserver?.disconnect();
    resizeObserver = null;
    listenersAttached = false;
  }

  function close(restoreFocus = false) {
    if (!open && !fallbackOpen) return;
    open = false;
    fallbackOpen = false;
    try {
      if (menu?.matches(':popover-open')) menu.hidePopover();
    } catch {}
    if (menuParent && menu) {
      menuParent.insertBefore(menu, menuNextSibling?.parentNode === menuParent ? menuNextSibling : null);
      menu.setAttribute('popover', 'manual');
      menuParent = null;
      menuNextSibling = null;
    }
    releasePopover?.();
    releasePopover = null;
    detachPositioning();
    clearTypeahead();
    if (restoreFocus) trigger?.focus();
  }

  function closeOnOutsidePointer(event: PointerEvent) {
    if (!root?.contains(event.target as Node) && !menu?.contains(event.target as Node)) close(false);
  }

  function closeOnKeyboardExit(event: KeyboardEvent) {
    if (!open) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close(true);
    } else if (event.key === 'Tab') close(false);
  }

  function reposition() {
    if (!open) return;
    if (!root?.isConnected || !trigger?.isConnected || !menu?.isConnected) {
      close(false);
      return;
    }
    const anchor = trigger.getBoundingClientRect();
    if (!anchor.width || !anchor.height) {
      close(false);
      return;
    }
    const layout = positionFloatingMenu(anchor, window.innerWidth, window.innerHeight, Math.min(320, options.length * 44));
    if (!layout.maxHeight) {
      close(false);
      return;
    }
    menuStyle = `left:${layout.left}px;top:${layout.top}px;width:${layout.width}px;max-height:${layout.maxHeight}px`;
  }

  function attachPositioning() {
    if (listenersAttached) return;
    document.addEventListener('pointerdown', closeOnOutsidePointer, true);
    document.addEventListener('keydown', closeOnKeyboardExit, true);
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
    window.visualViewport?.addEventListener('resize', reposition);
    if (typeof ResizeObserver !== 'undefined') {
      resizeObserver = new ResizeObserver(reposition);
      resizeObserver.observe(trigger);
    }
    listenersAttached = true;
  }

  function scrollActive() {
    if (!menu || activeIndex < 0) return;
    menu.querySelector<HTMLElement>(`[data-option-index="${activeIndex}"]`)?.scrollIntoView({ block: 'nearest' });
  }

  function selectedIndex() {
    return options.findIndex(option => Object.is(option.value, value) && !option.disabled);
  }

  function showFallback() {
    menuParent = menu.parentNode;
    menuNextSibling = menu.nextSibling;
    menu.removeAttribute('popover');
    (root.closest('dialog[open]') ?? document.body).appendChild(menu);
    fallbackOpen = true;
  }

  async function show(initialIndex?: number) {
    if (disabled || open) return;
    const first = firstEnabledIndex(options);
    if (first < 0) return;
    const selectedOptionIndex = selectedIndex();
    activeIndex = initialIndex ?? (selectedOptionIndex >= 0 ? selectedOptionIndex : first);
    open = true;
    releasePopover = registerOpenPopover(() => close(false));
    await tick();
    if (!open || !menu) return;
    try {
      if (typeof menu.showPopover === 'function') {
        menu.showPopover();
      } else {
        showFallback();
      }
    } catch {
      showFallback();
    }
    attachPositioning();
    reposition();
    scrollActive();
  }

  function move(direction: -1 | 1) {
    const next = nextEnabledIndex(options, activeIndex, direction);
    if (next < 0) return;
    activeIndex = next;
    reposition();
    scrollActive();
  }

  function setTypeahead(character: string, openIfClosed: boolean) {
    typeBuffer += character.toLocaleLowerCase();
    if (typeTimer !== undefined) clearTimeout(typeTimer);
    typeTimer = setTimeout(clearTypeahead, 500);
    const next = typeaheadIndex(options, activeIndex, typeBuffer);
    if (next < 0) return;
    if (!open && openIfClosed) void show(next);
    else {
      activeIndex = next;
      scrollActive();
    }
  }

  function handleKeydown(event: KeyboardEvent) {
    if (disabled) return;
    const { key } = event;
    if (key === 'Escape' && open) {
      event.preventDefault();
      event.stopPropagation();
      close(true);
      return;
    }
    if (key === 'Tab') {
      close(false);
      return;
    }
    if (!open) {
      if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Home' || key === 'End' || key === 'Enter' || key === ' ') {
        event.preventDefault();
        const first = firstEnabledIndex(options);
        const last = lastEnabledIndex(options);
        const selectedOptionIndex = selectedIndex();
        const initial = key === 'Home' ? first
          : key === 'End' ? last
            : selectedOptionIndex >= 0 ? selectedOptionIndex
              : key === 'ArrowUp' ? last : first;
        void show(initial);
        return;
      }
      if (key.length === 1 && !event.altKey && !event.ctrlKey && !event.metaKey) {
        event.preventDefault();
        setTypeahead(key, true);
      }
      return;
    }
    if (key === 'ArrowDown' || key === 'ArrowUp') {
      event.preventDefault();
      move(key === 'ArrowDown' ? 1 : -1);
    } else if (key === 'Home' || key === 'End') {
      event.preventDefault();
      activeIndex = key === 'Home' ? firstEnabledIndex(options) : lastEnabledIndex(options);
      scrollActive();
    } else if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      commit(activeIndex);
    } else if (key.length === 1 && !event.altKey && !event.ctrlKey && !event.metaKey) {
      event.preventDefault();
      setTypeahead(key, false);
    }
  }

  function commit(index: number) {
    const option = options[index];
    if (!option || option.disabled) return;
    const changed = !Object.is(option.value, value);
    close(false);
    trigger?.focus();
    if (changed) void onchange(option.value);
  }

  function keepTriggerFocus(event: PointerEvent) {
    if (event.button === 0) event.preventDefault();
  }

  $effect(() => {
    const currentOptions = options;
    const currentValue = value;
    if (!metadataInitialized) {
      lastValue = currentValue;
      lastOptions = currentOptions;
      metadataInitialized = true;
      return;
    }
    const optionsChanged = currentOptions !== lastOptions;
    const valueChanged = !Object.is(currentValue, lastValue);
    lastOptions = currentOptions;
    lastValue = currentValue;
    if (valueChanged) {
      if (open) {
        const currentSelection = currentOptions.findIndex(option => Object.is(option.value, currentValue) && !option.disabled);
        if (currentSelection >= 0) activeIndex = currentSelection;
      }
    }
    if (!open) return;
    const first = firstEnabledIndex(currentOptions);
    if (disabled || first < 0) {
      close(false);
      return;
    }
    if (optionsChanged || !currentOptions[activeIndex] || currentOptions[activeIndex].disabled) {
      const currentSelection = currentOptions.findIndex(option => Object.is(option.value, currentValue) && !option.disabled);
      activeIndex = currentSelection >= 0 ? currentSelection : first;
    }
    void tick().then(() => {
      if (open) {
        reposition();
        scrollActive();
      }
    });
  });

  onDestroy(() => close(false));
</script>

<span class="game-select" bind:this={root}>
  <button
    bind:this={trigger}
    id={controlId}
    type="button"
    class="game-select-trigger"
    data-value={value === undefined ? '' : String(value)}
    role="combobox"
    aria-label={label}
    aria-haspopup="listbox"
    aria-expanded={open}
    aria-controls={listboxId}
    aria-activedescendant={activeOptionId}
    disabled={disabled}
    onclick={() => open ? close(false) : void show()}
    onkeydown={handleKeydown}
  >
    <span class:placeholder={!selected} class="game-select-value">{selected?.label ?? placeholder}</span>
    <svg class="game-select-chevron" viewBox="0 0 12 8" aria-hidden="true"><path d="m1 1 5 5 5-5" /></svg>
  </button>
  <div
    bind:this={menu}
    id={listboxId}
    class="game-select-menu"
    class:is-fallback-open={fallbackOpen}
    popover="manual"
    role="listbox"
    aria-label={label}
    style={menuStyle}
  >
    {#each options as option, index (option.value)}
      <button
        id={`${controlId}-option-${index}`}
        class="game-select-option"
        class:active={activeIndex === index}
        class:selected={Object.is(option.value, value)}
        class:disabled={option.disabled}
        disabled={option.disabled ?? false}
        data-option-index={index}
        role="option"
        tabindex="-1"
        type="button"
        aria-selected={Object.is(option.value, value)}
        aria-disabled={option.disabled ?? false}
        onpointerdown={keepTriggerFocus}
        onpointermove={() => { if (!option.disabled) activeIndex = index; }}
        onclick={() => commit(index)}
      >
        <span class="game-select-option-label">{option.label}</span>
        {#if Object.is(option.value, value)}<span class="game-select-check" aria-hidden="true">✓</span>{/if}
      </button>
    {/each}
  </div>
</span>

<style>
  .game-select{display:inline-block;width:100%;min-width:0;max-width:100%;vertical-align:middle}
  .game-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:.75rem;width:100%;min-width:0;min-height:44px;padding:.6rem .75rem;border:2px solid var(--control-border);border-radius:2px;background:var(--control-paper);color:var(--ink);box-shadow:inset 2px 2px 0 var(--panel-highlight);font:inherit;font-size:max(1rem,1em);line-height:1.4;text-align:left}
  .game-select-trigger:focus-visible{outline:3px solid var(--control-focus);outline-offset:4px}
  .game-select-trigger:disabled{opacity:.55;cursor:not-allowed}
  .game-select-value{flex:1;min-width:0;overflow-wrap:anywhere}
  .game-select-value.placeholder{color:var(--ink-muted)}
  .game-select-chevron{width:14px;height:10px;flex:none;fill:none;stroke:currentColor;stroke-width:2}
  .game-select-menu{display:none;position:fixed;inset:auto;z-index:1000;margin:0;padding:4px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;border:2px solid var(--control-border);border-radius:2px;background:var(--paper-light);color:var(--ink);box-shadow:0 4px 0 var(--wood-dark)}
  .game-select-menu:popover-open,.game-select-menu.is-fallback-open{display:block}
  .game-select-option{display:flex;align-items:flex-start;gap:8px;width:100%;min-height:40px;padding:8px 10px;border:0;background:transparent;color:var(--ink);font:inherit;line-height:1.35;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:normal;cursor:pointer}
  .game-select-option.active{background:color-mix(in srgb,var(--control-accent) 16%,var(--paper-light))}
  .game-select-option.selected{background:var(--selected-background);color:var(--selected-ink)}
  .game-select-option[aria-disabled="true"]{color:var(--ink-muted);opacity:.55;cursor:not-allowed}
  .game-select-option-label{flex:1;min-width:0}
  .game-select-check{flex:none;font-weight:900}
  @media(forced-colors:active){.game-select-trigger,.game-select-menu{border-color:ButtonText;box-shadow:none}.game-select-trigger{background:Field;color:FieldText}.game-select-menu{background:Canvas;color:CanvasText}.game-select-option.active{outline:1px solid Highlight}.game-select-option.selected{background:Highlight;color:HighlightText}.game-select-option[aria-disabled="true"]{color:GrayText}}
</style>
