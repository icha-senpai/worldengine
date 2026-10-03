export function normalizeCardWidth(value) {
  if (value === null || value === undefined || value === "") return null;
  const width = Number(value);
  return Number.isInteger(width) && width >= 25 && width <= 100 ? width : null;
}

export function guideCardLayoutAttributes() {
  return {
    align: {
      default: "left",
      rendered: false,
      parseHTML: (element) => element.getAttribute("data-card-align") || "left",
    },
    width: {
      default: null,
      rendered: false,
      parseHTML: (element) =>
        normalizeCardWidth(element.getAttribute("data-card-width")),
    },
    wrap: {
      default: false,
      rendered: false,
      parseHTML: (element) => element.getAttribute("data-card-wrap") === "true",
    },
  };
}

export function guideCardLayoutStyle(attributes) {
  const align = ["left", "center", "right"].includes(attributes.align)
    ? attributes.align
    : "left";
  const width = normalizeCardWidth(attributes.width);
  return {
    ...(width !== null ? { width: `${width}%` } : {}),
    ...(attributes.wrap === true
      ? { "--guide-wrap-width": width !== null ? `${width}%` : "50%" }
      : {}),
    marginLeft: align === "left" ? "0" : "auto",
    marginRight: align === "right" ? "0" : "auto",
  };
}

export function guideCardLayoutHtml(attributes) {
  const width = normalizeCardWidth(attributes.width);
  const style = guideCardLayoutStyle(attributes);
  return {
    "data-card-align": ["left", "center", "right"].includes(attributes.align)
      ? attributes.align
      : "left",
    "data-card-wrap": String(
      attributes.wrap === true && attributes.align !== "center",
    ),
    ...(width !== null ? { "data-card-width": String(width) } : {}),
    style: `${style.width ? `width: ${style.width}; ` : ""}${style["--guide-wrap-width"] ? `--guide-wrap-width: ${style["--guide-wrap-width"]}; ` : ""}margin-left: ${style.marginLeft}; margin-right: ${style.marginRight};`,
  };
}
