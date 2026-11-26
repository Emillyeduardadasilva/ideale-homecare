
<script setup>
import { ref, onMounted } from 'vue'
import {
  UserRound,
  Mail,
  Phone,
  ShieldCheck,
  Save,
  LockKeyhole,
  LoaderCircle
} from '@lucide/vue'
import api from '../services/api'

const carregando = ref(true)
const salvando = ref(false)
const alterandoSenha = ref(false)
const mensagem = ref('')
const erro = ref('')

const perfil = ref({
  nome: '',
  email: '',
  telefone: '',
  cargo: ''
})

const senha = ref({
  atual: '',
  nova: '',
  confirmar: ''
})

onMounted(async () => {
  try {
    const resposta = await api.get('/auth/me')
    const usuario = resposta.data?.user || resposta.data?.data || resposta.data

    perfil.value = {
      nome: usuario?.name || usuario?.nome || '',
      email: usuario?.email || '',
      telefone: usuario?.phone || usuario?.telefone || '',
      cargo: usuario?.role || usuario?.cargo || ''
    }
  } catch (e) {
    erro.value = 'Não foi possível carregar os dados do perfil.'
  } finally {
    carregando.value = false
  }
})

async function salvarPerfil() {
  mensagem.value = ''
  erro.value = ''

  if (!perfil.value.nome.trim()) {
    erro.value = 'Informe seu nome.'
    return
  }

  salvando.value = true

  try {
    const resposta = await api.put('/auth/profile', {
      name: perfil.value.nome.trim(),
      phone: perfil.value.telefone.trim() || null
    })

    const usuario = resposta.data?.data

    if (usuario) {
      perfil.value.nome = usuario.name || perfil.value.nome
      perfil.value.telefone = usuario.phone || ''
    }

    mensagem.value = resposta.data?.message || 'Perfil atualizado com sucesso.'
  } catch (e) {
    erro.value =
      e.response?.data?.message ||
      'Não foi possível salvar as informações do perfil.'
  } finally {
    salvando.value = false
  }
}

async function alterarSenha() {
  mensagem.value = ''
  erro.value = ''

  if (!senha.value.atual || !senha.value.nova || !senha.value.confirmar) {
    erro.value = 'Preencha todos os campos de senha.'
    return
  }

  if (senha.value.nova.length < 8) {
    erro.value = 'A nova senha deve ter pelo menos 8 caracteres.'
    return
  }

  if (!/[a-zA-Z]/.test(senha.value.nova) || !/[0-9]/.test(senha.value.nova)) {
    erro.value = 'A nova senha precisa conter letras e números.'
    return
  }

  if (senha.value.nova !== senha.value.confirmar) {
    erro.value = 'As senhas não coincidem.'
    return
  }

  alterandoSenha.value = true

  try {
    const resposta = await api.put('/auth/password', {
      current_password: senha.value.atual,
      password: senha.value.nova,
      password_confirmation: senha.value.confirmar
    })

    mensagem.value = resposta.data?.message || 'Senha alterada com sucesso.'

    senha.value = {
      atual: '',
      nova: '',
      confirmar: ''
    }
  } catch (e) {
    erro.value =
      e.response?.data?.message ||
      'Não foi possível alterar a senha.'
  } finally {
    alterandoSenha.value = false
  }
}
</script>

<template>
  <main class="perfil-page">
    <header class="pagina-header">
      <div>
        <span class="subtitulo">MINHA CONTA</span>
        <h1>Meu Perfil</h1>
        <p>Visualize suas informações e gerencie sua conta.</p>
      </div>

      <div class="icone-header">
        <UserRound :size="25" />
      </div>
    </header>

    <div v-if="carregando" class="estado-carregamento">
      <LoaderCircle class="girando" :size="25" />
      Carregando perfil...
    </div>

    <template v-else>
      <div class="perfil-resumo">
        <div class="avatar">
          <UserRound :size="38" />
        </div>

        <div class="resumo-info">
          <h2>{{ perfil.nome || 'Usuário' }}</h2>
          <p>{{ perfil.email || 'E-mail não informado' }}</p>

          <span class="status-conta">
            <ShieldCheck :size="14" />
            Conta ativa
          </span>
        </div>
      </div>

      <section class="perfil-card">
        <div class="card-titulo">
          <div class="titulo-icone">
            <UserRound :size="19" />
          </div>

          <div>
            <h2>Informações pessoais</h2>
            <p>Seus dados cadastrados na plataforma.</p>
          </div>
        </div>

        <form @submit.prevent="salvarPerfil">
          <div class="campos-grid">
            <label class="campo">
              Nome completo

              <div class="input-wrapper">
                <UserRound :size="17" />
                <input
                  v-model="perfil.nome"
                  type="text"
                  placeholder="Seu nome completo"
                  required
                >
              </div>
            </label>

            <label class="campo">
              E-mail

              <div class="input-wrapper">
                <Mail :size="17" />
                <input
                  v-model="perfil.email"
                  type="email"
                  disabled
                >
              </div>
            </label>

            <label class="campo">
              Telefone

              <div class="input-wrapper">
                <Phone :size="17" />
                <input
                  v-model="perfil.telefone"
                  type="tel"
                  placeholder="(00) 00000-0000"
                >
              </div>
            </label>

            <label class="campo">
              Tipo de conta

              <div class="input-wrapper">
                <ShieldCheck :size="17" />
                <input
                  :value="perfil.cargo || 'Usuário'"
                  disabled
                >
              </div>
            </label>
          </div>

          <button
            class="botao-principal"
            type="submit"
            :disabled="salvando"
          >
            <LoaderCircle v-if="salvando" class="girando" :size="17" />
            <Save v-else :size="17" />

            {{ salvando ? 'Salvando...' : 'Salvar informações' }}
          </button>
        </form>
      </section>

      <section class="perfil-card">
        <div class="card-titulo">
          <div class="titulo-icone">
            <LockKeyhole :size="19" />
          </div>

          <div>
            <h2>Segurança da conta</h2>
            <p>Gerencie sua senha de acesso.</p>
          </div>
        </div>

        <form @submit.prevent="alterarSenha">
          <div class="campos-grid senha-grid">
            <label class="campo">
              Senha atual

              <input
                v-model="senha.atual"
                type="password"
                placeholder="Digite sua senha atual"
                autocomplete="current-password"
                required
              >
            </label>

            <label class="campo">
              Nova senha

              <input
                v-model="senha.nova"
                type="password"
                placeholder="Mínimo de 8 caracteres"
                autocomplete="new-password"
                required
              >
            </label>

            <label class="campo">
              Confirmar nova senha

              <input
                v-model="senha.confirmar"
                type="password"
                placeholder="Repita a nova senha"
                autocomplete="new-password"
                required
              >
            </label>
          </div>

          <p class="dica-senha">
            A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.
          </p>

          <button
            class="botao-secundario"
            type="submit"
            :disabled="alterandoSenha"
          >
            <LoaderCircle v-if="alterandoSenha" class="girando" :size="17" />
            <LockKeyhole v-else :size="17" />

            {{ alterandoSenha ? 'Alterando...' : 'Alterar senha' }}
          </button>
        </form>
      </section>

      <div v-if="mensagem" class="mensagem sucesso">
        {{ mensagem }}
      </div>

      <div v-if="erro" class="mensagem erro">
        {{ erro }}
      </div>
    </template>
  </main>
