<script setup>
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api, { initializeCsrf } from '../services/api'

const route = useRoute()
const router = useRouter()
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const token = ref(typeof route.query.token === 'string' ? route.query.token : '')
const password = ref('')
const confirmation = ref('')
const error = ref('')
const message = ref('')
const loading = ref(false)

async function resetPassword() {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    await initializeCsrf()
    await api.post('/auth/reset-password', { email: email.value, token: token.value, password: password.value, password_confirmation: confirmation.value })
    message.value = 'Senha redefinida. Você já pode entrar.'
    window.setTimeout(() => router.push({ name: 'login', query: { email: email.value } }), 1200)
  } catch (e) {
    const validation = e.response?.data?.errors
    error.value = validation ? Object.values(validation).flat().join(' ') : (e.response?.data?.message || 'Link inválido ou expirado. Solicite outra recuperação.')
  } finally { loading.value = false }
}
</script>

<template>
  <main class="page"><section class="card">
    <h1>Escolha uma nova senha</h1>
    <form @submit.prevent="resetPassword">
      <label for="email">E-mail</label><input id="email" v-model="email" type="email" autocomplete="email" required>
      <label for="password">Nova senha</label><input id="password" v-model="password" type="password" autocomplete="new-password" minlength="8" required>
      <small>Mínimo de 8 caracteres, incluindo letras e números.</small>
      <label for="confirmation">Confirmar senha</label><input id="confirmation" v-model="confirmation" type="password" autocomplete="new-password" required>
      <p v-if="error" class="error" role="alert">{{ error }}</p><p v-if="message" class="success" role="status">{{ message }}</p>
      <button :disabled="loading || !token">{{ loading ? 'Salvando...' : 'Redefinir senha' }}</button>
      <p v-if="!token" class="error">Link incompleto. Solicite uma nova recuperação.</p>
    </form>
    <p><RouterLink to="/login">Voltar para entrar</RouterLink></p>
  </section></main>
</template>

<style scoped>
.page{min-height:100vh;display:grid;place-items:center;padding:20px;background:#f8f5f6}.card{box-sizing:border-box;width:100%;max-width:420px;padding:36px;border-radius:16px;background:#fff;box-shadow:0 4px 20px #0001}h1{text-align:center;color:#a64d70}p{text-align:center;color:#666;line-height:1.5}label{display:block;margin:15px 0 7px;color:#444}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #ddd;border-radius:8px}small{display:block;margin-top:6px;color:#666}button{width:100%;margin:22px 0;padding:13px;border:0;border-radius:8px;background:#a64d70;color:#fff;cursor:pointer}button:disabled{opacity:.6}.error{color:#b42318}.success{color:#18794e}
</style>
