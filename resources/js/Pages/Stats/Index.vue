<!-- resources/js/Pages/Stats/Index.vue -->
<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    stats: { type: Object, required: true }
});

// États pour les dialogues
const previewDialog = ref(false);
const editDialog = ref(false);
const moveDialog = ref(false);
const currentFileUrl = ref('');
const currentFileTitle = ref('');
const editingArchive = ref(null);
const movingArchive = ref(null);
const availableDossiers = ref([]);
const isLoadingDossiers = ref(false);

// Formulaire d'édition
const editForm = useForm({
    titre: '',
    reference: '',
    dossier_id: null,
    date_document: '',
    description: '',
    mots_cles: '',
});

// Formulaire de déplacement
const moveForm = useForm({
    dossier_id: null,
});

// Vérifications des rôles
const isArchiviste = computed(() => props.stats?.is_archiviste === true);
const isGestionnaire = computed(() => props.stats?.is_gestionnaire === true);
const isAdmin = computed(() => props.stats?.is_admin === true);
const isDivision = computed(() => props.stats?.is_division === true);

const canModify = computed(() => {
    return isAdmin.value || isGestionnaire.value || isArchiviste.value;
});

const canValidate = computed(() => {
    return isAdmin.value || isGestionnaire.value;
});

const formatDate = (date) => {
    if (!date) return '-';
    try {
        return new Date(date).toLocaleDateString('fr-FR');
    } catch {
        return '-';
    }
};

const getFileIcon = (type) => {
    if (!type) return 'mdi-file-document';
    const typeStr = String(type).toLowerCase();
    const icons = {
        pdf: 'mdi-file-pdf-box',
        jpg: 'mdi-file-image',
        png: 'mdi-file-image',
        jpeg: 'mdi-file-image',
        docx: 'mdi-file-word',
        doc: 'mdi-file-word',
        xls: 'mdi-file-excel',
        xlsx: 'mdi-file-excel'
    };
    return icons[typeStr] || 'mdi-file-document';
};

const getFileColor = (type) => {
    if (!type) return 'grey';
    const typeStr = String(type).toLowerCase();
    const colors = {
        pdf: 'red',
        jpg: 'orange',
        png: 'orange',
        jpeg: 'orange',
        docx: 'blue',
        doc: 'blue',
        xls: 'green',
        xlsx: 'green'
    };
    return colors[typeStr] || 'grey';
};

const getStatusInfo = (status) => {
    const statusMap = {
        'pending': { label: 'En attente', color: 'warning', icon: 'mdi-clock-outline' },
        'validated': { label: 'Validé', color: 'success', icon: 'mdi-check-circle' },
        'rejected': { label: 'Rejeté', color: 'error', icon: 'mdi-close-circle' }
    };
    return statusMap[status] || { label: 'Inconnu', color: 'grey', icon: 'mdi-help-circle' };
};

// Prévisualisation
const previewFile = (archive) => {
    currentFileUrl.value = route('archives.view', archive.id);
    currentFileTitle.value = archive.titre;
    previewDialog.value = true;
};

// Édition
const openEditDialog = (archive) => {
    if (!canModify.value) {
        alert('Vous n\'avez pas les droits pour modifier ce document.');
        return;
    }
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
    editForm.put(route('archives.update', editingArchive.value.id), {
        onSuccess: () => {
            editDialog.value = false;
            editingArchive.value = null;
            editForm.reset();
            router.reload({ only: ['stats'] });
        },
        onError: (errors) => {
            console.error('Erreur lors de la mise à jour:', errors);
            alert('Erreur lors de la mise à jour du document.');
        }
    });
};

// Déplacement
const openMoveDialog = async (archive) => {
    if (!canModify.value) {
        alert('Vous n\'avez pas les droits pour déplacer ce document.');
        return;
    }
    movingArchive.value = archive;
    moveForm.dossier_id = archive.dossier_id;
    moveForm.clearErrors();
    isLoadingDossiers.value = true;
    moveDialog.value = true;

    try {
        const response = await axios.get(route('dossiers.list'));
        availableDossiers.value = response.data;
        availableDossiers.value = availableDossiers.value.filter(d => d.id !== archive.dossier_id);
    } catch (error) {
        console.error('Erreur lors du chargement des dossiers:', error);
        alert('Impossible de charger la liste des dossiers.');
    } finally {
        isLoadingDossiers.value = false;
    }
};

