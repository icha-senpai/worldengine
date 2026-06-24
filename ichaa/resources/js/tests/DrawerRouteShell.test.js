import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import {
    beginDrawerNavigation,
    finishDrawerNavigation,
    PENDING_DRAWER_REVEAL_MS,
} from '@/lib/drawerNavigation'

const { routerVisitMock } = vi.hoisted(() => ({
    routerVisitMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        router: {
            visit: routerVisitMock,
        },
    }
})

const mountShell = (props = {}) => mount(DrawerRouteShell, {
    props: {
        open: true,
        ready: false,
        title: 'New Entity',
        routeHref: '/entities/create',
        closeHref: '/entities',
        ...props,
    },
    slots: {
        default: '<div data-test="drawer-content">Ready content</div>',
    },
    global: {
        stubs: {
            AppDrawer: {
                props: ['title', 'trailItems', 'closeHref'],
                template: '<div class="app-drawer-stub"><slot /></div>',
            },
        },
    },
})

describe('DrawerRouteShell', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        routerVisitMock.mockReset()
        finishDrawerNavigation()
    })

    afterEach(() => {
        finishDrawerNavigation()
        vi.runOnlyPendingTimers()
        vi.useRealTimers()
    })

    it('holds the loading shell briefly when a pending drawer route resolves immediately', async () => {
        beginDrawerNavigation({ href: '/entities/create' })
        await vi.advanceTimersByTimeAsync(PENDING_DRAWER_REVEAL_MS)

        const wrapper = mountShell()

        expect(wrapper.find('.drawer-loading').exists()).toBe(true)
        expect(wrapper.find('[data-test="drawer-content"]').exists()).toBe(false)

        await wrapper.setProps({ ready: true })
        await nextTick()

        expect(wrapper.find('.drawer-loading').exists()).toBe(true)
        expect(wrapper.find('[data-test="drawer-content"]').exists()).toBe(false)

        await vi.advanceTimersByTimeAsync(160)
        await nextTick()

        expect(wrapper.find('.drawer-loading').exists()).toBe(false)
        expect(wrapper.find('[data-test="drawer-content"]').exists()).toBe(true)
    })

    it('shows content immediately when the drawer is already ready on mount', () => {
        const wrapper = mountShell({
            ready: true,
            routeHref: '/entities/42/edit',
        })

        expect(wrapper.find('.drawer-loading').exists()).toBe(false)
        expect(wrapper.find('[data-test="drawer-content"]').exists()).toBe(true)
    })
})
