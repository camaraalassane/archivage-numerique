<!-- resources/js/Pages/Dossiers/Index.vue -->
<script setup>
import { ref, watch, computed, reactive } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    dossiers: { type: Array, required: true },
    annees: { type: Array, default: () => [] },
    mois: { type: Array, default: () => [] },
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

// Permissions
const canManage = computed(() => props.permissions?.can_manage_dossiers || false);

const dialog = ref(false);
const deleteDialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const itemToDelete = ref(null);

// MODE MULTIPLE & SCAN DISQUE
const multipleMode = ref(false);
const dossierNamesInput = ref('');
const multipleDossiersList = ref([]);
const folderInput = ref(null);
const scanning = ref(false);

const couleurs = [
    { name: 'Bleu', value: '#1976D2' },
    { name: 'Vert', value: '#388E3C' },
    { name: 'Orange', value: '#F57C00' },
    { name: 'Rouge', value: '#D32F2F' },
    { name: 'Violet', value: '#7B1FA2' },
    { name: 'Turquoise', value: '#00897B' },
    { name: 'Cyan', value: '#00BCD4' },
    { name: 'Rose', value: '#E91E63' }
];

// Synchroniser la saisie manuelle
watch(dossierNamesInput, (newVal) => {
    if (!newVal || !newVal.trim()) {
        multipleDossiersList.value = [];
        return;
    }

    const names = newVal
        .split(/[\n,]+/)
        .map(name => name.trim())
        .filter(name => name.length > 0);

    const existingItems = [...multipleDossiersList.value];
    multipleDossiersList.value = names.map((name) => {
        const existing = existingItems.find(item => item.nom === name);
        return {
            nom: name,
            couleur: existing ? existing.couleur : form.couleur,
            ordre: existing ? existing.ordre : (Number(form.ordre) || 0),
            date_creation: existing ? existing.date_creation : null,
            nb_fichiers: existing ? existing.nb_fichiers : 0
        };
    });
});

// === SCAN DU DISQUE ===
const triggerFolderScan = async () => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour créer des dossiers.', 'error');
        return;
    }

    if (window.showDirectoryPicker) {
        try {
            scanning.value = true;
            await scanWithDirectoryPicker();
        } catch (err) {
            if (err.name !== 'AbortError') {
                console.error(err);
                showNotify('Erreur lors du scan du dossier.', 'error');
            }
        } finally {
            scanning.value = false;
        }
        return;
    }

    triggerFolderInput();
};

const scanWithDirectoryPicker = async () => {
    try {
        const rootHandle = await window.showDirectoryPicker();
        const folderDataMap = {};

        for await (const [name, handle] of rootHandle.entries()) {
            if (handle.kind !== 'directory') {
                continue;
            }

            let fileCount = 0;
            let earliestDate = null;

            async function walk(dirHandle) {
                for await (const [, childHandle] of dirHandle.entries()) {
                    if (childHandle.kind === 'file') {
                        try {
                            const file = await childHandle.getFile();
                            fileCount++;
                            const formattedDate = new Date(file.lastModified)
                                .toISOString().slice(0, 19).replace('T', ' ');
                            if (earliestDate === null || formattedDate < earliestDate) {
                                earliestDate = formattedDate;
                            }
                        } catch (e) {
                            console.warn('Erreur lecture fichier:', e);
                        }
                    } else if (childHandle.kind === 'directory') {
                        await walk(childHandle);
                    }
                }
            }

            await walk(handle);

            folderDataMap[name] = {
                date: earliestDate,
                fileCount: fileCount,
            };
        }

        const detectedFolderNames = Object.keys(folderDataMap).sort();

        if (detectedFolderNames.length === 0) {
            showNotify('Aucun sous-dossier trouvé dans le dossier sélectionné.', 'error');
            return;
        }

        multipleDossiersList.value = detectedFolderNames.map((name, index) => ({
            nom: name,
            couleur: couleurs[index % couleurs.length].value,
            ordre: 0,
            date_creation: folderDataMap[name].date,
            nb_fichiers: folderDataMap[name].fileCount
        }));

        dossierNamesInput.value = detectedFolderNames.join('\n');

        const emptyCount = detectedFolderNames.filter(n => folderDataMap[n].fileCount === 0).length;
        const totalFiles = multipleDossiersList.value.reduce((acc, curr) => acc + curr.nb_fichiers, 0);

        let message = `${detectedFolderNames.length} dossier(s) détecté(s) avec ${totalFiles} fichier(s) au total.`;
        if (emptyCount > 0) {
            message += ` Dont ${emptyCount} dossier(s) vide(s).`;
        }
        showNotify(message, 'success');
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.error('Erreur scan:', err);
            showNotify('Erreur lors du scan: ' + err.message, 'error');
        }
        throw err;
    }
};

