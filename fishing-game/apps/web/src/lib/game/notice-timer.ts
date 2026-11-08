export function createNoticeTimer(onVisibleChange: (visible: boolean) => void, delayMs = 6000) {
  let key: string | null = null;
  let visible = false;
  let paused = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let generation = 0;

  function cancel() {
    generation++;
    if (timer !== undefined) clearTimeout(timer);
    timer = undefined;
  }
  function setVisible(value: boolean) {
    if (visible !== value) {
      visible = value;
      onVisibleChange(value);
    }
  }
  function schedule() {
    cancel();
    if (!visible || paused) return;
    const current = generation;
    timer = setTimeout(() => {
      if (generation !== current) return;
      timer = undefined;
      setVisible(false);
    }, delayMs);
  }
  return {
    show(next: string) {
      if (next === key) return;
      key = next;
      setVisible(true);
      schedule();
    },
    dismiss() {
      cancel();
      paused = false;
      setVisible(false);
    },
    reset() {
      cancel();
      key = null;
      paused = false;
      setVisible(false);
    },
    pause() {
      paused = true;
      cancel();
    },
    resume() {
      paused = false;
      schedule();
    },
  };
}

export type NoticeTimer = ReturnType<typeof createNoticeTimer>;

export function holdNotice(node: HTMLElement, controller: NoticeTimer) {
  let hovered = node.matches(':hover');
  let held = false;
  function update() {
    const next = hovered || node.contains(document.activeElement);
    if (next === held) return;
    held = next;
    if (held) controller.pause();
    else controller.resume();
  }
  function enter() { hovered = true; update(); }
  function leave() { hovered = false; update(); }
  function focusOut(event: FocusEvent) {
    if (event.relatedTarget instanceof Node && node.contains(event.relatedTarget)) return;
    const next = hovered;
    if (next !== held) {
      held = next;
      if (held) controller.pause();
      else controller.resume();
    }
  }
  node.addEventListener('pointerenter', enter);
  node.addEventListener('pointerleave', leave);
  node.addEventListener('focusin', update);
  node.addEventListener('focusout', focusOut);
  update();
  return {
    destroy() {
      node.removeEventListener('pointerenter', enter);
      node.removeEventListener('pointerleave', leave);
      node.removeEventListener('focusin', update);
      node.removeEventListener('focusout', focusOut);
      if (held) controller.resume();
    },
  };
}
