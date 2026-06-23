<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({ directions: Array });

const form = useForm({
    code: '',
    nom: '',
    direction_id: null,
    description: '',
    active: true,
});

const submit = () => {
    form.post(route('services.store'));
};
</script>

<template>
    <Head title="Nouveau Service" />
    <AuthenticatedLayout>
        <v-container>
            <v-row justify="center">
                <v-col cols="12" md="8">
                    <v-card>
                        <v-card-title class="bg-secondary text-white pa-4">
                            Créer un nouveau Service
                        </v-card-title>
                        
                        <v-card-text class="pt-6">
                            <v-form @submit.prevent="submit">
                                <v-row>
                                    <v-col cols="12" md="4">
                                        <v-text-field
                                            v-model="form.code"
                                            label="Code"
                                            :error-messages="form.errors.code"
                                            variant="outlined"
                                            density="compact"
                                        ></v-text-field>
                                    </v-col>
                                    <v-col cols="12" md="8">
                                        <v-text-field
                                            v-model="form.nom"
                                            label="Nom du Service"
                                            :error-messages="form.errors.nom"
                                            variant="outlined"
                                            density="compact"
                                        ></v-text-field>
                                    </v-col>
                                </v-row>

                                <v-select
                                    v-model="form.direction_id"
                                    :items="directions"
                                    item-title="nom"
                                    item-value="id"
                                    label="Direction de rattachement"
                                    :error-messages="form.errors.direction_id"
                                    variant="outlined"
                                    class="mt-2"
                                ></v-select>

                                <v-textarea
                                    v-model="form.description"
                                    label="Description (Optionnel)"
                                    variant="outlined"
                                    rows="3"
                                    class="mt-2"
                                ></v-textarea>

                                <v-switch
                                    v-model="form.active"
                                    label="Service opérationnel"
                                    color="secondary"
                                    hide-details
                                ></v-switch>

                                <v-divider class="my-4"></v-divider>

                                <div class="d-flex justify-end">
                                    <v-btn variant="text" :href="route('services.index')" class="me-2">Retour</v-btn>
                                    <v-btn type="submit" color="secondary" :loading="form.processing">Confirmer</v-btn>
                                </div>
                            </v-form>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>
    </AuthenticatedLayout>
</template>