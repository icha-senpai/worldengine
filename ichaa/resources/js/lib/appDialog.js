import { computed, reactive } from 'vue'

const state = reactive({
    queue: [],
    nextId: 1,
})

function enqueueDialog(payload) {
    return new Promise((resolve) => {
        state.queue.push({
            id: state.nextId++,
            ...payload,
            resolve,
        })
    })
}

function extractDetails(details) {
    if (!details) {
        return ''
    }

    if (typeof details === 'string') {
        return details.trim()
    }

    if (Array.isArray(details)) {
        return details
            .flatMap((item) => extractDetails(item).split('\n'))
            .map((item) => item.trim())
            .filter(Boolean)
            .join('\n')
    }

    if (typeof details === 'object') {
        return Object.entries(details)
            .flatMap(([key, value]) => {
                if (Array.isArray(value)) {
                    return value.map((entry) => `${key}: ${entry}`)
                }

                return `${key}: ${value}`
            })
            .map((item) => item.trim())
            .filter(Boolean)
            .join('\n')
    }

    return String(details).trim()
}

export const currentDialog = computed(() => state.queue[0] ?? null)

export function confirmDialog(options = {}) {
    return enqueueDialog({
        kind: 'confirm',
        eyebrow: options.eyebrow ?? 'Confirm Action',
        title: options.title ?? 'Are you sure?',
        message: options.message ?? '',
        details: extractDetails(options.details),
        confirmLabel: options.confirmLabel ?? 'Confirm',
        cancelLabel: options.cancelLabel ?? 'Cancel',
        confirmVariant: options.confirmVariant ?? 'danger',
        closeable: options.closeable ?? true,
    })
}

export function showErrorDialog(options = {}) {
    return enqueueDialog({
        kind: 'error',
        eyebrow: options.eyebrow ?? 'Action Failed',
        title: options.title ?? 'Something went wrong',
        message: options.message ?? 'The request could not be completed.',
        details: extractDetails(options.details),
        confirmLabel: options.confirmLabel ?? 'Close',
        cancelLabel: '',
        confirmVariant: options.confirmVariant ?? 'danger',
        closeable: options.closeable ?? true,
    })
}

export function promptDialog(options = {}) {
    return enqueueDialog({
        kind: 'prompt',
        eyebrow: options.eyebrow ?? 'Enter Value',
        title: options.title ?? 'Provide a value',
        message: options.message ?? '',
        details: extractDetails(options.details),
        confirmLabel: options.confirmLabel ?? 'Save',
        cancelLabel: options.cancelLabel ?? 'Cancel',
        confirmVariant: options.confirmVariant ?? 'primary',
        closeable: options.closeable ?? true,
        inputLabel: options.inputLabel ?? '',
        inputPlaceholder: options.inputPlaceholder ?? '',
        inputType: options.inputType ?? 'text',
        initialValue: String(options.initialValue ?? ''),
        allowEmpty: options.allowEmpty ?? false,
        trimInput: options.trimInput ?? true,
    })
}

export function resolveCurrentDialog(result) {
    const dialog = state.queue.shift()

    dialog?.resolve(result)
}

export function dismissCurrentDialog() {
    const dialog = state.queue.shift()

    if (!dialog) {
        return
    }

    dialog.resolve(dialog.kind === 'confirm' ? false : undefined)
}
