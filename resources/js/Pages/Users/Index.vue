<!-- resources/js/Pages/Users/Index.vue -->
<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    users: { type: Array, required: true },
    roles: { type: Array, required: true }
});

const editDialog = ref(false);
const deleteDialog = ref(false);
const editingUser = ref(null);
const userToDelete = ref(null);

const form = useForm({
    name: '',
    email: '',
    role: 1,
});

const openEditDialog = (user) => {
    editingUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.clearErrors();
    editDialog.value = true;
};

const updateUser = () => {
    form.put(route('users.update', editingUser.value.id), {
        onSuccess: () => {
            editDialog.value = false;
            editingUser.value = null;
            form.reset();
        }
    });
};

const confirmDelete = (user) => {
    userToDelete.value = user;
    deleteDialog.value = true;
};

const deleteUser = () => {
    router.delete(route('users.destroy', userToDelete.value.id), {
        onSuccess: () => {
            deleteDialog.value = false;
            userToDelete.value = null;
        }
    });
};

const getRoleLabel = (role) => {
    const found = props.roles.find(r => r.id === role);
    return found ? found.name : 'Inconnu';
};

const getRoleColor = (role) => {
    return role === 2 ? 'success' : 'primary';
};
</script>

<template>

    <Head title="Gestion des Utilisateurs" />
    <AuthenticatedLayout>
        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <div class="d-flex align-center">
                    <v-icon icon="mdi-account-group" color="primary" size="28" class="mr-3"></v-icon>
                    <div>
                        <div class="text-h6 font-weight-bold">Gestion des Utilisateurs</div>
                        <div class="text-caption text-grey">Gestion des rôles et permissions</div>
                    </div>
                </div>
                <v-spacer></v-spacer>
            </v-toolbar>

            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Nom</th>
                        <th class="text-overline">Email</th>
                        <th class="text-overline text-center">Rôle</th>
                        <th class="text-overline text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td class="font-weight-bold">{{ user.name }}</td>
                        <td>{{ user.email }}</td>
                        <td class="text-center">
                            <v-chip :color="getRoleColor(user.role)" size="small">
                                {{ getRoleLabel(user.role) }}
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <v-btn icon="mdi-pencil" size="small" variant="text" color="primary"
                                @click="openEditDialog(user)" title="Modifier"></v-btn>
                            <v-btn v-if="user.id !== $page.props.auth.user.id" icon="mdi-delete" size="small"
                                variant="text" color="error" @click="confirmDelete(user)" title="Supprimer"></v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- Dialog d'édition -->
        <v-dialog v-model="editDialog" max-width="500px" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Modifier l'utilisateur</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" @click="editDialog = false"></v-btn>
                </v-toolbar>
                <v-card-text class="pa-6">
                    <v-form @submit.prevent="updateUser">
                        <v-text-field v-model="form.name" label="Nom" variant="outlined"
                            :error-messages="form.errors.name" required></v-text-field>

                        <v-text-field v-model="form.email" label="Email" type="email" variant="outlined"
                            :error-messages="form.errors.email" required></v-text-field>

                        <v-select v-model="form.role" :items="roles" item-title="name" item-value="id" label="Rôle"
                            variant="outlined" :error-messages="form.errors.role" required>
                            <template v-slot:item="{ item, props: itemProps }">
                                <v-list-item v-bind="itemProps">
                                    <div class="d-flex align-center">
                                        <v-chip :color="item.value === 2 ? 'success' : 'primary'" size="small"
                                            class="mr-2">
                                            {{ item.title }}
                                        </v-chip>
                                    </div>
                                </v-list-item>
                            </template>
                        </v-select>

                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="editDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="form.processing" class="ml-2">
                                Mettre à jour
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Dialog de suppression -->
        <v-dialog v-model="deleteDialog" max-width="400px">
            <v-card>
                <v-card-title class="text-h6 text-error pa-4">
                    <v-icon start color="error">mdi-alert-circle</v-icon>
                    Confirmer la suppression
                </v-card-title>
                <v-card-text class="pa-4">
                    Êtes-vous sûr de vouloir supprimer l'utilisateur <strong>{{ userToDelete?.name }}</strong> ?
                    <div class="text-caption text-grey mt-2">Cette action est irréversible.</div>
                </v-card-text>
                <v-card-actions class="pa-4">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" @click="deleteUser">Supprimer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.v-table :deep(th) {
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background-color: #f5f5f5;
}

.v-table :deep(td) {
    font-size: 0.875rem;
}
</style>
