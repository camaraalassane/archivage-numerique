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
const targetMoisId = ref(null);
const targetAnneeId = ref(null);
const monthMappings = ref({}); // { "01_Janvier": 1 } (maps monthFolder string to a mois_id)

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

const moisWithAnnee = computed(() => {
    return props.mois.map(m => {
        const annee = props.annees.find(a => a.id === m.annee_id);
        return {
            ...m,
            label: annee ? `${annee.annee} - ${m.nom_mois}` : m.nom_mois,
        };
    });
});

const moisOfSelectedAnnee = computed(() => {
    if (!targetAnneeId.value) return [];
    return props.mois.filter(m => m.annee_id === targetAnneeId.value).map(m => ({
        ...m,
        label: m.nom_mois
    }));
});

const uniqueMonthFolders = computed(() => {
    const s = new Set();
    selectedFiles.value.forEach(f => {
        if (f.month_folder) s.add(f.month_folder);
    });
    return Array.from(s).sort();
});

const selectedCount = computed(() =>
    selectedFiles.value.filter(f => f.selected && !f.exists).length
);

const canImportAction = computed(() => {
    if (!canImport.value) return false;
    if (selectedCount.value === 0) return false;
    if (!targetMoisId.value && !targetAnneeId.value) return false;
    
    if (targetAnneeId.value) {
        // En mode année, il faut au moins un dossier mappé
        const hasMapping = uniqueMonthFolders.value.some(mFolder => monthMappings.value[mFolder]);
        if (!hasMapping) return false;
    }
    
    return true;
});

