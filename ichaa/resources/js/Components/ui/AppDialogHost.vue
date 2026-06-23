<script setup>
import { computed } from 'vue'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import {
    currentDialog,
    dismissCurrentDialog,
    resolveCurrentDialog,
} from '@/lib/appDialog'

const dialog = computed(() => currentDialog.value)
const show = computed(() => Boolean(dialog.value))
const isConfirm = computed(() => dialog.value?.kind === 'confirm')

function closeDialog() {
    dismissCurrentDialog()
}

function confirmAction() {
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

            <pre v-if="dialog.details" class="app-dialog__details">{{ dialog.details }}</pre>

            <div class="app-dialog__footer">
                <AppButton
                    v-if="isConfirm"
                    variant="ghost"
                    @click="closeDialog"
                >
                    {{ dialog.cancelLabel || 'Cancel' }}
                </AppButton>

                <AppButton
                    :variant="dialog.confirmVariant || (isConfirm ? 'danger' : 'primary')"
                    @click="confirmAction"
                >
                    {{ dialog.confirmLabel || (isConfirm ? 'Confirm' : 'Close') }}
                </AppButton>
            </div>
        </div>
    </Modal>
</template>
