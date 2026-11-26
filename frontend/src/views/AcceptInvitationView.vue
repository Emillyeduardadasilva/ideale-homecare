<script setup>
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()
const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')
const error = ref('')
const message = ref('')
const busy = ref(false)
const needsLogin = ref(false)
const needsVerification = ref(false)

async function accept() {
  error.value = ''; message.value = ''; needsLogin.value = false; needsVerification.value = false
  if (!token.value) { error.value = 'Link incompleto. Peça um novo convite ao gestor.'; return }
  busy.value = true
  try {
    await api.post('/invitations/accept', { token: token.value })
    message.value = 'Convite aceito. Sua conta está vinculada à rede de cuidados.'
    window.setTimeout(() => router.push('/'), 1200)
  } catch (e) {
    if (e.response?.status === 401) { needsLogin.value = true; error.value = 'Entre ou crie uma conta usando o mesmo e-mail do convite.' }
    else if (e.response?.status === 403) { needsVerification.value = true; error.value = 'Confirme seu e-mail antes de aceitar o convite.' }
    else error.value = e.response?.data?.message || 'Convite inválido, expirado ou revogado. Peça outro link ao gestor.'
  } finally { busy.value = false }
}

async function resend() {
  error.value = ''
  try { await api.post('/auth/email/verification-notification'); message.value = 'Se necessário, enviaremos um novo link de confirmação.' }
  catch (e) { error.value = e.response?.data?.message || 'Não foi possível solicitar o link agora.' }
}

const registerLink = computed(() => ({ name: 'register', query: { redirect: route.fullPath } }))
const loginLink = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))
</script>

<template>
  <main class="page"><section class="card">
    <h1>Convite para a rede de cuidados</h1>
    <p>Use a conta cadastrada com o mesmo e-mail para o qual o convite foi enviado.</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p><p v-if="message" class="success" role="status">{{ message }}</p>
    <div v-if="needsLogin"><RouterLink class="button" :to="loginLink">Entrar</RouterLink><RouterLink class="secondary" :to="registerLink">Criar conta</RouterLink></div>
    <button v-else-if="needsVerification" :disabled="busy" @click="resend">Reenviar confirmação de e-mail</button>
    <button v-else :disabled="busy" @click="accept">{{ busy ? 'Verificando...' : 'Aceitar convite' }}</button>
  </section></main>
</template>

<style scoped>
.page{min-height:100vh;display:grid;place-items:center;padding:20px;background:#f8f5f6}.card{box-sizing:border-box;width:100%;max-width:480px;padding:36px;border-radius:16px;background:#fff;box-shadow:0 4px 20px #0001;text-align:center}h1{color:#a64d70}p{color:#666;line-height:1.5}.error{color:#b42318}.success{color:#18794e}button,.button,.secondary{display:inline-block;margin:8px 4px;padding:12px 18px;border:0;border-radius:8px;background:#a64d70;color:#fff;font:inherit;text-decoration:none;cursor:pointer}.secondary{border:1px solid #a64d70;background:#fff;color:#a64d70}button:disabled{opacity:.6}
</style>
