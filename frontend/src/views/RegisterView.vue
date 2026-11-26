<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()
const name = ref('')
const email = ref('')
const password = ref('')
const confirmation = ref('')
const error = ref('')
const loading = ref(false)

async function register() {
  error.value = ''
  loading.value = true
  try {
    await axios.get('http://localhost:8000/sanctum/csrf-cookie', { withCredentials: true })
    await api.post('/auth/register', { name: name.value, email: email.value, password: password.value, password_confirmation: confirmation.value })
    await api.get('/auth/me')
    const redirect = route.query.redirect
    await router.push(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
  } catch (e) {
    const validation = e.response?.data?.errors
    error.value = validation ? Object.values(validation).flat().join(' ') : (e.response?.data?.message || 'Não foi possível criar a conta.')
  } finally { loading.value = false }
}
</script>

<template>
  <main class="page"><section class="card">
    <h1>Ideale Home Care</h1><p>Crie sua conta familiar.</p>
    <form @submit.prevent="register">
      <label for="name">Nome</label><input id="name" v-model="name" autocomplete="name" required maxlength="200">
      <label for="email">E-mail</label><input id="email" v-model="email" type="email" autocomplete="email" required>
      <label for="password">Senha</label><input id="password" v-model="password" type="password" autocomplete="new-password" minlength="8" required>
      <small>Mínimo de 8 caracteres, incluindo letras e números.</small>
      <label for="confirmation">Confirmar senha</label><input id="confirmation" v-model="confirmation" type="password" autocomplete="new-password" required>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button :disabled="loading">{{ loading ? 'Criando conta...' : 'Criar conta' }}</button>
    </form>
    <p>Já tem conta? <RouterLink to="/login">Entrar</RouterLink></p>
    <small>Confirme seu e-mail para acessar os dados de cuidado.</small>
  </section></main>
</template>

<style scoped>
.page{min-height:100vh;display:grid;place-items:center;padding:20px;background:#f8f5f6}.card{box-sizing:border-box;width:100%;max-width:420px;padding:36px;border-radius:16px;background:#fff;box-shadow:0 4px 20px #0001}h1{text-align:center;color:#a64d70}p{text-align:center;color:#666}label{display:block;margin:15px 0 7px;color:#444}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #ddd;border-radius:8px}small{display:block;margin-top:6px;color:#666}button{width:100%;margin:22px 0;padding:13px;border:0;border-radius:8px;background:#a64d70;color:#fff;cursor:pointer}button:disabled{opacity:.6}.error{color:#b42318}
</style>
