<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>{{ users.length }} users</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Users</h1>
                </div>

                <div class="page-hero__actions">
                    <AppButton variant="primary" @click="openCreateDrawer">
                        Create User
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="index-surface space-y-6">
            <section
                v-for="user in users"
                :key="user.id"
                class="index-record"
            >
                <div class="index-record__layout">
                    <div class="index-record__copy">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="index-record__title prose-wrap">{{ user.name }}</span>
                            <span v-if="user.is_admin" class="chip">Role: Admin</span>
                            <span v-if="user.is_verified" class="chip">Verified</span>
                            <span v-else class="chip">Unverified</span>
                            <span v-if="user.can_access_bitcraft" class="chip">Bitcraft</span>
                            <span v-if="user.can_access_world_engine" class="chip">World Engine</span>
                        </div>

                        <p class="index-record__subtitle prose-wrap">{{ user.email }}</p>

                        <div class="mt-4 flex flex-wrap gap-4">
                            <label
                                v-for="role in roles"
                                :key="`${user.id}-${role.key}`"
                                class="inline-flex items-center gap-2 font-ui text-sm text-muted-1"
                            >
                                <Checkbox
                                    v-model:checked="accessSelections[user.id]"
                                    :value="role.key"
                                />
                                <span>{{ role.label }}</span>
                            </label>

                            <span
                                v-if="user.is_footmouthkick_user"
                                class="inline-flex items-center rounded-md border border-success/25 bg-success/10 px-2 py-1 font-ui text-xs uppercase tracking-[0.12em] text-success"
                            >
                                Admin locked
                            </span>
                        </div>
                    </div>

                    <div class="index-record__side flex flex-col gap-2 sm:flex-row">
                        <AppButton
                            variant="primary"
                            size="sm"
                            :disabled="processingUserId === user.id"
                            @click="saveUserAccess(user)"
                        >
                            {{ processingUserId === user.id ? 'Saving' : 'Save Access' }}
                        </AppButton>

                        <AppButton variant="ghost" size="sm" @click="startEditing(user)">
                            Edit
                        </AppButton>

                        <AppButton
                            v-if="user.can_delete"
                            variant="danger"
                            size="sm"
                            :disabled="deletingUserId === user.id"
                            @click="deleteUser(user)"
                        >
                            {{ deletingUserId === user.id ? 'Deleting' : 'Delete' }}
                        </AppButton>
                    </div>
                </div>
            </section>
        </div>

        <AppDrawer
            v-if="isCreateDrawerOpen"
            title="Create User"
            close-label="Cancel"
            @close="closeCreateDrawer"
        >
            <form class="space-y-5" @submit.prevent="createUser">
                <div class="form-grid-2-tight">
                    <div>
                        <InputLabel value="Name" />
                        <TextInput v-model="createForm.name" class="mt-1 w-full" type="text" autocomplete="name" />
                        <InputError class="mt-2" :message="createForm.errors.name" />
                    </div>

                    <div>
                        <InputLabel value="Email" />
                        <TextInput v-model="createForm.email" class="mt-1 w-full" type="email" autocomplete="email" />
                        <InputError class="mt-2" :message="createForm.errors.email" />
                    </div>

                    <div>
                        <InputLabel value="Password" />
                        <TextInput v-model="createForm.password" class="mt-1 w-full" type="password" autocomplete="new-password" />
                        <InputError class="mt-2" :message="createForm.errors.password" />
                    </div>

                    <div>
                        <InputLabel value="Confirm Password" />
                        <TextInput v-model="createForm.password_confirmation" class="mt-1 w-full" type="password" autocomplete="new-password" />
                        <InputError class="mt-2" :message="createForm.errors.password_confirmation" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 font-ui text-sm text-muted-1">
                        <Checkbox v-model:checked="createForm.verified" />
                        <span>Verified</span>
                    </label>

                    <label
                        v-for="role in roles"
                        :key="`create-${role.key}`"
                        class="inline-flex items-center gap-2 font-ui text-sm text-muted-1"
                    >
                        <Checkbox
                            v-model:checked="createForm.access_roles"
                            :value="role.key"
                        />
                        <span>{{ role.label }}</span>
                    </label>
                </div>

                <InputError :message="createForm.errors.verified" />
                <InputError :message="createForm.errors.access_roles" />

                <div class="form-actions">
                    <AppButton type="submit" variant="primary" :disabled="createForm.processing">
                        {{ createForm.processing ? 'Creating' : 'Create User' }}
                    </AppButton>
                    <AppButton type="button" variant="ghost" @click="closeCreateDrawer">
                        Cancel
                    </AppButton>
                </div>
            </form>
        </AppDrawer>

        <AppDrawer
            v-if="editingUser"
            :title="`Edit ${editingUser.name}`"
            close-label="Cancel"
            @close="cancelEditing"
        >
            <form class="space-y-5" @submit.prevent="updateUser(editingUser)">
                <div class="form-grid-2-tight">
                    <div>
                        <InputLabel value="Name" />
                        <TextInput
                            v-model="editForm.name"
                            class="mt-1 w-full"
                            type="text"
                            autocomplete="name"
                            :disabled="!editingUser.can_edit_reserved_identity"
                        />
                        <InputError class="mt-2" :message="editForm.errors.name" />
                    </div>

                    <div>
                        <InputLabel value="Email" />
                        <TextInput
                            v-model="editForm.email"
                            class="mt-1 w-full"
                            type="email"
                            autocomplete="email"
                            :disabled="!editingUser.can_edit_reserved_identity"
                        />
                        <InputError class="mt-2" :message="editForm.errors.email" />
                    </div>

                    <div>
                        <InputLabel value="New Password" />
                        <TextInput v-model="editForm.password" class="mt-1 w-full" type="password" autocomplete="new-password" />
                        <InputError class="mt-2" :message="editForm.errors.password" />
                    </div>

                    <div>
                        <InputLabel value="Confirm New Password" />
                        <TextInput v-model="editForm.password_confirmation" class="mt-1 w-full" type="password" autocomplete="new-password" />
                        <InputError class="mt-2" :message="editForm.errors.password_confirmation" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 font-ui text-sm text-muted-1">
                        <Checkbox
                            v-model:checked="editForm.verified"
                            :disabled="editingUser.is_footmouthkick_user"
                        />
                        <span>Verified</span>
                    </label>

                    <label
                        v-for="role in roles"
                        :key="`edit-${editingUser.id}-${role.key}`"
                        class="inline-flex items-center gap-2 font-ui text-sm text-muted-1"
                    >
                        <Checkbox
                            v-model:checked="editForm.access_roles"
                            :value="role.key"
                        />
                        <span>{{ role.label }}</span>
                    </label>

                    <span
                        v-if="editingUser.is_footmouthkick_user"
                        class="inline-flex items-center rounded-md border border-success/25 bg-success/10 px-2 py-1 font-ui text-xs uppercase tracking-[0.12em] text-success"
                    >
                        Admin locked
                    </span>
                </div>

                <InputError :message="editForm.errors.verified" />
                <InputError :message="editForm.errors.access_roles" />

                <div class="form-actions">
                    <AppButton type="submit" variant="primary" :disabled="editForm.processing">
                        {{ editForm.processing ? 'Saving' : 'Save User' }}
                    </AppButton>
                    <AppButton type="button" variant="ghost" @click="cancelEditing">
                        Cancel
                    </AppButton>
                </div>
            </form>
        </AppDrawer>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Checkbox from '@/Components/Checkbox.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import AppDrawer from '@/Components/ui/AppDrawer.vue'
