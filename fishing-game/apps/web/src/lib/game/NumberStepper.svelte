<script lang="ts">
  import { parseNumberValue, stepNumberValue } from './custom-control-logic';

  let generatedId = $props.id();
  let {
    value,
    onchange,
    min,
    max,
    step = 1,
    label,
    id,
    describedby,
    disabled = false
  }: {
    value: number;
    onchange: (value: number) => void | Promise<void>;
    min: number;
    max: number;
    step?: number;
    label: string;
    id?: string;
    describedby?: string;
    disabled?: boolean;
  } = $props();
  const controlId = $derived(id ?? generatedId);

  let raw = $state('');
  let editing = $state(false);
  let lastValue: number | undefined;
  let initialized = false;
  let pendingValue: number | undefined;
  const parsed = $derived(parseNumberValue(raw));
  const invalid = $derived(!Number.isFinite(parsed) || parsed < min || parsed > max || !Number.isInteger((parsed - min) / step));

  $effect(() => {
    const incoming = value;
    if (!initialized) {
      raw = Number.isFinite(incoming) ? String(incoming) : '';
      lastValue = incoming;
      initialized = true;
      return;
    }
    if (!Object.is(incoming, lastValue)) {
      lastValue = incoming;
      if (editing) pendingValue = incoming;
      else raw = Number.isFinite(incoming) ? String(incoming) : '';
    }
  });

  function inputChanged(event: Event) {
    raw = (event.currentTarget as HTMLInputElement).value;
    editing = true;
    pendingValue = undefined;
    const next = parseNumberValue(raw);
    lastValue = next;
    void onchange(next);
  }

  function blur() {
    editing = false;
    if (pendingValue !== undefined) {
      raw = Number.isFinite(pendingValue) ? String(pendingValue) : '';
      lastValue = pendingValue;
      pendingValue = undefined;
    }
  }

  function setValue(next: number) {
    raw = String(next);
    editing = false;
    pendingValue = undefined;
    lastValue = next;
    void onchange(next);
  }

  function adjust(direction: -1 | 1) {
    if (disabled) return;
    setValue(stepNumberValue(parsed, min, max, step, direction));
  }

  function keydown(event: KeyboardEvent) {
    if (disabled) return;
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      adjust(event.key === 'ArrowUp' ? 1 : -1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      setValue(min);
    } else if (event.key === 'End') {
      event.preventDefault();
      setValue(max);
    }
  }
</script>

<div class="number-stepper">
  <label for={controlId}>{label}</label>
  <div class="number-stepper-control">
    <button class="number-stepper-button" type="button" aria-label={`Decrease ${label}`} disabled={disabled || Number.isFinite(parsed) && parsed <= min} onclick={() => adjust(-1)}>−</button>
    <input
      id={controlId}
      type="text"
      inputmode="numeric"
      role="spinbutton"
      aria-label={label}
      aria-valuemin={min}
      aria-valuemax={max}
      aria-valuenow={Number.isFinite(parsed) ? parsed : undefined}
      aria-invalid={invalid}
      aria-describedby={describedby}
      value={raw}
      disabled={disabled}
      oninput={inputChanged}
      onblur={blur}
      onkeydown={keydown}
    />
    <button class="number-stepper-button" type="button" aria-label={`Increase ${label}`} disabled={disabled || Number.isFinite(parsed) && parsed >= max} onclick={() => adjust(1)}>+</button>
  </div>
</div>

<style>
  .number-stepper{display:grid;gap:5px;min-width:0}
  .number-stepper-control{display:flex;align-items:stretch;gap:6px;min-width:0}
  .number-stepper-control input{flex:1;min-width:0;text-align:center;font-variant-numeric:tabular-nums}
  .number-stepper-control input[aria-invalid="true"]{border-color:var(--danger-ink)}
  .number-stepper-button{flex:none;min-width:44px;min-height:44px;padding:6px 10px;border:1px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink);font:22px var(--font-game)}
  .number-stepper-button:hover:not(:disabled){background:var(--paper-light)}
  .number-stepper-button:disabled{opacity:.55;cursor:not-allowed}
  .number-stepper-button:focus-visible{outline:3px solid var(--control-focus);outline-offset:2px}
</style>
