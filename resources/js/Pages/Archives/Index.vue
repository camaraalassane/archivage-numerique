<!-- resources/js/Pages/Archives/Index.vue -->
<script setup>
import { ref, computed, watch, reactive } from 'vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import debounce from 'lodash/debounce';
import axios from 'axios';

const props = defineProps({
    archives: { type: Object, required: true },
    dossiers: { type: Array, default: () => [] },
    annees: { type: Array, default: () => [] },
    mois: { type: Array, default: () => [] },
    type_documents: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) }
});

// === PERMISSIONS ===
const userRole = computed(() => window.$page?.props?.auth?.user?.role ?? 1);
const isArchiviste = computed(() => userRole.value === 1);
const isGestionnaire = computed(() => userRole.value === 2);
const isAdmin = computed(() => userRole.value === 3);
const isDivision = computed(() => userRole.value === 4);

const canCreate = computed(() => isArchiviste.value || isGestionnaire.value || isAdmin.value);
const canEdit = computed(() => isArchiviste.value || isGestionnaire.value || isAdmin.value);
const canDelete = computed(() => isGestionnaire.value || isAdmin.value);
const canExport = computed(() => isGestionnaire.value || isAdmin.value);
const canValidate = computed(() => isGestionnaire.value || isAdmin.value);

// SNACKBAR
const snackbar = reactive({
    show: false,
    text: '',
    color: 'primary',
    icon: 'mdi-check-circle'
});

const showNotify = (text, type = 'success') => {
    snackbar.text = text;
    snackbar.color = type === 'success' ? 'green-darken-1' : 'red-darken-1';
    snackbar.icon = type === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle';
    snackbar.show = true;
};

// DIALOGUE DE CONFIRMATION
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

const dialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

// Modes
const multipleMode = ref(false);
const folderMode = ref(false);

// 🔥 ÉTATS POUR LA DÉTECTION DES DOUBLONS
const checkingDuplicate = ref(false);
const duplicateWarning = ref(false);
const duplicateFiles = ref([]);

// Filtres
const search = ref(props.filters?.search || '');
const filterDossier = ref(props.filters?.dossier_id || null);
const filterType = ref(props.filters?.type || null);
const filterDateDebut = ref(props.filters?.date_debut || null);
const filterDateFin = ref(props.filters?.date_fin || null);

// État du formulaire
const selectedAnneeId = ref(null);
const selectedMoisId = ref(null);
const availableMois = ref([]);
const availableDossiers = ref([]);
const folderFiles = ref([]);
const folderFilesInfo = ref([]);

const form = useForm({
    titre: '',
    reference: '',
    dossier_id: null,
    date_document: new Date().toISOString().substr(0, 10),
    description: '',
    mots_cles: '',
    fichier: null,
    fichiers: [],
});

// Génération de référence
const generateReference = () => {
    if (!selectedAnneeId.value || !selectedMoisId.value || !form.dossier_id) return '';

    const annee = props.annees.find(a => a.id === selectedAnneeId.value);
    const moisItem = props.mois.find(m => m.id === selectedMoisId.value);
    const dossier = props.dossiers.find(d => d.id === form.dossier_id);

    if (!annee || !moisItem || !dossier) return '';

    const dossierClean = dossier.nom.toUpperCase().replace(/[^A-Z0-9]/g, '_');
    const rawBase = `${annee.annee}_${String(moisItem.mois).padStart(2, '0')}_${dossierClean}`;

    return rawBase.substr(0, 25);
};

const updateReference = () => {
    if (!isEditing.value && !multipleMode.value && selectedAnneeId.value && selectedMoisId.value && form.dossier_id) {
        form.reference = generateReference();
    }
};

// Watchers
watch(selectedAnneeId, (newAnneeId) => {
    if (newAnneeId) {
        availableMois.value = props.mois.filter(m => m.annee_id === newAnneeId);
        selectedMoisId.value = null;
        form.dossier_id = null;
        availableDossiers.value = [];
    } else {
        availableMois.value = [];
        selectedMoisId.value = null;
        form.dossier_id = null;
        availableDossiers.value = [];
    }
});

