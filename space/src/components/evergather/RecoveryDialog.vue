<script setup>
import { computed } from "vue";
import MaterialsDialog from "./MaterialsDialog.vue";
import { useActionForm } from "../../evergather/useActionForm";
import { repairTarget } from "../../evergather/gameplayUi";
import { selectQuickAction } from "../../evergather/playSession";
const props = defineProps({
  request: { type: Object, required: true },
  sources: { type: Object, required: true },
  player: { type: Object, required: true },
});
const emit = defineEmits(["close"]);
const form = useActionForm({
  recipe: "",
  job: "",
  expedition: "",
  tool_id: "",
  activity: "",
});
const entry = computed(() =>
  (
    ({
      craft: props.sources.recipes,
      job: props.sources.jobs,
      expedition: props.sources.expeditions,
      repair: props.sources.tools,
      acquire: props.sources.inventory,
      activity: props.sources.activities,
    })[props.request.action] ?? []
  ).find((value) =>
    props.request.action === "repair"
      ? value.tool_id === props.request.key
      : props.request.action === "acquire"
        ? value.item_key === props.request.key
        : value.key === props.request.key,
  ),
);
const target = computed(() => {
  const value = entry.value;
  if (!value) return null;
  if (props.request.action === "acquire")
    return {
      label: "Acquire",
      toolName: value.item_name,
      browseOnly: true,
      goldCost: 0,
      ready: false,
      status: "Gather or make supplies here.",
      materials: [
        {
          ...value,
          quantity: props.request.quantity,
          has_enough: Number(value.owned_quantity) >= props.request.quantity,
        },
      ],
    };
  if (props.request.action === "repair") return repairTarget(value);
  const field = {
    craft: "ingredients",
    job: "requirements",
    expedition: "supplies",
    activity: "supplies",
  }[props.request.action];
  const ready = Boolean(
    value[
      {
        craft: "can_craft",
        job: "can_complete",
        expedition: "can_start",
        activity: "can_perform",
      }[props.request.action]
    ],
  );
  return {
    label: {
      craft: "Craft",
      job: "Turn In",
      expedition: "Run Expedition",
      activity: "Perform Activity",
    }[props.request.action],
    toolName: value.label,
    materials: value[field],
    goldCost: value.gold_cost ?? 0,
    ready,
    status: !value.is_unlocked
      ? `Need ${value.skill_label} Lv ${value.required_level}`
      : value.requires_tool_repair
        ? "Repair tool first"
        : !ready
          ? "Need supplies or gold"
          : "Ready",
    repairToolId: value.requires_tool_repair
      ? value.equipped_tool?.tool_id
      : null,
  };
});
function complete() {
  if (!target.value?.ready) return;
  const field = {
    craft: "recipe",
    job: "job",
    expedition: "expedition",
    repair: "tool_id",
    activity: "activity",
  }[props.request.action];
  form[field] = props.request.key;
  form.submit(props.request.action, {
    onSuccess: () => {
      if (props.request.action !== "repair")
        selectQuickAction(props.request.action, entry.value);
      emit("close");
    },
  });
}
</script>
<template>
  <MaterialsDialog
    v-if="target"
    :target="target"
    :sources="sources"
    :player="player"
    :error="form.errors._error"
    @close="emit('close')"
    @complete="complete"
  />
  <div v-else role="status" class="game-notice ui-notice">
    This selection is unavailable.
    <button class="app-btn app-btn--ghost app-btn--sm" @click="emit('close')">
      Close
    </button>
  </div>
</template>