const triggerFolderInput = () => {
    folderInput.value.click();
};

const handleFolderScan = (event) => {
    const files = event.target.files;
    if (!files || files.length === 0) return;

    const folderDataMap = {};

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const relativePath = file.webkitRelativePath;
        const pathParts = relativePath.split('/');

        if (pathParts.length < 3) {
            continue;
        }

        const folderName = pathParts[1];

        if (!folderDataMap[folderName]) {
            folderDataMap[folderName] = {
                date: null,
                fileCount: 0,
            };
        }

        folderDataMap[folderName].fileCount++;

        const fileDate = file.lastModifiedDate || new Date(file.lastModified);
        const formattedDate = fileDate.toISOString().slice(0, 19).replace('T', ' ');

        if (folderDataMap[folderName].date === null || formattedDate < folderDataMap[folderName].date) {
            folderDataMap[folderName].date = formattedDate;
        }
    }

    const detectedFolderNames = Object.keys(folderDataMap).sort();

    if (detectedFolderNames.length === 0) {
        showNotify('Aucun sous-dossier valide trouvé.', 'error');
        return;
    }

    multipleDossiersList.value = detectedFolderNames.map((name, index) => ({
        nom: name,
        couleur: couleurs[index % couleurs.length].value,
        ordre: 0,
        date_creation: folderDataMap[name].date,
        nb_fichiers: folderDataMap[name].fileCount
    }));

    dossierNamesInput.value = detectedFolderNames.join('\n');

    const totalFiles = multipleDossiersList.value.reduce((acc, curr) => acc + curr.nb_fichiers, 0);
    showNotify(
        `${detectedFolderNames.length} dossier(s) détecté(s) avec ${totalFiles} fichier(s) au total.`,
        'success'
    );
};

// === FILTRES ===
const filterAnneeId = ref(null);
const filterMoisId = ref(null);
const searchQuery = ref('');

const filteredMoisForForm = computed(() => {
    if (form.annee_id) {
        return props.mois.filter(m => m.annee_id === form.annee_id);
    }
    return [];
});

const filteredMoisForSelect = computed(() => {
    if (!filterAnneeId.value) return [];
    return props.mois.filter(m => m.annee_id === filterAnneeId.value);
});

const filteredDossiers = computed(() => {
    let result = props.dossiers;
    if (filterAnneeId.value) {
        result = result.filter(d => {
            const moisItem = props.mois.find(m => m.id === d.mois_id);
            return moisItem && moisItem.annee_id === filterAnneeId.value;
        });
    }
    if (filterMoisId.value) {
        result = result.filter(d => d.mois_id === filterMoisId.value);
    }
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(d =>
            d.nom.toLowerCase().includes(query) ||
            d.code.toLowerCase().includes(query)
        );
    }
    return result;
});

// === FORMULAIRE ===
const form = useForm({
    annee_id: null,
    mois_id: null,
    nom: '',
    code: '',
    description: '',
    couleur: '#1976D2',
    ordre: 0,
    active: true
});

