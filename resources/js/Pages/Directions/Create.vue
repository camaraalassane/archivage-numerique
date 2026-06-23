<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    nom: '',
    description: '',
    active: true,
});

const submit = () => {
    form.post(route('directions.store'));
};
</script>

<template>
    <Head title="Nouvelle Direction" />
    <AuthenticatedLayout>
        <v-container>
            <v-row justify="center">
                <v-col cols="12" md="8">
                    <v-card>
                        <v-card-title class="bg-primary text-white">
                            Nouvelle Direction
                        </v-card-title>
                        
                        <v-card-text class="mt-4">
                            <v-form @submit.prevent="submit">
                                <v-text-field
                                    v-model="form.code"
                                    label="Code de la direction"
                                    :error-messages="form.errors.code"
                                    variant="outlined"
                                ></v-text-field>

                                <v-text-field
                                    v-model="form.nom"
                                    label="Nom complet"
                                    :error-messages="form.errors.nom"
                                    variant="outlined"
                                    class="mt-2"
                                ></v-text-field>

                                <v-textarea
                                    v-model="form.description"
                                    label="Description"
                                    :error-messages="form.errors.description"
                                    variant="outlined"
                                    class="mt-2"
                                ></v-textarea>

                                <v-switch
                                    v-model="form.active"
                                    label="Direction active"
                                    color="primary"
                                ></v-switch>

                                <div class="d-flex justify-end mt-4">
                                    <v-btn variant="text" :href="route('directions.index')" class="me-2">Annuler</v-btn>
                                    <v-btn type="submit" color="primary" :loading="form.processing">Enregistrer</v-btn>
                                </div>
                            </v-form>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>
    </AuthenticatedLayout>
</template>