export const widgetKinds = ["activity", "inventory", "passive-crafts", "tasks"];
export function validateWidget(token: string, kind: string, settings: string) {
  if (
    !/^[a-zA-Z0-9_-]{24,80}$/.test(token) ||
    !widgetKinds.includes(kind) ||
    settings.length > 8000
  )
    throw new Error("Invalid widget settings.");
  const value = JSON.parse(settings);
  if (!value || typeof value !== "object" || Array.isArray(value))
    throw new Error("Invalid widget settings.");
  for (const [key, item] of Object.entries(value)) {
    if (
      !/^[a-zA-Z][a-zA-Z0-9]*$/.test(key) ||
      ["__proto__", "constructor", "prototype", "user", "profile"].includes(key)
    )
      throw new Error("Invalid widget setting.");
    if (
      !["string", "number", "boolean"].includes(typeof item) &&
      !Array.isArray(item) &&
      item !== null
    )
      throw new Error("Invalid widget value.");
    if (typeof item === "string" && item.length > 3000)
      throw new Error("Widget value is too long.");
  }
  const tasks =
    typeof value.tasks === "string"
      ? JSON.parse(value.tasks || "[]")
      : (value.tasks ?? []);
  if (
    !Array.isArray(tasks) ||
    tasks.length > 20 ||
    tasks.some(
      (task) =>
        !task ||
        typeof task.id !== "string" ||
        !/^\w[\w-]{0,79}$/.test(task.id) ||
        typeof task.text !== "string" ||
        task.text.length > 160 ||
        typeof task.done !== "boolean",
    )
  )
    throw new Error("Check your task list.");
  if (
    String(value.title ?? "").length > 80 ||
    String(value.character ?? "").length > 80 ||
    String(value.icons ?? "").length > 40
  )
    throw new Error("Widget labels are too long.");
  return JSON.stringify(value);
}