const generateCode = (anneeId, moisId, dossierNom) => {
    if (!anneeId || !moisId || !dossierNom) return '';
    const annee = props.annees.find(a => a.id === anneeId);
    const moisItem = props.mois.find(m => m.id === moisId);
    if (!annee || !moisItem) return '';

    const nomClean = dossierNom
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '_');

    return `DOSSIER_${annee.annee}_${moisItem.mois}_${nomClean}`;
};

const selectedMoisInfo = computed(() => form.mois_id ? props.mois.find(m => m.id === form.mois_id) : null);
const anneeInfo = computed(() => form.annee_id ? props.annees.find(a => a.id === form.annee_id) : null);

const updateCode = () => {
    if (form.annee_id && form.mois_id && form.nom && !isEditing.value) {
        form.code = generateCode(form.annee_id, form.mois_id, form.nom);
    }
};

watch(() => form.annee_id, () => {
    if (!isEditing.value) {
        form.mois_id = null;
        form.code = '';
    }
});

watch(() => form.mois_id, () => {
    if (!isEditing.value && form.nom) updateCode();
});

watch(() => form.nom, (newNom) => {
    if (!isEditing.value && form.annee_id && form.mois_id && newNom) updateCode();
});

watch(filterAnneeId, () => {
    filterMoisId.value = null;
});

const openCreateDialog = () => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour créer des dossiers.', 'error');
        return;
    }
    isEditing.value = false;
    editingId.value = null;
    multipleMode.value = false;
    dossierNamesInput.value = '';
    multipleDossiersList.value = [];
    form.reset();
    form.annee_id = null;
    form.mois_id = null;
    form.couleur = '#1976D2';
    form.ordre = 0;
    form.active = true;
    form.code = '';
    form.clearErrors();
    dialog.value = true;
};

const openEditDialog = (dossier) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour modifier des dossiers.', 'error');
        return;
    }
    isEditing.value = true;
    editingId.value = dossier.id;
    multipleMode.value = false;
    dossierNamesInput.value = '';
    multipleDossiersList.value = [];

    const moisItem = props.mois.find(m => m.id === dossier.mois_id);
    form.annee_id = moisItem ? moisItem.annee_id : null;
    form.mois_id = dossier.mois_id;
    form.nom = dossier.nom;
    form.code = dossier.code;
    form.description = dossier.description || '';
    form.couleur = dossier.couleur || '#1976D2';
    form.ordre = dossier.ordre || 0;
    form.active = dossier.active;
    form.clearErrors();
    dialog.value = true;
};