watch(selectedMoisId, (newMoisId) => {
    if (newMoisId) {
        availableDossiers.value = props.dossiers.filter(d => d.mois_id === newMoisId);
        form.dossier_id = null;
        form.reference = '';
    } else {
        availableDossiers.value = [];
        form.dossier_id = null;
        form.reference = '';
    }
});

watch([selectedAnneeId, selectedMoisId, () => form.dossier_id], () => {
    updateReference();
});

// Recherche avec debounce
const updateSearch = debounce(() => {
    router.get(route('archives.index'), {
        search: search.value,
        dossier_id: filterDossier.value,
        type: filterType.value,
        date_debut: filterDateDebut.value,
        date_fin: filterDateFin.value
    }, {
        preserveState: true,
        replace: true,
        preserveScroll: true
    });
}, 400);

watch([search, filterDossier, filterType, filterDateDebut, filterDateFin], () => {
    updateSearch();
});

const resetFilters = () => {
    search.value = '';
    filterDossier.value = null;
    filterType.value = null;
    filterDateDebut.value = null;
    filterDateFin.value = null;
};

const exportExcel = () => {
    if (!canExport.value) {
        showNotify('Vous n\'avez pas les droits pour exporter.', 'error');
        return;
    }
    const params = new URLSearchParams({
        search: search.value || '',
        dossier_id: filterDossier.value || '',
        date_debut: filterDateDebut.value || '',
        date_fin: filterDateFin.value || ''
    }).toString();
    window.open(route('archives.export') + '?' + params, '_blank');
};

// 🔥 VÉRIFICATION DES DOUBLONS
const checkDuplicatesBeforeSubmit = async () => {
    if (!multipleMode.value || !form.fichiers || form.fichiers.length === 0) {
        return true;
    }

    checkingDuplicate.value = true;
    duplicateFiles.value = [];
    duplicateWarning.value = false;

    try {
        const response = await axios.post(route('archives.check-duplicates'), {
            dossier_id: form.dossier_id,
            fichiers: form.fichiers.map(f => f.name)
        });

        checkingDuplicate.value = false;

        if (response.data.duplicates && response.data.duplicates.length > 0) {
            duplicateFiles.value = response.data.duplicates;
            duplicateWarning.value = true;

            const message = duplicateFiles.value.map(d =>
                `📄 ${d.file} (Réf: ${d.reference}) - ${d.status === 'pending' ? 'En attente' : d.status === 'validated' ? 'Validé' : 'Rejeté'}`
            ).join('\n');

            showNotify(`${duplicateFiles.value.length} fichier(s) en double détecté(s) !`, 'warning');
            return false;
        }

        return true;
    } catch (error) {
        checkingDuplicate.value = false;
        console.error('Erreur lors de la vérification des doublons:', error);
        return true;
    }
};

// 🔥 FORCER L'ARCHIVAGE MALGRÉ LES DOUBLONS
const forceSubmit = () => {
    duplicateWarning.value = false;
    submit();
};

const openCreateDialog = () => {
    if (!canCreate.value) {
        showNotify('Vous n\'avez pas les droits pour créer des archives.', 'error');
        return;
    }
    isEditing.value = false;
    editingId.value = null;
    multipleMode.value = false;
    folderMode.value = false;
    folderFiles.value = [];
    folderFilesInfo.value = [];
    duplicateWarning.value = false;
    duplicateFiles.value = [];
    form.reset();
    form.clearErrors();
    selectedAnneeId.value = null;
    selectedMoisId.value = null;
    availableMois.value = [];
    availableDossiers.value = [];
    form.dossier_id = null;
    form.reference = '';
    form.fichier = null;
    form.fichiers = [];
    form.date_document = new Date().toISOString().substr(0, 10);
    dialog.value = true;
};

