<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    annees: { type: Array, default: () => [] },
    mois: { type: Array, default: () => [] },
    dossiers: { type: Array, default: () => [] },
    user: { type: Object, required: true },
    permissions: { type: Object, default: () => ({}) }
});

const canImport = computed(() => props.permissions?.can_import || false);

// Mode: 'server' or 'local'
const importMode = ref('local'); 

// Server mode
const importPath = ref('');

// Local mode
const isDragging = ref(false);
const localFilesData = ref([]); // File objects for local upload
const uploadProgress = ref({ current: 0, total: 0 });

const selectedFiles = ref([]);
const isLoading = ref(false);
const scanning = ref(false);
const importedCount = ref(0);
const errorCount = ref(0);
const duplicateCount = ref(0);
const results = ref([]);
const showResults = ref(false);
const folders = ref([]);
const expandedFolders = ref(new Set());

const filterMoisId = ref(null);
const folderMapping = ref({});

const snackbar = ref({ show: false, text: '', color: 'success', timeout: 5000 });

const showNotification = (text, color = 'success') => {
    snackbar.value = { show: true, text, color, timeout: 5000 };
};

const dateDocument = ref(new Date().toISOString().split('T')[0]);

const dossiersWithPath = computed(() => {
    return props.dossiers.map(d => {
        const mois = props.mois.find(m => m.id === d.mois_id);
        const annee = mois ? props.annees.find(a => a.id === mois.annee_id) : null;
        return {
            ...d,
            chemin: annee && mois ? `${annee.annee} / ${mois.nom_mois} / ${d.nom}` : d.nom,
        };
    });
});

const filteredDossiers = computed(() => {
    if (!filterMoisId.value) return dossiersWithPath.value;
    return dossiersWithPath.value.filter(d => d.mois_id === filterMoisId.value);
});

const moisWithAnnee = computed(() => {
    return props.mois.map(m => {
        const annee = props.annees.find(a => a.id === m.annee_id);
        return {
            ...m,
            label: annee ? `${annee.annee} - ${m.nom_mois}` : m.nom_mois,
        };
    });
});

const selectedCount = computed(() =>
    selectedFiles.value.filter(f => f.selected && !f.exists).length
);

const canImportAction = computed(() => {
    if (!canImport.value) return false;
    if (selectedCount.value === 0) return false;
    return folders.value.some(f =>
        folderMapping.value[f] &&
        selectedFiles.value.some(sf => sf.folder === f && sf.selected && !sf.exists)
    );
});

const resetFilter = () => {
    filterMoisId.value = null;
};

// ============================================
// MODE SERVEUR (Ancien système)
// ============================================
const scanDirectory = async () => {
    if (!canImport.value) return;
    if (!importPath.value) {
        showNotification('Veuillez entrer le chemin du dossier à importer.', 'warning');
        return;
    }

    scanning.value = true;
    resetLists();

    try {
        const response = await axios.post('/import/scan', { path: importPath.value });
        if (response.data.error) {
            showNotification(response.data.error, 'error');
            return;
        }
        if (response.data.files.length === 0) {
            showNotification('Aucun fichier valide trouvé dans ce dossier.', 'warning');
            return;
        }

        selectedFiles.value = response.data.files.map(f => ({ ...f, selected: !f.exists }));
        folders.value = response.data.folders || [];
        initializeMapping();
        showNotification(`${selectedFiles.value.length} fichier(s) détecté(s).`, 'success');
    } catch (error) {
        console.error(error);
        showNotification('Erreur lors du scan.', 'error');
    } finally {
        scanning.value = false;
    }
};

