<template>
    <ScaffoldFormPage
        :presentation="embedded ? 'drawer' : 'page'"
        :embedded="embedded"
        :title="title"
        :back-href="backHref"
        :back-label="backLabel"
        :cancel-href="cancelHref"
        :submit-label="submitLabel"
        :processing-label="processingLabel"
        :destroy-href="destroyHref"
        :destroy-label="destroyLabel"
        :destroy-confirm="destroyConfirm"
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    title: { type: String, required: true },
    backHref: { type: [String, Object], required: true },
    backLabel: { type: String, required: true },
    cancelHref: { type: [String, Object], required: true },
    submitHref: { type: [String, Object], required: true },
    submitMethod: {
        type: String,
        required: true,
        validator: (value) => ['post', 'put'].includes(value),
    },
    submitLabel: { type: String, required: true },
    processingLabel: { type: String, default: 'Saving...' },
    destroyHref: { type: [String, Object], default: null },
    destroyLabel: { type: String, default: 'Move to Trash' },
    destroyConfirm: { type: String, default: 'Move this item to trash?' },
    formData: { type: Object, required: true },
    sections: { type: Array, required: true },
})

const form = useForm({ ...props.formData })

const submit = () => {
    if (props.submitMethod === 'put') {
        form.put(props.submitHref)
        return
    }

    form.post(props.submitHref)
}
</script>
