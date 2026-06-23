<script setup>
    import { useForm, Head, Link } from '@inertiajs/vue3';
    import { ref } from 'vue';

    defineProps({ canResetPassword: Boolean, status: String });

    const form = useForm({ email: '', password: '', remember: false });
    const showPassword = ref(false);
    
    const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <v-app>
        <v-container fluid class="pa-0 fill-height">
            <v-row no-gutters class="fill-height">
                <!-- Colonne gauche avec logo DTTIA (fond bleu foncé) -->
                <v-col cols="12" md="6" class="d-none d-md-flex bg-blue-darken-4 align-center justify-center h-screen">
                    <div class="text-center pa-10">
                        <v-img src="/images/LOGOdttia.jpeg" width="220" class="mb-8 rounded-circle bg-white pa-4 elevation-10 mx-auto"></v-img>
                        <h1 class="text-h2 font-weight-black text-white">DTTIA</h1>
                        <p class="text-h6 text-white opacity-80 mt-4">Direction des Transmissions et Informatique</p>
                        <p class="text-body-1 text-white opacity-70 mt-2">Gestion du Magasin</p>
                    </div>
                </v-col>

                <!-- Colonne droite avec formulaire de connexion (design original) -->
                <v-col cols="12" md="6" class="d-flex align-center justify-center bg-grey-lighten-4 pa-6 overflow-y-auto h-screen">
                    <v-card elevation="0" rounded="xl" class="pa-6 pa-md-10 w-100 bg-white" style="max-width: 450px; border: 1px solid rgba(0, 0, 0, 0.08);">
                        <div class="text-center mb-6">
                            <!-- Logo visible sur mobile uniquement -->
                            <v-avatar color="primary" size="64" class="mb-4 d-md-none elevation-3">
                                <v-icon icon="mdi-shield-lock" size="36" color="white" />
                            </v-avatar>
                            
                            <h2 class="text-h4 font-weight-bold mb-2">Connexion</h2>
                            <p class="text-grey mb-8">Connectez-vous pour accéder à vos archives</p>
                        </div>

                        <v-alert v-if="status" type="success" variant="tonal" class="mb-4" density="compact">{{ status }}</v-alert>

                        <v-form @submit.prevent="submit">
                            <v-text-field 
                                v-model="form.email" 
                                label="Email" 
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
                                class="mb-6"
                                @click:append-inner="showPassword = !showPassword"
                            />

                            <div class="d-flex justify-space-between align-center mb-6">
                                <v-checkbox 
                                    v-model="form.remember" 
                                    label="Rester connecté" 
                                    density="compact" 
                                    color="primary"
                                    hide-details
                                />
                                <Link 
                                    v-if="canResetPassword" 
                                    :href="route('password.request')" 
                                    class="text-primary text-decoration-none"
                                >
                                    Oublié ?
                                </Link>
                            </div>

                            <v-btn 
                                type="submit" 
                                color="primary" 
                                block 
                                size="x-large" 
                                :loading="form.processing" 
                                class="rounded-lg font-weight-bold"
                            >
                                SE CONNECTER
                            </v-btn>

                            <div class="text-center mt-6">
                                <span class="text-grey">Pas encore de compte ?</span>
                                <Link 
                                    :href="route('register')" 
                                    class="text-primary text-decoration-none font-weight-bold ms-1"
                                >
                                    Créer un compte
                                </Link>
                            </div>
                        </v-form>
                    </v-card>
                </v-col>
            </v-row>
        </v-container>
    </v-app>
</template>

<style scoped>
    .h-screen {
        height: 100vh;
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