const autoMapMonths = () => {
    if (!targetAnneeId.value) return;
    uniqueMonthFolders.value.forEach(mFolder => {
        if (!monthMappings.value[mFolder]) {
            // Tentative de détection (ex: "01_Janvier" -> cherche "Janvier" ou "1")
            const lowerFolder = mFolder.toLowerCase();
            const match = moisOfSelectedAnnee.value.find(m => 
                lowerFolder.includes(m.nom_mois.toLowerCase()) || 
                lowerFolder.startsWith(m.mois.toString().padStart(2, '0'))
            );
            if (match) {
                monthMappings.value[mFolder] = match.id;
            }
        }
    });
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
        showNotification(`${selectedFiles.value.length} fichier(s) détecté(s).`, 'success');
        expandedFolders.value = new Set(folders.value);
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
    let m = filename.match(/(19\d{2}|20\d{2})[-_](0[1-9]|1[0-2])[-_](0[1-9]|[12]\d|3[01])/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    m = filename.match(/(?<!\d)(19\d{2}|20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?!\d)/);
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
                                // Le relPath contient tous les dossiers depuis l'élément glissé
                                const relPath = path ? `${path}/${file.name}` : file.name;
                                const folderParts = relPath.split('/');
                                
                                // En mode glisser-déposer, si l'utilisateur glisse le dossier Année,
                                // folderParts[0] = Année, folderParts[1] = Mois, folderParts[2] = Dossier
                                // S'il glisse les dossiers Mois,
                                let monthFolder = null;
                                let folder = 'Racine';
                                
                                if (targetMoisId.value) {
                                    // Mode "Mois"
                                    if (folderParts.length >= 2) {
                                        if (folderParts[0].match(/janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout|septembre|octobre|novembre|décembre|decembre|[0-9]{2}_/i)) {
                                            monthFolder = folderParts[0];
                                            folder = folderParts.length >= 2 ? folderParts[1] : 'Racine';
                                        } else {
                                            monthFolder = null;
                                            folder = folderParts[0];
                                        }
                                    } else {
                                        monthFolder = null;
                                        folder = 'Racine';
                                    }
                                } else {
                                    // Mode "Annee"
                                    if (folderParts.length >= 3) {
                                        if (folderParts[0].toUpperCase().includes('ANNEE') || folderParts[0].match(/20\d{2}/)) {
                                            monthFolder = folderParts[1];
                                            folder = folderParts.length >= 4 ? folderParts[2] : 'Racine';
                                        } else {
                                            // S'ils ont glissé directement les mois
                                            monthFolder = folderParts[0];
                                            folder = folderParts[1];
                                        }
                                    } else if (folderParts.length === 2) {
                                        monthFolder = folderParts[0];
                                        folder = 'Racine';
                                    } else {
                                        folder = folderParts[0];
                                    }
                                }
                        
                        tempFiles.push({
                            fileObj: file,
                            name: file.name,
                            path: relPath,
                            folder: folder,
                            month_folder: monthFolder,
                            extension: ext,
                            size: file.size,
                            detected_date: detectDateFromFilename(file.name),
                            os_date: new Date(file.lastModified).toISOString().split('T')[0],
                            exists: false,
                            selected: true
                        });
                    }
                    resolve();
                });
            });
        } else if (entry.isDirectory) {
            return new Promise(resolve => {
                const dirReader = entry.createReader();
                let hasEntries = false;
                
                const readAllEntries = () => {
                    dirReader.readEntries(async entries => {
                        if (entries.length === 0) {
                            if (!hasEntries) {
                                // Dossier complètement vide
                                const relPath = path ? `${path}/${entry.name}` : entry.name;
                                const folderParts = relPath.split('/');
                                
                                let monthFolder = null;
                                let folder = 'Racine';
                                
                                if (targetMoisId.value) {
                                    // Mode "Mois": the user drags subfolders directly, OR month folders.
                                    if (folderParts.length >= 2) {
                                        if (folderParts[0].match(/janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout|septembre|octobre|novembre|décembre|decembre|[0-9]{2}_/i)) {
                                            // e.g. "07_Juillet/Dossier_A/file.pdf"
                                            monthFolder = folderParts[0];
                                            folder = folderParts.length >= 2 ? folderParts[1] : 'Racine';
                                        } else {
                                            // e.g. "Dossier_A/file.pdf"
                                            monthFolder = null; // We already have targetMoisId, so we don't care
                                            folder = folderParts[0];
                                        }
                                    } else {
                                        // e.g. "file.pdf"
                                        monthFolder = null;
                                        folder = 'Racine';
                                    }
                                } else {
                                    // Mode "Annee"
                                    if (folderParts.length >= 2) {
                                        if (folderParts[0].toUpperCase().includes('ANNEE') || folderParts[0].match(/20\d{2}/)) {
                                            monthFolder = folderParts[1] || null;
                                            folder = folderParts.length >= 3 ? folderParts[2] : 'Racine';
                                        } else {
                                            monthFolder = folderParts[0];
                                            folder = folderParts.length >= 2 ? folderParts[1] : 'Racine';
                                        }
                                    } else if (folderParts.length === 1) {
                                        monthFolder = folderParts[0];
                                    }
                                }
                                
                                tempFiles.push({
                                    fileObj: new File([""], "empty.txt", { type: "text/plain" }),
                                    name: "empty.txt", // Nom factice pour affichage et backend
                                    path: relPath + "/empty.txt",
                                    folder: folder,
                                    month_folder: monthFolder,
                                    extension: "txt",
                                    size: 0,
                                    detected_date: null,
                                    os_date: null,
                                    exists: false,
                                    is_empty_dir: true,
                                    selected: true
                                });
                            }
                            resolve();
                        } else {
                            hasEntries = true;
                            for (const child of entries) {
                                await readEntry(child, path ? `${path}/${entry.name}` : entry.name);
                            }
                            readAllEntries();
                        }
                    });
                };
                readAllEntries();
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
    
    expandedFolders.value = new Set(folders.value);
    scanning.value = false;
    showNotification(`${tempFiles.length} fichier(s) détecté(s) localement.`, 'success');
};

const resetLists = () => {
    selectedFiles.value = [];
    localFilesData.value = [];
    folders.value = [];
    expandedFolders.value = new Set();
    showResults.value = false;
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

    let filesToImport = selectedFiles.value.filter(f => f.selected && !f.exists);

    if (targetAnneeId.value) {
        // Ignorer les fichiers dont le mois n'a pas été associé
        filesToImport = filesToImport.filter(f => monthMappings.value[f.month_folder]);
    }
    
    if (filesToImport.length === 0) {
        showNotification('Aucun fichier sélectionné avec un mois cible valide.', 'warning');
        isLoading.value = false;
        return;
    }

    if (importMode.value === 'server') {
        // Envoi au serveur
        try {
            const response = await axios.post('/import/process', {
                files: filesToImport.map(f => ({ 
                    name: f.name, 
                    path: f.path, 
                    folder: f.folder, 
                    extension: f.extension, 
                    size: f.size, 
                    detected_date: f.detected_date,
                    is_empty_dir: f.is_empty_dir || false,
                    // Si on est en mode année, on envoie le mois_id mappé pour chaque fichier !
                    mois_id: targetAnneeId.value ? monthMappings.value[f.month_folder] : targetMoisId.value
                })),
                base_path: importPath.value,
                date_document: dateDocument.value,
                mois_id: targetMoisId.value, // Global fallback
            });
            handleProcessResponse(response.data);
        } catch (error) {
            handleError(error);
        }
    } else {
        // Mode local : upload séquentiel
        uploadProgress.value = { current: 0, total: filesToImport.length };
        
        for (const fileMeta of filesToImport) {
            const actualFile = localFilesData.value.find(f => f.path === fileMeta.path)?.fileObj;
            if (!actualFile) {
                errorCount.value++;
                results.value.push({ file: fileMeta.name, success: false, error: 'Fichier local introuvable' });
                continue;
            }

            const formData = new FormData();
            formData.append('file', actualFile);
            formData.append('folder', fileMeta.folder);
            formData.append('relative_path', fileMeta.path);
            
            // Injection du mois_id précis basé sur le mapping si on est en mode Année
            const finalMoisId = targetAnneeId.value ? monthMappings.value[fileMeta.month_folder] : targetMoisId.value;
            if (finalMoisId) formData.append('mois_id', finalMoisId);
            
            formData.append('fallback_date', dateDocument.value);
            if (fileMeta.detected_date) formData.append('detected_date', fileMeta.detected_date);
            if (fileMeta.os_date) formData.append('os_date', fileMeta.os_date);

            if (fileMeta.is_empty_dir) formData.append('is_empty_dir', '1');
            
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
                    let errMsg = error.response?.data?.error || 'Erreur serveur';
                    if (error.response?.data?.errors) {
                        errMsg = Object.values(error.response.data.errors).flat().join(', ');
                    }
                    results.value.push({ file: fileMeta.name, success: false, error: errMsg });
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
                                    <!-- SELECTION DU MOIS CIBLE -->
                                    <v-divider class="my-4"></v-divider>
                                    <h4 class="text-subtitle-1 font-weight-bold mb-1">
                                        Configuration de l'importation
                                    </h4>
                                    
                                    <div class="bg-grey-lighten-5 pa-4 rounded-lg mb-4">
                                        <div class="d-flex align-center flex-wrap gap-4">
                                            <div style="flex: 1; min-width: 300px;">
                                                <v-row>
                                                    <v-col cols="12" md="6">
                                                        <div class="text-caption font-weight-medium text-grey mb-1">
                                                            <v-icon size="small" class="mr-1">mdi-calendar-range</v-icon>
                                                            Importer par Année (Détection auto des mois) :
                                                        </div>
                                                        <v-select v-model="targetAnneeId" :items="props.annees" item-title="annee"
                                                            item-value="id" label="Année cible" variant="outlined" density="comfortable"
                                                            hide-details @update:modelValue="val => { if (val) { targetMoisId = null; autoMapMonths(); } }"
                                                            clearable>
                                                            <template v-slot:prepend-inner>
                                                                <v-icon color="primary" size="small">mdi-calendar-range</v-icon>
                                                            </template>
                                                        </v-select>
                                                    </v-col>
                                                    
                                                    <v-col cols="12" md="6">
                                                        <div class="text-caption font-weight-medium text-grey mb-1">
                                                            <v-icon size="small" class="mr-1">mdi-calendar-month</v-icon>
                                                            OU Importer dans un Mois précis :
                                                        </div>
                                                        <v-select v-model="targetMoisId" :items="moisWithAnnee" item-title="label"
                                                            item-value="id" label="Mois cible" variant="outlined" density="comfortable"
                                                            hide-details @update:modelValue="targetMoisId ? targetAnneeId = null : null"
                                                            clearable>
                                                            <template v-slot:prepend-inner>
                                                                <v-icon color="primary" size="small">mdi-calendar-month</v-icon>
                                                            </template>
                                                        </v-select>
                                                    </v-col>
                                                </v-row>
                                                
                                                <!-- UI Mapping des mois détectés -->
                                                <div v-if="targetAnneeId && uniqueMonthFolders.length > 0" class="mt-4 pt-3 border-t">
                                                    <div class="text-caption font-weight-medium text-primary mb-2">
                                                        <v-icon size="small" class="mr-1">mdi-link-variant</v-icon>
                                                        Correspondance des sous-dossiers
                                                    </div>
                                                    <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                                                        Laissez vide pour <strong>ignorer</strong> l'importation de ce dossier.
                                                    </v-alert>
                                                    
                                                    <v-row v-for="mFolder in uniqueMonthFolders" :key="mFolder" align="center" class="mb-1">
                                                        <v-col cols="5" class="py-1">
                                                            <div class="text-body-2 text-truncate" :title="mFolder || 'Racine'">
                                                                <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-folder</v-icon>
                                                                {{ mFolder || 'Aucun dossier parent' }}
                                                            </div>
                                                        </v-col>
                                                        <v-col cols="7" class="py-1">
                                                            <v-select
                                                                v-model="monthMappings[mFolder]"
                                                                :items="moisOfSelectedAnnee"
                                                                item-title="label"
                                                                item-value="id"
                                                                density="compact"
                                                                variant="outlined"
                                                                hide-details
                                                                placeholder="Choisir le mois..."
                                                            ></v-select>
                                                        </v-col>
                                                    </v-row>
                                                </div>
                                            </div>
                                            
                                            <div style="flex: 2;">
                                                <v-alert type="info" variant="tonal" class="mb-0" density="compact">
                                                    Les dossiers détectés seront <strong>créés automatiquement</strong> dans le mois sélectionné s'ils n'existent pas. Les doublons seront ignorés.
                                                </v-alert>
                                            </div>
                                        </div>
                                    </div>

                                    <v-btn color="success" size="large" block class="mt-4" @click="importFiles" :loading="isLoading" :disabled="!canImportAction">
                                        <v-icon start>mdi-cloud-upload</v-icon>
                                        Importer {{ selectedCount }} fichier(s) vers le mois sélectionné
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
                                        <div class="d-flex align-center justify-space-between pa-3 rounded-lg mb-1 bg-grey-lighten-4"
                                            @click="toggleFolder(folder)" style="cursor: pointer;">
                                            <div class="d-flex align-center gap-2">
                                                <v-icon color="amber-darken-2">mdi-folder</v-icon>
                                                <span class="font-weight-medium">{{ folder }}</span>
                                                <v-chip size="x-small" color="grey">{{ countFilesByFolder(folder) }} fichiers</v-chip>
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
