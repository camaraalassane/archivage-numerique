<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    users: { type: Array, required: true },
    roles: { type: Array, required: true }
});

const editDialog = ref(false);
const deleteDialog = ref(false);
const createDialog = ref(false);
const editingUser = ref(null);
const userToDelete = ref(null);
const removeConfidentialPassword = ref(false);
const editingUserId = ref(null);

const form = useForm({
    name: '',
    email: '',
    role: 1,
    peut_archiver_confidentiel: false,
    peut_valider_confidentiel: false,
    peut_consulter_confidentiel: false,
    mot_de_passe_confidentiel: '',
    remove_confidential_password: false,
});

const createTab = ref('ordinaire');
const editTab = ref('ordinaire');

const createForm = useForm({
    name: '',
    email: '',
    role: 1,
    password: '',
    peut_archiver_confidentiel: false,
    peut_valider_confidentiel: false,
    peut_consulter_confidentiel: false,
    mot_de_passe_confidentiel: '',
});

const editingUserHasPassword = computed(() => {
    const user = props.users.find(u => u.id === editingUserId.value);
    return !!user?.has_confidential_password;
});

const openCreateDialog = () => {
    createForm.reset();
    createForm.clearErrors();
    createTab.value = 'ordinaire';
    createDialog.value = true;
};

const createUser = () => {
    createForm.post(route('users.store'), {
        onSuccess: () => {
            createDialog.value = false;
            createForm.reset();
        }
    });
};

const openEditDialog = (user) => {
    form.clearErrors();
    editTab.value = 'ordinaire';
    editingUserId.value = user.id;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.peut_archiver_confidentiel = !!user.peut_archiver_confidentiel;
    form.peut_valider_confidentiel = !!user.peut_valider_confidentiel;
    form.peut_consulter_confidentiel = !!user.peut_consulter_confidentiel;
    form.mot_de_passe_confidentiel = '';
    form.remove_confidential_password = false;
    removeConfidentialPassword.value = false;
    editDialog.value = true;
};

