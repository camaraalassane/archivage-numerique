<!-- resources/js/Pages/Archiviste/PendingRejected.vue -->
<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    archives: { type: Object, required: true },
    dossiers: { type: Array, default: () => [] },
    type_documents: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) }
});

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

// Filtres
const search = ref(props.filters?.search || '');
const filterDossier = ref(props.filters?.dossier_id || null);
const filterType = ref(props.filters?.type || null);
const filterStatus = ref(props.filters?.validation_status || null);
const filterDateDebut = ref(props.filters?.date_debut || null);
const filterDateFin = ref(props.filters?.date_fin || null);

// Dialogue de confirmation
const confirmDialog = ref(false);
const confirmAction = ref(null);
const confirmMessage = ref('');
const confirmTitle = ref('Confirmation');

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

// Dialogue d'édition
const editDialog = ref(false);
const editingArchive = ref(null);
const editForm = useForm({
    titre: '',
    reference: '',
    dossier_id: null,
    date_document: '',
    description: '',
    mots_cles: '',
});

const openEditDialog = (archive) => {
    editingArchive.value = archive;
    editForm.titre = archive.titre;
    editForm.reference = archive.reference;
    editForm.dossier_id = archive.dossier_id;
    editForm.date_document = archive.date_document;
    editForm.description = archive.description || '';
    editForm.mots_cles = archive.mots_cles || '';
    editForm.clearErrors();
    editDialog.value = true;
};

const updateArchive = () => {
    editForm.put(route('archiviste.update', editingArchive.value.id), {
        onSuccess: () => {
            editDialog.value = false;
            editingArchive.value = null;
            editForm.reset();
            showNotify('Archive mise à jour avec succès', 'success');
        },
        onError: () => {
            showNotify('Erreur lors de la mise à jour', 'error');
        }
    });
};

const deleteArchive = (id) => {
    showConfirm('Supprimer définitivement cette archive ?', () => {
        router.delete(route('archiviste.destroy', id), {
            onSuccess: () => {
                showNotify('Archive supprimée avec succès', 'success');
            },
            onError: () => {
                showNotify('Erreur lors de la suppression', 'error');
            }
        });
    }, 'Supprimer l\'archive');
};

// Filtrage
const updateSearch = debounce(() => {
    router.get(route('archiviste.pending-rejected'), {
        search: search.value,
        dossier_id: filterDossier.value,
        type: filterType.value,
        validation_status: filterStatus.value,
        date_debut: filterDateDebut.value,
        date_fin: filterDateFin.value
    }, {
        preserveState: true,
        replace: true,
        preserveScroll: true
    });
}, 400);

const resetFilters = () => {
    search.value = '';
    filterDossier.value = null;
    filterType.value = null;
    filterStatus.value = null;
    filterDateDebut.value = null;
    filterDateFin.value = null;
};

// Formater les dates
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('fr-FR');
};

// Statut
const getStatusInfo = (status) => {
    const statusMap = {
        'pending': { label: 'En attente', color: 'warning', icon: 'mdi-clock-outline' },
        'validated': { label: 'Validé', color: 'success', icon: 'mdi-check-circle' },
        'rejected': { label: 'Rejeté', color: 'error', icon: 'mdi-close-circle' }
    };
    return statusMap[status] || { label: 'Inconnu', color: 'grey', icon: 'mdi-help-circle' };
};

const getFileIcon = (type) => {
    const icons = { pdf: 'mdi-file-pdf-box', jpg: 'mdi-file-image', png: 'mdi-file-image', docx: 'mdi-file-word', jpeg: 'mdi-file-image' };
    return icons[type?.toLowerCase()] || 'mdi-file-document';
};
</script>

