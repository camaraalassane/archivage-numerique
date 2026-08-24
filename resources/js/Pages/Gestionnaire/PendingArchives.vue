<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    pendingArchives: { type: Array, default: () => [] },
    archivistes: { type: Array, default: () => [] },
    user: { type: Object, required: true },
});

// États
const currentTab = ref('ordinaire');
const selectedIds = ref([]);
const rejectDialog = ref(false);
const rejectAllDialog = ref(false);
const confirmDialog = ref(false);
const previewDialog = ref(false);
const validateDialog = ref(false);
const currentArchive = ref(null);
const rejectReason = ref('');
const rejectAllReason = ref('');
const validateReason = ref('');
const searchQuery = ref('');
const expandedArchiviste = ref(null);
const currentFileUrl = ref('');
const currentFileTitle = ref('');
const confirmAction = ref(null);
const confirmMessage = ref('');
const confirmTitle = ref('Confirmation');

// Snackbar
const snackbar = ref({ show: false, text: '', color: 'success' });
const showNotification = (text, color = 'success') => {
    snackbar.value = { show: true, text, color };
    setTimeout(() => {
        snackbar.value.show = false;
    }, 3000);
};

// Dialogue de confirmation personnalisé
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

// Filtrage par type (ordinaire vs confidentiel)
const filteredArchives = computed(() => {
    return props.pendingArchives.filter(a => {
        if (currentTab.value === 'confidentiel') {
            return a.type_document_confidentiel === 1;
        }
        return a.type_document_confidentiel !== 1;
    });
});

const countOrdinaire = computed(() => props.pendingArchives.filter(a => a.type_document_confidentiel !== 1).length);
const countConfidentiel = computed(() => props.pendingArchives.filter(a => a.type_document_confidentiel === 1).length);

// Groupement par archiviste
const archivesByArchiviste = computed(() => {
    const groups = {};
    filteredArchives.value.forEach(archive => {
        const key = archive.created_by || 0;
        const name = archive.createur?.name || 'Archiviste ' + key;
        if (!groups[key]) {
            groups[key] = { id: key, name, archives: [] };
        }
        groups[key].archives.push(archive);
    });
    return Object.values(groups).sort((a, b) => b.archives.length - a.archives.length);
});

// Sélection
const toggleSelect = (id) => {
    const index = selectedIds.value.indexOf(id);
    if (index > -1) {
        selectedIds.value.splice(index, 1);
    } else {
        selectedIds.value.push(id);
    }
};

const selectAll = (archives) => {
    const ids = archives.map(a => a.id);
    const allSelected = ids.every(id => selectedIds.value.includes(id));
    if (allSelected) {
        selectedIds.value = selectedIds.value.filter(id => !ids.includes(id));
    } else {
        ids.forEach(id => {
            if (!selectedIds.value.includes(id)) {
                selectedIds.value.push(id);
            }
        });
    }
};

// Prévisualisation (dans un dialogue)
const previewFile = (archive) => {
    currentFileUrl.value = route('gestionnaire.view', archive.id);
    currentFileTitle.value = archive.titre;
    previewDialog.value = true;
};

// Validation individuelle
const validateArchive = (archive) => {
    router.post(route('gestionnaire.validate', archive.id), {
        comment: 'Validé par le gestionnaire'
    }, {
        onSuccess: () => {
            showNotification('✅ Archive validée');
            router.reload({ only: ['pendingArchives'] });
        },
        onError: () => showNotification('❌ Erreur lors de la validation', 'error'),
    });
};

// Rejet
const openRejectDialog = (archive) => {
    currentArchive.value = archive;
    rejectReason.value = '';
    rejectDialog.value = true;
};

const confirmReject = () => {
    if (!rejectReason.value.trim()) {
        showNotification('Veuillez indiquer un motif.', 'error');
        return;
    }
    router.post(route('gestionnaire.reject', currentArchive.value.id), {
        comment: rejectReason.value
    }, {
        onSuccess: () => {
            rejectDialog.value = false;
            showNotification('❌ Archive rejetée');
            router.reload({ only: ['pendingArchives'] });
        },
        onError: () => showNotification('❌ Erreur lors du rejet', 'error'),
    });
};

// Suppression individuelle
const deleteArchive = (archive) => {
    showConfirm('⚠️ Supprimer définitivement cette archive ?', () => {
        router.delete(route('gestionnaire.destroy', archive.id), {
            onSuccess: () => {
                showNotification('🗑️ Archive supprimée');
                router.reload({ only: ['pendingArchives'] });
            },
            onError: () => showNotification('❌ Erreur lors de la suppression', 'error'),
        });
    }, 'Supprimer l\'archive');
};

