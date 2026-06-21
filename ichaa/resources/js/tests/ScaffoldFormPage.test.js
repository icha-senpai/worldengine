import { reactive } from 'vue'
import { mount } from '@vue/test-utils'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'

const mountPage = ({ formOverrides = {}, sections, onSubmit } = {}) => {
    const form = reactive({
        errors: {},
        processing: false,
        ...formOverrides,
    })

    const submitHandler = onSubmit ?? vi.fn()

    const wrapper = mount(ScaffoldFormPage, {
        props: {
            title: 'Test Form',
            backHref: '/entities',
            backLabel: 'Entities',
            cancelHref: '/entities',
            submitLabel: 'Save',
            form,
            sections: sections ?? [],
            onSubmit: submitHandler,
        },
        global: {
            stubs: {
                Link: {
                    template: '<a><slot /></a>',
                },
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
            },
        },
    })

    return { form, wrapper, submitHandler }
}

describe('ScaffoldFormPage', () => {
    it('normalizes id-based json fields before submit', async () => {
        const { form, wrapper, submitHandler } = mountPage({
            formOverrides: {
                related_entity_ids: [],
            },
            sections: [
                {
                    title: 'Links',
                    fields: [
                        {
                            key: 'related_entity_ids',
                            label: 'Related Entity IDs',
                            type: 'json',
                        },
                    ],
                },
            ],
        })

        await wrapper.find('textarea').setValue('1, 2, 3')
        await wrapper.find('form').trigger('submit.prevent')

        expect(submitHandler).toHaveBeenCalledOnce()
        expect(form.related_entity_ids).toEqual([1, 2, 3])
    })

    it('wraps plain text json fields into a TipTap-like document payload', async () => {
        const { form, wrapper, submitHandler } = mountPage({
            formOverrides: {
                lore_notes: null,
            },
            sections: [
                {
                    title: 'Lore',
                    fields: [
                        {
                            key: 'lore_notes',
                            label: 'Lore Notes',
                            type: 'json',
                        },
                    ],
                },
            ],
        })

        await wrapper.find('textarea').setValue('First paragraph\n\nSecond paragraph')
        await wrapper.find('form').trigger('submit.prevent')

        expect(submitHandler).toHaveBeenCalledOnce()
        expect(form.lore_notes).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'First paragraph' }],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Second paragraph' }],
                },
            ],
        })
    })
})
