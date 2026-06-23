<script setup>
    import { ref } from 'vue';
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import { Head, useForm, router } from '@inertiajs/vue3';

    const props = defineProps({
        directions: {
            type: Array,
            default: () => [] // Très important pour éviter les erreurs avant le chargement
        }
    });

    const dialog = ref(false);
    const isEditing = ref(false);
    const currentId = ref(null);
    const search = ref('');

    const headers = [
        { title: 'CODE', key: 'code', align: 'start', width: '150px' },
        { title: 'NOM DE LA DIRECTION', key: 'nom', align: 'start' },
        { title: 'STATUT', key: 'active', align: 'center', width: '120px' },
        { title: 'ACTIONS', key: 'actions', sortable: false, align: 'end', width: '150px' },
    ];

    const form = useForm({
        code: '',
        nom: '',
        description: '',
        active: true,
    });

    const openCreateModal = () => {
        isEditing.value = false;
        currentId.value = null;
        form.reset();
        form.clearErrors();
        dialog.value = true;
    };

    const openEditModal = (direction) => {
        isEditing.value = true;
        currentId.value = direction.id;
        form.code = direction.code;
        form.nom = direction.nom;
        form.description = direction.description;
        form.active = direction.active == 1;
        form.clearErrors();
        dialog.value = true;
    };

    const closeModal = () => {
        dialog.value = false;
        form.reset();
    };

    const submit = () => {
        const action = isEditing.value
            ? route('directions.update', currentId.value)
            : route('directions.store');

        const method = isEditing.value ? 'put' : 'post';

        form[method](action, {
            onSuccess: () => closeModal(),
        });
    };

    const deleteDirection = (id) => {
        if (confirm('Voulez-vous supprimer cette direction ?')) {
            router.delete(route('directions.destroy', id), { preserveScroll: true });
        }
    };
</script>

<template>

    <Head title="Directions" />

    <AuthenticatedLayout>
        <v-card elevation="1" class="rounded-xl overflow-hidden border d-flex flex-column" height="85vh">

            <v-toolbar color="white" border-bottom height="88" flat class="px-4">
                <v-icon icon="mdi-office-building-outline" color="primary" size="32" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Directions</div>
                    <div class="text-caption text-grey-darken-1">Structure organisationnelle</div>
                </div>

                <v-spacer></v-spacer>

                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Recherche rapide..." variant="outlined" hide-details clearable color="primary" class="mx-4" style="max-width: 500px; min-width: 300px;"></v-text-field>

                <v-btn color="primary" prepend-icon="mdi-plus" variant="flat" rounded="lg" size="large" @click="openCreateModal" class="text-none px-6">
                    Ajouter
                </v-btn>
            </v-toolbar>

            <div class="flex-grow-1 overflow-hidden bg-grey-lighten-5 pa-4">
                <v-card border flat class="rounded-lg h-100 d-flex flex-column">
                    <v-data-table :headers="headers" :items="directions" :search="search" hover fixed-header class="h-100" items-per-page="10">
                        <template v-slot:item.code="{ value }">
                            <v-chip variant="tonal" color="primary" size="small" class="font-weight-black">{{ value }}</v-chip>
                        </template>

                        <template v-slot:item.active="{ value }">
                            <v-chip :color="value ? 'success' : 'error'" size="x-small" label class="font-weight-bold">
                                {{ value ? 'ACTIVE' : 'INACTIVE' }}
                            </v-chip>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <v-btn icon="mdi-pencil-outline" variant="text" size="small" color="blue" @click="openEditModal(item)"></v-btn>
                            <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" @click="deleteDirection(item.id)"></v-btn>
                        </template>

                    </v-data-table>
                </v-card>
            </div>
        </v-card>

        <v-dialog v-model="dialog" max-width="600px" persistent>
            <v-card class="rounded-xl">
                <v-toolbar color="white" border-bottom flat>
                    <v-btn icon="mdi-close" variant="text" @click="closeModal" size="small"></v-btn>
                    <v-toolbar-title class="text-body-1 font-weight-bold">
                        {{ isEditing ? 'Modifier la direction' : 'Nouvelle direction' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn :color="isEditing ? 'info' : 'primary'" variant="flat" rounded="lg" @click="submit" :loading="form.processing" class="text-none px-6 mr-2">
                        {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
                    </v-btn>
                </v-toolbar>

                <v-card-text class="pa-6">
                    <v-form @submit.prevent="submit">
                        <v-row dense>
                            <v-col cols="12" md="4">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1">CODE UNIQUE</div>
                                <v-text-field v-model="form.code" :error-messages="form.errors.code" variant="outlined" placeholder="Ex: DAF" persistent-placeholder color="primary"></v-text-field>
                            </v-col>
                            <v-col cols="12" md="8">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1">NOM COMPLET</div>
                                <v-text-field v-model="form.nom" :error-messages="form.errors.nom" variant="outlined" placeholder="Ex: Direction des Affaires Financières" persistent-placeholder color="primary"></v-text-field>
                            </v-col>
                            <v-col cols="12">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1">DESCRIPTION (OPTIONNEL)</div>
                                <v-textarea v-model="form.description" variant="outlined" rows="3" placeholder="Informations complémentaires..." color="primary"></v-textarea>
                            </v-col>
                            <v-col cols="12">
                                <v-card variant="tonal" :color="form.active ? 'success' : 'grey'" class="pa-2 rounded-lg">
                                    <v-switch v-model="form.active" :label="form.active ? 'Direction activée (Sera visible partout)' : 'Direction désactivée'" color="success" hide-details inset></v-switch>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-dialog>
    </AuthenticatedLayout>
</template>

<style scoped>

    /* Correction pour forcer la pagination en bas et le scroll interne */
    :deep(.v-data-table) {
        display: flex;
        flex-direction: column;
    }

    :deep(.v-data-table__wrapper) {
        flex-grow: 1;
    }

    :deep(.v-data-table-footer) {
        border-top: 1px solid #e0e0e0;
        background: white;
    }

    /* En-têtes en majuscules pro */
    :deep(thead th) {
        background-color: #f8f9fa !important;
        font-size: 0.7rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.05em;
        color: #546e7a !important;
    }
</style>
