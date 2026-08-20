<!-- resources/js/Pages/ActivityLogs/Index.vue -->
<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    logs: { type: Object, required: true },
});

const showClearDialog = ref(false);

const clearLogs = () => {
    router.delete(route('activity-logs.clear'), {
        onSuccess: () => {
            showClearDialog.value = false;
        }
    });
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const d = new Date(dateString);
    return d.toLocaleString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
};

const goToPage = (url) => {
    if (url) {
        router.get(url);
    }
};

const getActionColor = (action) => {
    if (action.includes('created')) return 'success';
    if (action.includes('deleted')) return 'error';
    if (action.includes('updated')) return 'warning';
    if (action.includes('validated')) return 'info';
    if (action.includes('login')) return 'primary';
    if (action.includes('logout')) return 'blue-grey';
    return 'grey';
};

const getActionLabel = (action) => {
    const map = {
        'archive_created': 'Création archive',
        'archive_batch_created': 'Création en masse',
        'archive_updated': 'Modification archive',
        'archive_deleted': 'Suppression archive',
        'archive_validated': 'Validation archive',
        'user_created': 'Création utilisateur',
        'user_updated': 'Modification utilisateur',
        'user_deleted': 'Suppression utilisateur',
        'user_login': 'Connexion',
        'user_logout': 'Déconnexion',
        'dossier_created': 'Création dossier',
        'dossier_batch_created': 'Création dossiers (lot)',
        'dossier_updated': 'Modification dossier',
        'dossier_deleted': 'Suppression dossier',
        'dossier_mois_created': 'Création mois',
        'dossier_mois_updated': 'Modification mois',
        'dossier_mois_deleted': 'Suppression mois',
        'dossier_annee_created': 'Création année',
        'dossier_annee_updated': 'Modification année',
        'dossier_annee_deleted': 'Suppression année',
    };
    return map[action] || action;
};

const getRoleName = (roleId) => {
    const roles = {
        1: 'Archiviste',
        2: 'Gestionnaire',
        3: 'Administrateur',
        4: 'Division'
    };
    return roles[roleId] || 'Inconnu';
};
</script>

<template>
    <Head title="Journal des événements" />
    <AuthenticatedLayout>
        <v-card elevation="1" class="rounded-xl overflow-hidden">
            <v-toolbar color="white" border-bottom class="px-4 py-2">
                <div class="d-flex align-center">
                    <v-icon icon="mdi-history" color="primary" size="28" class="mr-3"></v-icon>
                    <div>
                        <div class="text-h6 font-weight-bold">Journal des événements</div>
                        <div class="text-caption text-grey">Historique des actions utilisateurs</div>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-btn
                    color="error"
                    variant="flat"
                    prepend-icon="mdi-delete-sweep"
                    @click="showClearDialog = true"
                    v-if="$page.props.auth.user.role == 3"
                >
                    Vider le journal
                </v-btn>
            </v-toolbar>

            <v-table hover density="comfortable">
                <thead>
                    <tr class="bg-grey-lighten-4">
                        <th class="text-overline">Date</th>
                        <th class="text-overline">Utilisateur</th>
                        <th class="text-overline">Rôle</th>
                        <th class="text-overline">Action</th>
                        <th class="text-overline">Description</th>
                        <th class="text-overline">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id">
                        <td class="text-caption text-grey-darken-1">{{ formatDate(log.created_at) }}</td>
                        <td class="font-weight-medium">{{ log.user?.name || 'Système/Supprimé' }}</td>
                        <td class="text-caption">
                            <v-chip size="x-small" v-if="log.user">
                                {{ getRoleName(log.user.role) }}
                            </v-chip>
                        </td>
                        <td>
                            <v-chip :color="getActionColor(log.action)" size="small">
                                {{ getActionLabel(log.action) }}
                            </v-chip>
                        </td>
                        <td class="text-caption text-grey-darken-3" style="max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" :title="log.description">
                            {{ log.description }}
                        </td>
                        <td class="text-caption text-grey">{{ log.ip_address }}</td>
                    </tr>
                    <tr v-if="!logs.data || logs.data.length === 0">
                        <td colspan="6" class="text-center py-8 text-grey">Aucun événement enregistré.</td>
                    </tr>
                </tbody>
            </v-table>
            
            <v-divider></v-divider>
            
            <!-- Pagination -->
            <div class="pa-3 bg-grey-lighten-5 d-flex align-center justify-space-between flex-wrap gap-2">
                <div class="text-caption text-grey-darken-1">
                    Affichage de {{ logs.from || 0 }} à {{ logs.to || 0 }} sur {{ logs.total || 0 }} événements
                </div>
                <div class="d-flex gap-1">
                    <v-btn v-for="(link, k) in logs.links" :key="k"
                        :disabled="link.url === null" :variant="link.active ? 'flat' : 'text'"
                        :color="link.active ? 'primary' : 'grey-darken-1'" size="small" class="px-2"
                        @click="goToPage(link.url)" v-html="link.label">
                    </v-btn>
                </div>
            </div>
        </v-card>
    </AuthenticatedLayout>

    <!-- Dialog Confirmation Vider -->
    <v-dialog v-model="showClearDialog" max-width="500">
        <v-card class="rounded-xl">
            <v-card-title class="bg-error text-white pa-4">
                <v-icon icon="mdi-alert" class="mr-2"></v-icon>
                Confirmation de suppression
            </v-card-title>
            <v-card-text class="pa-4 pt-6">
                Êtes-vous sûr de vouloir vider <strong>entièrement</strong> le journal des événements ?
                <br><br>
                Cette action est irréversible et supprimera l'historique de toutes les actions effectuées sur l'application.
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer></v-spacer>
                <v-btn color="grey-darken-1" variant="text" @click="showClearDialog = false">Annuler</v-btn>
                <v-btn color="error" variant="flat" @click="clearLogs">Oui, vider le journal</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.v-table :deep(th) {
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background-color: #f5f5f5;
}
.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
</style>