const moveArchive = () => {
    if (!moveForm.dossier_id) {
        alert('Veuillez sélectionner un dossier de destination.');
        return;
    }

    router.put(route('archives.update', movingArchive.value.id), {
        titre: movingArchive.value.titre,
        reference: movingArchive.value.reference,
        dossier_id: moveForm.dossier_id,
        date_document: movingArchive.value.date_document,
        description: movingArchive.value.description || '',
        mots_cles: movingArchive.value.mots_cles || '',
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            moveDialog.value = false;
            movingArchive.value = null;
            moveForm.reset();
            availableDossiers.value = [];
            router.reload({ only: ['stats'] });
        },
        onError: (errors) => {
            console.error('Erreur lors du déplacement:', errors);
            alert('Erreur lors du déplacement du document.');
        }
    });
};

// Validation
const validateArchive = (archive, status) => {
    if (!canValidate.value) {
        alert('Vous n\'avez pas les droits pour valider des documents.');
        return;
    }

    const statusLabel = status === 'validated' ? 'valider' : 'rejeter';
    if (!confirm(`Voulez-vous ${statusLabel} ce document ?`)) return;

    router.post(route('archives.validate', archive.id), {
        status: status,
        comment: `Document ${statusLabel} via les statistiques`
    }, {
        onSuccess: () => {
            router.reload({ only: ['stats'] });
        },
        onError: (errors) => {
            console.error('Erreur lors de la validation:', errors);
            alert('Erreur lors de la validation du document.');
        }
    });
};

// Suppression
const deleteArchive = (id) => {
    if (!canModify.value) {
        alert('Vous n\'avez pas les droits pour supprimer ce document.');
        return;
    }
    if (confirm('Supprimer définitivement ce document ?')) {
        router.delete(route('archives.destroy', id), {
            onSuccess: () => {
                router.reload({ only: ['stats'] });
            },
            onError: (errors) => {
                console.error('Erreur lors de la suppression:', errors);
                alert('Erreur lors de la suppression du document.');
            }
        });
    }
};

// Téléchargement
const downloadFile = (archive) => {
    window.open(route('archives.download', archive.id), '_blank');
};
</script>

