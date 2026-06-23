<!-- resources/js/Pages/Annees/Index.vue -->
<script setup>
import { ref, watch, computed } from 'vue';  // ← AJOUTER computed ici
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    annees: { type: Array, required: true },
    permissions: { type: Object, default: () => ({}) }
});

const dialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

// Dialogue de confirmation
const confirmDialog = ref(false);
const confirmAction = ref(null);
const confirmMessage = ref('');
const confirmTitle = ref('Confirmation');

// Permissions
const canManage = computed(() => props.permissions?.can_manage_years || false);

const generateCode = (annee) => {
    if (!annee) return '';
    return `ANNEE_${annee}`;
};

const form = useForm({
    annee: '',
    code: '',
    description: '',
    active: true
});

watch(() => form.annee, (newAnnee) => {
    if (newAnnee && !isEditing.value) {
        form.code = generateCode(newAnnee);
    }
});

const showConfirm = (message, action, title = 'Confirmation') => {
    confirmMessage.value = message;
    confirmAction.value = action;
    confirmTitle.value = title;
    confirmDialog.value = true;
};

const executeConfirm = () => {
    if (confirmAction.value) {
        confirmAction.value();
    }
    confirmDialog.value = false;
};

const openCreateDialog = () => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour créer des années.', 'error');
        return;
    }
    isEditing.value = false;
    form.reset();
    form.code = '';
    form.clearErrors();
    dialog.value = true;
};

const openEditDialog = (annee) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour modifier des années.', 'error');
        return;
    }
    isEditing.value = true;
    editingId.value = annee.id;
    form.annee = annee.annee;
    form.code = annee.code;
    form.description = annee.description || '';
    form.active = annee.active;
    form.clearErrors();
    dialog.value = true;
};

const submit = () => {
    if (!isEditing.value && form.annee) {
        form.code = generateCode(form.annee);
    }

    if (isEditing.value) {
        form.put(route('annees.update', editingId.value), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                showNotify('Année mise à jour avec succès', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la mise à jour', 'error');
            },
            preserveScroll: true
        });
    } else {
        form.post(route('annees.store'), {
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                showNotify('Année créée avec succès ! Les 12 mois ont été générés automatiquement.', 'success');
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
        showNotify('Vous n\'avez pas les droits pour supprimer des années.', 'error');
        return;
    }
    showConfirm('Supprimer cette année ? Cette action est irréversible.', () => {
        router.delete(route('annees.destroy', id), {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['annees'] });
                showNotify('Année supprimée avec succès', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la suppression', 'error');
            }
        });
    }, 'Supprimer l\'année');
};

const cloturer = (id) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour clôturer des années.', 'error');
        return;
    }
    showConfirm('🔒 Clôturer cette année ? Plus aucun document ne pourra être ajouté.', () => {
        router.post(route('annees.cloturer', id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['annees'] });
                showNotify('Année clôturée avec succès', 'success');
            },
            onError: (errors) => {
                console.error('Erreur clôture:', errors);
                showNotify('Erreur lors de la clôture', 'error');
            }
        });
    }, 'Clôturer l\'année');
};

const rouvrir = (id) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour rouvrir des années.', 'error');
        return;
    }
    showConfirm('🔓 Rouvrir cette année ? Les documents pourront à nouveau être ajoutés.', () => {
        router.post(route('annees.rouvrir', id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['annees'] });
                showNotify('Année réouverte avec succès', 'success');
            },
            onError: (errors) => {
                console.error('Erreur réouverture:', errors);
                showNotify('Erreur lors de la réouverture', 'error');
            }
        });
    }, 'Rouvrir l\'année');
};

const toggleActive = (id, currentStatus) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour modifier la visibilité.', 'error');
        return;
    }
    const message = currentStatus
        ? '👁️ Désactiver la visibilité de cette année ?'
        : '👁️ Activer la visibilité de cette année ?';

    showConfirm(message, () => {
        router.post(route('annees.toggle', id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['annees'] });
                showNotify('Visibilité mise à jour', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la mise à jour', 'error');
            }
        });
    }, currentStatus ? 'Désactiver' : 'Activer');
};

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

// === STATUT ===
const getStatutInfo = (annee) => {
    if (annee.statut === 'cloturee') {
        return { label: '🔒 Clôturée', color: 'error', icon: 'mdi-lock' };
    }
    if (annee.statut === 'active') {
        return { label: '✅ Active', color: 'success', icon: 'mdi-check-circle' };
    }
    return { label: '❌ Inactive', color: 'warning', icon: 'mdi-close-circle' };
};
</script>

