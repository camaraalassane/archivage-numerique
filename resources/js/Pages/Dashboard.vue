<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import { ref, computed, reactive, watch, onMounted } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    treeData: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    user: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) }
});

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

// ÉTATS DE NAVIGATION
const currentView = ref('root');
const currentPath = ref({ annee: null, mois: null, dossier: null });
const history = ref([]);
const searchQuery = ref('');
const statusFilter = ref('all');

// ÉTATS POUR LE CHARGEMENT ASYNCHRONE AVEC PAGINATION
const dossierArchives = ref([]);
const isLoadingArchives = ref(false);
const currentDossierId = ref(null);
const archivesPagination = ref(null);
const currentPage = ref(1);
const perPage = ref(10);

// ÉTATS DIALOGUES
const uploadDialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const previewDialog = ref(false);
const currentFileUrl = ref('');
const currentFileTitle = ref('');

// MODES D'IMPORTATION
const multipleMode = ref(false);
const folderMode = ref(false);
const folderFiles = ref([]);
const folderFilesInfo = ref([]);

// 🔥 ÉTATS POUR LA DÉTECTION DES DOUBLONS
const checkingDuplicate = ref(false);
const duplicateWarning = ref(false);
const duplicateFiles = ref([]);

// PERMISSIONS
const canArchive = computed(() => {
    return props.permissions?.can_manage_dossiers || false;
});

const canValidate = computed(() => {
    return props.permissions?.can_validate || false;
});

const canManageUsers = computed(() => {
    return props.permissions?.can_manage_users || false;
});

const canExport = computed(() => {
    return props.permissions?.can_export || false;
});

const canModify = computed(() => {
    return props.permissions?.can_modify_archives || false;
});

// FORMULAIRE
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

// FILTRAGE DES DONNÉES
const filteredData = computed(() => {
    const q = searchQuery.value?.toLowerCase() || '';

    if (currentView.value === 'root') {
        return props.treeData.filter(a => a.annee.toString().includes(q));
    }
    if (currentView.value === 'mois') {
        const annee = currentPath.value.annee;
        return annee?.mois?.filter(m => m.nom_mois.toLowerCase().includes(q)) || [];
    }
    if (currentView.value === 'dossiers') {
        const mois = currentPath.value.mois;
        return mois?.dossiers?.filter(d => d.nom.toLowerCase().includes(q)) || [];
    }
    if (currentView.value === 'files') {
        return dossierArchives.value;
    }
    return [];
});

const searchPlaceholder = computed(() => {
    if (currentView.value === 'root') return "Chercher une année...";
    if (currentView.value === 'mois') return "Chercher un mois...";
    if (currentView.value === 'dossiers') return "Chercher un dossier...";
    return "Chercher un fichier...";
});

const fileCount = computed(() => {
    return dossierArchives.value.length || 0;
});

// ACTIONS DE NAVIGATION
const enterAnnee = (annee) => {
    history.value.push({ view: currentView.value, path: JSON.parse(JSON.stringify(currentPath.value)) });
    currentPath.value.annee = annee;
    currentView.value = 'mois';
    searchQuery.value = '';
    currentPage.value = 1;
};

const enterMois = (mois) => {
    history.value.push({ view: currentView.value, path: JSON.parse(JSON.stringify(currentPath.value)) });
    currentPath.value.mois = mois;
    currentView.value = 'dossiers';
    searchQuery.value = '';
    currentPage.value = 1;
};

const enterDossier = async (dossier) => {
    history.value.push({ view: currentView.value, path: JSON.parse(JSON.stringify(currentPath.value)) });
    currentPath.value.dossier = dossier;
    currentView.value = 'files';
    searchQuery.value = '';
    currentDossierId.value = dossier.id;
    currentPage.value = 1;
    dossierArchives.value = [];

    await loadDossierArchives(dossier.id, currentPage.value);
};

const loadDossierArchives = async (dossierId, page = 1) => {
    isLoadingArchives.value = true;
    try {
        const response = await axios.get(route('dossiers.archives', dossierId), {
            params: {
                status: statusFilter.value !== 'all' ? statusFilter.value : null,
                search: searchQuery.value || null,
                page: page,
                per_page: perPage.value
            }
        });
        dossierArchives.value = response.data.data || [];
        archivesPagination.value = response.data;
    } catch (error) {
        console.error('Erreur lors du chargement des archives:', error);
        showNotify('Erreur lors du chargement des archives', 'error');
    } finally {
        isLoadingArchives.value = false;
    }
};

