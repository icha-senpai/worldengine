import { Node } from "@tiptap/core";
import { VueNodeViewRenderer } from "@tiptap/vue-3";
import GuideActivityCard from "./GuideActivityCard.vue";
import {
  guideCardLayoutAttributes,
  guideCardLayoutHtml,
} from "./guideCardLayout";

const attributes = ["activity", "recipeId", "itemId", "kind", "name"];

export const BitcraftActivity = Node.create({
  name: "bitcraftActivity",
  group: "block",
  atom: true,
  draggable: true,
  addAttributes() {
    return {
      ...guideCardLayoutAttributes(),
      ...Object.fromEntries(
        attributes.map((key) => [
          key,
          {
            default: null,
            rendered: false,
            parseHTML: (element) =>
              element.getAttribute(`data-guide-${key.toLowerCase()}`),
          },
        ]),
      ),
      settings: {
        default: {},
        rendered: false,
        parseHTML: (element) => {
          try {
            return JSON.parse(
              element.getAttribute("data-guide-settings") || "{}",
            );
          } catch {
            return {};
          }
        },
      },
    };
  },
  parseHTML() {
    return [{ tag: "div[data-guide-activity]" }];
  },
  renderHTML({ node }) {
    return [
      "div",
      {
        "data-guide-activity": node.attrs.activity,
        ...guideCardLayoutHtml(node.attrs),
        ...Object.fromEntries(
          attributes
            .filter((key) => node.attrs[key] !== null)
            .map((key) => [
              `data-guide-${key.toLowerCase()}`,
              String(node.attrs[key]),
            ]),
        ),
        "data-guide-settings": JSON.stringify(node.attrs.settings),
      },
      String(node.attrs.name || "Activity card"),
    ];
  },
  addNodeView() {
    return VueNodeViewRenderer(GuideActivityCard);
  },
});