const submit = () => {
    console.log('🚀 submit appelé', {
        isEditing: isEditing.value,
        multipleMode: multipleMode.value,
        form: form
    });

    if (form.processing) {
        console.log('⏳ form.processing est true, on annule');
        return;
    }

    // --- MODE ÉDITION ---
    if (isEditing.value) {
        console.log('📝 Mode édition');
        if (!form.mois_id || !form.nom || !form.code) {
            showNotify('Veuillez remplir tous les champs requis', 'error');
            return;
        }

        form.put(route('dossiers.update', editingId.value), {
            onSuccess: () => {
                dialog.value = false;
                resetForm();
                showNotify('Dossier mis à jour avec succès', 'success');
            },
            onError: (errors) => {
                console.error('Erreur:', errors);
                showNotify('Erreur lors de la mise à jour', 'error');
            },
            preserveScroll: true
        });
        return;
    }

    // --- MODE MULTIPLE ---
    if (multipleMode.value) {
        console.log('📦 Mode multiple');

        if (!form.annee_id) {
            showNotify('Veuillez sélectionner une année', 'error');
            return;
        }
        if (!form.mois_id) {
            showNotify('Veuillez sélectionner un mois', 'error');
            return;
        }

        const validDossiers = multipleDossiersList.value.filter(item =>
            item.nom && item.nom.trim() !== ''
        );

        if (validDossiers.length === 0) {
            showNotify('Aucun dossier valide à enregistrer', 'error');
            return;
        }

        const dossiersToSend = validDossiers.map(item => ({
            nom: item.nom.trim(),
            couleur: item.couleur || '#1976D2',
            ordre: parseInt(item.ordre) || 0,
            date_creation: item.date_creation || null
        }));

        console.log('📤 Envoi des dossiers:', {
            mois_id: form.mois_id,
            dossiers: dossiersToSend,
            description: form.description || '',
            active: form.active
        });

        router.post(route('dossiers.store.multiple'), {
            mois_id: form.mois_id,
            dossiers: dossiersToSend,
            description: form.description || '',
            active: form.active
        }, {
            preserveScroll: true,
            onSuccess: (response) => {
                console.log('✅ Succès:', response);
                dialog.value = false;
                resetForm();
                showNotify(`${dossiersToSend.length} dossier(s) créé(s) avec succès`, 'success');
            },
            onError: (errors) => {
                console.error('❌ Erreur détaillée:', errors);
                if (errors && errors.response && errors.response.data) {
                    const errorData = errors.response.data;
                    if (errorData.errors) {
                        const errorMessages = Object.values(errorData.errors).flat();
                        showNotify('Erreur: ' + errorMessages.join(', '), 'error');
                    } else if (errorData.message) {
                        showNotify('Erreur: ' + errorData.message, 'error');
                    } else {
                        showNotify('Erreur lors de la création en lot', 'error');
                    }
                } else {
                    showNotify('Erreur lors de la création en lot', 'error');
                }
            }
        });
        return;
    }

    // --- MODE CRÉATION UNIQUE ---
    console.log('📄 Mode création unique');
    if (!form.annee_id) {
        showNotify('Veuillez sélectionner une année', 'error');
        return;
    }
    if (!form.mois_id) {
        showNotify('Veuillez sélectionner un mois', 'error');
        return;
    }
    if (!form.nom) {
        showNotify('Veuillez entrer un nom de dossier', 'error');
        return;
    }

    form.code = generateCode(form.annee_id, form.mois_id, form.nom);
    console.log('📤 Envoi du dossier unique:', form);

    form.post(route('dossiers.store'), {
        onSuccess: () => {
            dialog.value = false;
            resetForm();
            showNotify('Dossier créé avec succès', 'success');
        },
        onError: (errors) => {
            console.error('Erreur:', errors);
            showNotify('Erreur lors de la création', 'error');
        },
        preserveScroll: true
    });
};

const resetForm = () => {
    form.reset();
    form.annee_id = null;
    form.mois_id = null;
    form.couleur = '#1976D2';
    form.ordre = 0;
    form.active = true;
    form.code = '';
    form.clearErrors();
    multipleMode.value = false;
    dossierNamesInput.value = '';
    multipleDossiersList.value = [];
    isEditing.value = false;
    editingId.value = null;
};

const confirmDelete = (id) => {
    if (!canManage.value) {
        showNotify('Vous n\'avez pas les droits pour supprimer des dossiers.', 'error');
        return;
    }
    itemToDelete.value = id;
    deleteDialog.value = true;
};

const deleteItem = () => {
    router.delete(route('dossiers.destroy', itemToDelete.value), {
        onSuccess: () => {
            deleteDialog.value = false;
            itemToDelete.value = null;
            showNotify('Dossier supprimé avec succès', 'success');
        },
        onError: (errors) => {
            console.error('Erreur:', errors);
            showNotify('Erreur lors de la suppression', 'error');
        },
        preserveScroll: true
    });
};

const getMoisLabel = (moisId) => {
    const moisItem = props.mois.find(m => m.id === moisId);
    if (!moisItem) return 'N/A';
    const annee = props.annees.find(a => a.id === moisItem.annee_id);
    return `${annee?.annee || ''} / ${moisItem.nom_mois}`;
};