// 🔥 CHANGEMENT DE PAGE
const changePage = (page) => {
    if (page < 1 || page > archivesPagination.value?.last_page) return;
    currentPage.value = page;
    loadDossierArchives(currentDossierId.value, page);
};

const goBack = () => {
    const last = history.value.pop();
    if (last) {
        currentView.value = last.view;
        currentPath.value = last.path;
        currentPage.value = 1;
        if (currentView.value === 'files') {
            loadDossierArchives(currentPath.value.dossier.id, currentPage.value);
        }
    }
};

const resetToRoot = () => {
    history.value = [];
    currentPath.value = { annee: null, mois: null, dossier: null };
    currentView.value = 'root';
    dossierArchives.value = [];
    currentDossierId.value = null;
    currentPage.value = 1;
    archivesPagination.value = null;
};

// GÉNÉRATION DE RÉFÉRENCE
const generateReference = () => {
    if (!currentPath.value.dossier || !currentPath.value.mois || !currentPath.value.annee) return '';

    const annee = currentPath.value.annee;
    const moisItem = currentPath.value.mois;
    const dossier = currentPath.value.dossier;

    const count = dossier?.archives_count || 0;
    const nextNum = String(count + 1).padStart(3, '0');

    return `${annee.annee}_${String(moisItem.mois).padStart(2, '0')}_${dossier.nom.toUpperCase().replace(/[^A-Z0-9]/g, '_')}_${nextNum}`;
};

// STATUT DES ARCHIVES
const getStatusInfo = (file) => {
    const statusMap = {
        'pending': { label: 'En attente', color: 'warning', icon: 'mdi-clock-outline' },
        'validated': { label: 'Validé', color: 'success', icon: 'mdi-check-circle' },
        'rejected': { label: 'Rejeté', color: 'error', icon: 'mdi-close-circle' }
    };
    return statusMap[file.validation_status] || { label: 'Inconnu', color: 'grey', icon: 'mdi-help-circle' };
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
    submitArchive();
};

// ACTIONS FICHIERS
const openUploadDialog = () => {
    if (!currentPath.value.dossier) {
        showNotify('Veuillez d\'abord sélectionner un dossier', 'warning');
        return;
    }

    isEditing.value = false;
    multipleMode.value = false;
    folderMode.value = false;
    folderFiles.value = [];
    folderFilesInfo.value = [];
    duplicateWarning.value = false;
    duplicateFiles.value = [];
    form.reset();
    form.clearErrors();
    form.dossier_id = currentPath.value.dossier.id;
    form.date_document = new Date().toISOString().substr(0, 10);
    form.reference = generateReference();
    uploadDialog.value = true;
};