</template>

<style scoped>
.perfil-page {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding-bottom: 30px;
  color: #24332f;
}

.pagina-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 25px;
}

.subtitulo {
  color: #4b927b;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1.5px;
}

.pagina-header h1 {
  margin: 5px 0;
  font-size: 28px;
  font-weight: 750;
}

.pagina-header p {
  margin: 0;
  color: #81908a;
  font-size: 14px;
}

.icone-header,
.titulo-icone {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #39856e;
  background: #eaf5f0;
  border-radius: 13px;
}

.icone-header {
  width: 52px;
  height: 52px;
}

.perfil-resumo {
  display: flex;
  align-items: center;
  gap: 17px;
  padding: 23px;
  margin-bottom: 20px;
  background: #fff;
  border: 1px solid #e9efec;
  border-radius: 16px;
}

.avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 68px;
  height: 68px;
  flex-shrink: 0;
  color: #39856e;
  background: #eaf5f0;
  border-radius: 50%;
}

.resumo-info h2 {
  margin: 0 0 5px;
  font-size: 19px;
}

.resumo-info p {
  margin: 0 0 9px;
  color: #82908a;
  font-size: 13px;
}

.status-conta {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #39856e;
  font-size: 12px;
  font-weight: 700;
}

.perfil-card {
  padding: 24px;
  margin-bottom: 20px;
  background: #fff;
  border: 1px solid #e9efec;
  border-radius: 16px;
}

.card-titulo {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
}

.titulo-icone {
  width: 40px;
  height: 40px;
  flex-shrink: 0;
}

.card-titulo h2 {
  margin: 0 0 4px;
  font-size: 16px;
}

.card-titulo p {
  margin: 0;
  color: #82908a;
  font-size: 12px;
}

.campos-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 19px;
  margin-bottom: 23px;
}

.senha-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 8px;
  color: #52615b;
  font-size: 12px;
  font-weight: 700;
}

.input-wrapper {
  display: flex;
  align-items: center;
  gap: 9px;
  height: 44px;
  padding: 0 12px;
  color: #91a099;
  background: #fbfcfb;
  border: 1px solid #e3ebe6;
  border-radius: 9px;
}

.input-wrapper input {
  width: 100%;
  height: 100%;
  padding: 0;
  color: #34443d;
  background: transparent;
  border: none;
  outline: none;
  font-size: 13px;
}

.campo > input {
  width: 100%;
  height: 44px;
  padding: 0 12px;
  color: #34443d;
  background: #fbfcfb;
  border: 1px solid #e3ebe6;
  border-radius: 9px;
  outline: none;
  font-size: 13px;
}

.campo input:focus,
.input-wrapper:focus-within {
  border-color: #65a991;
}

.campo input:disabled {
  color: #87938e;
  background: #f1f4f2;
  cursor: not-allowed;
}

.botao-principal,
.botao-secundario {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  min-height: 42px;
  padding: 0 17px;
  color: #fff;
  background: #39856e;
  border: none;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.2s;
}

.botao-principal:hover,
.botao-secundario:hover {
  background: #2d705c;
}

.botao-principal:disabled,
.botao-secundario:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.dica-senha {
  margin: -9px 0 19px;
  color: #82908a;
  font-size: 12px;
}

.mensagem {
  padding: 13px 16px;
  margin-top: 15px;
  border-radius: 9px;
  font-size: 13px;
}

.sucesso {
  color: #267253;
  background: #eaf7ef;
  border: 1px solid #ccebd8;
}

.erro {
  color: #b33b3b;
  background: #fff0f0;
  border: 1px solid #f4d0d0;
}

.estado-carregamento {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 30px;
  color: #718078;
}

.girando {
  animation: girar 1s linear infinite;
}

@keyframes girar {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 750px) {
  .campos-grid,
  .senha-grid {
    grid-template-columns: 1fr;
  }

  .perfil-card {
    padding: 18px;
  }

  .pagina-header h1 {
    font-size: 24px;
  }
}
</style>
