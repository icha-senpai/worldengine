import { shallowMount } from '@vue/test-utils'
import AppDrawer from '@/Components/ui/AppDrawer.vue'

describe('AppDrawer', () => {
    it('uses appear so drawers animate when they first mount open', () => {
        const wrapper = shallowMount(AppDrawer, {
            props: {
                title: 'Edit Session Log',
                trailItems: [],
            },
            global: {
                stubs: {
                    AppButton: {
                        template: '<button><slot /></button>',
                    },
                    PageHeaderTrail: {
                        template: '<div />',
                    },
                },
            },
        })

        const transition = wrapper.findComponent({ name: 'Transition' })

        expect(transition.exists()).toBe(true)
        expect(transition.props('appear')).toBe(true)
    })

    it('can disable the initial mount animation when a route drawer remounts resolved', () => {
        const wrapper = shallowMount(AppDrawer, {
            props: {
                title: 'Edit Session Log',
                trailItems: [],
                animateOnMount: false,
            },
            global: {
                stubs: {
                    AppButton: {
                        template: '<button><slot /></button>',
                    },
                    PageHeaderTrail: {
                        template: '<div />',
                    },
                },
            },
        })

        const transition = wrapper.findComponent({ name: 'Transition' })

        expect(transition.exists()).toBe(true)
        expect(transition.props('appear')).toBe(false)
    })
})