<template>

    <Head title="Gestion des Années" />
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
                <v-icon icon="mdi-calendar" color="primary" size="28" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Années d'archivage</div>
                    <div class="text-caption text-grey">Gestion des années dans l'arborescence</div>
                </div>
                <v-spacer></v-spacer>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog" :disabled="!canManage">
                    Nouvelle Année
                </v-btn>
            </v-toolbar>

            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Année</th>
                        <th class="text-overline">Code</th>
                        <th class="text-overline">Description</th>
                        <th class="text-overline text-center">Statut</th>
                        <th class="text-overline text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="annee in annees" :key="annee.id">
                        <td class="font-weight-bold text-h6">{{ annee.annee }}</td>
                        <td>
                            <v-chip size="small" color="blue-grey-lighten-4" text-color="blue-grey-darken-4">
                                {{ annee.code }}
                            </v-chip>
                        </td>
                        <td class="text-caption">{{ annee.description || '-' }}</td>
                        <td class="text-center">
                            <v-chip :color="getStatutInfo(annee).color" size="small">
                                {{ getStatutInfo(annee).label }}
                                <v-icon right size="x-small" class="ml-1">
                                    {{ getStatutInfo(annee).icon }}
                                </v-icon>
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <!-- Modifier -->
                            <v-btn icon="mdi-pencil" size="small" variant="text" color="primary"
                                @click="openEditDialog(annee)" title="Modifier" :disabled="!canManage"></v-btn>

                            <!-- VISIBILITÉ (ŒIL) -->
                            <v-btn v-if="annee.est_active_visible" icon="mdi-eye" size="small" variant="text"
                                color="success" @click="toggleActive(annee.id, true)" title="Désactiver la visibilité"
                                :disabled="!canManage"></v-btn>
                            <v-btn v-if="!annee.est_active_visible" icon="mdi-eye-off" size="small" variant="text"
                                color="grey" @click="toggleActive(annee.id, false)" title="Activer la visibilité"
                                :disabled="!canManage"></v-btn>

                            <!-- CLÔTURE (CADENAS) -->
                            <v-btn v-if="annee.peut_cloturer" icon="mdi-lock" size="small" variant="text"
                                color="warning" @click="cloturer(annee.id)" title="🔒 Clôturer (plus d'insertion)"
                                :disabled="!canManage"></v-btn>
                            <v-btn v-if="annee.peut_rouvrir" icon="mdi-lock-open" size="small" variant="text"
                                color="success" @click="rouvrir(annee.id)" title="🔓 Rouvrir (insertion possible)"
                                :disabled="!canManage"></v-btn>

                            <!-- Supprimer -->
                            <v-btn icon="mdi-delete" size="small" variant="text" color="error"
                                @click="confirmDelete(annee.id)" title="Supprimer" :disabled="!canManage"></v-btn>
                        </td>
                    </tr>
                    <tr v-if="annees.length === 0">
                        <td colspan="5" class="text-center py-12">
                            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-calendar-blank</v-icon>
                            <div class="text-h6 text-grey-lighten-1">Aucune année enregistrée</div>
                            <div class="text-caption text-grey mt-2">Cliquez sur "Nouvelle Année" pour commencer</div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- Dialog Création/Modification -->
        <v-dialog v-model="dialog" max-width="550px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh; display: flex; flex-direction: column;">
                <v-toolbar color="primary" class="rounded-t-xl">
                    <v-icon start class="ml-4">{{ isEditing ? 'mdi-pencil' : 'mdi-plus' }}</v-icon>
                    <v-toolbar-title class="font-weight-bold">
                        {{ isEditing ? "Modifier l'année" : 'Nouvelle année' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="dialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="overflow-y: auto; flex: 1;">
                    <v-form @submit.prevent="submit">
                        <v-text-field v-model="form.annee" label="Année" type="number" variant="outlined"
                            density="comfortable" :error-messages="form.errors.annee" :disabled="isEditing"
                            :readonly="isEditing" hint="Exemple: 2024, 2025, 2026" persistent-hint required>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-calendar</v-icon>
                            </template>
                        </v-text-field>

                        <v-text-field v-model="form.code" label="Code (auto-généré)" variant="outlined"
                            density="comfortable" :error-messages="form.errors.code" readonly disabled
                            hint="Le code est généré automatiquement à partir de l'année" persistent-hint>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-tag</v-icon>
                            </template>
                        </v-text-field>

                        <v-textarea v-model="form.description" label="Description" variant="outlined"
                            density="comfortable" rows="3" auto-grow hint="Description optionnelle de l'année"
                            persistent-hint></v-textarea>

                        <v-switch v-model="form.active" label="Année active" color="primary" inset hide-details>
                            <template v-slot:label>
                                <div>
                                    <span class="font-weight-bold">Année active</span>
                                    <span class="text-caption text-grey d-block">Permet d'activer ou désactiver cette
                                        année dans l'arborescence</span>
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
                        @click="submit">
                        {{ isEditing ? 'Mettre à jour' : 'Créer' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialogue de confirmation -->
        <v-dialog v-model="confirmDialog" max-width="450px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar
                    :color="confirmTitle.includes('Supprimer') ? 'error' : (confirmTitle.includes('Clôturer') ? 'warning' : 'primary')"
                    dark>
                    <v-icon start>{{ confirmTitle.includes('Supprimer') ? 'mdi-delete' :
                        (confirmTitle.includes('Clôturer') ?
                            'mdi-lock' : 'mdi-lock-open') }}</v-icon>
                    <v-toolbar-title>{{ confirmTitle }}</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="confirmDialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6">
                    <div class="text-body-1">{{ confirmMessage }}</div>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-4 bg-grey-lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="confirmDialog = false" rounded="lg">
                        Annuler
                    </v-btn>
                    <v-btn
                        :color="confirmTitle.includes('Supprimer') ? 'error' : (confirmTitle.includes('Clôturer') ? 'warning' : 'success')"
                        variant="flat" @click="executeConfirm" rounded="lg" class="px-6">
                        Confirmer
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
    transition: opacity 0.3s ease;
}

.v-dialog-enter-from,
.v-dialog-leave-to {
    opacity: 0;
}
</style>
