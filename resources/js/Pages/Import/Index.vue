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

// Vérifier les permissions
const canImport = computed(() => props.permissions?.can_import || false);

const importPath = ref('');
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

const hasUnmappedFolders = computed(() =>
    folders.value.some(f =>
        !folderMapping.value[f] &&
        selectedFiles.value.some(sf => sf.folder === f && sf.selected && !sf.exists)
    )
);

const resetFilter = () => {
    filterMoisId.value = null;
};

const scanDirectory = async () => {
    if (!canImport.value) {
        showNotification('Vous n\'avez pas les droits pour importer.', 'error');
        return;
    }

    if (!importPath.value) {
        showNotification('Veuillez entrer le chemin du dossier à importer.', 'warning');
        return;
    }

    scanning.value = true;
    selectedFiles.value = [];
    folders.value = [];
    folderMapping.value = {};
    expandedFolders.value = new Set();

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

        const mapping = {};
        folders.value.forEach(f => { mapping[f] = null; });
        folderMapping.value = mapping;
        folders.value.forEach(f => expandedFolders.value.add(f));

        const existingCount = selectedFiles.value.filter(f => f.exists).length;
        showNotification(
            `${selectedFiles.value.length} fichier(s) dans ${folders.value.length} dossier(s) détecté(s).` +
            (existingCount > 0 ? ` ${existingCount} déjà existant(s).` : ''),
            'success'
        );
    } catch (error) {
        console.error(error);
        showNotification('Erreur lors du scan.', 'error');
    } finally {
        scanning.value = false;
    }
};

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

    if (!canImport.value) {
        showNotification('Vous n\'avez pas les droits pour importer.', 'error');
        return;
    }

    if (selectedCount.value === 0) {
        showNotification('Aucun fichier sélectionné.', 'warning');
        return;
    }

    isLoading.value = true;
    showResults.value = false;
    results.value = [];
    importedCount.value = 0;
    errorCount.value = 0;
    duplicateCount.value = 0;

    try {
        const filesToImport = selectedFiles.value.filter(f => f.selected && !f.exists).map(f => ({
            name: f.name,
            path: f.path,
            folder: f.folder,
            extension: f.extension,
            size: f.size,
            detected_date: f.detected_date,
        }));

        const response = await axios.post('/import/process', {
            files: filesToImport,
            base_path: importPath.value,
            date_document: dateDocument.value,
            folder_mapping: folderMapping.value,
        });

        results.value = response.data.results || [];
        importedCount.value = response.data.imported || 0;
        errorCount.value = response.data.errors || 0;
        duplicateCount.value = response.data.duplicates || 0;
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
    } catch (error) {
        console.error(error);
        const msg = error.response?.data?.error || error.message || 'Erreur inconnue';
        showNotification('Erreur : ' + msg, 'error');
    } finally {
        isLoading.value = false;
    }
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

const availableDossiersCount = computed(() => {
    return filteredDossiers.value.length;
});
</script>

