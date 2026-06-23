<!-- resources/js/Pages/Gestionnaire/PendingArchives.vue -->
<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    archives: { type: Object, required: true },
    dossiers: { type: Array, default: () => [] },
    type_documents: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    user: { type: Object, required: true }
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
const filterUser = ref(props.filters?.created_by || null);
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

// Dialogue de rejet
const rejectDialog = ref(false);
const rejectArchive = ref(null);
const rejectForm = useForm({
    comment: '',
});

const openRejectDialog = (archive) => {
    rejectArchive.value = archive;
    rejectForm.comment = '';
    rejectForm.clearErrors();
    rejectDialog.value = true;
};

const confirmReject = () => {
    rejectForm.post(route('gestionnaire.reject', rejectArchive.value.id), {
        onSuccess: () => {
            rejectDialog.value = false;
            rejectArchive.value = null;
            rejectForm.reset();
            showNotify('Archive rejetée avec succès', 'success');
            router.reload({ only: ['archives'] });
        },
        onError: () => {
            showNotify('Erreur lors du rejet', 'error');
        }
    });
};

// Validation
const validateArchive = (archive) => {
    showConfirm('Voulez-vous valider cette archive ?', () => {
        router.post(route('gestionnaire.validate', archive.id), {
            comment: 'Validé par le gestionnaire'
        }, {
            onSuccess: () => {
                showNotify('Archive validée avec succès', 'success');
                router.reload({ only: ['archives'] });
            },
            onError: () => {
                showNotify('Erreur lors de la validation', 'error');
            }
        });
    }, 'Valider l\'archive');
};

