
<script setup>
import { onMounted, ref } from 'vue'
import api, { prepararCsrf } from '../services/api'
import { useRouter } from 'vue-router'
import { useRoute, RouterLink } from 'vue-router'

const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

onMounted(() => {
  if (typeof route.query.email === 'string') email.value = route.query.email
})

async function login() {
  error.value = ''
  loading.value = true

  try {
    await prepararCsrf()

    await api.post('/auth/login', {
      email: email.value,
      password: password.value
    })

    await api.get('/auth/me')

    const redirect = route.query.redirect
    await router.push(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
  } catch (e) {
    error.value =
      e.response?.data?.message || 'Não foi possível entrar. Confira seus dados.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <h1>Ideale Home Care</h1>
      <p>Bem-vindo(a)! Entre na sua conta.</p>

      <form @submit.prevent="login">
        <label>E-mail</label>
        <input
          v-model="email"
          type="email"
          placeholder="Digite seu e-mail"
          required
        />

        <label>Senha</label>
        <input
          v-model="password"
          type="password"
          placeholder="Digite sua senha"
          required
        />

        <p v-if="error" class="error">{{ error }}</p>

        <button type="submit" :disabled="loading">
          {{ loading ? 'Entrando...' : 'Entrar' }}
        </button>
      </form>
      <p class="register-link"><RouterLink to="/recuperar-senha">Esqueci minha senha</RouterLink></p>
      <p class="register-link">Ainda não tem conta? <RouterLink to="/cadastro">Criar cadastro</RouterLink></p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8f5f6;
  padding: 20px;
}

.login-card {
  width: 100%;
  max-width: 400px;
  background: white;
  padding: 40px;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
}

h1 {
  color: #a64d70;
  text-align: center;
  margin-bottom: 8px;
}

p {
  color: #777;
  text-align: center;
  margin-bottom: 25px;
}

label {
  display: block;
  margin: 15px 0 7px;
  color: #444;
}

input {
  width: 100%;
  padding: 12px;
  border: 1px solid #ddd;
  border-radius: 8px;
  box-sizing: border-box;
}

button {
  width: 100%;
  padding: 13px;
  margin-top: 25px;
  background: #a64d70;
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
}

.error {
  color: #c62828;
  margin: 12px 0;
}

.register-link {
  margin: 18px 0 0;
  font-size: 14px;
}

.register-link a {
  color: #a64d70;
  font-weight: 600;
}
</style>