// ============================================
// MODE LOCAL (Glisser-Déposer)
// ============================================
const detectDateFromFilename = (filename) => {
    let m = filename.match(/(\d{4})[-_](\d{2})[-_](\d{2})/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    m = filename.match(/(?<!\d)(\d{4})(\d{2})(\d{2})(?!\d)/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    return null;
};

const handleDrop = async (e) => {
    isDragging.value = false;
    if (!canImport.value) return;

    resetLists();
    scanning.value = true;
    const items = e.dataTransfer.items;
    let tempFiles = [];

    const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    const readEntry = async (entry, path = '') => {
        if (entry.isFile) {
            return new Promise(resolve => {
                entry.file(file => {
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (allowedExts.includes(ext)) {
                        const relPath = path ? `${path}/${file.name}` : file.name;
                        const folderParts = relPath.split('/');
                        const folder = folderParts.length > 1 ? folderParts[0] : 'Racine';
                        
                        tempFiles.push({
                            fileObj: file,
                            name: file.name,
                            path: relPath,
                            folder: folder,
                            extension: ext,
                            size: file.size,
                            detected_date: detectDateFromFilename(file.name),
                            exists: false, // Check doublons pas fait ici (fait au serveur)
                            selected: true
                        });
                    }
                    resolve();
                });
            });
        } else if (entry.isDirectory) {
            return new Promise(resolve => {
                const dirReader = entry.createReader();
                dirReader.readEntries(async entries => {
                    for (const child of entries) {
                        await readEntry(child, path ? `${path}/${entry.name}` : entry.name);
                    }
                    resolve();
                });
            });
        }
    };

    for (let i = 0; i < items.length; i++) {
        const item = items[i];
        if (item.webkitGetAsEntry) {
            const entry = item.webkitGetAsEntry();
            if (entry) await readEntry(entry);
        }
    }

    if (tempFiles.length === 0) {
        showNotification('Aucun fichier valide détecté.', 'warning');
        scanning.value = false;
        return;
    }

    localFilesData.value = tempFiles;
    selectedFiles.value = tempFiles.map(f => ({ ...f }));
    
    // Extract unique folders
    const uniqueFolders = new Set(tempFiles.map(f => f.folder));
    folders.value = Array.from(uniqueFolders);
    
    initializeMapping();
    scanning.value = false;
    showNotification(`${tempFiles.length} fichier(s) détecté(s) localement.`, 'success');
};

const resetLists = () => {
    selectedFiles.value = [];
    localFilesData.value = [];
    folders.value = [];
    folderMapping.value = {};
    expandedFolders.value = new Set();
    showResults.value = false;
};

const initializeMapping = () => {
    const mapping = {};
    folders.value.forEach(f => { mapping[f] = null; });
    folderMapping.value = mapping;
    folders.value.forEach(f => expandedFolders.value.add(f));
};

// ============================================
// COMMUN
// ============================================
const toggleFolder = (folder) => {
    if (expandedFolders.value.has(folder)) expandedFolders.value.delete(folder);
    else expandedFolders.value.add(folder);
};

const toggleAllFiles = (selected) => {
    selectedFiles.value.forEach(f => { if (!f.exists) f.selected = selected; });
};

const toggleFolderFiles = (folder, selected) => {
    selectedFiles.value.filter(f => f.folder === folder && !f.exists).forEach(f => f.selected = selected);
};

const countFilesByFolder = (folder) => selectedFiles.value.filter(f => f.folder === folder).length;
const countSelectedByFolder = (folder) => selectedFiles.value.filter(f => f.folder === folder && f.selected && !f.exists).length;

const importFiles = async () => {
    if (isLoading.value) return;
    if (!canImportAction.value) return;

    isLoading.value = true;
    showResults.value = false;
    results.value = [];
    importedCount.value = 0;
    errorCount.value = 0;
    duplicateCount.value = 0;

    const filesToImport = selectedFiles.value.filter(f => f.selected && !f.exists);

    if (importMode.value === 'server') {
        // Envoi au serveur (ancien système)
        try {
            const response = await axios.post('/import/process', {
                files: filesToImport.map(f => ({ name: f.name, path: f.path, folder: f.folder, extension: f.extension, size: f.size, detected_date: f.detected_date })),
                base_path: importPath.value,
                date_document: dateDocument.value,
                folder_mapping: folderMapping.value,
            });
            handleProcessResponse(response.data);
        } catch (error) {
            handleError(error);
        }
    } else {
        // Mode local : upload séquentiel
        uploadProgress.value = { current: 0, total: filesToImport.length };
        
        for (const fileMeta of filesToImport) {
            const dossierId = folderMapping.value[fileMeta.folder];
            if (!dossierId) continue;

            const actualFile = localFilesData.value.find(f => f.path === fileMeta.path)?.fileObj;
            if (!actualFile) {
                errorCount.value++;
                results.value.push({ file: fileMeta.name, success: false, error: 'Fichier local introuvable' });
                continue;
            }

            const formData = new FormData();
            formData.append('file', actualFile);
            formData.append('folder', fileMeta.folder);
            formData.append('dossier_id', dossierId);
            formData.append('fallback_date', dateDocument.value);
            if (fileMeta.detected_date) formData.append('detected_date', fileMeta.detected_date);

            try {
                const response = await axios.post('/import/upload-mass', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });
                
                importedCount.value++;
                results.value.push(response.data);
            } catch (error) {
                if (error.response?.data?.is_duplicate) {
                    duplicateCount.value++;
                    results.value.push({ file: fileMeta.name, success: false, is_duplicate: true, error: error.response.data.error });
                } else {
                    errorCount.value++;
                    results.value.push({ file: fileMeta.name, success: false, error: error.response?.data?.error || 'Erreur serveur' });
                }
            }
            uploadProgress.value.current++;
        }
        
        handleProcessResponse({ results: results.value, imported: importedCount.value, errors: errorCount.value, duplicates: duplicateCount.value });
    }
};

const handleProcessResponse = (data) => {
    results.value = data.results || [];
    importedCount.value = data.imported || 0;
    errorCount.value = data.errors || 0;
    duplicateCount.value = data.duplicates || 0;
    showResults.value = true;

    if (importedCount.value > 0) {
        const importedNames = results.value.filter(r => r.success).map(r => r.file);
        selectedFiles.value = selectedFiles.value.filter(f => !importedNames.includes(f.name));
    }

    if (importedCount.value > 0 && errorCount.value === 0 && duplicateCount.value === 0) {
        showNotification(`${importedCount.value} fichier(s) importé(s) avec succès !`, 'success');
    } else if (importedCount.value > 0) {
        showNotification(`${importedCount.value} importé(s), ${errorCount.value} erreur(s), ${duplicateCount.value} doublon(s).`, 'warning');
    } else {
        showNotification('Aucun fichier importé.', 'error');
    }

    router.reload({ only: ['dossiers', 'mois', 'annees'] });
    isLoading.value = false;
};

const handleError = (error) => {
    console.error(error);
    const msg = error.response?.data?.error || error.message || 'Erreur inconnue';
    showNotification('Erreur : ' + msg, 'error');
    isLoading.value = false;
};

const formatSize = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return bytes.toFixed(2) + ' ' + units[i];
};