const openEditDialog = (file) => {
    isEditing.value = true;
    editingId.value = file.id;
    multipleMode.value = false;
    folderMode.value = false;
    folderFiles.value = [];
    folderFilesInfo.value = [];
    duplicateWarning.value = false;
    duplicateFiles.value = [];
    form.clearErrors();
    form.titre = file.titre;
    form.reference = file.reference;
    form.dossier_id = currentPath.value.dossier?.id;
    form.date_document = file.date_document;
    form.description = file.description || '';
    form.mots_cles = file.mots_cles || '';
    uploadDialog.value = true;
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
        form.reference = generateReference();
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

// VALIDATION DES ARCHIVES
const validateArchive = (file, status) => {
    if (!canValidate.value) {
        showNotify('Vous n\'avez pas les droits pour valider', 'error');
        return;
    }

    const statusLabel = status === 'validated' ? 'valider' : 'rejeter';
    showConfirm(`Voulez-vous ${statusLabel} ce document ?`, () => {
        router.post(route('archives.validate', file.id), {
            status: status,
            comment: `Document ${statusLabel} par ${props.user?.name}`
        }, {
            onSuccess: () => {
                showNotify(`Document ${statusLabel} avec succès`, 'success');
                loadDossierArchives(currentDossierId.value, currentPage.value);
            },
            onError: () => showNotify('Erreur lors de la validation', 'error')
        });
    }, `${statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1)} le document`);
};

// SOUMISSION DU FORMULAIRE
const submitArchive = async () => {
    if (isEditing.value) {
        form.put(route('archives.update', editingId.value), {
            onSuccess: () => {
                showNotify('Document mis à jour avec succès');
                uploadDialog.value = false;
                loadDossierArchives(currentDossierId.value, currentPage.value);
            },
            onError: () => showNotify('Veuillez corriger les erreurs', 'error')
        });
        return;
    }

    if (multipleMode.value || folderMode.value) {
        if (!form.fichiers || form.fichiers.length === 0) {
            showNotify('Veuillez sélectionner des fichiers', 'error');
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

        form.fichiers.forEach((file, index) => {
            formData.append(`fichiers[${index}]`, file);
        });

        router.post(route('archives.store.multiple'), formData, {
            forceFormData: true,
            onSuccess: () => {
                showNotify(`${form.fichiers.length} fichier(s) archivés avec succès`);
                uploadDialog.value = false;
                form.reset();
                multipleMode.value = false;
                folderMode.value = false;
                folderFiles.value = [];
                folderFilesInfo.value = [];
                duplicateWarning.value = false;
                duplicateFiles.value = [];
                loadDossierArchives(currentDossierId.value, currentPage.value);
            },
            onError: () => showNotify('Erreur lors de l\'archivage', 'error')
        });
        return;
    }

    form.post(route('archives.store'), {
        onSuccess: () => {
            showNotify('Document archivé avec succès');
            uploadDialog.value = false;
            form.reset();
            loadDossierArchives(currentDossierId.value, currentPage.value);
        },
        onError: () => showNotify('Erreur lors de l\'archivage', 'error')
    });
};

const deleteArchive = (id) => {
    if (!canModify.value) {
        showNotify('Vous n\'avez pas les droits pour supprimer', 'error');
        return;
    }
    showConfirm('Supprimer définitivement ce document ?', () => {
        router.delete(route('archives.destroy', id), {
            onSuccess: () => {
                showNotify('Document supprimé avec succès', 'success');
                loadDossierArchives(currentDossierId.value, currentPage.value);
            },
            onError: () => showNotify('Erreur lors de la suppression', 'error')
        });
    }, 'Supprimer le document');
};

const previewFile = (file) => {
    currentFileUrl.value = route('archives.view', file.id);
    currentFileTitle.value = file.titre;
    previewDialog.value = true;
};

const downloadFile = (file) => {
    window.open(route('archives.download', file.id), '_blank');
};

// WATCH pour recharger les archives quand le filtre change
watch([searchQuery, statusFilter], () => {
    if (currentView.value === 'files' && currentDossierId.value) {
        currentPage.value = 1;
        loadDossierArchives(currentDossierId.value, currentPage.value);
    }
});

const formatDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';
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

const getFileIcon = (ext) => {
    const icons = { pdf: 'mdi-file-pdf-box', jpg: 'mdi-file-image', png: 'mdi-file-image', docx: 'mdi-file-word' };
    return icons[ext?.toLowerCase()] || 'mdi-file-document';
};

const getFileColor = (ext) => {
    const colors = { pdf: 'red-darken-1', jpg: 'orange-darken-1', png: 'orange-darken-1', docx: 'blue-darken-1' };
    return colors[ext?.toLowerCase()] || 'grey-darken-1';
};
</script>

<template>

    <Head title="Explorateur de fichiers" />
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
        <v-card elevation="1" class="rounded-xl overflow-hidden border" min-height="82vh">
            <v-toolbar color="white" border-bottom density="comfortable">
                <v-btn icon="mdi-arrow-left" :disabled="history.length === 0" @click="goBack" variant="text"
                    size="small"></v-btn>
                <v-btn icon="mdi-home-outline" @click="resetToRoot" variant="text" color="primary" size="small"></v-btn>

                <v-divider vertical inset class="mx-2"></v-divider>

                <div class="d-flex align-center bg-grey-lighten-4 rounded-lg px-3 py-1 border flex-grow-1 overflow-hidden"
                    style="max-width: 450px;">
                    <v-icon size="x-small" color="grey-darken-1" class="mr-1">mdi-pc-tower</v-icon>
                    <span class="text-caption cursor-pointer hover-text" @click="resetToRoot">Archives</span>

                    <template v-if="currentPath.annee">
                        <v-icon size="x-small">mdi-chevron-right</v-icon>
                        <span class="text-caption cursor-pointer hover-text text-truncate"
                            @click="enterAnnee(currentPath.annee)">
                            {{ currentPath.annee.annee }}
                        </span>
                    </template>

                    <template v-if="currentPath.mois">
                        <v-icon size="x-small">mdi-chevron-right</v-icon>
                        <span class="text-caption cursor-pointer hover-text text-truncate"
                            @click="enterMois(currentPath.mois)">
                            {{ currentPath.mois.nom_mois }}
                        </span>
                    </template>

                    <template v-if="currentPath.dossier">
                        <v-icon size="x-small">mdi-chevron-right</v-icon>
                        <span class="text-caption font-weight-bold text-primary text-truncate">
                            {{ currentPath.dossier.nom }}
                        </span>
                    </template>
                </div>

                <v-spacer></v-spacer>

                <v-text-field v-model="searchQuery" prepend-inner-icon="mdi-magnify" :placeholder="searchPlaceholder"
                    variant="solo-filled" density="compact" hide-details rounded="lg" flat class="mx-4 d-none d-sm-flex"
                    style="max-width: 250px;" clearable></v-text-field>

                <v-btn v-if="currentView === 'files' && canArchive" color="primary" prepend-icon="mdi-cloud-upload"
                    variant="flat" rounded="lg" size="small" @click="openUploadDialog" class="mr-4 px-4">
                    Archiver
                </v-btn>
            </v-toolbar>

            <v-card-text class="pa-6 bg-grey-lighten-5 custom-scrollbar"
                style="height: calc(82vh - 64px); overflow-y: auto;">

                <!-- Années -->
                <v-row v-if="currentView === 'root'" class="align-content-start">
                    <v-col v-for="annee in filteredData" :key="annee.id" cols="6" sm="4" md="3" lg="2">
                        <div class="folder-item" @dblclick="enterAnnee(annee)">
                            <v-icon size="80" color="blue-darken-1" class="folder-shadow">mdi-calendar</v-icon>
                            <div class="folder-name">{{ annee.annee }}</div>
                            <div class="text-caption text-grey">{{ annee.mois?.length || 0 }} mois</div>
                        </div>
                    </v-col>
                </v-row>

                <!-- Mois -->
                <v-row v-else-if="currentView === 'mois'" class="align-content-start">
                    <v-col v-for="mois in filteredData" :key="mois.id" cols="6" sm="4" md="3" lg="2">
                        <div class="folder-item" @dblclick="enterMois(mois)">
                            <v-badge :content="mois.dossiers?.length || '0'" color="primary" overlap location="top end"
                                offset-x="10" offset-y="10">
                                <v-icon size="80" color="amber-darken-2"
                                    class="folder-shadow">mdi-calendar-month</v-icon>
                            </v-badge>
                            <div class="folder-name">{{ mois.nom_mois }}</div>
                        </div>
                    </v-col>
                </v-row>

                <!-- Dossiers -->
                <v-row v-else-if="currentView === 'dossiers'" class="align-content-start">
                    <v-col v-for="dossier in filteredData" :key="dossier.id" cols="6" sm="4" md="3" lg="2">
                        <div class="folder-item" @dblclick="enterDossier(dossier)">
                            <v-badge :content="dossier.archives_count || '0'" color="primary" overlap location="top end"
                                offset-x="10" offset-y="10">
                                <v-icon size="80" :color="dossier.couleur || 'grey-darken-2'"
                                    class="folder-shadow">mdi-folder</v-icon>
                            </v-badge>
                            <div class="folder-name">{{ dossier.nom }}</div>
                        </div>
                    </v-col>
                </v-row>

                <!-- Fichiers avec PAGINATION -->
                <div v-else-if="currentView === 'files'">
                    <div class="d-flex justify-space-between align-center mb-4 flex-wrap gap-2">
                        <div>
                            <h3 class="text-h6">
                                <v-icon :color="currentPath.dossier?.couleur" class="mr-2">mdi-folder</v-icon>
                                {{ currentPath.dossier?.nom }}
                            </h3>
                            <p class="text-caption text-grey mt-1">
                                {{ fileCount }} fichier(s) dans ce dossier
                                <span v-if="isLoadingArchives" class="ml-2">
                                    <v-progress-circular indeterminate size="16" color="primary"></v-progress-circular>
                                </span>
                            </p>
                        </div>
                        <div class="d-flex align-center gap-2 flex-wrap">
                            <v-select v-model="statusFilter" :items="[
                                { title: 'Tous', value: 'all' },
                                { title: 'En attente', value: 'pending' },
                                { title: 'Validés', value: 'validated' },
                                { title: 'Rejetés', value: 'rejected' }
                            ]" item-title="title" item-value="value" label="Statut" variant="solo" density="compact"
                                hide-details flat style="max-width: 150px;" clearable></v-select>
                            <v-btn v-if="canArchive" color="primary" prepend-icon="mdi-cloud-upload"
                                @click="openUploadDialog">
                                Archiver
                            </v-btn>
                        </div>
                    </div>

                    <v-card border flat class="rounded-lg overflow-hidden">
                        <v-table hover density="comfortable">
                            <thead>
                                <tr class="bg-grey-lighten-4">
                                    <th class="text-overline font-weight-bold">Nom du document</th>
                                    <th class="text-overline font-weight-bold">Référence</th>
                                    <th class="text-overline font-weight-bold">Date</th>
                                    <th class="text-overline font-weight-bold text-center">Statut</th>
                                    <th class="text-right text-overline font-weight-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="file in filteredData" :key="file.id" @dblclick="previewFile(file)"
                                    class="cursor-pointer file-row">
                                    <td>
                                        <div class="d-flex align-center">
                                            <v-icon :color="getFileColor(file.type_document)" class="mr-3">
                                                {{ getFileIcon(file.type_document) }}
                                            </v-icon>
                                            <span class="font-weight-medium text-body-2">{{ file.titre }}</span>
                                        </div>
                                    </td>
                                    <td class="text-caption text-grey-darken-1">{{ file.reference }}</td>
                                    <td class="text-caption text-grey-darken-1">{{ formatDate(file.date_document) }}
                                    </td>
                                    <td class="text-center">
                                        <v-chip :color="getStatusInfo(file).color" size="x-small"
                                            :prepend-icon="getStatusInfo(file).icon">
                                            {{ getStatusInfo(file).label }}
                                        </v-chip>
                                    </td>
                                    <td class="text-right">
                                        <v-btn icon="mdi-eye-outline" size="x-small" variant="text"
                                            color="blue-grey-darken-2" @click="previewFile(file)"></v-btn>

                                        <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" color="primary"
                                            @click="openEditDialog(file)" v-if="canModify"></v-btn>

                                        <v-btn icon="mdi-download-outline" size="x-small" variant="text" color="primary"
                                            @click="downloadFile(file)"></v-btn>

                                        <template v-if="canValidate && file.validation_status === 'pending'">
                                            <v-btn icon="mdi-check" size="x-small" variant="text" color="success"
                                                @click="validateArchive(file, 'validated')" title="Valider"></v-btn>
                                            <v-btn icon="mdi-close" size="x-small" variant="text" color="error"
                                                @click="validateArchive(file, 'rejected')" title="Rejeter"></v-btn>
                                        </template>

                                        <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error"
                                            @click="deleteArchive(file.id)" v-if="canModify"></v-btn>
                                    </td>
                                </tr>
                                <tr v-if="filteredData.length === 0 && !isLoadingArchives">
                                    <td colspan="5" class="text-center py-8 text-grey">
                                        <div v-if="statusFilter !== 'all'">
                                            Aucun fichier avec ce statut
                                        </div>
                                        <div v-else>
                                            Aucun fichier dans ce dossier
                                            <v-btn variant="text" color="primary" @click="openUploadDialog" class="ml-2"
                                                v-if="canArchive">
                                                Archiver un document
                                            </v-btn>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="isLoadingArchives">
                                    <td colspan="5" class="text-center py-8">
                                        <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                        <div class="text-caption text-grey mt-2">Chargement des archives...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card>

                    <!-- 🔥 PAGINATION DU DASHBOARD -->
                    <div v-if="archivesPagination && archivesPagination.last_page > 1"
                        class="d-flex justify-center align-center mt-4 gap-2">
                        <v-btn size="small" variant="text" :disabled="currentPage <= 1"
                            @click="changePage(currentPage - 1)">
                            <v-icon>mdi-chevron-left</v-icon>
                        </v-btn>

                        <v-btn v-for="page in archivesPagination.last_page" :key="page" size="small"
                            :variant="page === currentPage ? 'flat' : 'text'"
                            :color="page === currentPage ? 'primary' : 'grey-darken-1'" @click="changePage(page)"
                            class="px-2">
                            {{ page }}
                        </v-btn>

                        <v-btn size="small" variant="text" :disabled="currentPage >= archivesPagination.last_page"
                            @click="changePage(currentPage + 1)">
                            <v-icon>mdi-chevron-right</v-icon>
                        </v-btn>
                    </div>

                    <div v-if="archivesPagination" class="text-caption text-grey text-center mt-2">
                        Page {{ archivesPagination.current_page }} sur {{ archivesPagination.last_page }}
                        ({{ archivesPagination.total }} fichiers au total)
                    </div>
                </div>

                <div v-if="filteredData.length === 0 && currentView !== 'files'" class="text-center py-16">
                    <v-icon size="80" color="grey-lighten-2">mdi-folder-search-outline</v-icon>
                    <div class="text-h6 text-grey-lighten-1 mt-4">Aucun résultat</div>
                </div>
            </v-card-text>
        </v-card>

        <!-- DIALOGUE D'ARCHIVAGE AVEC DOUBLONS -->
        <v-dialog v-model="uploadDialog" max-width="750px" persistent scrollable>
            <v-card class="rounded-xl overflow-hidden" style="max-height: 90vh;">
                <v-toolbar color="primary" flat>
                    <v-icon start class="ml-4">{{ isEditing ? 'mdi-pencil' : 'mdi-cloud-upload' }}</v-icon>
                    <v-toolbar-title class="font-weight-bold text-body-1">
                        {{ isEditing ? 'Modifier le document' : 'Archiver un document' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="uploadDialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="max-height: 60vh; overflow-y: auto;">
                    <div class="mb-4 pa-3 bg-grey-lighten-4 rounded-lg" v-if="currentPath.dossier">
                        <div class="text-caption text-grey">Dossier de destination</div>
                        <div class="font-weight-bold">
                            <v-icon :color="currentPath.dossier.couleur" size="small" class="mr-1">mdi-folder</v-icon>
                            {{ currentPath.dossier.nom }}
                        </div>
                        <div class="text-caption text-grey">
                            {{ currentPath.annee?.annee }} / {{ currentPath.mois?.nom_mois }}
                        </div>
                    </div>

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

                    <v-form @submit.prevent="submitArchive">
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

                        <div class="text-subtitle-2 font-weight-bold mb-2 text-primary">Informations du document</div>

                        <v-row dense>
                            <template v-if="!multipleMode">
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="form.reference" label="Référence (auto-générée)"
                                        variant="outlined" density="comfortable" :error-messages="form.errors.reference"
                                        readonly disabled hint="La référence est générée automatiquement"
                                        persistent-hint>
                                        <template v-slot:prepend-inner><v-icon color="primary"
                                                size="small">mdi-tag</v-icon></template>
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
                            <v-btn variant="text" @click="uploadDialog = false" :disabled="form.processing"
                                rounded="lg">Annuler</v-btn>
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

        <!-- PRÉVISUALISATION -->
        <v-dialog v-model="previewDialog" width="95%" max-width="1600px">
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
    </AuthenticatedLayout>
</template>

<style scoped>
.folder-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 8px;
    cursor: pointer;
    border-radius: 16px;
    transition: all 0.2s ease;
}

.folder-item:hover {
    background-color: rgba(25, 118, 210, 0.1);
    transform: translateY(-4px);
}

.folder-shadow {
    filter: drop-shadow(0px 4px 6px rgba(0, 0, 0, 0.1));
}

.folder-name {
    margin-top: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    text-align: center;
    color: #37474F;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    height: 2.8em;
    width: 100%;
}

.hover-text:hover {
    text-decoration: underline;
    color: #1976D2;
    cursor: pointer;
}

.file-row:hover {
    background-color: #F5Faff !important;
}

.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #CFD8DC;
    border-radius: 10px;
}

.cursor-pointer {
    cursor: pointer;
}

.border-bottom {
    border-bottom: 1px solid #eee;
}

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
</style>
