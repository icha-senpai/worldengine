import { mount } from '@vue/test-utils'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const { page } = vi.hoisted(() => ({
    page: {
        url: '/datacrypt/bitcraft/guides',
        props: { auth: { user: {
            name: 'Test reader',
            can_access_bitcraft: true,
            can_access_datacrypt: true,
        } } },
    },
}))

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { template: '<a><slot /></a>' },
}))

const storageKey = 'dataverse.shellNavState'
let wrapper

describe('collapsible desktop sidebar', () => {
    beforeEach(() => {
        localStorage.clear()
        global.route = vi.fn((name) => `/${name}`)
    })

    afterEach(() => {
        wrapper?.unmount()
        vi.restoreAllMocks()
        localStorage.clear()
    })

    it('starts expanded and replaces full navigation with a usable icon rail when collapsed', async () => {
        mountLayout()
        const toggle = wrapper.get('.desktop-shell-sidebar__toggle')
        const content = wrapper.get('#desktop-sidebar-content')

        expect(toggle.attributes('aria-expanded')).toBe('true')
        expect(toggle.attributes('aria-controls')).toBe(content.attributes('id'))
        expect(toggle.attributes('aria-label')).toBe('Collapse sidebar')
        expect(content.element.style.display).not.toBe('none')

        await toggle.trigger('click')

        expect(toggle.attributes('aria-expanded')).toBe('false')
        expect(toggle.attributes('aria-label')).toBe('Expand sidebar')
        expect(content.element.style.display).toBe('none')
        expect(content.attributes('inert')).toBeDefined()
        const rail = wrapper.get('.sidebar-icon-rail')
        expect(rail.find('[aria-label="Home"]').exists()).toBe(true)
        expect(rail.find('[aria-label="Profile"]').exists()).toBe(true)
        expect(rail.find('[aria-label="Log Out"]').attributes('method')).toBe('post')
        expect(rail.find('[aria-label="World Engine"]').exists()).toBe(false)
        expect(rail.find('[aria-label="Admin"]').exists()).toBe(false)
        expect(wrapper.get('.desktop-shell-sidebar').classes()).toContain('desktop-shell-sidebar--collapsed')
        expect(JSON.parse(localStorage.getItem(storageKey)).desktopSidebarCollapsed).toBe(true)

        await toggle.trigger('click')

        expect(content.element.style.display).not.toBe('none')
        expect(content.attributes('inert')).toBeUndefined()
        expect(wrapper.find('.sidebar-icon-rail').exists()).toBe(false)
        expect(JSON.parse(localStorage.getItem(storageKey)).desktopSidebarCollapsed).toBe(false)
    })

    it('restores the sidebar preference without losing expanded navigation sections', async () => {
        localStorage.setItem(storageKey, JSON.stringify({
            desktopSidebarCollapsed: true,
            desktopBitcraftToolsOpen: true,
            desktopExpandedDomainKeys: ['entities'],
        }))
        mountLayout()

        expect(wrapper.get('.desktop-shell-sidebar__toggle').attributes('aria-expanded')).toBe('false')
        await wrapper.get('.desktop-shell-sidebar__toggle').trigger('click')

        const nav = wrapper.get('nav[aria-label="Primary"]')
        expect(nav.findAll('button').find((button) => button.text().includes('Bitcraft Tools')).attributes('aria-expanded')).toBe('true')
        expect(nav.text()).toContain('Guides')
        expect(JSON.parse(localStorage.getItem(storageKey))).toMatchObject({
            desktopSidebarCollapsed: false,
            desktopBitcraftToolsOpen: true,
            desktopExpandedDomainKeys: ['entities'],
        })
    })

    it.each(['broken-json', 'null', '{"desktopSidebarCollapsed":"false"}'])('falls back to expanded for invalid saved preference %s', (saved) => {
        localStorage.setItem(storageKey, saved)
        mountLayout()
        expect(wrapper.get('.desktop-shell-sidebar__toggle').attributes('aria-expanded')).toBe('true')
    })

    it('still toggles when browser storage is unavailable', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Storage blocked') })
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Storage blocked') })
        mountLayout()

        await wrapper.get('.desktop-shell-sidebar__toggle').trigger('click')
        expect(wrapper.get('#desktop-sidebar-content').element.style.display).toBe('none')
        await wrapper.get('.desktop-shell-sidebar__toggle').trigger('click')
        expect(wrapper.get('#desktop-sidebar-content').element.style.display).not.toBe('none')
    })

    it('opens collapsed section links and closes the flyout on Escape or outside click', async () => {
        localStorage.setItem(storageKey, JSON.stringify({ desktopSidebarCollapsed: true }))
        mountLayout()
        const trigger = wrapper.get('.sidebar-icon-rail button[aria-label="Bitcraft Tools"]')
        expect(trigger.attributes('aria-expanded')).toBe('false')
        expect(wrapper.get('#rail-tooltip-bitcraft').text()).toBe('Bitcraft Tools')

        await trigger.trigger('click')
        expect(trigger.attributes('aria-expanded')).toBe('true')
        expect(wrapper.get('#rail-flyout-bitcraft').text()).toContain('Guides')
        expect(wrapper.get('#rail-flyout-bitcraft').find('[aria-current="page"]').text()).toBe('Guides')
        await trigger.trigger('keydown', { key: 'Escape' })
        expect(wrapper.find('#rail-flyout-bitcraft').exists()).toBe(false)

        await trigger.trigger('click')
        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('#rail-flyout-bitcraft').exists()).toBe(false)
    })

    it('keeps mobile navigation independent of the desktop preference', async () => {
        localStorage.setItem(storageKey, JSON.stringify({ desktopSidebarCollapsed: true }))
        mountLayout()

        await wrapper.get('button[aria-label="Toggle navigation"]').trigger('click')
        expect(wrapper.get('nav[aria-label="Primary mobile"]').isVisible()).toBe(true)
        expect(wrapper.get('.desktop-shell-sidebar__toggle').attributes('aria-expanded')).toBe('false')
    })
})

function mountLayout() {
    wrapper = mount(AuthenticatedLayout, {
        slots: { default: '<p>Guide content</p>' },
        global: {
            mocks: { $page: page, route: global.route },
            stubs: { SitePlayerPicker: true },
        },
    })
    return wrapper
}