const updateUser = () => {
    form.remove_confidential_password = removeConfidentialPassword.value;
    form.put(route('users.update', editingUserId.value), {
        onSuccess: () => {
            editDialog.value = false;
            editingUserId.value = null;
            form.reset();
            removeConfidentialPassword.value = false;
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
                <v-btn color="primary" prepend-icon="mdi-account-plus" @click="openCreateDialog">
                    Créer un utilisateur
                </v-btn>
            </v-toolbar>

            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Nom</th>
                        <th class="text-overline">Email</th>
                        <th class="text-overline text-center">Rôle</th>
                        <th class="text-overline text-center">Confidentiel</th>
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
                            <v-icon v-if="user.has_confidential_password" color="error" title="Mot de passe défini" size="small">mdi-shield-lock</v-icon>
                            <v-icon v-if="user.peut_consulter_confidentiel" color="info" class="ml-1" title="Droit de consultation confidentiel" size="small">mdi-eye-lock</v-icon>
                            <v-icon v-if="user.peut_archiver_confidentiel" color="warning" class="ml-1" title="Droit d'archivage confidentiel" size="small">mdi-upload-lock</v-icon>
                            <v-icon v-if="user.peut_valider_confidentiel" color="success" class="ml-1" title="Droit de validation confidentiel" size="small">mdi-check-decagram</v-icon>
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

        <!-- Dialog de création -->
        <v-dialog v-model="createDialog" max-width="500px" persistent>
            <v-card>
                <v-toolbar color="primary">
                    <v-toolbar-title>Créer un utilisateur</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" @click="createDialog = false"></v-btn>
                </v-toolbar>
                <v-card-text class="pa-6">
                    <v-form @submit.prevent="createUser">
                        <v-tabs v-model="createTab" color="primary" class="mb-4">
                            <v-tab value="ordinaire">Infos Ordinaires</v-tab>
                            <v-tab value="confidentiel" color="error">
                                <v-icon start>mdi-shield-lock</v-icon> Accès Confidentiel
                            </v-tab>
                        </v-tabs>

                        <v-window v-model="createTab">
                            <v-window-item value="ordinaire">
                                <v-text-field v-model="createForm.name" label="Nom" variant="outlined"
                                    :error-messages="createForm.errors.name" required></v-text-field>

                                <v-text-field v-model="createForm.email" label="Email" type="email" variant="outlined"
                                    :error-messages="createForm.errors.email" required></v-text-field>

                                <v-text-field v-model="createForm.password" label="Mot de passe" type="password" variant="outlined"
                                    :error-messages="createForm.errors.password" required></v-text-field>

                                <v-select v-model="createForm.role" :items="roles" item-title="name" item-value="id" label="Rôle"
                                    variant="outlined" :error-messages="createForm.errors.role" required>
                                    <template v-slot:item="{ item, props: itemProps }">
                                        <v-list-item v-bind="itemProps">
                                            <div class="d-flex align-center">
                                                <v-chip :color="item.value === 2 ? 'success' : 'primary'" size="small" class="mr-2">
                                                    {{ item.title }}
                                                </v-chip>
                                            </div>
                                        </v-list-item>
                                    </template>
                                </v-select>
                            </v-window-item>

                            <v-window-item value="confidentiel">
                                <v-alert type="info" variant="tonal" density="compact" class="mb-4 text-caption">
                                    Configurez ici les droits spécifiques et le mot de passe pour l'espace confidentiel.
                                </v-alert>

                                <v-text-field v-model="createForm.mot_de_passe_confidentiel" label="Mot de passe confidentiel" type="text" variant="outlined" density="comfortable"
                                    :error-messages="createForm.errors.mot_de_passe_confidentiel" hint="Laissez vide si l'utilisateur n'a pas accès" persistent-hint></v-text-field>

                                <v-switch v-model="createForm.peut_consulter_confidentiel" color="info" label="Peut consulter les archives confidentielles" hide-details class="mt-3"></v-switch>
                                <v-switch v-model="createForm.peut_archiver_confidentiel" color="error" label="Peut archiver des documents confidentiels" hide-details></v-switch>
                                <v-switch v-model="createForm.peut_valider_confidentiel" color="success" label="Peut valider des documents confidentiels" class="mb-2"></v-switch>
                            </v-window-item>
                        </v-window>

                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="createDialog = false">Annuler</v-btn>
                            <v-btn color="primary" type="submit" :loading="createForm.processing" class="ml-2">
                                Créer
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

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
                        <!-- TABS -->
                        <v-tabs v-model="editTab" color="primary" class="mb-4">
                            <v-tab value="ordinaire">Infos Ordinaires</v-tab>
                            <v-tab value="confidentiel" color="error">
                                <v-icon start>mdi-shield-lock</v-icon> Accès Confidentiel
                            </v-tab>
                        </v-tabs>

                        <v-window v-model="editTab">
                            <v-window-item value="ordinaire">
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
                            </v-window-item>

                            <v-window-item value="confidentiel">
                                <!-- Indicateur mot de passe existant -->
                                <v-alert v-if="editingUserHasPassword && !removeConfidentialPassword"
                                    type="info" variant="tonal" density="compact" class="mb-3">
                                    <div class="d-flex align-center justify-space-between">
                                        <span class="text-caption">✅ Mot de passe confidentiel déjà défini</span>
                                        <v-btn size="x-small" color="error" variant="text"
                                            @click="removeConfidentialPassword = true; form.mot_de_passe_confidentiel = ''">Retirer</v-btn>
                                    </div>
                                </v-alert>

                                <v-alert v-if="removeConfidentialPassword"
                                    type="warning" variant="tonal" density="compact" class="mb-3">
                                    <div class="d-flex align-center justify-space-between">
                                        <span class="text-caption">⚠️ Le mot de passe sera supprimé</span>
                                        <v-btn size="x-small" color="success" variant="text"
                                            @click="removeConfidentialPassword = false">Annuler</v-btn>
                                    </div>
                                </v-alert>

                                <!-- Champ mot de passe : visible si pas de mot de passe OU qu'on veut en définir un nouveau -->
                                <v-text-field
                                    v-if="!removeConfidentialPassword"
                                    v-model="form.mot_de_passe_confidentiel"
                                    :label="editingUserHasPassword ? 'Nouveau mot de passe (laisser vide pour garder l\'actuel)' : 'Définir un mot de passe confidentiel'"
                                    type="text" variant="outlined" density="comfortable"
                                    :error-messages="form.errors.mot_de_passe_confidentiel"
                                    :hint="editingUserHasPassword ? 'Laissez vide pour conserver le mot de passe actuel' : 'Laissez vide pour ne pas définir de mot de passe'"
                                    persistent-hint></v-text-field>

                                <v-switch v-model="form.peut_consulter_confidentiel" color="info"
                                    label="Peut consulter les archives confidentielles" class="mt-3" hide-details></v-switch>
                                    
                                <v-switch v-model="form.peut_archiver_confidentiel" color="error"
                                    label="Peut archiver des documents confidentiels" hide-details></v-switch>
                                
                                <v-switch v-model="form.peut_valider_confidentiel" color="success"
                                    label="Peut valider des documents confidentiels" class="mb-2"></v-switch>
                            </v-window-item>
                        </v-window>

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
