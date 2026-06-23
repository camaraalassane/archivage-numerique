<!-- resources/js/Pages/Mois/Index.vue -->
<script setup>
import { ref, watch, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    mois: { type: Array, required: true },
    annees: { type: Array, required: true },
    permissions: { type: Object, default: () => ({}) }
});

// Permissions
const canManage = computed(() => props.permissions?.can_manage_months || false);

// SNACKBAR
const snackbar = ref({
    show: false,
    text: '',
    color: 'success'
});

const showNotify = (text, color = 'success') => {
    snackbar.value = { show: true, text, color };
    setTimeout(() => {
        snackbar.value.show = false;
    }, 4000);
};

const dialog = ref(false);
const deleteDialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const itemToDelete = ref(null);

const form = useForm({
    annee_id: null,
    mois: 1,
    nom_mois: '',
    code: '',
    description: '',
    active: true
});

const moisOptions = [
    { value: 1, name: 'Janvier' }, { value: 2, name: 'Février' }, { value: 3, name: 'Mars' },
    { value: 4, name: 'Avril' }, { value: 5, name: 'Mai' }, { value: 6, name: 'Juin' },
    { value: 7, name: 'Juillet' }, { value: 8, name: 'Août' }, { value: 9, name: 'Septembre' },
    { value: 10, name: 'Octobre' }, { value: 11, name: 'Novembre' }, { value: 12, name: 'Décembre' }
];

// Fonction pour générer le code automatiquement
const generateCode = (anneeId, moisValue, anneeAnnee) => {
    if (!anneeId || !moisValue) return '';
    const anneeNum = anneeAnnee || getAnneeNumber(anneeId);
    const moisStr = String(moisValue).padStart(2, '0');
    return `MOIS_${anneeNum}_${moisStr}`;
};

// Récupérer le numéro de l'année à partir de l'ID
const getAnneeNumber = (anneeId) => {
    const annee = props.annees.find(a => a.id === anneeId);
    return annee ? annee.annee : '';
};

// Mettre à jour nom_mois quand le mois change
const updateNomMois = () => {
    const found = moisOptions.find(m => m.value === form.mois);
    if (found) {
        form.nom_mois = found.name;
    }
};

// Mettre à jour le code quand l'année ou le mois change
const updateCode = () => {
    if (form.annee_id && form.mois && !isEditing.value) {
        const anneeNum = getAnneeNumber(form.annee_id);
        if (anneeNum) {
            form.code = generateCode(form.annee_id, form.mois, anneeNum);
        }
    }
};

// Watch pour générer automatiquement le code
watch(() => form.annee_id, () => {
    if (!isEditing.value) {
        updateCode();
    }
});

watch(() => form.mois, () => {
    if (!isEditing.value) {
        updateNomMois();
        updateCode();
    }
});

const openCreateDialog = () => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour créer des mois.', 'error');
        return;
    }
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    form.mois = 1;
    form.active = true;
    updateNomMois();
    form.code = '';
    form.clearErrors();
    dialog.value = true;
};

const openEditDialog = (moisItem) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour modifier des mois.', 'error');
        return;
    }
    isEditing.value = true;
    editingId.value = moisItem.id;
    form.annee_id = moisItem.annee_id;
    form.mois = moisItem.mois;
    form.nom_mois = moisItem.nom_mois;
    form.code = moisItem.code;
    form.description = moisItem.description || '';
    form.active = moisItem.active;
    form.clearErrors();
    dialog.value = true;
};

const submit = () => {
    updateNomMois();

    if (!isEditing.value && form.annee_id && form.mois) {
        updateCode();
    }

    if (isEditing.value) {
        form.put(route('mois.update', editingId.value), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                showNotify('Mois mis à jour avec succès', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la mise à jour', 'error');
            },
            preserveScroll: true
        });
    } else {
        form.post(route('mois.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                showNotify('Mois créé avec succès !', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la création', 'error');
            },
            preserveScroll: true
        });
    }
};

const confirmDelete = (id) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour supprimer des mois.', 'error');
        return;
    }
    itemToDelete.value = id;
    deleteDialog.value = true;
};

const deleteItem = () => {
    router.delete(route('mois.destroy', itemToDelete.value), {
        onSuccess: () => {
            deleteDialog.value = false;
            itemToDelete.value = null;
            showNotify('Mois supprimé avec succès', 'success');
        },
        onError: () => {
            showNotify('Erreur lors de la suppression', 'error');
        },
        preserveScroll: true
    });
};

const getAnneeLabel = (anneeId) => {
    const annee = props.annees.find(a => a.id === anneeId);
    return annee ? annee.annee : 'N/A';
};
</script>