const openEditDialog = (archive) => {
    if (!canEdit.value) {
        showNotify('Vous n\'avez pas les droits pour modifier ce document.', 'error');
        return;
    }
    isEditing.value = true;
    editingId.value = archive.id;
    multipleMode.value = false;
    folderMode.value = false;
    folderFiles.value = [];
    folderFilesInfo.value = [];
    duplicateWarning.value = false;
    duplicateFiles.value = [];
    form.titre = archive.titre;
    form.reference = archive.reference;
    form.dossier_id = archive.dossier_id;
    form.date_document = archive.date_document;
    form.description = archive.description || '';
    form.mots_cles = archive.mots_cles || '';
    form.clearErrors();

    const dossier = props.dossiers.find(d => d.id === archive.dossier_id);
    if (dossier) {
        selectedMoisId.value = dossier.mois_id;
        const moisItem = props.mois.find(m => m.id === dossier.mois_id);
        if (moisItem) {
            selectedAnneeId.value = moisItem.annee_id;
            availableMois.value = props.mois.filter(m => m.annee_id === moisItem.annee_id);
            availableDossiers.value = props.dossiers.filter(d => d.mois_id === dossier.mois_id);
        }
    }
    dialog.value = true;
};

const onMultipleModeChange = (val) => {
    if (val) {
        folderMode.value = false;
        folderFiles.value = [];
        folderFilesInfo.value = [];
        duplicateWarning.value = false;
        duplicateFiles.value = [];
        form.fichier = null;
        form.fichiers = [];
        form.titre = '';
        form.reference = '';
    } else {
        form.fichiers = [];
        updateReference();
    }
};

const onFolderSelect = (event) => {
    const files = event.target.files;
    if (files.length > 0) {
        folderFiles.value = Array.from(files);
        form.fichiers = folderFiles.value;
        duplicateWarning.value = false;
        duplicateFiles.value = [];

        folderFilesInfo.value = folderFiles.value.map(file => ({
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: new Date(file.lastModified),
            extension: file.name.split('.').pop().toLowerCase()
        }));

        const totalSize = folderFiles.value.reduce((acc, f) => acc + f.size, 0);
        showNotify(`📁 ${folderFiles.value.length} fichier(s) sélectionné(s) - Taille: ${(totalSize / 1024 / 1024).toFixed(2)} Mo`, 'info');
    }
};

const toggleFolderMode = () => {
    folderMode.value = !folderMode.value;
    if (folderMode.value) {
        multipleMode.value = true;
        form.fichier = null;
        form.fichiers = [];
        folderFiles.value = [];
        folderFilesInfo.value = [];
        duplicateWarning.value = false;
        duplicateFiles.value = [];
        setTimeout(() => {
            const input = document.getElementById('folderInput');
            if (input) input.click();
        }, 100);
    } else {
        folderFiles.value = [];
        folderFilesInfo.value = [];
        form.fichiers = [];
        duplicateWarning.value = false;
        duplicateFiles.value = [];
    }
};