<template>

    <Head title="Archives en attente / Rejetées" />
    <AuthenticatedLayout>
        <!-- SNACKBAR -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" rounded="lg">
            <v-icon start>{{ snackbar.color === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
            {{ snackbar.text }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar.show = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <!-- DIALOGUE DE CONFIRMATION -->
        <v-dialog v-model="confirmDialog" max-width="450px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar :color="confirmTitle.includes('Supprimer') ? 'error' : 'primary'" dark>
                    <v-icon start>{{ confirmTitle.includes('Supprimer') ? 'mdi-delete' : 'mdi-alert-circle' }}</v-icon>
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
                    <v-btn variant="text" @click="confirmDialog = false" rounded="lg">Annuler</v-btn>
                    <v-btn :color="confirmTitle.includes('Supprimer') ? 'error' : 'primary'" variant="flat"
                        @click="executeConfirm" rounded="lg" class="px-6">Confirmer</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- CARD PRINCIPALE -->
        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <v-icon icon="mdi-archive-clock" color="warning" size="28" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Archives en attente / Rejetées</div>
                    <div class="text-caption text-grey">Gestion des archives à traiter</div>
                </div>
                <v-spacer></v-spacer>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Rechercher..."
                    variant="outlined" hide-details density="comfortable" style="max-width: 300px;"></v-text-field>
            </v-toolbar>

            <!-- FILTRES -->
            <div class="bg-grey-lighten-4 px-4 py-2 border-bottom d-flex align-center flex-wrap gap-3">
                <v-select v-model="filterStatus" :items="[
                    { title: 'En attente', value: 'pending' },
                    { title: 'Rejeté', value: 'rejected' }
                ]" item-title="title" item-value="value" label="Statut" variant="solo" density="compact" hide-details
                    flat clearable style="max-width: 150px;"></v-select>

                <v-select v-model="filterDossier" :items="dossiers" item-title="nom" item-value="id" label="Dossier"
                    variant="solo" density="compact" hide-details flat clearable style="max-width: 200px;"></v-select>

                <v-select v-model="filterType" :items="type_documents" label="Format" variant="solo" density="compact"
                    hide-details flat clearable style="max-width: 120px;"></v-select>

                <v-text-field v-model="filterDateDebut" type="date" label="Depuis le" variant="solo" density="compact"
                    hide-details flat style="max-width: 160px;"></v-text-field>

                <v-text-field v-model="filterDateFin" type="date" label="Jusqu'au" variant="solo" density="compact"
                    hide-details flat style="max-width: 160px;"></v-text-field>

                <v-btn variant="text" color="error" size="small" @click="resetFilters" prepend-icon="mdi-filter-off">
                    Réinitialiser
                </v-btn>
            </div>

            <!-- TABLEAU -->
            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Référence</th>
                        <th class="text-overline">Titre</th>
                        <th class="text-overline">Dossier</th>
                        <th class="text-overline">Date</th>
                        <th class="text-overline text-center">Statut</th>
                        <th class="text-overline text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="archive in archives.data" :key="archive.id">
                        <td class="font-weight-bold text-primary">{{ archive.reference }}</td>
                        <td>{{ archive.titre }}</td>
                        <td>
                            <v-chip size="x-small" color="primary" variant="tonal">
                                {{ archive.dossier?.nom || 'Non classé' }}
                            </v-chip>
                        </td>
                        <td>{{ formatDate(archive.date_document) }}</td>
                        <td class="text-center">
                            <v-chip :color="getStatusInfo(archive.validation_status).color" size="x-small">
                                <v-icon start size="x-small">{{ getStatusInfo(archive.validation_status).icon
                                }}</v-icon>
                                {{ getStatusInfo(archive.validation_status).label }}
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <v-btn icon="mdi-pencil" size="small" variant="text" color="primary"
                                @click="openEditDialog(archive)" title="Modifier"></v-btn>
                            <v-btn icon="mdi-delete" size="small" variant="text" color="error"
                                @click="deleteArchive(archive.id)" title="Supprimer"></v-btn>
                        </td>
                    </tr>
                    <tr v-if="archives.data.length === 0">
                        <td colspan="6" class="text-center py-12 text-grey">
                            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-archive-clock</v-icon>
                            <div class="text-h6 text-grey-lighten-1">Aucune archive en attente ou rejetée</div>
                            <div class="text-caption text-grey mt-2">Toutes les archives sont validées</div>
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <!-- PAGINATION -->
            <v-divider></v-divider>
            <div class="pa-3 bg-grey-lighten-5 d-flex align-center justify-space-between">
                <div class="text-caption text-grey-darken-1">
                    Affichage de {{ archives.from || 0 }} à {{ archives.to || 0 }} sur {{ archives.total }} archives
                </div>
                <div class="d-flex gap-1">
                    <v-btn v-for="(link, k) in archives.links" :key="k" :disabled="link.url === null"
                        :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'grey-darken-1'"
                        size="small" class="px-2"
                        @click="link.url ? router.get(link.url, {}, { preserveState: true, preserveScroll: true }) : null"
                        v-html="link.label"></v-btn>
                </div>
            </div>
        </v-card>

        <!-- DIALOGUE D'ÉDITION -->
        <v-dialog v-model="editDialog" max-width="600px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh;">
                <v-toolbar color="primary">
                    <v-icon start class="ml-4">mdi-pencil</v-icon>
                    <v-toolbar-title class="font-weight-bold">Modifier l'archive</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="editDialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="max-height: 60vh; overflow-y: auto;">
                    <v-form @submit.prevent="updateArchive">
                        <v-text-field v-model="editForm.titre" label="Titre" variant="outlined" density="comfortable"
                            :error-messages="editForm.errors.titre" required></v-text-field>

                        <v-text-field v-model="editForm.reference" label="Référence" variant="outlined"
                            density="comfortable" :error-messages="editForm.errors.reference" required></v-text-field>

                        <v-select v-model="editForm.dossier_id" :items="dossiers" item-title="nom" item-value="id"
                            label="Dossier" variant="outlined" density="comfortable"
                            :error-messages="editForm.errors.dossier_id" required>
                            <template v-slot:item="{ item, props: itemProps }">
                                <v-list-item v-bind="itemProps">
                                    <div class="d-flex align-center">
                                        <v-icon :color="item.raw.couleur" size="20" class="mr-2">mdi-folder</v-icon>
                                        {{ item.title }}
                                    </div>
                                </v-list-item>
                            </template>
                        </v-select>

                        <v-text-field v-model="editForm.date_document" label="Date du document" type="date"
                            variant="outlined" density="comfortable" :error-messages="editForm.errors.date_document"
                            required></v-text-field>

                        <v-textarea v-model="editForm.description" label="Description" variant="outlined"
                            density="comfortable" rows="3"></v-textarea>

                        <v-text-field v-model="editForm.mots_cles" label="Mots-clés" variant="outlined"
                            density="comfortable" hint="Séparés par des virgules" persistent-hint></v-text-field>

                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="editDialog = false" :disabled="editForm.processing">
                                Annuler
                            </v-btn>
                            <v-btn color="primary" type="submit" :loading="editForm.processing" class="ml-2">
                                Mettre à jour
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.gap-3 {
    gap: 12px;
}

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

.gap-1 {
    gap: 4px;
}
</style>
