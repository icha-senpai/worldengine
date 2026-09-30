<template>
    <div v-if="show" class="widget-setup-drawer">
        <AppButton type="button" variant="primary" @click="openDrawer">
            Edit Widget
        </AppButton>
        <AppButton type="button" variant="ghost" @click="$emit('widget-mode')">
            Widget Mode
        </AppButton>

        <AppDrawer
            v-if="drawerOpen"
            :title="title"
            close-label="Done"
            @close="closeDrawer"
        >
            <slot />
        </AppDrawer>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import AppButton from '@/Components/ui/AppButton.vue'
import AppDrawer from '@/Components/ui/AppDrawer.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Edit Widget' },
})

defineEmits(['widget-mode'])

const drawerOpen = ref(false)

const openDrawer = () => {
    drawerOpen.value = true
}

const closeDrawer = () => {
    drawerOpen.value = false
}

watch(() => props.show, (show) => {
    if (!show) {
        closeDrawer()
    }
})
</script>

<style scoped>
.widget-setup-drawer {
    display: flex;
    justify-content: flex-end;
    width: min(var(--tracker-width), 100vw);
}
</style>
