<!-- resources/js/Layouts/AuthenticatedLayout.vue -->
<script setup>
import { computed, ref } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';

const page = usePage();
const route = window.route;

const isLoading = ref(false);

router.on('start', () => { isLoading.value = true; });
router.on('finish', () => { isLoading.value = false; });

const userInitials = computed(() => {
    const name = page.props.auth?.user?.name;
    if (!name) return 'U';
    return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
});

// === VÉRIFICATIONS DES RÔLES ===
const userRole = computed(() => page.props.auth?.user?.role ?? 0);

const isArchiviste = computed(() => userRole.value === 1);
const isGestionnaire = computed(() => userRole.value === 2);
const isAdmin = computed(() => userRole.value === 3);
const isDivision = computed(() => userRole.value === 4);

// === PERMISSIONS ===
// Division ne voit que Dashboard
const canSeeMenu = computed(() => !isDivision.value);

// Gestion des années : uniquement Admin
const canManageYears = computed(() => isAdmin.value);

// Gestion des mois : uniquement Admin
const canManageMonths = computed(() => isAdmin.value);

// Gestion des dossiers : UNIQUEMENT Gestionnaire et Admin (pas Archiviste)
const canManageDossiers = computed(() => isGestionnaire.value || isAdmin.value);

// Gestion des utilisateurs : uniquement Admin
const canManageUsers = computed(() => isAdmin.value);

// Import : uniquement Admin
const canImport = computed(() => isAdmin.value);

// Archivage : accessible à tous sauf Division
const canViewArchives = computed(() => !isDivision.value);

// Statistiques : accessible à tous sauf Division
const canViewStats = computed(() => !isDivision.value);

// Archives en attente/rejetées : UNIQUEMENT Archiviste
const canViewPendingRejected = computed(() => isArchiviste.value);

// Archives en attente de validation : UNIQUEMENT Gestionnaire
const canViewPendingArchives = computed(() => isGestionnaire.value);

const currentPage = computed(() => {
    const componentName = page.component;
    if (componentName === 'Dashboard') return 'Dashboard';
    if (componentName === 'Annees/Index') return 'Annees';
    if (componentName === 'Mois/Index') return 'Mois';
    if (componentName === 'Dossiers/Index') return 'Dossiers';
    if (componentName === 'Archives/Index') return 'Archives';
    if (componentName === 'Stats/Index') return 'Stats';
    if (componentName === 'Users/Index') return 'Users';
    if (componentName === 'Import/Index') return 'Import';
    if (componentName === 'Archiviste/PendingRejected') return 'PendingRejected';
    if (componentName === 'Gestionnaire/PendingArchives') return 'PendingArchives';
    return null;
});

// Récupérer le nom du rôle pour l'affichage
const roleName = computed(() => {
    switch (userRole.value) {
        case 1: return 'Archiviste';
        case 2: return 'Gestionnaire';
        case 3: return 'Administrateur';
        case 4: return 'Division';
        default: return 'Inconnu';
    }
});