<template>

    <Head title="Importation d'archives" />
    <AuthenticatedLayout>
        <v-container fluid>
            <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" rounded="lg"
                location="top">
                <v-icon start>{{ snackbar.color === 'success' ? 'mdi-check-circle' : snackbar.color === 'warning' ?
                    'mdi-alert' : 'mdi-alert-circle' }}</v-icon>
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

                            <!-- Message si pas de droits -->
                            <v-alert v-if="!canImport" type="error" variant="elevated" class="mb-4">
                                <v-icon start>mdi-alert-circle</v-icon>
                                Vous n'avez pas les droits pour accéder à l'importation. Seuls les administrateurs
                                peuvent importer
                                des fichiers.
                            </v-alert>

                            <!-- Chemin + date -->
                            <v-row>
                                <v-col cols="12" md="8">
                                    <v-text-field v-model="importPath" label="Chemin du dossier à importer"
                                        placeholder="C:/Users/.../mes_archives" variant="outlined" density="comfortable"
                                        prepend-inner-icon="mdi-folder" hint="Exemple: C:/Users/.../mes_archives"
                                        persistent-hint :disabled="!canImport">
                                        <template v-slot:append>
                                            <v-btn color="primary" @click="scanDirectory" :loading="scanning"
                                                :disabled="!importPath || !canImport">
                                                Scanner
                                            </v-btn>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field v-model="dateDocument" label="Date par défaut" type="date"
                                        variant="outlined" density="comfortable" prepend-inner-icon="mdi-calendar"
                                        hint="Utilisée si aucune date n'est détectée" persistent-hint
                                        :disabled="!canImport"></v-text-field>
                                </v-col>
                            </v-row>

                            <!-- MAPPING DOSSIERS -->
                            <v-row v-if="folders.length > 0 && canImport">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <h4 class="text-subtitle-1 font-weight-bold mb-1">
                                        Assignation des dossiers détectés
                                    </h4>
                                    <p class="text-caption text-grey mb-4">
                                        Pour chaque dossier source, choisissez le dossier cible.
                                        Les dossiers sans cible assignée seront ignorés lors de l'importation.
                                    </p>

                                    <!-- Filtre unique pour les dossiers cibles -->
                                    <div class="bg-grey-lighten-5 pa-3 rounded-lg mb-4">
                                        <div class="d-flex align-center flex-wrap gap-3">
                                            <span class="text-caption font-weight-medium text-grey">
                                                <v-icon size="small" class="mr-1">mdi-filter</v-icon>
                                                Filtrer les dossiers cibles par mois :
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
                                            <v-chip color="info" size="small">
                                                {{ availableDossiersCount }} dossier(s) disponible(s)
                                            </v-chip>
                                            <v-chip v-if="filterMoisId" color="success" size="small">
                                                Filtre actif
                                            </v-chip>
                                        </div>
                                    </div>

                                    <v-row v-for="folder in folders" :key="folder" align="center" class="mb-2">
                                        <v-col cols="12" md="5">
                                            <v-card variant="outlined" class="pa-3"
                                                :class="folderMapping[folder] ? 'bg-green-lighten-5' : 'bg-grey-lighten-5'">
                                                <div class="d-flex align-center">
                                                    <v-icon
                                                        :color="folderMapping[folder] ? 'success' : 'amber-darken-2'"
                                                        size="28" class="mr-3">mdi-folder</v-icon>
                                                    <div>
                                                        <div class="font-weight-bold">{{ folder }}</div>
                                                        <div class="text-caption text-grey">
                                                            {{ countFilesByFolder(folder) }} fichier(s) —
                                                            {{ countSelectedByFolder(folder) }} sélectionné(s)
                                                        </div>
                                                        <v-chip v-if="!folderMapping[folder]" size="x-small"
                                                            color="warning" class="mt-1">
                                                            Sera ignoré
                                                        </v-chip>
                                                        <v-chip v-else size="x-small" color="success" class="mt-1">
                                                            Sera importé
                                                        </v-chip>
                                                    </div>
                                                </div>
                                            </v-card>
                                        </v-col>

                                        <v-col cols="12" md="1" class="text-center">
                                            <v-icon :color="folderMapping[folder] ? 'success' : 'grey'" size="28">
                                                mdi-arrow-right
                                            </v-icon>
                                        </v-col>

                                        <v-col cols="12" md="6">
                                            <v-autocomplete v-model="folderMapping[folder]" :items="filteredDossiers"
                                                item-title="chemin" item-value="id" label="Dossier cible (optionnel)"
                                                variant="outlined" density="comfortable"
                                                prepend-inner-icon="mdi-folder-arrow-right" clearable
                                                :placeholder="`Laisser vide pour ignorer '${folder}'`"
                                                :no-data-text="filterMoisId ? 'Aucun dossier pour ce mois' : 'Aucun dossier disponible'">
                                                <template v-slot:item="{ item, props: itemProps }">
                                                    <v-list-item v-bind="itemProps">
                                                        <template v-slot:prepend>
                                                            <v-icon :color="item.raw.couleur">mdi-folder</v-icon>
                                                        </template>
                                                        <v-list-item-subtitle>{{ item.raw.chemin
                                                        }}</v-list-item-subtitle>
                                                    </v-list-item>
                                                </template>
                                                <template v-slot:selection="{ item }">
                                                    <div class="d-flex align-center">
                                                        <v-icon :color="item.raw.couleur" size="18"
                                                            class="mr-2">mdi-folder</v-icon>
                                                        <span class="text-truncate" style="max-width: 280px;">{{
                                                            item.raw.chemin }}</span>
                                                    </div>
                                                </template>
                                            </v-autocomplete>
                                            <div v-if="folderMapping[folder]" class="text-caption text-grey mt-1">
                                                <v-icon size="x-small" class="mr-1">mdi-information</v-icon>
                                                Dossier sélectionné :
                                                {{ getDossierById(folderMapping[folder])?.chemin || 'ID: ' +
                                                    folderMapping[folder]
                                                }}
                                            </div>
                                        </v-col>
                                    </v-row>

                                    <v-alert v-if="hasUnmappedFolders && canImportAction" type="info" variant="tonal"
                                        density="compact" class="mb-4 mt-2">
                                        <v-icon start size="small">mdi-information</v-icon>
                                        Les dossiers sans dossier cible assigné seront ignorés lors de l'importation.
                                    </v-alert>

                                    <v-card v-if="folders.length > 0" variant="outlined"
                                        class="pa-3 mb-3 bg-grey-lighten-4">
                                        <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                                            <div class="text-caption">
                                                <span class="font-weight-bold">Résumé :</span>
                                                {{ mappedCount }} dossier(s) mappé(s) sur {{ folders.length }}
                                                dossier(s) détecté(s)
                                                <span v-if="filterMoisId" class="text-primary">
                                                    (filtre actif : {{moisWithAnnee.find(m => m.id ===
                                                        filterMoisId)?.label}})
                                                </span>
                                            </div>
                                            <div>
                                                <v-chip size="small" color="success" v-if="mappedCount > 0">
                                                    {{ mappedCount }} mappé(s)
                                                </v-chip>
                                                <v-chip size="small" color="warning"
                                                    v-if="folders.length - mappedCount > 0">
                                                    {{ folders.length - mappedCount }} non mappé(s)
                                                </v-chip>
                                            </div>
                                        </div>
                                    </v-card>

                                    <v-btn color="success" size="large" block class="mt-2" @click="importFiles"
                                        :loading="isLoading" :disabled="!canImportAction">
                                        <v-icon start>mdi-cloud-upload</v-icon>
                                        Importer {{ selectedCount }} fichier(s) dans
                                        {{ mappedCount }} dossier(s)
                                    </v-btn>

                                    <v-alert v-if="!canImportAction && selectedCount > 0" type="warning" variant="tonal"
                                        density="compact" class="mt-3">
                                        Assignez au moins un dossier cible pour pouvoir lancer l'importation.
                                    </v-alert>
                                </v-col>
                            </v-row>

                            <!-- Résultats -->
                            <v-row v-if="showResults && canImport">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <v-alert :type="errorCount > 0 ? 'warning' : 'success'" variant="elevated"
                                        class="mb-4" closable>
                                        <div class="d-flex align-center flex-wrap gap-2">
                                            <v-icon start>mdi-check-circle</v-icon>
                                            <span class="font-weight-bold">{{ importedCount }}</span> importé(s)
                                            <span class="mx-1">|</span>
                                            <span class="font-weight-bold text-error">{{ errorCount }}</span> erreur(s)
                                            <span v-if="duplicateCount > 0" class="mx-1">|</span>
                                            <span v-if="duplicateCount > 0" class="font-weight-bold text-warning">{{
                                                duplicateCount
                                            }}</span>
                                            <span v-if="duplicateCount > 0"> doublon(s)</span>
                                        </div>
                                    </v-alert>

                                    <v-table v-if="results.length > 0">
                                        <thead>
                                            <tr>
                                                <th>Fichier</th>
                                                <th>Statut</th>
                                                <th>Date</th>
                                                <th>Référence</th>
                                                <th>Dossier</th>
                                                <th>Détail</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in results" :key="result.file">
                                                <td>{{ result.file }}</td>
                                                <td>
                                                    <v-chip
                                                        :color="result.success ? 'success' : result.is_duplicate ? 'warning' : 'error'"
                                                        size="small">
                                                        {{ result.success ? 'Succès' : result.is_duplicate ? 'Doublon' :
                                                            'Échec' }}
                                                    </v-chip>
                                                </td>
                                                <td>{{ result.date_document || '-' }}</td>
                                                <td>{{ result.reference || '-' }}</td>
                                                <td>{{ result.dossier || '-' }}</td>
                                                <td class="text-caption text-error">{{ result.error || '-' }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-col>
                            </v-row>

                            <!-- Liste fichiers par dossier -->
                            <v-row v-if="selectedFiles.length > 0 && canImport">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <div class="d-flex justify-space-between align-center mb-3">
                                        <h4 class="text-subtitle-1 font-weight-bold">
                                            Fichiers trouvés ({{ selectedFiles.length }})
                                        </h4>
                                        <div>
                                            <v-btn size="small" variant="text" @click="toggleAllFiles(true)">Tout
                                                sélectionner</v-btn>
                                            <v-btn size="small" variant="text" @click="toggleAllFiles(false)">Tout
                                                désélectionner</v-btn>
                                        </div>
                                    </div>

                                    <div v-for="folder in folders" :key="folder" class="mb-3">
                                        <div class="d-flex align-center justify-space-between pa-3 rounded-lg mb-1"
                                            :class="folderMapping[folder] ? 'bg-green-lighten-5' : 'bg-grey-lighten-4'"
                                            @click="toggleFolder(folder)" style="cursor: pointer;">
                                            <div class="d-flex align-center gap-2">
                                                <v-icon color="amber-darken-2">mdi-folder</v-icon>
                                                <span class="font-weight-medium">{{ folder }}</span>
                                                <v-chip size="x-small" color="grey">
                                                    {{ countFilesByFolder(folder) }} fichiers
                                                </v-chip>
                                                <v-icon v-if="folderMapping[folder]" color="success"
                                                    size="18">mdi-check-circle</v-icon>
                                                <span v-if="folderMapping[folder]" class="text-caption text-success">
                                                    → {{ getDossierById(folderMapping[folder])?.chemin || '?' }}
                                                </span>
                                                <v-chip v-else size="x-small" color="warning">Sera ignoré</v-chip>
                                            </div>
                                            <div class="d-flex align-center">
                                                <v-btn size="x-small" variant="text"
                                                    @click.stop="toggleFolderFiles(folder, true)">Sélectionner</v-btn>
                                                <v-btn size="x-small" variant="text"
                                                    @click.stop="toggleFolderFiles(folder, false)">Désélectionner</v-btn>
                                                <v-icon>{{ expandedFolders.has(folder) ? 'mdi-chevron-up' :
                                                    'mdi-chevron-down' }}</v-icon>
                                            </div>
                                        </div>

                                        <v-table v-if="expandedFolders.has(folder)" hover>
                                            <thead>
                                                <tr class="bg-grey-lighten-4">
                                                    <th style="width:50px"></th>
                                                    <th>Nom</th>
                                                    <th>Extension</th>
                                                    <th>Taille</th>
                                                    <th>Date détectée</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="file in selectedFiles.filter(f => f.folder === folder)"
                                                    :key="file.path" :class="{ 'file-exists': file.exists }">
                                                    <td>
                                                        <v-checkbox v-model="file.selected" hide-details
                                                            :disabled="file.exists"></v-checkbox>
                                                    </td>
                                                    <td>
                                                        {{ file.name }}
                                                        <v-icon v-if="file.exists" color="warning"
                                                            size="small">mdi-alert</v-icon>
                                                    </td>
                                                    <td>
                                                        <v-chip size="x-small" color="primary">
                                                            {{ file.extension.toUpperCase() }}
                                                        </v-chip>
                                                    </td>
                                                    <td>{{ formatSize(file.size) }}</td>
                                                    <td>
                                                        <span v-if="file.detected_date"
                                                            class="font-weight-bold text-primary">
                                                            {{ file.detected_date }}
                                                        </span>
                                                        <span v-else class="text-grey">Non détectée</span>
                                                    </td>
                                                    <td>
                                                        <v-chip v-if="file.exists" color="warning" size="x-small">
                                                            Déjà existant
                                                        </v-chip>
                                                        <v-chip v-else color="success" size="x-small">
                                                            À importer
                                                        </v-chip>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>
                                    </div>
                                </v-col>
                            </v-row>

                            <v-row v-if="selectedFiles.length === 0 && !scanning">
                                <v-col cols="12">
                                    <div class="text-center py-12">
                                        <v-icon size="64" color="grey-lighten-1">mdi-folder-open</v-icon>
                                        <div class="text-h6 text-grey-lighten-1 mt-4">Aucun fichier scanné</div>
                                        <div class="text-caption text-grey">
                                            Entrez le chemin d'un dossier et cliquez sur "Scanner"
                                        </div>
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
    letter-spacing: 0.5px;
}

.file-exists {
    background-color: rgba(255, 193, 7, 0.08) !important;
}

.gap-2 {
    gap: 8px;
}
</style>
