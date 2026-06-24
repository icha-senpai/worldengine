<script setup>
import { computed, ref, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
import {
    currentDialog,
    dismissCurrentDialog,
    resolveCurrentDialog,
} from '@/lib/appDialog'

const dialog = computed(() => currentDialog.value)
const show = computed(() => Boolean(dialog.value))
const isConfirm = computed(() => dialog.value?.kind === 'confirm')
const isPrompt = computed(() => dialog.value?.kind === 'prompt')
const promptValue = ref('')
const canConfirm = computed(() => {
    if (!isPrompt.value) {
        return true
    }

    if (dialog.value?.allowEmpty) {
        return true
    }

    return promptValue.value.trim() !== ''
})

watch(dialog, (value) => {
    promptValue.value = value?.kind === 'prompt'
        ? String(value.initialValue ?? '')
        : ''
}, { immediate: true })

function closeDialog() {
    dismissCurrentDialog()
}

function confirmAction() {
    if (isPrompt.value) {
        resolveCurrentDialog(dialog.value?.trimInput === false ? promptValue.value : promptValue.value.trim())
        return
    }

    resolveCurrentDialog(isConfirm.value ? true : undefined)
}
</script>

<template>
    <Modal
        :show="show"
        max-width="lg"
        :closeable="dialog?.closeable ?? true"
        @close="closeDialog"
    >
        <div
            v-if="dialog"
            class="app-dialog"
            :class="{
                'app-dialog--confirm': dialog.kind === 'confirm',
                'app-dialog--error': dialog.kind === 'error',
            }"
        >
            <div class="app-dialog__header">
                <div class="app-dialog__copy">
                    <p class="app-dialog__eyebrow">{{ dialog.eyebrow }}</p>
                    <h3 class="app-dialog__title">{{ dialog.title }}</h3>
                    <p v-if="dialog.message" class="app-dialog__message">{{ dialog.message }}</p>
                </div>
            </div>

            <div v-if="isPrompt" class="space-y-2">
                <label v-if="dialog.inputLabel" class="field-label" for="app-dialog-prompt">
                    {{ dialog.inputLabel }}
                </label>
                <TextInput
                    id="app-dialog-prompt"
                    v-model="promptValue"
                    :type="dialog.inputType || 'text'"
                    :placeholder="dialog.inputPlaceholder || ''"
                    class="w-full"
                    @keydown.enter.prevent="canConfirm && confirmAction()"
                />
            </div>

            <pre v-if="dialog.details" class="app-dialog__details">{{ dialog.details }}</pre>

            <div class="app-dialog__footer">
                <AppButton
                    v-if="isConfirm || isPrompt"
                    variant="ghost"
                    @click="closeDialog"
                >
                    {{ dialog.cancelLabel || 'Cancel' }}
                </AppButton>

                <AppButton
                    :variant="dialog.confirmVariant || (isConfirm ? 'danger' : 'primary')"
                    :disabled="!canConfirm"
                    @click="confirmAction"
                >
                    {{ dialog.confirmLabel || (isConfirm ? 'Confirm' : 'Close') }}
                </AppButton>
            </div>
        </div>
    </Modal>
</template>