const getDossierById = (id) => dossiersWithPath.value.find(d => d.id === id);

const mappedCount = computed(() => {
    return Object.values(folderMapping.value).filter(id => id !== null).length;
});
const availableDossiersCount = computed(() => filteredDossiers.value.length);
</script>

<template>
    <Head title="Importation d'archives" />
    <AuthenticatedLayout>
        <v-container fluid>
            <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" rounded="lg" location="top">
                <v-icon start>{{ snackbar.color === 'success' ? 'mdi-check-circle' : snackbar.color === 'warning' ? 'mdi-alert' : 'mdi-alert-circle' }}</v-icon>
                {{ snackbar.text }}
                <template v-slot:actions>
                    <v-btn variant="text" color="white" @click="snackbar.show = false">Fermer</v-btn>
                </template>
            </v-snackbar>

            <v-row>
                <v-col cols="12">
                    <v-card class="rounded-xl">
                        <v-toolbar color="primary" dark>
                            <v-icon start>mdi-import</v-icon>
                            <v-toolbar-title class="font-weight-bold">Importation d'archives</v-toolbar-title>
                            <v-spacer></v-spacer>
                            <v-chip color="white" text-color="primary" v-if="selectedFiles.length > 0">
                                {{ selectedCount }} fichier(s) sélectionné(s)
                            </v-chip>
                        </v-toolbar>

                        <v-card-text class="pa-6">
                            <v-alert v-if="!canImport" type="error" variant="elevated" class="mb-4">
                                <v-icon start>mdi-alert-circle</v-icon>
                                Vous n'avez pas les droits pour importer des fichiers.
                            </v-alert>

                            <!-- CHOIX DU MODE -->
                            <div class="mb-6 d-flex align-center justify-center gap-4">
                                <v-btn-toggle v-model="importMode" color="primary" rounded="xl" mandatory :disabled="isLoading || scanning">
                                    <v-btn value="local" class="px-6 text-none">
                                        <v-icon start>mdi-monitor-arrow-down</v-icon>
                                        Depuis mon ordinateur (Glisser-Déposer)
                                    </v-btn>
                                    <v-btn value="server" class="px-6 text-none">
                                        <v-icon start>mdi-server-network</v-icon>
                                        Depuis le Serveur
                                    </v-btn>
                                </v-btn-toggle>
                            </div>

                            <v-row>
                                <!-- MODE LOCAL: DROPZONE -->
                                <v-col cols="12" v-if="importMode === 'local'">
                                    <div class="dropzone-area d-flex flex-column align-center justify-center py-12"
                                         :class="{ 'is-dragging': isDragging }" 
                                         @dragover.prevent="isDragging = true" 
                                         @dragleave.prevent="isDragging = false" 
                                         @drop.prevent="handleDrop">
                                         
                                        <v-icon size="64" :color="isDragging ? 'primary' : 'grey'">mdi-folder-upload-outline</v-icon>
                                        <div class="text-h6 mt-4 font-weight-bold" :class="isDragging ? 'text-primary' : 'text-grey-darken-2'">
                                            Glissez et déposez votre dossier complet ici
                                        </div>
                                        <div class="text-caption text-grey mt-2 text-center" style="max-width: 500px;">
                                            Le système lira automatiquement les sous-dossiers et détectera les dates pour l'importation de masse. (Ne glissez pas les fichiers un par un, glissez un dossier).
                                        </div>
                                    </div>
                                </v-col>

                                <!-- MODE SERVEUR -->
                                <v-col cols="12" md="8" v-if="importMode === 'server'">
                                    <v-text-field v-model="importPath" label="Chemin absolu sur le serveur"
                                        placeholder="/var/www/.../scans" variant="outlined" density="comfortable"
                                        prepend-inner-icon="mdi-folder" hint="Utilisé uniquement si l'application accède au même disque"
                                        persistent-hint :disabled="!canImport || isLoading">
                                        <template v-slot:append>
                                            <v-btn color="primary" @click="scanDirectory" :loading="scanning"
                                                :disabled="!importPath || !canImport || isLoading">
                                                Scanner
                                            </v-btn>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                
                                <v-col cols="12" :md="importMode === 'server' ? 4 : 12">
                                    <v-text-field v-model="dateDocument" label="Date de secours par défaut" type="date"
                                        variant="outlined" density="comfortable" prepend-inner-icon="mdi-calendar"
                                        hint="Utilisée si aucune date n'est détectée dans le nom du fichier" persistent-hint
                                        :disabled="!canImport || isLoading"></v-text-field>
                                </v-col>
                            </v-row>

                            <!-- PROGRESS BAR POUR LOCAL UPLOAD -->
                            <v-expand-transition>
                                <div v-if="isLoading && importMode === 'local' && uploadProgress.total > 0" class="mt-6 mb-4">
                                    <div class="d-flex justify-space-between mb-1">
                                        <span class="font-weight-bold text-primary">Importation en cours...</span>
                                        <span class="text-primary">{{ uploadProgress.current }} / {{ uploadProgress.total }} fichiers</span>
                                    </div>
                                    <v-progress-linear :model-value="(uploadProgress.current / uploadProgress.total) * 100" color="primary" height="20" rounded striped></v-progress-linear>
                                </div>
                            </v-expand-transition>

                            <!-- MAPPING DOSSIERS -->
                            <v-row v-if="folders.length > 0 && canImport">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <h4 class="text-subtitle-1 font-weight-bold mb-1">
                                        Assignation des dossiers détectés
                                    </h4>
                                    
                                    <div class="bg-grey-lighten-5 pa-3 rounded-lg mb-4">
                                        <div class="d-flex align-center flex-wrap gap-3">
                                            <span class="text-caption font-weight-medium text-grey">
                                                <v-icon size="small" class="mr-1">mdi-filter</v-icon>
                                                Filtrer les cibles par mois :
                                            </span>
                                            <v-select v-model="filterMoisId" :items="moisWithAnnee" item-title="label"
                                                item-value="id" label="Mois" variant="solo" density="compact"
                                                hide-details flat clearable style="max-width: 280px;">
                                                <template v-slot:prepend-inner>
                                                    <v-icon color="primary" size="small">mdi-calendar-month</v-icon>
                                                </template>
                                                <template v-slot:item="{ item, props: itemProps }">
                                                    <v-list-item v-bind="itemProps">
                                                        <div class="d-flex align-center">
                                                            <v-icon size="small" class="mr-2">mdi-calendar</v-icon>
                                                            {{ item.raw.label }}
                                                        </div>
                                                    </v-list-item>
                                                </template>
                                                <template v-slot:selection="{ item }">
                                                    <div class="d-flex align-center">
                                                        <v-icon size="small" class="mr-2">mdi-calendar</v-icon>
                                                        {{ item.raw.label }}
                                                    </div>
                                                </template>
                                            </v-select>
                                            <v-btn v-if="filterMoisId" variant="text" color="error" size="small"
                                                @click="resetFilter" prepend-icon="mdi-filter-off">
                                                Réinitialiser
                                            </v-btn>
                                            <v-spacer></v-spacer>
                                            <v-chip color="info" size="small">{{ availableDossiersCount }} cibles</v-chip>
                                        </div>
                                    </div>

                                    <v-row v-for="folder in folders" :key="folder" align="center" class="mb-2">
                                        <v-col cols="12" md="5">
                                            <v-card variant="outlined" class="pa-3" :class="folderMapping[folder] ? 'bg-green-lighten-5' : 'bg-grey-lighten-5'">
                                                <div class="d-flex align-center">
                                                    <v-icon :color="folderMapping[folder] ? 'success' : 'amber-darken-2'" size="28" class="mr-3">mdi-folder</v-icon>
                                                    <div>
                                                        <div class="font-weight-bold">{{ folder }}</div>
                                                        <div class="text-caption text-grey">
                                                            {{ countFilesByFolder(folder) }} fichier(s) — {{ countSelectedByFolder(folder) }} sélectionné(s)
                                                        </div>
                                                    </div>
                                                </div>
                                            </v-card>
                                        </v-col>
                                        <v-col cols="12" md="1" class="text-center">
                                            <v-icon :color="folderMapping[folder] ? 'success' : 'grey'" size="28">mdi-arrow-right</v-icon>
                                        </v-col>
                                        <v-col cols="12" md="6">
                                            <v-autocomplete v-model="folderMapping[folder]" :items="filteredDossiers"
                                                item-title="chemin" item-value="id" label="Dossier cible (optionnel)"
                                                variant="outlined" density="comfortable" prepend-inner-icon="mdi-folder-arrow-right" clearable
                                                :placeholder="`Laisser vide pour ignorer '${folder}'`">
                                                <template v-slot:item="{ item, props: itemProps }">
                                                    <v-list-item v-bind="itemProps">
                                                        <template v-slot:prepend><v-icon :color="item.raw.couleur">mdi-folder</v-icon></template>
                                                        <v-list-item-subtitle>{{ item.raw.chemin }}</v-list-item-subtitle>
                                                    </v-list-item>
                                                </template>
                                                <template v-slot:selection="{ item }">
                                                    <div class="d-flex align-center">
                                                        <v-icon :color="item.raw.couleur" size="18" class="mr-2">mdi-folder</v-icon>
                                                        <span class="text-truncate">{{ item.raw.chemin }}</span>
                                                    </div>
                                                </template>
                                            </v-autocomplete>
                                        </v-col>
                                    </v-row>

                                    <v-btn color="success" size="large" block class="mt-4" @click="importFiles" :loading="isLoading" :disabled="!canImportAction">
                                        <v-icon start>mdi-cloud-upload</v-icon>
                                        Importer {{ selectedCount }} fichier(s) vers {{ mappedCount }} dossier(s) cible(s)
                                    </v-btn>
                                </v-col>
                            </v-row>

                            <!-- Résultats -->
                            <v-row v-if="showResults && canImport">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <v-alert :type="errorCount > 0 ? 'warning' : 'success'" variant="elevated" class="mb-4" closable>
                                        <div class="d-flex align-center gap-2">
                                            <v-icon start>mdi-check-circle</v-icon>
                                            <span class="font-weight-bold">{{ importedCount }}</span> importé(s) | 
                                            <span class="font-weight-bold text-error">{{ errorCount }}</span> erreur(s)
                                            <span v-if="duplicateCount > 0" class="mx-1">|</span>
                                            <span v-if="duplicateCount > 0" class="font-weight-bold text-warning">{{ duplicateCount }} doublon(s)</span>
                                        </div>
                                    </v-alert>

                                    <v-table v-if="results.length > 0" hover density="compact">
                                        <thead>
                                            <tr>
                                                <th>Fichier</th>
                                                <th>Statut</th>
                                                <th>Dossier</th>
                                                <th>Détail</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in results" :key="result.file">
                                                <td>{{ result.file }}</td>
                                                <td>
                                                    <v-chip :color="result.success ? 'success' : result.is_duplicate ? 'warning' : 'error'" size="x-small">
                                                        {{ result.success ? 'Succès' : result.is_duplicate ? 'Doublon' : 'Échec' }}
                                                    </v-chip>
                                                </td>
                                                <td>{{ result.dossier || '-' }}</td>
                                                <td class="text-caption text-error">{{ result.error || '-' }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-col>
                            </v-row>

                            <!-- Liste fichiers -->
                            <v-row v-if="selectedFiles.length > 0 && canImport && !isLoading">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <div class="d-flex justify-space-between align-center mb-3">
                                        <h4 class="text-subtitle-1 font-weight-bold">Fichiers trouvés ({{ selectedFiles.length }})</h4>
                                        <div>
                                            <v-btn size="small" variant="text" @click="toggleAllFiles(true)">Tout sélectionner</v-btn>
                                            <v-btn size="small" variant="text" @click="toggleAllFiles(false)">Tout désélectionner</v-btn>
                                        </div>
                                    </div>

                                    <div v-for="folder in folders" :key="folder" class="mb-3">
                                        <div class="d-flex align-center justify-space-between pa-3 rounded-lg mb-1"
                                            :class="folderMapping[folder] ? 'bg-green-lighten-5' : 'bg-grey-lighten-4'"
                                            @click="toggleFolder(folder)" style="cursor: pointer;">
                                            <div class="d-flex align-center gap-2">
                                                <v-icon color="amber-darken-2">mdi-folder</v-icon>
                                                <span class="font-weight-medium">{{ folder }}</span>
                                                <v-chip size="x-small" color="grey">{{ countFilesByFolder(folder) }} fichiers</v-chip>
                                                <v-icon v-if="folderMapping[folder]" color="success" size="18">mdi-check-circle</v-icon>
                                                <span v-if="folderMapping[folder]" class="text-caption text-success">
                                                    → {{ getDossierById(folderMapping[folder])?.chemin || '?' }}
                                                </span>
                                            </div>
                                            <div class="d-flex align-center">
                                                <v-btn size="x-small" variant="text" @click.stop="toggleFolderFiles(folder, true)">Select</v-btn>
                                                <v-icon>{{ expandedFolders.has(folder) ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                                            </div>
                                        </div>

                                        <v-table v-if="expandedFolders.has(folder)" hover density="compact">
                                            <thead>
                                                <tr class="bg-grey-lighten-4">
                                                    <th style="width:40px"></th>
                                                    <th>Nom</th>
                                                    <th>Ext</th>
                                                    <th>Date détectée</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="file in selectedFiles.filter(f => f.folder === folder)" :key="file.path">
                                                    <td><v-checkbox v-model="file.selected" hide-details density="compact" :disabled="file.exists"></v-checkbox></td>
                                                    <td>{{ file.name }}</td>
                                                    <td><v-chip size="x-small" color="primary">{{ file.extension.toUpperCase() }}</v-chip></td>
                                                    <td class="text-primary font-weight-bold">{{ file.detected_date || 'Non détectée' }}</td>
                                                    <td>
                                                        <v-chip v-if="file.exists" color="warning" size="x-small">Existant</v-chip>
                                                        <v-chip v-else color="success" size="x-small">À importer</v-chip>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>
                                    </div>
                                </v-col>
                            </v-row>

                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>
    </AuthenticatedLayout>
</template>

<style scoped>
.v-table :deep(th) {
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
}
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.dropzone-area {
    border: 3px dashed #CFD8DC;
    border-radius: 16px;
    transition: all 0.3s ease;
    background-color: #FAFAFA;
}
.dropzone-area.is-dragging {
    border-color: #1976D2;
    background-color: #E3F2FD;
    transform: scale(1.02);
}
</style>
