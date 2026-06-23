import { describe, expect, it, vi } from 'vitest'
import { SwapEnterBehavior } from '@/lib/tiptap/swapEnterBehavior'

describe('SwapEnterBehavior', () => {
    it('maps Enter to hard break and exposes Shift-Enter override', () => {
        const setHardBreak = vi.fn(() => true)

        const shortcuts = SwapEnterBehavior.config.addKeyboardShortcuts.call({
            editor: {
                commands: {
                    setHardBreak,
                },
                state: {},
                view: {
                    dispatch: vi.fn(),
                },
            },
        })

        expect(Object.keys(shortcuts)).toEqual(expect.arrayContaining(['Enter', 'Shift-Enter']))
        expect(shortcuts.Enter()).toBe(true)
        expect(setHardBreak).toHaveBeenCalledTimes(1)
        expect(typeof shortcuts['Shift-Enter']).toBe('function')
    })
})