<template>

    <Head title="Gestion des Mois" />
    <AuthenticatedLayout>
        <!-- SNACKBAR -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" rounded="lg">
            <v-icon start>{{ snackbar.color === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
            {{ snackbar.text }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar.show = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <div class="d-flex align-center">
                    <v-icon icon="mdi-calendar-month" color="primary" size="28" class="mr-3"></v-icon>
                    <div>
                        <div class="text-h6 font-weight-bold">Mois d'archivage</div>
                        <div class="text-caption text-grey">Gestion des mois dans l'arborescence</div>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog" :disabled="!canManage">
                    Nouveau Mois
                </v-btn>
            </v-toolbar>

            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-left text-overline">Année</th>
                        <th class="text-left text-overline">Mois</th>
                        <th class="text-left text-overline">Code</th>
                        <th class="text-left text-overline">Description</th>
                        <th class="text-center text-overline">Statut</th>
                        <th class="text-center text-overline">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in mois" :key="item.id">
                        <td class="font-weight-bold text-h6">{{ getAnneeLabel(item.annee_id) }}</td>
                        <td>
                            <v-icon color="primary" size="small" class="mr-1">mdi-calendar-month</v-icon>
                            {{ item.nom_mois }}
                        </td>
                        <td>
                            <v-chip size="small" color="blue-grey-lighten-4" text-color="blue-grey-darken-4">
                                {{ item.code }}
                            </v-chip>
                        </td>
                        <td class="text-caption">{{ item.description || '-' }}</td>
                        <td class="text-center">
                            <v-chip :color="item.active ? 'success' : 'error'" size="small">
                                {{ item.active ? 'Actif' : 'Inactif' }}
                                <v-icon right size="x-small" class="ml-1">
                                    {{ item.active ? 'mdi-check-circle' : 'mdi-close-circle' }}
                                </v-icon>
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <v-btn icon="mdi-pencil" size="small" variant="text" color="primary"
                                @click="openEditDialog(item)" title="Modifier" :disabled="!canManage"></v-btn>
                            <v-btn icon="mdi-delete" size="small" variant="text" color="error"
                                @click="confirmDelete(item.id)" title="Supprimer" :disabled="!canManage"></v-btn>
                        </td>
                    </tr>
                    <tr v-if="mois.length === 0">
                        <td colspan="6" class="text-center py-12">
                            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-calendar-month</v-icon>
                            <div class="text-h6 text-grey-lighten-1">Aucun mois enregistré</div>
                            <div class="text-caption text-grey mt-2">Cliquez sur "Nouveau Mois" pour commencer</div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- Dialog Création/Modification -->
        <v-dialog v-model="dialog" max-width="600px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh; display: flex; flex-direction: column;">
                <v-toolbar color="primary" class="rounded-t-xl">
                    <v-icon start class="ml-4">{{ isEditing ? 'mdi-pencil' : 'mdi-plus' }}</v-icon>
                    <v-toolbar-title class="font-weight-bold">
                        {{ isEditing ? 'Modifier le mois' : 'Nouveau mois' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="dialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="overflow-y: auto; flex: 1;">
                    <v-form @submit.prevent="submit">
                        <v-select v-model="form.annee_id" :items="annees" item-title="annee" item-value="id"
                            label="Année" variant="outlined" density="comfortable"
                            :error-messages="form.errors.annee_id" :disabled="form.processing || isEditing"
                            :readonly="isEditing" required>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-calendar</v-icon>
                            </template>
                        </v-select>

                        <v-select v-model="form.mois" :items="moisOptions" item-title="name" item-value="value"
                            label="Mois" variant="outlined" density="comfortable" :error-messages="form.errors.mois"
                            @update:model-value="updateNomMois" :disabled="form.processing || isEditing"
                            :readonly="isEditing" required>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-calendar-month</v-icon>
                            </template>
                        </v-select>

                        <v-text-field v-model="form.nom_mois" label="Nom du mois" variant="outlined"
                            density="comfortable" :error-messages="form.errors.nom_mois" readonly
                            disabled></v-text-field>

                        <v-text-field v-model="form.code" label="Code (auto-généré)" variant="outlined"
                            density="comfortable" :error-messages="form.errors.code" readonly disabled
                            hint="Le code est généré automatiquement à partir de l'année et du mois" persistent-hint>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-tag</v-icon>
                            </template>
                        </v-text-field>

                        <v-textarea v-model="form.description" label="Description (optionnelle)" variant="outlined"
                            density="comfortable" rows="3" auto-grow hint="Description optionnelle du mois"
                            persistent-hint></v-textarea>

                        <v-switch v-model="form.active" label="Mois actif" color="primary" inset hide-details>
                            <template v-slot:label>
                                <div>
                                    <span class="font-weight-bold">Mois actif</span>
                                    <span class="text-caption text-grey d-block">Permet d'activer ou désactiver ce mois
                                        dans l'arborescence</span>
                                </div>
                            </template>
                        </v-switch>
                    </v-form>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-4 bg-grey-lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="dialog = false" :disabled="form.processing" rounded="lg">
                        Annuler
                    </v-btn>
                    <v-btn color="primary" type="submit" :loading="form.processing" class="px-6 ml-2" rounded="lg"
                        @click="submit" :disabled="!canManage">
                        {{ isEditing ? 'Mettre à jour' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog Suppression -->
        <v-dialog v-model="deleteDialog" max-width="450px">
            <v-card class="rounded-xl">
                <v-card-title class="text-h6 text-error pa-4">
                    <v-icon start color="error">mdi-alert-circle</v-icon>
                    Confirmer la suppression
                </v-card-title>
                <v-divider></v-divider>
                <v-card-text class="pa-4">
                    Êtes-vous sûr de vouloir supprimer ce mois ?
                    <div class="text-caption text-grey mt-2">Cette action est irréversible et supprimera tous les
                        dossiers
                        associés.</div>
                </v-card-text>
                <v-card-actions class="pa-4 bg-grey-lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" @click="deleteItem" :disabled="!canManage">
                        Supprimer
                    </v-btn>
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
    padding: 12px 16px;
}

.v-dialog-enter-active,
.v-dialog-leave-active {
    transition: opacity 0.2s ease;
}

.v-dialog-enter-from,
.v-dialog-leave-to {
    opacity: 0;
}
</style>
