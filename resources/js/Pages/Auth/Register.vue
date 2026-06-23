<script setup>
    import { useForm, Head, Link } from '@inertiajs/vue3';
    import { ref } from 'vue';

    const form = useForm({ 
        name: '', 
        email: '', 
        password: '', 
        password_confirmation: '' 
    });
    
    const showPassword = ref(false);
    const showConfirmPassword = ref(false);
    
    const submit = () => form.post(route('register'), { 
        onFinish: () => form.reset('password', 'password_confirmation') 
    });
</script>

<template>
    <v-app>
        <v-container fluid class="pa-0 fill-height">
            <v-row no-gutters class="fill-height">
                <!-- Colonne gauche avec logo DTTIA (fixe) -->
                <v-col cols="12" md="6" class="d-none d-md-flex bg-blue-darken-4 align-center justify-center" style="height: 100vh; position: sticky; top: 0;">
                    <div class="text-center pa-10">
                        <v-img src="/images/LOGOdttia.jpeg" width="220" class="mb-8 rounded-circle bg-white pa-4 elevation-10 mx-auto"></v-img>
                        <h1 class="text-h2 font-weight-black text-white">DTTIA</h1>
                        <p class="text-h6 text-white opacity-80 mt-4">Direction des Transmissions et Informatique</p>
                        <p class="text-body-1 text-white opacity-70 mt-2">Gestion du Magasin</p>
                    </div>
                </v-col>

                <!-- Colonne droite avec formulaire (scrollable) -->
                <v-col cols="12" md="6" class="bg-grey-lighten-4" style="height: 100vh; overflow-y: auto;">
                    <div class="d-flex align-center justify-center min-height-full pa-6">
                        <v-card elevation="0" rounded="xl" class="pa-6 pa-md-10 w-100 bg-white" style="max-width: 450px; border: 1px solid rgba(0, 0, 0, 0.08);">
                            <div class="text-center mb-6">
                                <!-- Logo visible sur mobile uniquement -->
                                <v-avatar color="primary" size="64" class="mb-4 d-md-none elevation-3">
                                    <v-icon icon="mdi-account-plus" size="36" color="white" />
                                </v-avatar>
                                
                                <h2 class="text-h4 font-weight-bold mb-2">Inscription</h2>
                                <p class="text-grey mb-8">Rejoignez la plateforme d'archivage numérique</p>
                            </div>

                            <v-form @submit.prevent="submit">
                                <v-text-field 
                                    v-model="form.name" 
                                    label="Nom complet" 
                                    variant="outlined" 
                                    :error-messages="form.errors.name" 
                                    prepend-inner-icon="mdi-account-outline"
                                    color="primary"
                                    class="mb-2"
                                />
                                
                                <v-text-field 
                                    v-model="form.email" 
                                    label="Email" 
                                    type="email" 
                                    variant="outlined" 
                                    :error-messages="form.errors.email" 
                                    prepend-inner-icon="mdi-email-outline"
                                    color="primary"
                                    class="mb-2"
                                />
                                
                                <v-text-field 
                                    v-model="form.password" 
                                    label="Mot de passe" 
                                    :type="showPassword ? 'text' : 'password'"
                                    variant="outlined" 
                                    :error-messages="form.errors.password" 
                                    prepend-inner-icon="mdi-lock-outline"
                                    :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                                    color="primary"
                                    class="mb-2"
                                    @click:append-inner="showPassword = !showPassword"
                                />
                                
                                <v-text-field 
                                    v-model="form.password_confirmation" 
                                    label="Confirmer le mot de passe" 
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    variant="outlined" 
                                    :error-messages="form.errors.password_confirmation" 
                                    prepend-inner-icon="mdi-lock-check-outline"
                                    :append-inner-icon="showConfirmPassword ? 'mdi-eye-off' : 'mdi-eye'"
                                    color="primary"
                                    class="mb-6"
                                    @click:append-inner="showConfirmPassword = !showConfirmPassword"
                                />

                                <v-btn 
                                    type="submit" 
                                    color="primary" 
                                    block 
                                    size="x-large" 
                                    :loading="form.processing" 
                                    class="rounded-lg font-weight-bold"
                                >
                                    S'INSCRIRE
                                </v-btn>

                                <div class="text-center mt-6">
                                    <span class="text-grey">Déjà inscrit ?</span>
                                    <Link 
                                        :href="route('login')" 
                                        class="text-primary text-decoration-none font-weight-bold ms-1"
                                    >
                                        Connectez-vous
                                    </Link>
                                </div>
                            </v-form>
                        </v-card>
                    </div>
                </v-col>
            </v-row>
        </v-container>
    </v-app>
</template>

<style scoped>
    .fill-height {
        height: 100vh;
    }
    
    .min-height-full {
        min-height: 100%;
    }

    /* Animation d'entrée très légère */
    .v-container {
        animation: fadeIn 0.4s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    /* Style pour le lien */
    a {
        color: inherit;
        text-decoration: none;
    }
</style>