<template>

    <Head title="Statistiques" />
    <AuthenticatedLayout>
        <v-container>
            <!-- Cartes de statistiques -->
            <v-row>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="primary" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.total_archives || 0 }}</div>
                                    <div class="text-caption">Documents archivés</div>
                                </div>
                                <v-icon size="40">mdi-file-document</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="success" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.total_dossiers || 0 }}</div>
                                    <div class="text-caption">Dossiers</div>
                                </div>
                                <v-icon size="40">mdi-folder-multiple</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="orange-darken-2" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.total_annees || 0 }}</div>
                                    <div class="text-caption">Années</div>
                                </div>
                                <v-icon size="40">mdi-calendar</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="purple-darken-2" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.total_mois || 0 }}</div>
                                    <div class="text-caption">Mois</div>
                                </div>
                                <v-icon size="40">mdi-calendar-month</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Statistiques de validation (Admin et Gestionnaire) -->
            <v-row v-if="isGestionnaire || isAdmin">
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="warning" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.en_attente || 0 }}
                                    </div>
                                    <div class="text-caption">En attente de validation</div>
                                </div>
                                <v-icon size="40">mdi-clock-alert</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="success" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.validees || 0 }}
                                    </div>
                                    <div class="text-caption">Validés</div>
                                </div>
                                <v-icon size="40">mdi-check-circle</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="error" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.rejetees || 0 }}
                                    </div>
                                    <div class="text-caption">Rejetés</div>
                                </div>
                                <v-icon size="40">mdi-close-circle</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.a_valider || 0 }}
                                    </div>
                                    <div class="text-caption">À valider (hors vos archives)</div>
                                </div>
                                <v-icon size="40">mdi-account-check</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Statistiques personnelles (Archiviste) -->
            <v-row v-if="isArchiviste">
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.total_archives || 0 }}
                                    </div>
                                    <div class="text-caption">Mes archives</div>
                                </div>
                                <v-icon size="40">mdi-file-document</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.archives_ce_mois || 0 }}
                                    </div>
                                    <div class="text-caption">Ce mois-ci</div>
                                </div>
                                <v-icon size="40">mdi-calendar-month</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.archives_cette_semaine || 0
                                    }}</div>
                                    <div class="text-caption">Cette semaine</div>
                                </div>
                                <v-icon size="40">mdi-calendar-week</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="warning" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.en_attente || 0 }}</div>
                                    <div class="text-caption">En attente de validation</div>
                                </div>
                                <v-icon size="40">mdi-clock-outline</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Archives par année -->
            <v-row>
                <v-col cols="12" md="6">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-chart-bar</v-icon>
                            Archives par année
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.archives_par_annee && stats.archives_par_annee.length > 0">
                                <thead>
                                    <tr>
                                        <th>Année</th>
                                        <th class="text-right">Nombre</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in stats.archives_par_annee" :key="item.annee">
                                        <td>{{ item.annee }}</td>
                                        <td class="text-right">
                                            <v-chip color="primary" size="small">{{ item.total }}</v-chip>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-chart-bar</v-icon>
                                <div>Aucune donnée disponible</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Types de documents -->
                <v-col cols="12" md="6">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-file-types</v-icon>
                            Types de documents
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.archives_par_type && Object.keys(stats.archives_par_type).length > 0">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-right">Nombre</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(total, type) in stats.archives_par_type" :key="type">
                                        <td>
                                            <v-icon :color="getFileColor(type)" size="small" class="mr-2">
                                                {{ getFileIcon(type) }}
                                            </v-icon>
                                            {{ String(type).toUpperCase() }}
                                        </td>
                                        <td class="text-right">
                                            <v-chip color="success" size="small">{{ total }}</v-chip>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-file-types</v-icon>
                                <div>Aucune donnée disponible</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Dernières archives -->
            <v-row>
                <v-col cols="12">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-clock-outline</v-icon>
                            {{ isArchiviste ? 'Mes dernières archives' : isDivision ? 'Dernières archives validées' :
                                'Dernières archives ajoutées' }}
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.recent_archives && stats.recent_archives.length > 0">
                                <thead>
                                    <tr class="bg-grey-lighten-4">
                                        <th>Référence</th>
                                        <th>Titre</th>
                                        <th>Emplacement</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Statut</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="archive in stats.recent_archives" :key="archive.id">
                                        <td class="font-weight-bold">{{ archive.reference || '-' }}</td>
                                        <td>{{ archive.titre || '-' }}</td>
                                        <td>
                                            <v-chip size="x-small" color="primary" variant="tonal">
                                                {{ archive.chemin || 'Non classé' }}
                                            </v-chip>
                                        </td>
                                        <td>{{ formatDate(archive.date_document) }}</td>
                                        <td>
                                            <v-icon :color="getFileColor(archive.type_document)" size="small">
                                                {{ getFileIcon(archive.type_document) }}
                                            </v-icon>
                                        </td>
                                        <td>
                                            <v-chip :color="getStatusInfo(archive.validation_status).color"
                                                size="x-small">
                                                <v-icon start size="x-small">{{
                                                    getStatusInfo(archive.validation_status).icon }}</v-icon>
                                                {{ getStatusInfo(archive.validation_status).label }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center">
                                            <v-btn icon="mdi-eye" size="small" variant="text" color="info"
                                                @click="previewFile(archive)" title="Visualiser"></v-btn>

                                            <v-btn icon="mdi-download" size="small" variant="text" color="primary"
                                                @click="downloadFile(archive)" title="Télécharger"></v-btn>

                                            <!-- Validation (Admin et Gestionnaire) -->
                                            <template
                                                v-if="archive.validation_status === 'pending' && (isGestionnaire || isAdmin)">
                                                <v-btn icon="mdi-check" size="small" variant="text" color="success"
                                                    @click="validateArchive(archive, 'validated')"
                                                    title="Valider"></v-btn>
                                                <v-btn icon="mdi-close" size="small" variant="text" color="error"
                                                    @click="validateArchive(archive, 'rejected')"
                                                    title="Rejeter"></v-btn>
                                            </template>

                                            <v-btn v-if="archive.can_modifier" icon="mdi-pencil" size="small"
                                                variant="text" color="warning" @click="openEditDialog(archive)"
                                                title="Modifier"></v-btn>

                                            <v-btn v-if="archive.can_modifier" icon="mdi-folder-move" size="small"
                                                variant="text" color="primary" @click="openMoveDialog(archive)"
                                                title="Déplacer"></v-btn>

                                            <v-btn v-if="archive.can_modifier" icon="mdi-delete" size="small"
                                                variant="text" color="error" @click="deleteArchive(archive.id)"
                                                title="Supprimer"></v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-clock-outline</v-icon>
                                <div>Aucune archive récente</div>
                                <div class="text-caption mt-2">Commencez à archiver des documents !</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>

        <!-- Dialog Prévisualisation -->
        <v-dialog v-model="previewDialog" width="95%" max-width="1200px">
            <v-card rounded="xl">
                <v-toolbar color="primary" density="comfortable">
                    <v-icon start class="ml-4">mdi-file-eye</v-icon>
                    <v-toolbar-title class="text-body-1">{{ currentFileTitle }}</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="previewDialog = false"></v-btn>
                </v-toolbar>
                <iframe :src="currentFileUrl" width="100%" style="height: 85vh; border: none;"></iframe>
            </v-card>
        </v-dialog>

        <!-- Dialog Modification -->
        <v-dialog v-model="editDialog" max-width="600px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh;">
                <v-toolbar color="warning">
                    <v-icon start class="ml-4">mdi-pencil</v-icon>
                    <v-toolbar-title class="font-weight-bold">Modifier le document</v-toolbar-title>
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

                        <v-text-field v-model="editForm.date_document" label="Date du document" type="date"
                            variant="outlined" density="comfortable" :error-messages="editForm.errors.date_document"
                            required></v-text-field>

                        <v-textarea v-model="editForm.description" label="Description" variant="outlined"
                            density="comfortable" rows="3"></v-textarea>

                        <v-text-field v-model="editForm.mots_cles" label="Mots-clés" variant="outlined"
                            density="comfortable" hint="Séparés par des virgules" persistent-hint></v-text-field>

                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="editDialog = false">Annuler</v-btn>
                            <v-btn color="warning" type="submit" :loading="editForm.processing" class="ml-2">
                                Mettre à jour
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Dialog Déplacement -->
        <v-dialog v-model="moveDialog" max-width="550px" persistent>
            <v-card class="rounded-xl" style="max-height: 80vh;">
                <v-toolbar color="primary" class="rounded-t-xl">
                    <v-icon start class="ml-4">mdi-folder-move</v-icon>
                    <v-toolbar-title class="font-weight-bold">Déplacer le document</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="moveDialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6">
                    <div class="mb-4 pa-3 bg-grey-lighten-4 rounded-lg">
                        <div class="text-caption text-grey">Document à déplacer</div>
                        <div class="font-weight-bold text-body-1">{{ movingArchive?.titre || 'Document' }}</div>
                        <div class="text-caption text-grey mt-1">
                            <v-icon size="small" class="mr-1">mdi-folder</v-icon>
                            Dossier actuel: {{ movingArchive?.dossier_nom || 'Non classé' }}
                        </div>
                    </div>

                    <v-progress-linear v-if="isLoadingDossiers" indeterminate color="primary"
                        class="mb-4"></v-progress-linear>

                    <v-form @submit.prevent="moveArchive">
                        <v-select v-model="moveForm.dossier_id" :items="availableDossiers" item-title="chemin"
                            item-value="id" label="Nouveau dossier" variant="outlined" density="comfortable"
                            :error-messages="moveForm.errors.dossier_id"
                            :disabled="isLoadingDossiers || moveForm.processing" required>
                            <template v-slot:item="{ item, props: itemProps }">
                                <v-list-item v-bind="itemProps">
                                    <div class="d-flex align-center">
                                        <v-icon :color="item.raw.couleur || 'primary'" size="small"
                                            class="mr-2">mdi-folder</v-icon>
                                        <span>{{ item.raw.chemin || item.raw.nom }}</span>
                                    </div>
                                </v-list-item>
                            </template>
                            <template v-slot:selection="{ item }">
                                <div class="d-flex align-center">
                                    <v-icon :color="item?.raw?.couleur || 'primary'" size="small"
                                        class="mr-2">mdi-folder</v-icon>
                                    <span>{{ item?.raw?.chemin || item?.raw?.nom || 'Sélectionner un dossier' }}</span>
                                </div>
                            </template>
                            <template v-slot:no-data>
                                <div class="pa-4 text-center">
                                    <v-icon size="32" color="grey-lighten-1" class="mb-2">mdi-folder-off</v-icon>
                                    <div class="text-caption text-grey">Aucun dossier disponible</div>
                                </div>
                            </template>
                        </v-select>

                        <div class="d-flex justify-end mt-4">
                            <v-btn variant="text" @click="moveDialog = false" :disabled="moveForm.processing"
                                rounded="lg">
                                Annuler
                            </v-btn>
                            <v-btn color="primary" type="submit" :loading="moveForm.processing" class="px-6 ml-2"
                                rounded="lg" :disabled="!moveForm.dossier_id || isLoadingDossiers">
                                Déplacer
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.v-card {
    transition: all 0.3s ease;
}

.v-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.v-table :deep(th) {
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.v-btn {
    margin: 0 2px;
}
</style>
