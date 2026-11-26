<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { RouterLink } from 'vue-router'
import api from '../services/api'

const email = ref('')
const error = ref('')
const message = ref('')
const loading = ref(false)

async function requestReset() {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    await axios.get('http://localhost:8000/sanctum/csrf-cookie', { withCredentials: true })
    const response = await api.post('/auth/forgot-password', { email: email.value })
    message.value = response.data.message || 'Se houver uma conta com esse e-mail, enviaremos as instruções.'
  } catch (e) {
    error.value = e.response?.data?.message || 'Não foi possível solicitar a recuperação agora.'
  } finally { loading.value = false }
}
</script>

<template>
  <main class="page"><section class="card">
    <h1>Recuperar senha</h1>
    <p>Informe o e-mail da sua conta para receber as instruções.</p>
    <form @submit.prevent="requestReset">
      <label for="email">E-mail</label><input id="email" v-model="email" type="email" autocomplete="email" required>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="message" class="success" role="status">{{ message }}</p>
      <p v-if="message" class="note">No ambiente local, o link fica no log do backend: <code>backend/storage/logs/laravel.log</code>.</p>
      <button :disabled="loading">{{ loading ? 'Enviando...' : 'Enviar instruções' }}</button>
    </form>
    <p><RouterLink to="/login">Voltar para entrar</RouterLink></p>
  </section></main>
</template>

<style scoped>
.page{min-height:100vh;display:grid;place-items:center;padding:20px;background:#f8f5f6}.card{box-sizing:border-box;width:100%;max-width:420px;padding:36px;border-radius:16px;background:#fff;box-shadow:0 4px 20px #0001}h1{text-align:center;color:#a64d70}p{text-align:center;color:#666;line-height:1.5}label{display:block;margin:15px 0 7px;color:#444}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #ddd;border-radius:8px}button{width:100%;margin:22px 0;padding:13px;border:0;border-radius:8px;background:#a64d70;color:#fff;cursor:pointer}button:disabled{opacity:.6}.error{color:#b42318}.success{color:#18794e}.note{font-size:12px}code{overflow-wrap:anywhere}
</style>