// Actions en masse
const validateAllSelected = () => {
    if (selectedIds.value.length === 0) {
        showNotification('Aucune archive sélectionnée.', 'error');
        return;
    }
    showConfirm(`Valider ${selectedIds.value.length} archive(s) ?`, () => {
        router.post(route('gestionnaire.validate-all'), {
            ids: selectedIds.value
        }, {
            onSuccess: () => {
                const count = selectedIds.value.length;
                selectedIds.value = [];
                showNotification(`✅ ${count} archives validées`);
                router.reload({ only: ['pendingArchives'] });
            },
            onError: () => showNotification('❌ Erreur lors de la validation', 'error'),
        });
    }, 'Validation en masse');
};

const rejectAllSelected = () => {
    if (selectedIds.value.length === 0) {
        showNotification('Aucune archive sélectionnée.', 'error');
        return;
    }
    rejectAllDialog.value = true;
};

const confirmRejectAll = () => {
    if (!rejectAllReason.value.trim()) {
        showNotification('Veuillez indiquer un motif.', 'error');
        return;
    }
    router.post(route('gestionnaire.reject-all'), {
        ids: selectedIds.value,
        comment: rejectAllReason.value
    }, {
        onSuccess: () => {
            rejectAllDialog.value = false;
            const count = selectedIds.value.length;
            selectedIds.value = [];
            rejectAllReason.value = '';
            showNotification(`❌ ${count} archives rejetées`);
            router.reload({ only: ['pendingArchives'] });
        },
        onError: () => showNotification('❌ Erreur lors du rejet', 'error'),
    });
};

const deleteAllSelected = () => {
    if (selectedIds.value.length === 0) {
        showNotification('Aucune archive sélectionnée.', 'error');
        return;
    }
    showConfirm(`⚠️ Supprimer définitivement ${selectedIds.value.length} archive(s) ? Cette action est irréversible !`, () => {
        router.post(route('gestionnaire.destroy-all'), {
            ids: selectedIds.value
        }, {
            onSuccess: () => {
                const count = selectedIds.value.length;
                selectedIds.value = [];
                showNotification(`🗑️ ${count} archives supprimées`);
                router.reload({ only: ['pendingArchives'] });
            },
            onError: () => showNotification('❌ Erreur lors de la suppression', 'error'),
        });
    }, '⚠️ Suppression en masse');
};