// Filtrage
const updateSearch = debounce(() => {
    router.get(route('gestionnaire.pending-archives'), {
        search: search.value,
        dossier_id: filterDossier.value,
        type: filterType.value,
        created_by: filterUser.value,
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
    filterUser.value = null;
    filterDateDebut.value = null;
    filterDateFin.value = null;
};

// Formater les dates
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('fr-FR');
};

const getFileIcon = (type) => {
    const icons = { pdf: 'mdi-file-pdf-box', jpg: 'mdi-file-image', png: 'mdi-file-image', docx: 'mdi-file-word', jpeg: 'mdi-file-image' };
    return icons[type?.toLowerCase()] || 'mdi-file-document';
};

const getFileColor = (type) => {
    const colors = { pdf: 'red', jpg: 'orange', png: 'orange', docx: 'blue', jpeg: 'orange' };
    return colors[type?.toLowerCase()] || 'grey';
};

const getDossierPath = (archive) => {
    if (!archive.dossier) return 'Non classé';
    return `${archive.dossier.mois?.annee?.annee || ''} / ${archive.dossier.mois?.nom_mois || ''} / ${archive.dossier.nom}`;
};
</script>

<template>

    <Head title="Archives en attente de validation" />
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
                <v-toolbar color="primary" dark>
                    <v-icon start>mdi-check-circle</v-icon>
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
                    <v-btn color="primary" variant="flat" @click="executeConfirm" rounded="lg"
                        class="px-6">Valider</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- DIALOGUE DE REJET -->
        <v-dialog v-model="rejectDialog" max-width="500px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar color="error" dark>
                    <v-icon start>mdi-close-circle</v-icon>
                    <v-toolbar-title>Rejeter l'archive</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="rejectDialog = false"></v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text class="pa-6">
                    <div class="text-body-1 mb-4">
                        Vous êtes sur le point de rejeter l'archive : <strong>{{ rejectArchive?.titre }}</strong>
                    </div>
                    <v-form @submit.prevent="confirmReject">
                        <v-textarea v-model="rejectForm.comment" label="Motif du rejet" variant="outlined"
                            density="comfortable" rows="3" :error-messages="rejectForm.errors.comment" required
                            hint="Veuillez indiquer la raison du rejet" persistent-hint></v-textarea>
                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="rejectDialog = false" :disabled="rejectForm.processing">
                                Annuler
                            </v-btn>
                            <v-btn color="error" type="submit" :loading="rejectForm.processing" class="ml-2">
                                Rejeter
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- CARD PRINCIPALE -->
        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <v-icon icon="mdi-account-check" color="primary" size="28" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Archives en attente de validation</div>
                    <div class="text-caption text-grey">Valider ou rejeter les archives soumises par les archivistes
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Rechercher..."
                    variant="outlined" hide-details density="comfortable" style="max-width: 300px;"></v-text-field>
            </v-toolbar>

            <!-- FILTRES -->
            <div class="bg-grey-lighten-4 px-4 py-2 border-bottom d-flex align-center flex-wrap gap-3">
                <v-select v-model="filterUser" :items="users" item-title="name" item-value="id" label="Archiviste"
                    variant="solo" density="compact" hide-details flat clearable style="max-width: 180px;">
                    <template v-slot:prepend-inner>
                        <v-icon color="primary" size="small">mdi-account</v-icon>
                    </template>
                </v-select>

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

            <!-- STATISTIQUES -->
            <div class="px-4 py-2 bg-grey-lighten-3 border-bottom">
                <div class="d-flex align-center gap-4">
                    <span class="text-caption font-weight-bold">Total en attente :</span>
                    <v-chip color="warning" size="small">
                        <v-icon start size="x-small">mdi-clock-outline</v-icon>
                        {{ archives.total || 0 }} archive(s)
                    </v-chip>
                    <span class="text-caption text-grey ml-2">Dernière mise à jour : {{ new Date().toLocaleTimeString()
                    }}</span>
                </div>
            </div>

            <!-- TABLEAU -->
            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Référence</th>
                        <th class="text-overline">Titre</th>
                        <th class="text-overline">Archiviste</th>
                        <th class="text-overline">Emplacement</th>
                        <th class="text-overline">Date</th>
                        <th class="text-overline text-center">Format</th>
                        <th class="text-overline text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="archive in archives.data" :key="archive.id">
                        <td class="font-weight-bold text-primary">{{ archive.reference }}</td>
                        <td>{{ archive.titre }}</td>
                        <td>
                            <v-chip size="x-small" color="blue" variant="tonal">
                                <v-icon start size="x-small">mdi-account</v-icon>
                                {{ archive.createur?.name || 'Inconnu' }}
                            </v-chip>
                        </td>
                        <td>
                            <v-chip size="x-small" color="primary" variant="tonal">
                                {{ getDossierPath(archive) }}
                            </v-chip>
                        </td>
                        <td>{{ formatDate(archive.date_document) }}</td>
                        <td class="text-center">
                            <v-icon :color="getFileColor(archive.type_document)" size="small">
                                {{ getFileIcon(archive.type_document) }}
                            </v-icon>
                        </td>
                        <td class="text-center">
                            <div class="d-flex align-center justify-center gap-1">
                                <v-btn icon="mdi-eye" size="small" variant="text" color="info"
                                    :href="route('gestionnaire.view', archive.id)" target="_blank"
                                    title="Visualiser"></v-btn>
                                <v-btn icon="mdi-download" size="small" variant="text" color="primary"
                                    :href="route('gestionnaire.download', archive.id)" title="Télécharger"></v-btn>
                                <v-btn icon="mdi-check" size="small" variant="flat" color="success"
                                    @click="validateArchive(archive)" title="Valider"></v-btn>
                                <v-btn icon="mdi-close" size="small" variant="flat" color="error"
                                    @click="openRejectDialog(archive)" title="Rejeter"></v-btn>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="archives.data.length === 0">
                        <td colspan="7" class="text-center py-12 text-grey">
                            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-check-circle</v-icon>
                            <div class="text-h6 text-grey-lighten-1">Aucune archive en attente</div>
                            <div class="text-caption text-grey mt-2">Toutes les archives ont été traitées</div>
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
    </AuthenticatedLayout>
</template>

<style scoped>
.gap-3 {
    gap: 12px;
}

.gap-1 {
    gap: 4px;
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
</style>
