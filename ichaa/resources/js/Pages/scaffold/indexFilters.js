import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'

export const useIndexFilters = (routeName, initialFilters = {}, options = {}) => {
    const form = reactive({ ...initialFilters })
    const routeParams = options.routeParams
    const visitOptions = { ...options }

    delete visitOptions.routeParams

    const hasActiveFilters = computed(() =>
        Object.values(form).some((value) => {
            if (typeof value === 'boolean') {
                return value
            }

            return String(value ?? '').trim() !== ''
        })
    )

    const normalizedPayload = () =>
        Object.fromEntries(
            Object.entries(form).flatMap(([key, value]) => {
                if (typeof value === 'boolean') {
                    return value ? [[key, true]] : []
                }

                const trimmed = String(value ?? '').trim()

                return trimmed === '' ? [] : [[key, trimmed]]
            })
        )

    const visit = (payload) => router.get(route(routeName, routeParams), payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        ...visitOptions,
    })

    const applyFilters = () => visit(normalizedPayload())

    const clearFilters = () => {
        Object.keys(form).forEach((key) => {
            form[key] = typeof form[key] === 'boolean' ? false : ''
        })

        visit({})
    }

    return {
        filterForm: form,
        hasActiveFilters,
        applyFilters,
        clearFilters,
    }
}