// Utilitaires
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
    <Head title="Archives en attente" />
    <AuthenticatedLayout>
        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" class="mt-16">
            <v-icon start>{{ snackbar.color === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
            {{ snackbar.text }}
        </v-snackbar>

        <!-- Dialogue de confirmation personnalisé -->
        <v-dialog v-model="confirmDialog" max-width="450px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar :color="confirmTitle.includes('Supprimer') ? 'error' : 'primary'" dark class="rounded-t-xl">
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

        <!-- Dialogue de prévisualisation -->
        <v-dialog v-model="previewDialog" width="95%" max-width="1200px">
            <v-card rounded="xl">
                <v-toolbar color="primary" density="comfortable" class="rounded-t-xl">
                    <v-icon start class="ml-4">mdi-file-eye</v-icon>
                    <v-toolbar-title class="text-body-1">{{ currentFileTitle }}</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="previewDialog = false"></v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <iframe :src="currentFileUrl" width="100%" style="height: 85vh; border: none;"></iframe>
            </v-card>
        </v-dialog>

        <!-- DIALOGUE REJET -->
        <v-dialog v-model="rejectDialog" max-width="400px" persistent>
            <v-card class="rounded-lg">
                <v-toolbar color="error" dark density="compact">
                    <v-icon start size="small">mdi-close-circle</v-icon>
                    <v-toolbar-title class="text-subtitle-1">Rejeter l'archive</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" size="small" @click="rejectDialog = false"></v-btn>
                </v-toolbar>
                <v-card-text class="pa-4">
                    <p class="mb-2 text-body-2 font-weight-medium">{{ currentArchive?.titre }}</p>
                    <v-textarea v-model="rejectReason" label="Motif du rejet" rows="2" density="compact" required></v-textarea>
                </v-card-text>
                <v-card-actions class="pa-2 bg-grey-lighten-4">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" size="small" @click="rejectDialog = false">Annuler</v-btn>
                    <v-btn color="error" variant="flat" size="small" @click="confirmReject">Rejeter</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>


        <!-- Dialogue Rejet en masse -->
        <v-dialog v-model="rejectAllDialog" max-width="450px">
            <v-card class="rounded-xl">
                <v-toolbar color="error" dark class="rounded-t-xl">
                    <v-icon start>mdi-close-circle</v-icon>
                    <v-toolbar-title>Rejeter en masse</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="rejectAllDialog = false"></v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-card-text class="pa-4">
                    <p class="mb-2 text-body-2">{{ selectedIds.length }} archive(s) sélectionnée(s)</p>
                    <v-textarea v-model="rejectAllReason" label="Motif du rejet" rows="2" density="compact" required></v-textarea>
                </v-card-text>
                <v-divider></v-divider>
                <v-card-actions class="pa-3">
                    <v-spacer></v-spacer>
                    <v-btn variant="text" @click="rejectAllDialog = false">Annuler</v-btn>
                    <v-btn color="error" @click="confirmRejectAll">Tout rejeter</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Carte principale -->
        <v-card elevation="2" class="rounded-xl">
            <!-- En-tête -->
            <v-toolbar color="primary" dark class="rounded-t-xl" density="compact">
                <v-icon start class="ml-2">mdi-account-check</v-icon>
                <v-toolbar-title class="text-subtitle-1 font-weight-bold">Archives en attente</v-toolbar-title>
                
                <v-spacer></v-spacer>

                <!-- Toggle Ordinaire / Confidentiel -->
                <v-btn-toggle v-model="currentTab" mandatory density="compact" class="mx-4 bg-white" color="primary" rounded="lg">
                    <v-btn value="ordinaire" size="small">
                        Ordinaire
                        <v-chip size="x-small" :color="currentTab === 'ordinaire' ? 'primary' : 'grey'" class="ml-1">{{ countOrdinaire }}</v-chip>
                    </v-btn>
                    <v-btn value="confidentiel" size="small" v-if="props.user.peut_valider_confidentiel == 1 || props.user.role === 3">
                        <v-icon start size="small" color="error">mdi-shield-lock</v-icon>
                        Confidentiel
                        <v-chip size="x-small" :color="currentTab === 'confidentiel' ? 'error' : 'grey'" class="ml-1">{{ countConfidentiel }}</v-chip>
                    </v-btn>
                </v-btn-toggle>

                <v-text-field
                    v-model="searchQuery"
                    prepend-inner-icon="mdi-magnify"
                    placeholder="Rechercher..."
                    variant="solo-filled"
                    density="compact"
                    hide-details
                    flat
                    class="mx-2"
                    style="max-width: 220px;"
                ></v-text-field>
            </v-toolbar>

            <v-card-text class="pa-2">
                <!-- Actions en masse -->
                <div v-if="selectedIds.length > 0" class="d-flex align-center ga-2 pa-2 mb-2 bg-blue-lighten-5 rounded-lg flex-wrap">
                    <v-chip color="primary" size="x-small" class="font-weight-bold">{{ selectedIds.length }}</v-chip>
                    <span class="text-caption text-grey">sélectionnée(s)</span>
                    <v-divider vertical class="mx-1"></v-divider>
                    <v-btn color="success" size="x-small" variant="flat" @click="validateAllSelected" prepend-icon="mdi-check-all">Valider</v-btn>
                    <v-btn color="warning" size="x-small" variant="flat" @click="rejectAllSelected" prepend-icon="mdi-close-all">Rejeter</v-btn>
                    <v-btn color="error" size="x-small" variant="flat" @click="deleteAllSelected" prepend-icon="mdi-delete-sweep">Supprimer</v-btn>
                    <v-btn size="x-small" variant="text" @click="selectedIds = []">×</v-btn>
                </div>

                <!-- Groupes par archiviste -->
                <v-row v-for="group in archivesByArchiviste" :key="group.id" class="mb-2" dense>
                    <v-col cols="12" class="pa-1">
                        <v-card class="rounded-lg" variant="outlined">
                            <!-- En-tête groupe -->
                            <div class="d-flex align-center pa-2 bg-grey-lighten-4 rounded-t-lg" style="cursor:pointer; min-height: 36px;" @click="expandedArchiviste = expandedArchiviste === group.id ? null : group.id">
                                <v-checkbox
                                    :model-value="group.archives.every(a => selectedIds.includes(a.id))"
                                    @click.stop="selectAll(group.archives)"
                                    hide-details
                                    density="compact"
                                    class="mr-1"
                                ></v-checkbox>
                                <v-icon start size="small">mdi-account</v-icon>
                                <span class="font-weight-medium text-body-2">{{ group.name }}</span>
                                <v-chip size="x-small" color="primary" class="ml-2">{{ group.archives.length }}</v-chip>
                                <v-spacer></v-spacer>
                                <v-icon size="small">{{ expandedArchiviste === group.id ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>

                            <!-- Tableau -->
                            <v-expand-transition>
                                <div v-if="expandedArchiviste === group.id">
                                    <v-table density="compact" hover>
                                        <thead>
                                            <tr class="bg-grey-lighten-5">
                                                <th style="width: 30px; padding: 2px 6px;">
                                                    <v-checkbox
                                                        :model-value="group.archives.every(a => selectedIds.includes(a.id))"
                                                        @update:model-value="selectAll(group.archives)"
                                                        hide-details
                                                        density="compact"
                                                    ></v-checkbox>
                                                </th>
                                                <th class="text-caption font-weight-bold" style="padding: 2px 6px;">Réf.</th>
                                                <th class="text-caption font-weight-bold" style="padding: 2px 6px;">Titre</th>
                                                <th class="text-caption font-weight-bold text-center" style="padding: 2px 6px;">Format</th>
                                                <th class="text-caption font-weight-bold text-center" style="padding: 2px 6px; width: 160px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="archive in group.archives" :key="archive.id" :class="{ 'bg-blue-lighten-5': selectedIds.includes(archive.id) }">
                                                <td style="padding: 1px 6px;">
                                                    <v-checkbox
                                                        :model-value="selectedIds.includes(archive.id)"
                                                        @update:model-value="toggleSelect(archive.id)"
                                                        hide-details
                                                        density="compact"
                                                    ></v-checkbox>
                                                </td>
                                                <td style="padding: 1px 6px;">
                                                    <span class="text-primary font-weight-medium text-caption">{{ archive.reference }}</span>
                                                </td>
                                                <td style="padding: 1px 6px; max-width: 200px;">
                                                    <span class="text-truncate text-caption d-block">{{ archive.titre }}</span>
                                                    <div v-if="archive.validation_status === 'rejected'" class="text-error" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;">
                                                        <v-icon size="x-small" color="error">mdi-close-circle</v-icon> Rejeté 
                                                        <span v-if="archive.validation_comment">- {{ archive.validation_comment }}</span>
                                                    </div>
                                                </td>
                                                <td class="text-center" style="padding: 1px 6px;">
                                                    <v-icon :color="getFileColor(archive.type_document)" size="x-small">
                                                        {{ getFileIcon(archive.type_document) }}
                                                    </v-icon>
                                                </td>
                                                <td class="text-center" style="padding: 1px 6px;">
                                                    <div class="d-flex align-center justify-center ga-1">
                                                        <!-- 👁️ Œil : ouvre le dialogue -->
                                                        <v-btn icon="mdi-eye" size="x-small" variant="text" color="info" @click="previewFile(archive)" title="Visualiser"></v-btn>
                                                        <!-- ⬇️ Téléchargement -->
                                                        <v-btn icon="mdi-download" size="x-small" variant="text" color="primary" :href="route('gestionnaire.download', archive.id)" title="Télécharger"></v-btn>
                                                        <!-- ✅ Valider -->
                                                        <v-btn icon="mdi-check" size="x-small" variant="flat" color="success" @click="validateArchive(archive)" title="Valider"></v-btn>
                                                        <!-- ❌ Rejeter -->
                                                        <v-btn icon="mdi-close" size="x-small" variant="flat" color="error" @click="openRejectDialog(archive)" title="Rejeter"></v-btn>
                                                        <!-- 🗑️ Supprimer -->
                                                        <v-btn icon="mdi-delete" size="x-small" variant="flat" color="error" @click="deleteArchive(archive)" title="Supprimer"></v-btn>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </div>
                            </v-expand-transition>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Message vide -->
                <div v-if="filteredArchives.length === 0" class="text-center py-8">
                    <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-check-circle</v-icon>
                    <div class="text-h6 text-grey-lighten-1">Aucune archive {{ currentTab === 'ordinaire' ? 'ordinaire' : 'confidentielle' }} en attente</div>
                    <div class="text-caption text-grey mt-1">Toutes les archives ont été traitées</div>
                </div>
            </v-card-text>
        </v-card>
    </AuthenticatedLayout>
</template>

<style scoped>
.ga-1 { gap: 4px; }
.ga-2 { gap: 8px; }
.v-table :deep(th) {
    font-size: 0.6rem !important;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #666;
}
.v-table :deep(td) {
    font-size: 0.7rem !important;
    padding: 1px 6px !important;
}
.v-table :deep(.v-checkbox) {
    margin: 0 !important;
}
.text-truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.d-block {
    display: block;
}
</style>