// === SOUMISSION AVEC VÉRIFICATION DES DOUBLONS ===
const submit = async () => {
    if (form.processing) return;

    // ÉDITION
    if (isEditing.value) {
        form.put(route('archives.update', editingId.value), {
            onSuccess: () => {
                dialog.value = false;
                resetForm();
                showNotify('Document mis à jour avec succès', 'success');
            },
            onError: (errors) => {
                console.error('Erreur:', errors);
                showNotify('Erreur lors de la mise à jour', 'error');
            },
            preserveScroll: true
        });
        return;
    }

    // MODE MULTIPLE
    if (multipleMode.value || folderMode.value) {
        if (!form.fichiers || form.fichiers.length === 0) {
            showNotify('Veuillez sélectionner des fichiers', 'error');
            return;
        }

        if (!selectedAnneeId.value || !selectedMoisId.value || !form.dossier_id) {
            showNotify('Veuillez sélectionner une année, un mois et un dossier', 'error');
            return;
        }

        const canProceed = await checkDuplicatesBeforeSubmit();
        if (!canProceed) {
            return;
        }

        const formData = new FormData();
        formData.append('dossier_id', form.dossier_id);
        formData.append('date_document', form.date_document);
        formData.append('description', form.description || '');
        formData.append('mots_cles', form.mots_cles || '');

        const baseRef = generateReference();

        form.fichiers.forEach((file, index) => {
            formData.append(`fichiers[${index}]`, file);
            formData.append(`references[${index}]`, baseRef);
        });

        router.post(route('archives.store.multiple'), formData, {
            forceFormData: true,
            onSuccess: () => {
                dialog.value = false;
                form.reset();
                multipleMode.value = false;
                folderMode.value = false;
                folderFiles.value = [];
                folderFilesInfo.value = [];
                duplicateWarning.value = false;
                duplicateFiles.value = [];
                selectedAnneeId.value = null;
                selectedMoisId.value = null;
                showNotify('Fichiers archivés avec succès', 'success');
            },
            onError: (errors) => {
                showNotify('Erreur lors de l\'archivage multiple', 'error');
            }
        });
        return;
    }

    // MODE UNIQUE
    if (!selectedAnneeId.value || !selectedMoisId.value || !form.dossier_id) {
        showNotify('Veuillez sélectionner une année, un mois et un dossier', 'error');
        return;
    }

    if (!form.titre || !form.fichier) {
        showNotify('Veuillez remplir les champs obligatoires (Titre et Fichier)', 'error');
        return;
    }

    form.reference = generateReference();

    form.post(route('archives.store'), {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
            selectedAnneeId.value = null;
            selectedMoisId.value = null;
            duplicateWarning.value = false;
            duplicateFiles.value = [];
            showNotify('Document archivé avec succès', 'success');
        },
        onError: (errors) => {
            console.error(errors);
            if (errors.doublon) {
                showNotify(errors.doublon, 'error');
            } else {
                showNotify('Erreur lors de l\'archivage', 'error');
            }
        },
        preserveScroll: true
    });
};

const resetForm = () => {
    form.reset();
    form.clearErrors();
    multipleMode.value = false;
    folderMode.value = false;
    isEditing.value = false;
    editingId.value = null;
    duplicateWarning.value = false;
    duplicateFiles.value = [];
    selectedAnneeId.value = null;
    selectedMoisId.value = null;
};

const deleteArchive = (id) => {
    if (!canDelete.value) {
        showNotify('Vous n\'avez pas les droits pour supprimer ce document.', 'error');
        return;
    }
    showConfirm('Supprimer définitivement ce document ?', () => {
        router.delete(route('archives.destroy', id), {
            onSuccess: () => {
                showNotify('Document supprimé avec succès', 'success');
            },
            onError: () => showNotify('Erreur lors de la suppression', 'error')
        });
    }, 'Supprimer le document');
};

const getFileIcon = (ext) => {
    const icons = { pdf: 'mdi-file-pdf-box', jpg: 'mdi-file-image', png: 'mdi-file-image', docx: 'mdi-file-word', jpeg: 'mdi-file-image' };
    return icons[ext?.toLowerCase()] || 'mdi-file-document';
};

const getFileColor = (ext) => {
    const colors = { pdf: 'red', jpg: 'orange', png: 'orange', docx: 'blue', jpeg: 'orange' };
    return colors[ext?.toLowerCase()] || 'grey';
};

const getDossierPath = (archive) => {
    if (!archive.dossier) return 'N/A';
    return `${archive.dossier.mois?.annee?.annee || ''} / ${archive.dossier.mois?.nom_mois || ''} / ${archive.dossier.nom}`;
};

const noDataMessage = isArchiviste.value ? 'Aucun document archivé par vos soins.' : 'Aucun document ne correspond à vos critères.';

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('fr-FR');
};

