import { Extension } from '@tiptap/core'
import { chainCommands, createParagraphNear, liftEmptyBlock, newlineInCode, splitBlock } from '@tiptap/pm/commands'

export const SwapEnterBehavior = Extension.create({
    name: 'swapEnterBehavior',

    priority: 1000,

    addKeyboardShortcuts() {
        return {
            Enter: () => this.editor.commands.setHardBreak(),
            'Shift-Enter': () => chainCommands(
                newlineInCode,
                createParagraphNear,
                liftEmptyBlock,
                splitBlock,
            )(this.editor.state, this.editor.view.dispatch, this.editor.view),
        }
    },
})