import { confirmDialog } from '@/lib/appDialog'

const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
})

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    verified: true,
    access_roles: [],
})

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    verified: false,
    access_roles: [],
})

const accessSelections = reactive({})
const processingUserId = ref(null)
const editingUserId = ref(null)
const deletingUserId = ref(null)
const isCreateDrawerOpen = ref(false)

const editingUser = computed(() => props.users.find((user) => user.id === editingUserId.value) ?? null)

const syncAccessSelections = () => {
    props.users.forEach((user) => {
        accessSelections[user.id] = [...user.access_roles]
    })
}

watch(() => props.users, syncAccessSelections, { immediate: true, deep: true })

const openCreateDrawer = () => {
    createForm.clearErrors()
    isCreateDrawerOpen.value = true
}

const closeCreateDrawer = () => {
    isCreateDrawerOpen.value = false
    createForm.reset()
    createForm.clearErrors()
}

const createUser = () => {
    createForm.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset()
            closeCreateDrawer()
        },
    })
}

const startEditing = (user) => {
    editingUserId.value = user.id
    editForm.clearErrors()
    editForm.name = user.name
    editForm.email = user.email
    editForm.password = ''
    editForm.password_confirmation = ''
    editForm.verified = user.is_verified
    editForm.access_roles = [...user.access_roles]
}

const cancelEditing = () => {
    editingUserId.value = null
    editForm.reset()
    editForm.clearErrors()
}

const updateUser = (user) => {
    editForm.put(route('admin.users.update', user.id), {
        preserveScroll: true,
        onSuccess: () => {
            cancelEditing()
        },
    })
}

const saveUserAccess = (user) => {
    processingUserId.value = user.id

    router.put(route('admin.users.access.update', user.id), {
        access_roles: accessSelections[user.id] ?? [],
    }, {
        preserveScroll: true,
        onFinish: () => {
            processingUserId.value = null
        },
    })
}

const deleteUser = async (user) => {
    const confirmed = await confirmDialog({
        title: 'Delete user?',
        message: `Delete ${user.name}? This removes their account access.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    deletingUserId.value = user.id

    router.delete(route('admin.users.destroy', user.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingUserId.value = null
        },
    })
}
</script>