const resetFilters = () => {
    filterAnneeId.value = null;
    filterMoisId.value = null;
    searchQuery.value = '';
};
</script>

<template>

    <Head title="Gestion des Dossiers" />
    <AuthenticatedLayout>
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" rounded="lg">
            <v-icon start>{{ snackbar.icon }}</v-icon>
            {{ snackbar.text }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar.show = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <div class="d-flex align-center">
                    <v-icon icon="mdi-folder-multiple" color="primary" size="28" class="mr-3"></v-icon>
                    <div>
                        <div class="text-h6 font-weight-bold">Dossiers d'archivage</div>
                        <div class="text-caption text-grey">Gestion de l'arborescence numérique</div>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog" :disabled="!canManage">
                    Nouveau Dossier
                </v-btn>
            </v-toolbar>

            <div class="bg-grey-lighten-4 px-4 py-3 border-bottom d-flex align-center flex-wrap gap-3">
                <v-select v-model="filterAnneeId" :items="annees" item-title="annee" item-value="id"
                    label="Filtrer par année" variant="solo" density="compact" hide-details flat clearable
                    style="max-width: 200px;">
                    <template v-slot:prepend-inner>
                        <v-icon color="primary" size="small">mdi-calendar</v-icon>
                    </template>
                </v-select>

                <v-select v-model="filterMoisId" :items="filteredMoisForSelect" item-title="nom_mois" item-value="id"
                    label="Filtrer par mois" variant="solo" density="compact" hide-details flat clearable
                    style="max-width: 200px;" :disabled="!filterAnneeId">
                    <template v-slot:prepend-inner>
                        <v-icon color="primary" size="small">mdi-calendar-month</v-icon>
                    </template>
                </v-select>

                <v-text-field v-model="searchQuery" prepend-inner-icon="mdi-magnify" placeholder="Rechercher..."
                    variant="solo" density="compact" hide-details flat clearable
                    style="max-width: 250px;"></v-text-field>

                <v-btn v-if="filterAnneeId || filterMoisId || searchQuery" variant="text" color="error" size="small"
                    @click="resetFilters" prepend-icon="mdi-filter-off">
                    Réinitialiser
                </v-btn>

                <v-spacer></v-spacer>
                <div class="text-caption text-grey">
                    {{ filteredDossiers.length }} dossiers sur {{ dossiers.length }} au total
                </div>
            </div>

            <v-table hover>
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Nom</th>
                        <th class="text-overline">Code</th>
                        <th class="text-overline">Localisation</th>
                        <th class="text-overline text-center">Couleur</th>
                        <th class="text-overline text-center">Statut</th>
                        <th class="text-overline text-center">Ordre</th>
                        <th class="text-overline text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="dossier in filteredDossiers" :key="dossier.id">
                        <td>
                            <div class="d-flex align-center">
                                <v-icon :color="dossier.couleur" size="22" class="mr-2">mdi-folder</v-icon>
                                <span class="font-weight-medium">{{ dossier.nom }}</span>
                            </div>
                        </td>
                        <td>
                            <v-chip size="small" color="blue-grey-lighten-4" text-color="blue-grey-darken-4">
                                {{ dossier.code }}
                            </v-chip>
                        </td>
                        <td class="text-caption">{{ getMoisLabel(dossier.mois_id) }}</td>
                        <td class="text-center">
                            <v-avatar :color="dossier.couleur" size="24" class="elevation-1"></v-avatar>
                        </td>
                        <td class="text-center">
                            <v-chip :color="dossier.active ? 'success' : 'error'" size="small">
                                {{ dossier.active ? 'Actif' : 'Inactif' }}
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <v-chip size="small" color="grey-lighten-3">
                                {{ dossier.ordre || 0 }}
                            </v-chip>
                        </td>
                        <td class="text-center">
                            <v-btn icon="mdi-pencil" size="small" variant="text" color="primary"
                                @click="openEditDialog(dossier)" title="Modifier" :disabled="!canManage"></v-btn>
                            <v-btn icon="mdi-delete" size="small" variant="text" color="error"
                                @click="confirmDelete(dossier.id)" title="Supprimer" :disabled="!canManage"></v-btn>
                        </td>
                    </tr>
                    <tr v-if="filteredDossiers.length === 0">
                        <td colspan="7" class="text-center py-12">
                            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-folder-open</v-icon>
                            <div class="text-h6 text-grey-lighten-1">
                                Aucun dossier enregistré
                            </div>
                            <div class="text-caption text-grey mt-2">
                                Cliquez sur "Nouveau Dossier" pour commencer
                            </div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- Dialog Création/Modification -->
        <v-dialog v-model="dialog" max-width="750px" persistent scrollable>
            <v-card class="rounded-xl" style="max-height: 90vh; display: flex; flex-direction: column;">
                <v-toolbar color="primary" class="rounded-t-xl">
                    <v-icon start class="ml-4">{{ isEditing ? 'mdi-pencil' : 'mdi-plus' }}</v-icon>
                    <v-toolbar-title class="font-weight-bold">
                        {{ isEditing ? 'Modifier le dossier' : 'Nouveau dossier' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="dialog = false"></v-btn>
                </v-toolbar>

                <v-divider></v-divider>

                <v-card-text class="pa-6" style="overflow-y: auto; flex: 1;">
                    <v-progress-linear v-if="form.processing" :model-value="form.progress?.percentage" color="primary"
                        height="4" striped class="mb-4" rounded></v-progress-linear>

                    <v-form @submit.prevent="submit" ref="formRef">
                        <div v-if="form.annee_id && form.mois_id && anneeInfo && selectedMoisInfo"
                            class="mb-4 pa-3 bg-grey-lighten-4 rounded-lg">
                            <div class="text-caption text-grey">Emplacement cible :</div>
                            <div class="font-weight-bold">
                                <v-icon color="blue-darken-1" size="small" class="mr-1">mdi-calendar</v-icon>
                                {{ anneeInfo.annee }}
                                <v-icon size="small" class="mx-1">mdi-chevron-right</v-icon>
                                <v-icon color="amber-darken-2" size="small" class="mr-1">mdi-calendar-month</v-icon>
                                {{ selectedMoisInfo.nom_mois }}
                            </div>
                        </div>

                        <v-select v-model="form.annee_id" :items="annees" item-title="annee" item-value="id"
                            :label="isEditing ? 'Année' : '1. Choisir l\'année'" variant="outlined"
                            density="comfortable" :error-messages="form.errors.annee_id"
                            :disabled="isEditing || form.processing" required>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-calendar</v-icon>
                            </template>
                        </v-select>

                        <v-select v-model="form.mois_id" :items="filteredMoisForForm"
                            :item-title="(item) => item.nom_mois" item-value="id"
                            :label="isEditing ? 'Mois' : '2. Choisir le mois'" variant="outlined" density="comfortable"
                            :error-messages="form.errors.mois_id"
                            :disabled="!form.annee_id || isEditing || form.processing" required>
                            <template v-slot:prepend-inner>
                                <v-icon color="primary" size="small">mdi-calendar-month</v-icon>
                            </template>
                            <template v-slot:no-data>
                                <div class="pa-4 text-center">
                                    <v-icon size="32" color="grey-lighten-1" class="mb-2">mdi-calendar-month</v-icon>
                                    <div class="text-caption text-grey">Aucun mois disponible pour cette année</div>
                                </div>
                            </template>
                        </v-select>

                        <v-switch v-if="!isEditing" v-model="multipleMode" color="primary" inset hide-details
                            class="mb-4" :disabled="!form.annee_id || !form.mois_id">
                            <template v-slot:label>
                                <div>
                                    <span class="font-weight-bold">Créer plusieurs dossiers</span>
                                    <span class="text-caption text-grey d-block">
                                        Saisie en lot ou scan depuis votre disque local
                                    </span>
                                </div>
                            </template>
                        </v-switch>

                        <template v-if="!multipleMode || isEditing">
                            <v-text-field v-model="form.nom" label="Nom du dossier" variant="outlined"
                                density="comfortable" :error-messages="form.errors.nom" :disabled="form.processing"
                                hint="Exemple: Factures, Contrats, RH, etc." persistent-hint required>
                                <template v-slot:prepend-inner>
                                    <v-icon color="primary" size="small">mdi-folder</v-icon>
                                </template>
                            </v-text-field>

                            <v-text-field v-model="form.code" :label="isEditing ? 'Code' : 'Code (auto-généré)'"
                                variant="outlined" density="comfortable" :error-messages="form.errors.code"
                                :disabled="!isEditing || form.processing" :readonly="!isEditing"
                                :hint="isEditing ? 'Code du dossier (non modifiable)' : 'Généré automatiquement'"
                                persistent-hint>
                                <template v-slot:prepend-inner>
                                    <v-icon color="primary" size="small">mdi-tag</v-icon>
                                </template>
                            </v-text-field>

                            <v-select v-model="form.couleur" :items="couleurs" item-title="name" item-value="value"
                                label="Couleur du dossier" variant="outlined" density="comfortable">
                                <template v-slot:selection="{ item }">
                                    <div class="d-flex align-center">
                                        <v-avatar :color="item.value" size="20" class="mr-2"></v-avatar>
                                        {{ item.title }}
                                    </div>
                                </template>
                                <template v-slot:item="{ item, props: itemProps }">
                                    <v-list-item v-bind="itemProps">
                                        <div class="d-flex align-center">
                                            <v-avatar :color="item.value" size="20" class="mr-2"></v-avatar>
                                            {{ item.title }}
                                        </div>
                                    </v-list-item>
                                </template>
                            </v-select>
                        </template>

                        <template v-if="!isEditing && multipleMode">
                            <v-card class="pa-4 mb-4 bg-blue-lighten-5 rounded-lg" variant="flat">
                                <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                                    <div>
                                        <div class="text-subtitle-2 font-weight-bold">📁 Récupération depuis votre
                                            espace de stockage :</div>
                                        <div class="text-caption">
                                            Sélectionnez le dossier de votre mois pour importer automatiquement
                                            ses sous-dossiers (y compris les dossiers vides) et leur date d'origine.
                                            <br>
                                            <span class="text-error">⚠️ Les fichiers à la racine seront ignorés.</span>
                                        </div>
                                    </div>
                                    <v-btn color="secondary" prepend-icon="mdi-folder-search" @click="triggerFolderScan"
                                        :loading="scanning" :disabled="!canManage">
                                        Scanner le disque
                                    </v-btn>
                                    <input type="file" ref="folderInput" class="d-none" webkitdirectory directory
                                        multiple @change="handleFolderScan" />
                                </div>
                            </v-card>

                            <v-textarea v-model="dossierNamesInput" label="Noms des dossiers (un par ligne)"
                                variant="outlined" density="comfortable" rows="3" auto-grow
                                placeholder="Factures&#10;Contrats&#10;RH&#10;Archives"
                                hint="Séparez les noms par des virgules ou des sauts de ligne" persistent-hint>
                                <template v-slot:prepend-inner>
                                    <v-icon color="primary" size="small">mdi-format-list-bulleted</v-icon>
                                </template>
                            </v-textarea>

                            <div v-if="multipleDossiersList.length > 0" class="mb-4">
                                <div class="text-subtitle-2 font-weight-bold mb-2 text-primary">
                                    Configuration des dossiers détectés ({{ multipleDossiersList.length }}) :
                                </div>
                                <v-card variant="outlined" class="pa-4 bg-grey-lighten-5 rounded-lg"
                                    style="max-height: 250px; overflow-y: auto;">
                                    <div v-for="(item, index) in multipleDossiersList" :key="index"
                                        class="d-flex align-center gap-3 border-bottom pb-2 mb-2"
                                        style="border-color: #e0e0e0 !important;">
                                        <div class="d-flex align-center" style="min-width: 220px; flex: 1;">
                                            <v-icon :color="item.couleur" class="mr-2">mdi-folder</v-icon>
                                            <div>
                                                <span class="font-weight-medium text-body-2 d-block">{{ item.nom
                                                }}</span>
                                                <span class="text-caption text-grey"
                                                    style="font-size: 10px !important;">
                                                    📅 {{ item.date_creation || 'Date inconnue (dossier vide)' }}
                                                    <span v-if="item.nb_fichiers" class="ml-2">
                                                        📄 {{ item.nb_fichiers }} fichier(s)
                                                    </span>
                                                    <span v-else class="ml-2 text-warning">— dossier vide</span>
                                                </span>
                                            </div>
                                        </div>
                                        <v-select v-model="item.couleur" :items="couleurs" item-title="name"
                                            item-value="value" label="Couleur" variant="solo" density="compact"
                                            hide-details flat style="max-width: 140px; background: white;">
                                            <template v-slot:selection="{ item: c }">
                                                <v-avatar :color="c.value" size="12" class="mr-1"></v-avatar>
                                                {{ c.title }}
                                            </template>
                                        </v-select>
                                        <v-text-field v-model="item.ordre" type="number" label="Ordre" variant="solo"
                                            density="compact" hide-details flat
                                            style="max-width: 80px; background: white;">
                                        </v-text-field>
                                    </div>
                                </v-card>
                            </div>
                        </template>

                        <v-text-field v-model="form.ordre" label="Ordre d'affichage" type="number" variant="outlined"
                            density="comfortable" hint="Nombre pour classer les dossiers (plus petit = plus haut)"
                            persistent-hint>
                        </v-text-field>

                        <v-textarea v-model="form.description" label="Description (optionnelle)" variant="outlined"
                            density="comfortable" rows="2" auto-grow hint="Description optionnelle du dossier"
                            persistent-hint>
                        </v-textarea>

                        <v-switch v-model="form.active" label="Dossier actif" color="primary" inset hide-details>
                            <template v-slot:label>
                                <div>
                                    <span class="font-weight-bold">Dossier actif</span>
                                    <span class="text-caption text-grey d-block">
                                        Permet d'activer ou désactiver ce dossier dans l'arborescence
                                    </span>
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
                    <v-btn color="primary" @click="submit" :loading="form.processing" class="px-6 ml-2" rounded="lg"
                        :disabled="!canManage">
                        <template v-if="isEditing">Mettre à jour</template>
                        <template v-else-if="multipleMode">Créer les dossiers</template>
                        <template v-else>Créer</template>
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="deleteDialog" max-width="450px">
            <v-card class="rounded-xl">
                <v-card-title class="text-h6 text-error pa-4">
                    <v-icon start color="error">mdi-alert-circle</v-icon>
                    Confirmer la suppression
                </v-card-title>
                <v-divider></v-divider>
                <v-card-text class="pa-4">
                    Êtes-vous sûr de vouloir supprimer ce dossier ?
                    <div class="text-caption text-grey mt-2">
                        Cette action est irréversible et supprimera tous les documents archivés dans ce dossier.
                    </div>
                </v-card-text>
                <v-card-actions class="pa-4 bg-grey-lighten-5">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="deleteDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" @click="deleteItem" :disabled="!canManage">Supprimer</v-btn>
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

.gap-3 {
    gap: 12px;
}
</style>