const roleColor = computed(() => {
    switch (userRole.value) {
        case 1: return 'blue';
        case 2: return 'green';
        case 3: return 'red';
        case 4: return 'orange';
        default: return 'grey';
    }
});

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <v-app>
        <v-overlay v-model="isLoading" class="align-center justify-center" contained persistent>
            <v-progress-circular color="yellow-accent-4" indeterminate size="64"></v-progress-circular>
        </v-overlay>

        <v-card elevation="0" class="rounded-0">
            <v-toolbar class="text-white gradient-toolbar" extended height="80">
                <v-toolbar-title class="font-weight-black text-h5">
                    <v-icon icon="mdi-archive-lock" class="me-2"></v-icon>
                    ARCHIVAGE
                </v-toolbar-title>

                <v-spacer></v-spacer>

                <div class="d-flex align-center me-4" v-if="page.props.auth?.user">
                    <v-avatar color="yellow-accent-4" size="32" class="me-2">
                        <span class="text-black text-caption font-weight-bold">{{ userInitials }}</span>
                    </v-avatar>
                    <span class="text-subtitle-2 font-weight-bold hidden-sm-and-down">
                        {{ page.props.auth.user.name }}
                    </span>
                    <v-chip :color="roleColor" size="x-small" class="ml-2" label>
                        {{ roleName }}
                    </v-chip>
                </div>

                <v-btn icon @click="logout" v-if="page.props.auth?.user">
                    <v-icon>mdi-logout</v-icon>
                    <v-tooltip activator="parent" location="bottom">Déconnexion</v-tooltip>
                </v-btn>

                <template v-slot:extension>
                    <!-- Si Division : pas de tabs, que le Dashboard -->
                    <v-tabs v-if="!isDivision" :model-value="currentPage" align-tabs="title" color="yellow-accent-4"
                        slider-color="yellow-accent-4">
                        <!-- Dashboard : accessible à tous -->
                        <v-tab value="Dashboard" :tag="Link" :href="route('dashboard')">
                            <v-icon start>mdi-view-dashboard</v-icon> Dashboard
                        </v-tab>

                        <!-- Années : UNIQUEMENT ADMIN -->
                        <v-tab v-if="canManageYears" value="Annees" :tag="Link" :href="route('annees.index')">
                            <v-icon start>mdi-calendar</v-icon> Années
                        </v-tab>

                        <!-- Mois : UNIQUEMENT ADMIN -->
                        <v-tab v-if="canManageMonths" value="Mois" :tag="Link" :href="route('mois.index')">
                            <v-icon start>mdi-calendar-month</v-icon> Mois
                        </v-tab>

                        <!-- Dossiers : UNIQUEMENT Gestionnaire et Admin -->
                        <v-tab v-if="canManageDossiers" value="Dossiers" :tag="Link" :href="route('dossiers.index')">
                            <v-icon start>mdi-folder-multiple</v-icon> Dossiers
                        </v-tab>

                        <!-- Archives : accessible à tous sauf Division -->
                        <v-tab v-if="canViewArchives" value="Archives" :tag="Link" :href="route('archives.index')">
                            <v-icon start>mdi-cloud-upload</v-icon> Archivage
                        </v-tab>

                        <!-- Statistiques : accessible à tous sauf Division -->
                        <v-tab v-if="canViewStats" value="Stats" :tag="Link" :href="route('stats')">
                            <v-icon start>mdi-chart-box</v-icon> Statistiques
                        </v-tab>

                        <!-- Import : UNIQUEMENT ADMIN -->
                        <v-tab v-if="canImport" value="Import" :tag="Link" :href="route('import.index')">
                            <v-icon start>mdi-import</v-icon> Import
                        </v-tab>

                        <!-- Utilisateurs : UNIQUEMENT ADMIN -->
                        <v-tab v-if="canManageUsers" value="Users" :tag="Link" :href="route('users.index')">
                            <v-icon start>mdi-account-group</v-icon> Utilisateurs
                        </v-tab>

                        <!-- Archives en attente/rejetées : UNIQUEMENT ARCHIVISTE -->
                        <v-tab v-if="canViewPendingRejected" value="PendingRejected" :tag="Link"
                            :href="route('archiviste.pending-rejected')">
                            <v-icon start>mdi-archive-clock</v-icon> À traiter
                        </v-tab>

                        <!-- Archives en attente de validation : UNIQUEMENT GESTIONNAIRE -->
                        <v-tab v-if="canViewPendingArchives" value="PendingArchives" :tag="Link"
                            :href="route('gestionnaire.pending-archives')">
                            <v-icon start>mdi-account-check</v-icon> À valider
                        </v-tab>
                    </v-tabs>

                    <!-- Division : seulement le Dashboard -->
                    <v-tabs v-else :model-value="currentPage" align-tabs="title" color="yellow-accent-4"
                        slider-color="yellow-accent-4">
                        <v-tab value="Dashboard" :tag="Link" :href="route('dashboard')">
                            <v-icon start>mdi-view-dashboard</v-icon> Dashboard
                        </v-tab>
                    </v-tabs>
                </template>
            </v-toolbar>
        </v-card>

        <v-main class="bg-grey-lighten-4">
            <v-container fluid class="pa-6">
                <v-fade-transition>
                    <v-alert v-if="page.props.flash?.success" type="success" variant="elevated" closable class="mb-4">
                        {{ page.props.flash.success }}
                    </v-alert>
                    <v-alert v-if="page.props.flash?.error" type="error" variant="elevated" closable class="mb-4">
                        {{ page.props.flash.error }}
                    </v-alert>
                </v-fade-transition>

                <slot />
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.v-tab {
    text-transform: none !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
}

:deep(a.v-tab) {
    text-decoration: none !important;
}

.gradient-toolbar {
    background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%) !important;
}
</style>
