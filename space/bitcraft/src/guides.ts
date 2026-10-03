export function validateGuide(args: {
  title: string;
  summary: string;
  category: string;
  content: string;
}) {
  if (!args.title.trim() || args.title.length > 255)
    throw new Error("Choose a guide title of at most 255 characters.");
  if (args.summary.length > 1000)
    throw new Error("The summary must be at most 1000 characters.");
  if (args.category.length > 100)
    throw new Error("The category must be at most 100 characters.");
  if (args.content.length > 200000)
    throw new Error(
      "The guide content is too large. Use smaller images or image URLs.",
    );
  const document = JSON.parse(args.content);
  if (document?.type !== "doc" || !Array.isArray(document.content))
    throw new Error("A valid guide document is required.");
  let count = 0;
  const visit = (node: any, depth: number) => {
    if (depth > 40 || ++count > 10000 || !node || typeof node.type !== "string")
      throw new Error("This guide document is too complex.");
    for (const key of ["href", "src"]) {
      const value = node.attrs?.[key];
      if (
        value &&
        !/^(https?:\/\/|\/[^/]|data:image\/(png|jpeg|webp|gif);base64,|mailto:|#)/i.test(
          String(value),
        )
      )
        throw new Error("Use a valid image or link URL.");
    }
    for (const mark of node.marks ?? [])
      if (
        mark.attrs?.href &&
        !/^(https?:\/\/|\/[^/]|mailto:|#)/i.test(String(mark.attrs.href))
      )
        throw new Error("Use a valid link URL.");
    for (const child of node.content ?? []) visit(child, depth + 1);
  };
  visit(document, 0);
  return {
    ...args,
    title: args.title.trim(),
    summary: args.summary.trim(),
    category: args.category.trim(),
  };
}
