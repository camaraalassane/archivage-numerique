<!-- resources/js/Pages/Stats/Index.vue -->
<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    stats: { type: Object, required: true }
});

const snackbar = ref({ show: false, text: '', color: 'success', icon: 'mdi-check-circle' });
const showNotify = (text, color = 'success') => {
    snackbar.value = { show: true, text, color, icon: color === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' };
};

const confidentialDialog = ref(false);
const confidentialForm = useForm({ password: '' });

const unlockConfidential = () => {
    confidentialForm.post(route('confidential.unlock'), {
        onSuccess: () => {
            confidentialDialog.value = false;
            confidentialForm.reset();
            router.get(route('stats', { space: 'confidential' }));
        },
        onError: () => showNotify('Mot de passe incorrect', 'error')
    });
};

const isArchiviste = computed(() => props.stats.is_archiviste === true);
const isGestionnaire = computed(() => props.stats.is_gestionnaire === true);
const isAdmin = computed(() => props.stats.is_admin === true);
const isDivision = computed(() => props.stats.is_division === true);
const hasConfidentialAccess = computed(() => props.stats.has_confidential_access === true);
const isConfidentialSpace = computed(() => props.stats.is_confidential_space === true);
const requiresUnlock = computed(() => props.stats.requires_unlock === true);

const formatDate = (date) => {
    if (!date) return '-';
    try { return new Date(date).toLocaleDateString('fr-FR'); } catch { return '-'; }
};

const getFileIcon = (type) => {
    if (!type) return 'mdi-file-document';
    const icons = { pdf: 'mdi-file-pdf-box', jpg: 'mdi-file-image', png: 'mdi-file-image', jpeg: 'mdi-file-image', docx: 'mdi-file-word', doc: 'mdi-file-word', xls: 'mdi-file-excel', xlsx: 'mdi-file-excel' };
    return icons[String(type).toLowerCase()] || 'mdi-file-document';
};

const getFileColor = (type) => {
    if (!type) return 'grey';
    const colors = { pdf: 'red', jpg: 'orange', png: 'orange', jpeg: 'orange', docx: 'blue', doc: 'blue', xls: 'green', xlsx: 'green' };
    return colors[String(type).toLowerCase()] || 'grey';
};

const getStatusInfo = (status) => {
    const map = {
        'pending': { label: 'En attente', color: 'warning', icon: 'mdi-clock-outline' },
        'validated': { label: 'Valide', color: 'success', icon: 'mdi-check-circle' },
        'rejected': { label: 'Rejete', color: 'error', icon: 'mdi-close-circle' }
    };
    return map[status] || { label: 'Inconnu', color: 'grey', icon: 'mdi-help-circle' };
};

const previewDialog = ref(false);
const currentFileUrl = ref('');
const currentFileTitle = ref('');

const previewFile = (archive) => {
    currentFileUrl.value = route('archives.view', archive.id);
    currentFileTitle.value = archive.titre;
    previewDialog.value = true;
};

const downloadFile = (archive) => {
    window.open(route('archives.download', archive.id), '_blank');
};
</script>

<template>
    <Head title="Statistiques" />
    <AuthenticatedLayout>

        <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" rounded="lg">
            <v-icon start>{{ snackbar.icon }}</v-icon>
            {{ snackbar.text }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar.show = false">Fermer</v-btn>
            </template>
        </v-snackbar>

        <v-dialog v-model="confidentialDialog" max-width="400px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar color="teal-darken-2" dark>
                    <v-icon start>mdi-shield-lock-outline</v-icon>
                    <v-toolbar-title>Espace Confidentiel</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon="mdi-close" variant="text" @click="confidentialDialog = false"></v-btn>
                </v-toolbar>
                <v-divider></v-divider>
                <v-form @submit.prevent="unlockConfidential">
                    <v-card-text class="pa-6">
                        <div class="text-body-2 mb-4 text-center">
                            Saisissez votre mot de passe pour acceder aux statistiques confidentielles.
                        </div>
                        <v-text-field v-model="confidentialForm.password" :error-messages="confidentialForm.errors.password"
                            label="Mot de passe" type="password" prepend-inner-icon="mdi-lock" variant="outlined"
                            density="comfortable" rounded="lg" autofocus></v-text-field>
                    </v-card-text>
                    <v-divider></v-divider>
                    <v-card-actions class="pa-4 bg-grey-lighten-5">
                        <v-spacer></v-spacer>
                        <v-btn variant="text" @click="confidentialDialog = false" rounded="lg">Annuler</v-btn>
                        <v-btn color="teal-darken-2" variant="flat" type="submit" :loading="confidentialForm.processing" rounded="lg" class="px-6">
                            Deverrouiller
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>

        <v-container>
            <v-row class="mb-4 align-center">
                <v-col cols="12" class="d-flex justify-space-between align-center">
                    <h2 class="text-h5 font-weight-bold" :class="isConfidentialSpace ? 'text-teal-darken-2' : ''">
                        <v-icon start>{{ isConfidentialSpace ? 'mdi-shield-check' : 'mdi-chart-box' }}</v-icon>
                        {{ isConfidentialSpace ? 'Statistiques Confidentielles' : 'Statistiques Generales' }}
                    </h2>
                    <v-btn v-if="hasConfidentialAccess && !isConfidentialSpace" @click="confidentialDialog = true"
                        color="teal-darken-2" prepend-icon="mdi-shield-lock-outline" variant="tonal" rounded="lg">
                        Statistiques confidentielles
                    </v-btn>
                    <v-btn v-if="hasConfidentialAccess && isConfidentialSpace" @click="router.get(route('stats'))"
                        color="primary" prepend-icon="mdi-arrow-left" variant="tonal" rounded="lg">
                        Retour aux statistiques ordinaires
                    </v-btn>
                </v-col>
            </v-row>

            <v-row v-if="requiresUnlock" class="my-10">
                <v-col cols="12" class="text-center">
                    <v-icon size="80" color="grey-lighten-1">mdi-lock</v-icon>
                    <h3 class="text-h5 font-weight-bold mt-4 text-grey-darken-2">Espace Verrouille</h3>
                    <p class="text-body-1 text-grey-darken-1 mb-6">Veuillez deverrouiller l espace pour voir ces statistiques.</p>
                    <v-btn color="teal-darken-2" variant="flat" size="large" rounded="lg" @click="confidentialDialog = true" prepend-icon="mdi-key">
                        Deverrouiller
                    </v-btn>
                </v-col>
            </v-row>

            <v-row v-if="!requiresUnlock">
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="primary" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.total_archives ?? 0 }}</div>
                                    <div class="text-caption">Documents archives</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.total_dossiers ?? 0 }}</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.total_annees ?? 0 }}</div>
                                    <div class="text-caption">Annees</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.total_mois ?? 0 }}</div>
                                    <div class="text-caption">Mois</div>
                                </div>
                                <v-icon size="40">mdi-calendar-month</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row v-if="!requiresUnlock && (isGestionnaire || isAdmin)" class="mt-4">
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="warning" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.en_attente ?? 0 }}</div>
                                    <div class="text-caption">En attente</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.validees ?? 0 }}</div>
                                    <div class="text-caption">Valides</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.rejetees ?? 0 }}</div>
                                    <div class="text-caption">Rejetes</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.validation_stats?.taux_validation ?? 0 }}%</div>
                                    <div class="text-caption">Taux validation</div>
                                </div>
                                <v-icon size="40">mdi-percent</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row v-if="!requiresUnlock && isArchiviste" class="mt-4">
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.total_archives ?? 0 }}</div>
                                    <div class="text-caption">Mes archives</div>
                                </div>
                                <v-icon size="40">mdi-file-document-edit</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" md="3">
                    <v-card class="rounded-xl" color="info" dark>
                        <v-card-text class="pa-4">
                            <div class="d-flex justify-space-between align-center">
                                <div>
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.archives_ce_mois ?? 0 }}</div>
                                    <div class="text-caption">Ce mois</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.archives_cette_semaine ?? 0 }}</div>
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
                                    <div class="text-h4 font-weight-bold">{{ stats.my_stats?.en_attente ?? 0 }}</div>
                                    <div class="text-caption">En attente</div>
                                </div>
                                <v-icon size="40">mdi-clock-outline</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row v-if="!requiresUnlock" class="mt-4">
                <v-col cols="12" md="6">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-chart-bar</v-icon>
                            Archives par annee
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.archives_par_annee && stats.archives_par_annee.length > 0">
                                <thead>
                                    <tr>
                                        <th>Annee</th>
                                        <th class="text-right">Nombre</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in stats.archives_par_annee" :key="item.annee">
                                        <td>{{ item.annee }}</td>
                                        <td class="text-right"><v-chip color="primary" size="small">{{ item.total }}</v-chip></td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-chart-bar</v-icon>
                                <div>Aucune donnee disponible</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" md="6">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-file-multiple</v-icon>
                            Types de documents
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.archives_par_type && stats.archives_par_type.length > 0">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-right">Nombre</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in stats.archives_par_type" :key="item.type_document">
                                        <td>
                                            <v-icon :color="getFileColor(item.type_document)" size="small" class="mr-2">{{ getFileIcon(item.type_document) }}</v-icon>
                                            {{ item.type_document ? String(item.type_document).toUpperCase() : 'Inconnu' }}
                                        </td>
                                        <td class="text-right"><v-chip color="success" size="small">{{ item.total }}</v-chip></td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-file-multiple</v-icon>
                                <div>Aucune donnee disponible</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row v-if="!requiresUnlock" class="mt-4">
                <v-col cols="12">
                    <v-card class="rounded-xl">
                        <v-card-title class="font-weight-bold">
                            <v-icon start>mdi-clock-outline</v-icon>
                            {{ isArchiviste ? 'Mes dernieres archives' : isDivision ? 'Dernieres archives validees' : 'Dernieres archives ajoutees' }}
                        </v-card-title>
                        <v-card-text>
                            <v-table v-if="stats.recent_archives && stats.recent_archives.length > 0">
                                <thead>
                                    <tr class="bg-grey-lighten-4">
                                        <th>Reference</th>
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
                                            <v-chip size="x-small" color="primary" variant="tonal">{{ archive.chemin || 'Non classe' }}</v-chip>
                                        </td>
                                        <td>{{ formatDate(archive.date_document) }}</td>
                                        <td>
                                            <v-icon :color="getFileColor(archive.type_document)" size="small">{{ getFileIcon(archive.type_document) }}</v-icon>
                                        </td>
                                        <td>
                                            <v-chip :color="getStatusInfo(archive.validation_status).color" size="x-small">
                                                <v-icon start size="x-small">{{ getStatusInfo(archive.validation_status).icon }}</v-icon>
                                                {{ getStatusInfo(archive.validation_status).label }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center">
                                            <v-btn icon="mdi-eye" size="small" variant="text" color="info" @click="previewFile(archive)"></v-btn>
                                            <v-btn icon="mdi-download" size="small" variant="text" color="primary" @click="downloadFile(archive)"></v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="text-center py-8 text-grey">
                                <v-icon size="48" color="grey-lighten-2" class="mb-2">mdi-clock-outline</v-icon>
                                <div>Aucune archive recente</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>

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

    </AuthenticatedLayout>
</template>

<style scoped>
.v-card { transition: all 0.3s ease; }
.v-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); }
.v-table :deep(th) { font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
</style>
