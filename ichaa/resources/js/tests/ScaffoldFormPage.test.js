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
                RichTextEditor: {
                    name: 'RichTextEditor',
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template: '<button data-test="rich-editor" @click="$emit(\'update:modelValue\', { type: \'doc\', content: [{ type: \'paragraph\', content: [{ type: \'text\', text: \'Updated from editor\' }] }] })">Editor</button>',
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
                            jsonMode: 'ids',
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

    it('blocks submit when explicit raw json is invalid', async () => {
        const { form, wrapper, submitHandler } = mountPage({
            formOverrides: {
                metadata: null,
            },
            sections: [
                {
                    title: 'Meta',
                    fields: [
                        {
                            key: 'metadata',
                            label: 'Metadata JSON',
                            type: 'json',
                            jsonMode: 'raw',
                        },
                    ],
                },
            ],
        })

        await wrapper.find('textarea').setValue('{invalid json')
        await wrapper.find('form').trigger('submit.prevent')

        expect(submitHandler).not.toHaveBeenCalled()
        expect(form.metadata).toBe(null)
        expect(wrapper.text()).toContain('Enter valid JSON.')
    })

    it('submits document json fields from the rich text editor payload', async () => {
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

        await wrapper.find('[data-test="rich-editor"]').trigger('click')
        await wrapper.find('form').trigger('submit.prevent')

        expect(submitHandler).toHaveBeenCalledOnce()
        expect(form.lore_notes).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Updated from editor' }],
                },
            ],
        })
    })

    it('captures selected files for file fields', async () => {
        const { form, wrapper } = mountPage({
            formOverrides: {
                upload_file: null,
            },
            sections: [
                {
                    title: 'Upload',
                    fields: [
                        {
                            key: 'upload_file',
                            label: 'Upload File',
                            type: 'file',
                        },
                    ],
                },
            ],
        })

        const file = new File(['image-bytes'], 'test-image.png', { type: 'image/png' })
        const input = wrapper.find('input[type="file"]')

        Object.defineProperty(input.element, 'files', {
            value: [file],
        })

        await input.trigger('change')

        expect(form.upload_file).toBe(file)
        expect(wrapper.text()).toContain('Selected: test-image.png')
    })
})
