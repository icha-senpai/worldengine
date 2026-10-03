import { nextTick, onScopeDispose, reactive, ref } from "vue";
import { evergather } from "../evergather";

export const actionError = ref("");
export const actionProcessing = ref(false);

// ICHAA panels keep their form fields and callbacks, but submit directly to
// reducers. Subscriptions supply the committed state; no HTTP reload or deltas.
export function useActionForm(initial) {
  let active = true;
  onScopeDispose(() => {
    active = false;
  });
  const defaults = structuredClone(initial);
  const form = reactive({
    ...structuredClone(defaults),
    processing: false,
    errors: {},
    reset() {
      Object.assign(form, structuredClone(defaults));
      form.errors = {};
    },
    async submit(action, options = {}) {
      if (!active || actionProcessing.value) return;
      form.errors = {};
      form.processing = true;
      actionProcessing.value = true;
      actionError.value = "";
      options.onStart?.();
      try {
        await executeAction(action, form, options.id);
        await nextTick();
        if (active) options.onSuccess?.();
      } catch (error) {
        const message =
          error instanceof Error
            ? error.message
            : "The action could not be completed.";
        actionError.value = message;
        form.errors = Object.fromEntries(
          [...Object.keys(initial), "listing", "_error"].map((key) => [
            key,
            message,
          ]),
        );
        if (active) options.onError?.(form.errors);
      } finally {
        form.processing = false;
        actionProcessing.value = false;
        if (active) options.onFinish?.();
      }
    },
  });
  return form;
}

export async function executeAction(action, data, id) {
  const conn = evergather.value;
  if (!conn)
    throw new Error("Connect to Evergather before starting an action.");
  const reducers = conn.reducers;
  const equippedId = () => {
    const tool = [...conn.db.myTools.iter()].find(
      (tool) => tool.equipped && `tool_${tool.skill}` === data.slot,
    );
    if (!tool)
      throw new Error("This tool is no longer equipped. Select it again.");
    return tool.id;
  };
  switch (action) {
    case "gather":
      return reducers.performAction({
        kind: "gathering_actions",
        key: data.action,
      });
    case "activity":
      return reducers.performAction({
        kind: "skill_activities",
        key: data.activity,
      });
    case "craft":
      return reducers.craft({ key: data.recipe });
    case "job":
      return reducers.completeJob({ key: data.job });
    case "acceptJob":
      return reducers.acceptJob({ key: data.job });
    case "expedition":
      return reducers.runExpedition({ key: data.expedition });
    case "shop":
      return reducers.buyShopOffer({ key: data.offer });
    case "rarity":
      return reducers.upgradeToolRarity({ id: equippedId() });
    case "tier":
      return reducers.upgradeToolTier({ id: equippedId() });
    case "equip":
      return reducers.equipTool({ id: BigInt(data.tool_id), equipped: true });
    case "unequip":
      return reducers.equipTool({ id: equippedId(), equipped: false });
    case "repair":
      return reducers.repairTool({ id: BigInt(data.tool_id) });
    case "salvage":
      return reducers.removeTool({ id: BigInt(data.tool_id), salvage: true });
    case "retire":
      return reducers.removeTool({ id: BigInt(data.tool_id), salvage: false });
    case "list":
      return data.listing_type === "tool"
        ? reducers.listTool({
            id: BigInt(data.tool_id),
            unitPrice: Number(data.unit_price),
          })
        : reducers.listItem({
            itemKey: data.item_key,
            quantity: Number(data.quantity),
            unitPrice: Number(data.unit_price),
          });
    case "vendor":
      return reducers.sellToVendor({
        itemKey: data.item_key,
        quantity: Number(data.quantity),
      });
    case "buy":
      return reducers.buyListing({ id: BigInt(id) });
    case "cancel":
      return reducers.cancelListing({ id: BigInt(id) });
    case "claim":
      return reducers.claimAchievement({ key: data.achievement });
    case "loadout": {
      const player = [...conn.db.myPlayer.iter()][0];
      const claim = [...conn.db.myAchievements.iter()].find(
        (claim) => claim.achievementKey === data.title_claim_key,
      );
      if (data.title_claim_key && !claim)
        throw new Error("Claim this title before equipping it.");
      return reducers.updateCharacter({
        name: player.name,
        species: player.species,
        region: player.region,
        title: claim?.title ?? "",
      });
    }
    case "character":
      return reducers.customizeCharacter({
        name: data.display_name,
        title: data.title,
        species: data.species,
        pronouns: data.pronouns,
        region: data.home_region,
        appearance: JSON.stringify(data.appearance),
      });
    default:
      throw new Error("That action is unavailable.");
  }
}
