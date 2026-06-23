import { Extension } from '@tiptap/core'

export const FieldColor = Extension.create({
    name: 'fieldColor',

    addOptions() {
        return {
            types: ['textStyle'],
        }
    },

    addGlobalAttributes() {
        return [
            {
                types: this.options.types,
                attributes: {
                    fieldColor: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('data-field-color') || null,
                        renderHTML: (attributes) => {
                            if (!attributes.fieldColor) {
                                return {}
                            }

                            return {
                                'data-field-color': attributes.fieldColor,
                                style: `background-color: ${attributes.fieldColor}; color: inherit;`,
                            }
                        },
                    },
                },
            },
        ]
    },

    addCommands() {
        return {
            setFieldColor: (fieldColor) => ({ chain }) => chain()
                .setMark('textStyle', { fieldColor })
                .run(),
            unsetFieldColor: () => ({ chain }) => chain()
                .setMark('textStyle', { fieldColor: null })
                .removeEmptyTextStyle()
                .run(),
        }
    },
})
