<script setup>
    import { ref } from 'vue';
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import { Head, useForm, router } from '@inertiajs/vue3';

    const props = defineProps({
        services: Array,
        directions: {
            type: Array,
            default: () => [] // Sécurité pour le chargement différé
        }
    });

    // --- ÉTATS ---
    const dialog = ref(false);
    const isEditing = ref(false);
    const currentServiceId = ref(null);
    const search = ref('');

    const headers = [
        { title: 'CODE', key: 'code', align: 'start', width: '120px' },
        { title: 'NOM DU SERVICE', key: 'nom', align: 'start' },
        { title: 'DIRECTION', key: 'direction.nom', align: 'start' },
        { title: 'STATUT', key: 'active', align: 'center', width: '120px' },
        { title: 'ACTIONS', key: 'actions', sortable: false, align: 'end', width: '150px' },
    ];

    const form = useForm({
        code: '',
        nom: '',
        direction_id: null,
        description: '',
        active: true,
    });

    // --- LOGIQUE D'OUVERTURE ---
    const openCreateModal = () => {
        isEditing.value = false;
        currentServiceId.value = null;
        form.reset();
        form.clearErrors();
        dialog.value = true;
    };

    const openEditModal = (service) => {
        isEditing.value = true;
        currentServiceId.value = service.id;
        form.code = service.code;
        form.nom = service.nom;
        form.direction_id = service.direction_id;
        form.description = service.description;
        form.active = service.active == 1;
        form.clearErrors();
        dialog.value = true;
    };

    const closeModal = () => {
        dialog.value = false;
        form.reset();
    };

    // --- ACTIONS ---
    const submit = () => {
        const method = isEditing.value ? 'put' : 'post';
        const url = isEditing.value
            ? route('services.update', currentServiceId.value)
            : route('services.store');

        form[method](url, {
            onSuccess: () => closeModal(),
        });
    };

    const deleteService = (id) => {
        if (confirm('Voulez-vous vraiment supprimer ce service ?')) {
            router.delete(route('services.destroy', id), { preserveScroll: true });
        }
    };
</script>

<template>

    <Head title="Services" />

    <AuthenticatedLayout>
        <v-card elevation="1" class="rounded-xl overflow-hidden border d-flex flex-column" height="85vh">

            <v-toolbar color="white" border-bottom height="88" flat class="px-4">
                <v-icon icon="mdi-tray-full" color="primary" size="32" class="mr-3"></v-icon>
                <div>
                    <div class="text-h6 font-weight-bold">Services</div>
                    <div class="text-caption text-grey-darken-1">Unités de travail par direction</div>
                </div>

                <v-spacer></v-spacer>

                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Rechercher un service, un code ou une direction..." variant="outlined" hide-details clearable color="primary" class="mx-4" style="max-width: 500px; min-width: 350px;"></v-text-field>

                <v-btn color="primary" prepend-icon="mdi-plus" variant="flat" rounded="lg" size="large" @click="openCreateModal" class="text-none px-6">
                    Nouveau Service
                </v-btn>
            </v-toolbar>

            <div class="flex-grow-1 overflow-hidden bg-grey-lighten-5 pa-4">
                <v-card border flat class="rounded-lg h-100 d-flex flex-column">
                    <v-data-table :headers="headers" :items="services" :search="search" hover fixed-header class="h-100" items-per-page="10">
                        <template v-slot:item.code="{ value }">
                            <v-chip variant="tonal" color="primary" size="small" class="font-weight-black">{{ value }}</v-chip>
                        </template>

                        <template v-slot:item.direction.nom="{ value }">
                            <span class="text-body-2 text-medium-emphasis">
                                <v-icon size="small" class="mr-1">mdi-office-building-outline</v-icon>
                                {{ value }}
                            </span>
                        </template>

                        <template v-slot:item.active="{ value }">
                            <v-chip :color="value ? 'success' : 'error'" size="x-small" label class="font-weight-bold">
                                {{ value ? 'ACTIF' : 'INACTIF' }}
                            </v-chip>
                        </template>

                        <template v-slot:item.actions="{ item }">
                            <v-btn icon="mdi-pencil-outline" variant="text" size="small" color="blue" @click="openEditModal(item)"></v-btn>
                            <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" @click="deleteService(item.id)"></v-btn>
                        </template>
                    </v-data-table>
                </v-card>
            </div>
        </v-card>

        <v-dialog v-model="dialog" max-width="650px" persistent transition="dialog-bottom-transition">
            <v-card class="rounded-xl overflow-hidden">
                <v-toolbar color="white" border-bottom flat>
                    <v-btn icon="mdi-close" variant="text" @click="closeModal" size="small"></v-btn>
                    <v-toolbar-title class="text-body-1 font-weight-bold">
                        {{ isEditing ? 'Modifier le service' : 'Créer un nouveau service' }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn :color="isEditing ? 'blue-darken-1' : 'primary'" variant="flat" rounded="lg" @click="submit" :loading="form.processing" class="text-none px-6 mr-2">
                        {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
                    </v-btn>
                </v-toolbar>

                <v-card-text class="pa-8">
                    <v-form @submit.prevent="submit">
                        <v-row dense>
                            <v-col cols="12" md="4">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1 uppercase">CODE SERVICE</div>
                                <v-text-field v-model="form.code" :error-messages="form.errors.code" variant="outlined" placeholder="Ex: S-COMPTA" persistent-placeholder color="primary"></v-text-field>
                            </v-col>
                            <v-col cols="12" md="8">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1 uppercase">NOM DU SERVICE</div>
                                <v-text-field v-model="form.nom" :error-messages="form.errors.nom" variant="outlined" placeholder="Ex: Comptabilité Générale" persistent-placeholder color="primary"></v-text-field>
                            </v-col>

                            <v-col cols="12">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1 uppercase">DIRECTION DE RATTACHEMENT</div>
                                <v-select v-model="form.direction_id" :items="directions" item-title="nom" item-value="id" variant="outlined" placeholder="Sélectionnez une direction" color="primary" :error-messages="form.errors.direction_id" prepend-inner-icon="mdi-office-building"></v-select>
                            </v-col>

                            <v-col cols="12">
                                <div class="text-caption font-weight-bold mb-1 text-grey-darken-1 uppercase">DESCRIPTION</div>
                                <v-textarea v-model="form.description" variant="outlined" rows="3" placeholder="Notes sur les missions du service..." color="primary"></v-textarea>
                            </v-col>

                            <v-col cols="12">
                                <v-card variant="tonal" :color="form.active ? 'success' : 'grey'" class="pa-2 rounded-lg border-dashed">
                                    <v-switch v-model="form.active" :label="form.active ? 'Service actif et opérationnel' : 'Service inactif'" color="success" hide-details inset></v-switch>
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

    /* Force la pagination en bas */
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

    /* En-têtes pro */
    :deep(thead th) {
        background-color: #f8f9fa !important;
        font-size: 0.7rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.05em;
        color: #546e7a !important;
    }

    .uppercase {
        text-transform: uppercase;
        letter-spacing: 1px;
    }
</style>