const formatSize = (bytes) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(2)} ${units[i]}`;
};
</script>

<template>

    <Head title="Gestion des Archives" />
    <AuthenticatedLayout>
        <!-- SNACKBAR -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" rounded="lg">
            <v-icon start>{{ snackbar.icon }}</v-icon>
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
        <v-card elevation="1" class="rounded-xl overflow-hidden border d-flex flex-column" height="88vh">
            <v-toolbar color="white" border-bottom height="80" flat class="px-4">
                <v-icon icon="mdi-archive" color="primary" size="32" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Archives</div>
                    <div class="text-caption text-grey">
                        {{ isArchiviste ? 'Mes documents archivés' : 'Gestion du fonds documentaire' }}
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Rechercher..."
                    variant="outlined" hide-details density="comfortable" class="mx-4"
                    style="max-width: 350px;"></v-text-field>
                <v-btn v-if="canExport" color="success" prepend-icon="mdi-microsoft-excel" variant="tonal" class="mr-2"
                    @click="exportExcel">Exporter</v-btn>
                <v-btn v-if="canCreate" color="primary" prepend-icon="mdi-plus" variant="flat" rounded="lg"
                    @click="openCreateDialog">Nouveau</v-btn>
            </v-toolbar>

            <!-- FILTRES -->
            <div class="bg-grey-lighten-4 px-4 py-2 border-bottom d-flex align-center flex-wrap gap-3">
                <v-select v-model="filterDossier" :items="dossiers" item-title="nom" item-value="id" label="Dossier"
                    variant="solo" density="compact" hide-details flat clearable style="max-width: 250px;"></v-select>
                <v-text-field v-model="filterDateDebut" type="date" label="Depuis le" variant="solo" density="compact"
                    hide-details flat style="max-width: 160px;"></v-text-field>
                <v-text-field v-model="filterDateFin" type="date" label="Jusqu'au" variant="solo" density="compact"
                    hide-details flat style="max-width: 160px;"></v-text-field>
                <v-select v-model="filterType" :items="type_documents" label="Format" variant="solo" density="compact"
                    hide-details flat clearable style="max-width: 120px;"></v-select>
                <v-btn v-if="filterDossier || search || filterDateDebut || filterType" variant="text" color="error"
                    size="small" @click="resetFilters" prepend-icon="mdi-filter-off">Réinitialiser</v-btn>
            </div>

            <!-- TABLEAU AVEC PAGINATION -->
            <div class="flex-grow-1 overflow-hidden bg-white">
                <v-table fixed-header class="h-100">
                    <thead>
                        <tr>
                            <th class="bg-grey-lighten-5">Référence</th>
                            <th class="bg-grey-lighten-5">Titre</th>
                            <th class="bg-grey-lighten-5">Emplacement</th>
                            <th class="bg-grey-lighten-5">Date Doc.</th>
                            <th class="bg-grey-lighten-5 text-center">Format</th>
                            <th class="bg-grey-lighten-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="archive in archives.data" :key="archive.id">
                            <td class="font-weight-bold text-primary">{{ archive.reference }}</td>
                            <td class="text-truncate" style="max-width: 250px;">{{ archive.titre }}</td>
                            <td class="text-caption">{{ getDossierPath(archive) }}</td>
                            <td class="text-caption">{{ new Date(archive.date_document).toLocaleDateString() }}</td>
                            <td class="text-center">
                                <v-icon :color="getFileColor(archive.type_document)">{{
                                    getFileIcon(archive.type_document) }}</v-icon>
                            </td>
                            <td class="text-right">
                                <v-btn icon="mdi-eye" variant="text" color="info"
                                    :href="route('archives.view', archive.id)" target="_blank"></v-btn>
                                <v-btn icon="mdi-download" variant="text" color="primary"
                                    :href="route('archives.download', archive.id)"></v-btn>
                                <v-btn v-if="canEdit" icon="mdi-pencil" variant="text" color="primary"
                                    @click="openEditDialog(archive)"></v-btn>
                                <v-btn v-if="canDelete" icon="mdi-delete" variant="text" color="error"
                                    @click="deleteArchive(archive.id)"></v-btn>
                            </td>
                        </tr>
                        <tr v-if="archives.data.length === 0">
                            <td colspan="6" class="text-center py-10 text-grey italic">{{ noDataMessage }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </div>

            <!-- PAGINATION -->
            <v-divider></v-divider>
            <div class="pa-3 bg-grey-lighten-5 d-flex align-center justify-space-between flex-wrap gap-2">
                <div class="text-caption text-grey-darken-1">
                    Affichage de {{ archives.from || 0 }} à {{ archives.to || 0 }} sur {{ archives.total }} documents
                </div>
                <div class="d-flex gap-1">
                    <v-btn v-for="(link, k) in archives.links" :key="k" :disabled="link.url === null"
                        :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'grey-darken-1'"
                        size="small" class="px-2"
                        @click="link.url ? router.get(link.url, {}, { preserveState: true, preserveScroll: true }) : null"
                        v-html="link.label">
                    </v-btn>
                </div>
            </div>
        </v-card>

        <!-- Dialog Création/Modification -->
        <v-dialog v-model="dialog" max-width="800px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh; display: flex; flex-direction: column;">
                <v-toolbar :color="isEditing ? 'primary' : 'primary'" class="rounded-t-xl">
                    <v-icon start class="ml-4">{{ isEditing ? 'mdi-pencil' : 'mdi-plus' }}</v-icon>
                    <v-toolbar-title class="font-weight-bold">
                        <span v-if="isEditing">Modifier l'archive</span>
                        <span v-else>Nouvelle Archive</span>
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="dialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="overflow-y: auto; flex: 1;">
                    <v-progress-linear v-if="form.processing" :model-value="form.progress?.percentage" color="primary"
                        height="4" striped class="mb-4" rounded></v-progress-linear>

                    <!-- 🔥 ALERTE DOUBLONS -->
                    <v-alert v-if="duplicateWarning && duplicateFiles.length > 0" type="warning" variant="tonal"
                        class="mb-4" closable @click:close="duplicateWarning = false">
                        <div class="font-weight-bold mb-2">
                            ⚠️ {{ duplicateFiles.length }} fichier(s) en double détecté(s)
                        </div>
                        <div v-for="dup in duplicateFiles.slice(0, 5)" :key="dup.file" class="text-caption">
                            • {{ dup.file }}
                            <span class="text-grey">(Réf: {{ dup.reference }})</span>
                            <v-chip
                                :color="dup.status === 'pending' ? 'warning' : dup.status === 'validated' ? 'success' : 'error'"
                                size="x-small" class="ml-1">
                                {{ dup.status === 'pending' ? 'En attente' : dup.status === 'validated' ? 'Validé' :
                                    'Rejeté' }}
                            </v-chip>
                        </div>
                        <div v-if="duplicateFiles.length > 5" class="text-caption text-grey mt-1">
                            + {{ duplicateFiles.length - 5 }} autres fichiers
                        </div>
                        <div class="mt-2">
                            <v-btn size="small" color="warning" variant="text" @click="forceSubmit">
                                Ignorer et archiver quand même
                            </v-btn>
                        </div>
                    </v-alert>

                    <v-form @submit.prevent="submit">
                        <!-- Emplacement -->
                        <div class="mb-4">
                            <div class="text-subtitle-2 font-weight-bold mb-2 text-primary">Emplacement du document
                            </div>
                            <v-row dense>
                                <v-col cols="12" md="4">
                                    <v-select v-model="selectedAnneeId" :items="annees" item-title="annee"
                                        item-value="id" label="1. Choisir l'année" variant="outlined"
                                        density="comfortable" :disabled="isEditing" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-calendar</v-icon></template>
                                    </v-select>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-select v-model="selectedMoisId" :items="availableMois" item-title="nom_mois"
                                        item-value="id" label="2. Choisir le mois" variant="outlined"
                                        density="comfortable" :disabled="!selectedAnneeId || isEditing" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-calendar-month</v-icon></template>
                                    </v-select>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-select v-model="form.dossier_id" :items="availableDossiers" item-title="nom"
                                        item-value="id" label="3. Choisir le dossier" variant="outlined"
                                        density="comfortable" :disabled="!selectedMoisId || isEditing"
                                        :error-messages="form.errors.dossier_id" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-folder</v-icon></template>
                                        <template v-slot:item="{ item, props: itemProps }">
                                            <v-list-item v-bind="itemProps">
                                                <div class="d-flex align-center">
                                                    <v-icon :color="item.raw.couleur" size="20"
                                                        class="mr-2">mdi-folder</v-icon>
                                                    {{ item.title }}
                                                </div>
                                            </v-list-item>
                                        </template>
                                    </v-select>
                                </v-col>
                            </v-row>
                        </div>

                        <v-divider class="my-4"></v-divider>

                        <!-- Mode d'importation -->
                        <div v-if="!isEditing" class="mb-4">
                            <div class="text-subtitle-2 font-weight-bold mb-2 text-primary">Mode d'importation</div>
                            <v-radio-group v-model="multipleMode" color="primary" hide-details>
                                <v-radio label="Fichier unique" :value="false"></v-radio>
                                <v-radio label="Plusieurs fichiers" :value="true"></v-radio>
                            </v-radio-group>

                            <div v-if="multipleMode" class="mt-3">
                                <v-btn color="info" variant="tonal" @click="toggleFolderMode" block>
                                    <v-icon left>{{ folderMode ? 'mdi-folder-open' : 'mdi-folder' }}</v-icon>
                                    {{ folderMode ? 'Dossier sélectionné' : 'Sélectionner un dossier' }}
                                </v-btn>
                                <div class="text-caption text-grey mt-1">
                                    <span v-if="folderMode">
                                        {{ folderFiles.length }} fichier(s) trouvé(s) dans le dossier
                                    </span>
                                    <span v-else>
                                        Sélectionnez un dossier pour importer tous les fichiers
                                    </span>
                                </div>
                            </div>
                        </div>

                        <v-divider class="my-4" v-if="!isEditing"></v-divider>

                        <!-- Informations du document -->
                        <div class="text-subtitle-2 font-weight-bold mb-2 text-primary">Informations du document</div>

                        <v-row dense>
                            <template v-if="!multipleMode">
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="form.reference" label="Référence (auto-générée)"
                                        variant="outlined" density="comfortable" :error-messages="form.errors.reference"
                                        readonly disabled
                                        hint="La référence est générée automatiquement pour garantir l'unicité"
                                        persistent-hint>
                                        <template v-slot:prepend-inner>
                                            <v-icon color="primary" size="small">mdi-tag</v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="form.date_document" type="date" label="Date du document"
                                        variant="outlined" density="comfortable"
                                        :error-messages="form.errors.date_document" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-calendar</v-icon></template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field v-model="form.titre" label="Titre du document" variant="outlined"
                                        density="comfortable" :error-messages="form.errors.titre" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-file-document</v-icon></template>
                                    </v-text-field>
                                </v-col>
                            </template>

                            <template v-else>
                                <v-col cols="12">
                                    <v-alert type="info" variant="tonal" density="comfortable" class="mb-2">
                                        <strong>{{ folderMode ? 'Mode dossier' : 'Mode multi-fichiers' }}</strong>
                                        <div class="text-caption mt-1">
                                            <span v-if="folderMode">
                                                Les fichiers du dossier seront importés automatiquement ({{
                                                    folderFiles.length }} fichiers)
                                            </span>
                                            <span v-else>
                                                Sélectionnez plusieurs fichiers à importer
                                            </span>
                                        </div>
                                    </v-alert>
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field v-model="form.date_document" type="date"
                                        label="Date du document (appliquée à tous les fichiers)" variant="outlined"
                                        density="comfortable" :error-messages="form.errors.date_document" required>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-calendar</v-icon></template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12" v-if="folderMode && folderFilesInfo.length > 0">
                                    <v-card variant="outlined" class="pa-2"
                                        style="max-height: 150px; overflow-y: auto;">
                                        <div v-for="(file, index) in folderFilesInfo.slice(0, 15)" :key="index"
                                            class="d-flex align-center justify-space-between pa-1 border-bottom">
                                            <div class="d-flex align-center">
                                                <v-icon :color="getFileColor(file.extension)" size="small" class="mr-2">
                                                    {{ getFileIcon(file.extension) }}
                                                </v-icon>
                                                <span class="text-caption">{{ file.name }}</span>
                                            </div>
                                            <div class="text-caption text-grey">
                                                {{ formatDate(file.lastModified) }} - {{ formatSize(file.size) }}
                                            </div>
                                        </div>
                                        <div v-if="folderFilesInfo.length > 15"
                                            class="text-caption text-grey text-center pa-1">
                                            + {{ folderFilesInfo.length - 15 }} autres fichiers
                                        </div>
                                    </v-card>
                                </v-col>
                            </template>

                            <v-col cols="12">
                                <v-textarea v-model="form.description" label="Description" variant="outlined"
                                    density="comfortable" rows="2"></v-textarea>
                            </v-col>
                            <v-col cols="12">
                                <v-text-field v-model="form.mots_cles" label="Mots-clés (séparés par des virgules)"
                                    variant="outlined" density="comfortable" hint="Ex: contrat, facture, rh"
                                    persistent-hint>
                                    <template v-slot:prepend-inner><v-icon color="primary"
                                            size="small">mdi-tag-multiple</v-icon></template>
                                </v-text-field>
                            </v-col>

                            <v-col cols="12" v-if="!isEditing && !multipleMode">
                                <v-file-input v-model="form.fichier" label="Choisir le fichier (PDF, Images, Word...)"
                                    variant="outlined" prepend-icon="mdi-paperclip" show-size
                                    :error-messages="form.errors.fichier"
                                    accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.xls,.xlsx" required>
                                    <template v-slot:selection="{ fileNames }">
                                        <template v-for="fileName in fileNames" :key="fileName">
                                            <v-chip size="small" color="primary" class="mr-2">{{ fileName }}</v-chip>
                                        </template>
                                    </template>
                                </v-file-input>
                            </v-col>

                            <v-col cols="12" v-if="!isEditing && multipleMode && !folderMode">
                                <v-file-input v-model="form.fichiers"
                                    label="Choisir plusieurs fichiers (PDF, Images, Word...)" variant="outlined"
                                    prepend-icon="mdi-paperclip" show-size multiple counter
                                    :error-messages="form.errors.fichiers"
                                    accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.xls,.xlsx" required>
                                    <template v-slot:selection="{ fileNames }">
                                        <template v-for="fileName in fileNames" :key="fileName">
                                            <v-chip size="small" color="primary" class="mr-2 mb-1">{{ fileName
                                            }}</v-chip>
                                        </template>
                                    </template>
                                </v-file-input>
                            </v-col>

                            <input v-if="folderMode" id="folderInput" type="file" webkitdirectory multiple
                                style="display: none" @change="onFolderSelect" />
                        </v-row>

                        <v-card-actions class="px-0 mt-4">
                            <v-spacer></v-spacer>
                            <v-btn variant="text" @click="dialog = false" :disabled="form.processing" rounded="lg">
                                Annuler
                            </v-btn>
                            <v-btn v-if="duplicateWarning && duplicateFiles.length > 0" color="error" variant="tonal"
                                @click="forceSubmit" class="mr-2" :disabled="form.processing">
                                <v-icon left>mdi-alert</v-icon>
                                Ignorer les doublons ({{ duplicateFiles.length }})
                            </v-btn>
                            <v-btn color="primary" type="submit" :loading="form.processing || checkingDuplicate"
                                class="px-6 ml-2" rounded="lg"
                                :disabled="multipleMode && (!form.fichiers || form.fichiers.length === 0)">
                                <template v-if="checkingDuplicate">
                                    <v-progress-circular indeterminate size="20" color="white"
                                        class="mr-2"></v-progress-circular>
                                    Vérification...
                                </template>
                                <template v-else-if="isEditing">Mettre à jour</template>
                                <template v-else-if="multipleMode">
                                    Archiver {{ form.fichiers?.length || 0 }} fichier(s)
                                </template>
                                <template v-else>Archiver</template>
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.gap-1 {
    gap: 4px;
}

.gap-2 {
    gap: 8px;
}

.gap-3 {
    gap: 12px;
}

.h-100 {
    height: 100%;
}

.v-table :deep(th) {
    font-size: 0.8rem !important;
    font-weight: bold;
    color: #666;
}

.v-dialog-enter-active,
.v-dialog-leave-active {
    transition: opacity 0.2s ease;
}

.v-dialog-enter-from,
.v-dialog-leave-to {
    opacity: 0;
}

.border-bottom {
    border-bottom: 1px solid #eee;
}
</style>
