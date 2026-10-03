import Image from "@tiptap/extension-image";

export const DataverseImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      align: {
        default: "left",
        parseHTML: (element) => element.getAttribute("data-align") || "left",
        renderHTML: (attributes) => ({
          "data-align": attributes.align || "left",
        }),
      },
      width: {
        default: "100%",
        parseHTML: (element) => element.getAttribute("data-width") || "100%",
        renderHTML: (attributes) => ({
          "data-width": attributes.width || "100%",
          style: attributes.width
            ? `width: ${attributes.width}; --guide-wrap-width: ${attributes.width};`
            : null,
        }),
      },
      wrap: {
        default: false,
        parseHTML: (element) => element.getAttribute("data-wrap") === "true",
        renderHTML: (attributes) => ({
          "data-wrap": String(
            attributes.wrap === true && attributes.align !== "center",
          ),
        }),
      },
    };
  },

  addNodeView() {
    return null;
  },
});
