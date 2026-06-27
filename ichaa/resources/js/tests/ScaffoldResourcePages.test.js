import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import ResourceForm from '@/Pages/ScaffoldResources/Form.vue'

const { useFormMock } = vi.hoisted(() => ({
    useFormMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        useForm: useFormMock,
    }
})

const ScaffoldFormPageStub = defineComponent({
    name: 'ScaffoldFormPage',
    props: {
        onSubmit: { type: Function, required: true },
        submitLabel: { type: String, default: '' },
    },
    template: '<button type="button" @click="onSubmit">{{ submitLabel }}</button>',
})

describe('scaffold resource form page', () => {
    beforeEach(() => {
        useFormMock.mockReset()
        useFormMock.mockImplementation(() => ({
            post: vi.fn(),
            put: vi.fn(),
            errors: {},
            processing: false,
        }))
    })

    it('submits create forms with post', async () => {
        const form = {
            post: vi.fn(),
            put: vi.fn(),
            errors: {},
            processing: false,
        }
        useFormMock.mockReturnValue(form)

        const wrapper = mount(ResourceForm, {
            props: {
                title: 'New Record',
                backHref: '/back',
                backLabel: 'Records',
                cancelHref: '/cancel',
                submitHref: '/store',
                submitMethod: 'post',
                submitLabel: 'Create',
                formData: { name: '' },
                sections: [],
            },
            global: {
                stubs: {
                    ScaffoldFormPage: ScaffoldFormPageStub,
                },
            },
        })

        await wrapper.get('button').trigger('click')

        expect(form.post).toHaveBeenCalledWith('/store')
        expect(form.put).not.toHaveBeenCalled()
    })

    it('submits edit forms with put', async () => {
        const form = {
            post: vi.fn(),
            put: vi.fn(),
            errors: {},
            processing: false,
        }
        useFormMock.mockReturnValue(form)

        const wrapper = mount(ResourceForm, {
            props: {
                title: 'Edit Record',
                backHref: '/back',
                backLabel: 'Records',
                cancelHref: '/cancel',
                submitHref: '/update',
                submitMethod: 'put',
                submitLabel: 'Save',
                formData: { name: 'Existing' },
                sections: [],
            },
            global: {
                stubs: {
                    ScaffoldFormPage: ScaffoldFormPageStub,
                },
            },
        })

        await wrapper.get('button').trigger('click')

        expect(form.put).toHaveBeenCalledWith('/update')
        expect(form.post).not.toHaveBeenCalled()
    })
})
