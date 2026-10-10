// Match the activity indicator's short grace between individual actions.
const ACTION_GRACE_MS = 20000;

export function xpSourceAction(activity, at = Date.now()) {
  if (!activity || activity.delayed || activity.signed_in === false)
    return null;
  const actions = (activity.actions ?? [])
    .filter(
      (action) =>
        action.action_type &&
        action.action_type !== "None" &&
        !action.client_cancel &&
        Number.isFinite(Number(action.ends_at_ms)) &&
        Number(action.ends_at_ms) + ACTION_GRACE_MS > at,
    )
    .sort(
      (a, b) =>
        Number(b.start_time_ms ?? b.ends_at_ms) -
        Number(a.start_time_ms ?? a.ends_at_ms),
    );
  // Movement on the base layer can accompany an extraction on the upper layer.
  return (
    actions.find(
      (action) =>
        !["PlayerMove", "DeployableMove"].includes(action.action_type),
    ) ??
    actions[0] ??
    null
  );
}

export function resolveXpSource(action, card, playerId) {
  if (!action || !card || !["Craft", "Extract"].includes(action.action_type))
    return null;
  const gathering = action.action_type === "Extract";
  const entry = gathering
    ? card.entry
    : card.recipes?.find((row) => String(row.id) === String(action.recipe_id));
  if (!entry || String(entry.id) !== String(action.recipe_id)) return null;
  // A gathered resource can have several random drops; name the resource rather
  // than attributing the action's XP to an arbitrary possible drop.
  const item = gathering ? entry.resource : card.target;
  const skillId = gathering
    ? entry.skill?.id
    : entry.experiencePerProgress?.skill_id;
  const skillName = gathering
    ? entry.skill?.name
    : (entry.skillName ?? entry.skill?.name ?? entry.skill);
  if (!item?.name || (skillId == null && !skillName)) return null;
  return {
    playerId: String(playerId),
    recipeId: String(action.recipe_id),
    actionType: action.action_type,
    skillId,
    skillName,
    item,
    expiresAt: Number(action.ends_at_ms) + ACTION_GRACE_MS,
  };
}

export function xpItemForSkill(
  source,
  skill,
  activity,
  at = Date.now(),
  playerId,
) {
  if (
    !source ||
    !skill ||
    source.playerId !== String(playerId) ||
    source.expiresAt <= at
  )
    return null;
  const action = xpSourceAction(activity, at);
  if (
    !action ||
    action.action_type !== source.actionType ||
    String(action.recipe_id) !== source.recipeId
  )
    return null;
  const matches =
    source.skillId != null
      ? String(source.skillId) === String(skill.id)
      : String(source.skillName).toLowerCase() ===
        String(skill.name).toLowerCase();
  return matches ? source.item : null;
}
