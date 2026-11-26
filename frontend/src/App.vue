
<script setup>
import { computed, onMounted, provide, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
  HeartPulse,
  LayoutDashboard,
  Users,
  CalendarDays,
  Pill,
  ClipboardList,
  Activity,
  Bell,
  LogOut,
  Menu,
  FileText,
  Wallet,
  ChevronDown,
  UserRound
} from '@lucide/vue'

import api from './services/api'

const route = useRoute()
const router = useRouter()
const role = ref('loading')
const userName = ref('')
const userId = ref(null)
const podeGerenciarRede = ref(false)
provide('currentUserRole', role)
provide('currentUserId', userId)

const menuRecolhido = ref(false)
const gerenciamentoAberto = ref(true)
const consultaAberta = ref(true)
const paginasPublicas = ['/login', '/cadastro', '/aceitar-convite', '/recuperar-senha', '/reset-password']

const itemDashboard = { nome: 'Dashboard', caminho: '/', icone: LayoutDashboard }
const itemPacientes = { nome: 'Pacientes', caminho: '/pacientes', icone: Users }
const itemAgenda = { nome: 'Agenda', caminho: '/agenda', icone: CalendarDays }
const itemMedicamentos = { nome: 'Medicamentos', caminho: '/medicamentos', icone: Pill }
const itemTarefas = { nome: 'Tarefas', caminho: '/tarefas', icone: ClipboardList }
const itemEvolucao = { nome: 'Evolução', caminho: '/evolucao', icone: Activity }
const itemRelatorios = { nome: 'Relatórios', caminho: '/relatorios', icone: FileText }
const itemNotificacoes = { nome: 'Notificações', caminho: '/notificacoes', icone: Bell }
const itemPerfil = { nome: 'Meu perfil', caminho: '/perfil', icone: UserRound }

const menuGerenciar = computed(() => {
  switch (role.value) {
    case 'system_admin':
      return [
        { nome: 'Contas e administração', caminho: '/admin', icone: LayoutDashboard },
        itemPerfil
      ]
    case 'family':
      return [
        itemDashboard,
        itemPacientes,
        itemMedicamentos,
        itemTarefas,
        itemEvolucao,
        ...(podeGerenciarRede.value ? [{ nome: 'Rede de cuidadores', caminho: '/rede-cuidadores', icone: Users }] : []),
        { nome: 'Financeiro', caminho: '/financeiro', icone: Wallet },
        itemPerfil
      ]
    case 'ideale_admin':
      return [itemPerfil]
    case 'ideale_professional':
      return [itemAgenda, itemMedicamentos, itemTarefas, itemEvolucao, itemPerfil]
    case 'elder':
      return [itemPerfil]
    default:
      return []
  }
})

const menuConsulta = computed(() => {
  switch (role.value) {
    case 'system_admin':
      return [itemPacientes, itemAgenda, itemMedicamentos, itemTarefas, itemEvolucao, itemRelatorios, itemNotificacoes]
    case 'family':
      return [itemAgenda, itemRelatorios, itemNotificacoes]
    case 'ideale_admin':
      return [
        { nome: 'Painel da equipe', caminho: '/cuidador', icone: LayoutDashboard },
        { nome: 'Idosos vinculados', caminho: '/pacientes', icone: Users },
        itemAgenda,
        itemMedicamentos,
        itemTarefas,
        itemEvolucao,
        itemRelatorios,
        itemNotificacoes
      ]
    case 'ideale_professional':
      return [
        { nome: 'Painel da equipe', caminho: '/cuidador', icone: LayoutDashboard },
        { nome: 'Idosos vinculados', caminho: '/pacientes', icone: Users },
        itemRelatorios,
        itemNotificacoes
      ]
    case 'elder':
      return [itemDashboard, itemAgenda, itemMedicamentos, itemTarefas, itemNotificacoes]
    default:
      return []
  }
})

const tituloMenu = computed(() => {
  if (role.value === 'system_admin') return 'ADMINISTRAÇÃO GLOBAL'
  if (['ideale_admin', 'ideale_professional'].includes(role.value)) return 'ÁREA DE CUIDADO'
  if (role.value === 'elder') return 'ÁREA DO IDOSO'
  return role.value === 'loading' ? '' : 'ÁREA DA FAMÍLIA'
})

const nomePerfil = computed(() => ({
  system_admin: 'Administrador do sistema',
  ideale_admin: 'Equipe administrativa',
  ideale_professional: 'Cuidador',
  family: 'Familiar',
  elder: 'Idoso'
})[role.value] || 'Perfil')

const inicialPerfil = computed(() => userName.value.trim().charAt(0).toLocaleUpperCase('pt-BR') || 'I')

let carregamentoPerfil = null

async function carregarPerfil() {
  if (carregamentoPerfil) return carregamentoPerfil

  const perfilAnterior = role.value
  if (!perfilAnterior) role.value = 'loading'

  carregamentoPerfil = (async () => {
    try {
      const response = await api.get('/auth/me')
      const user = response.data?.data || response.data?.user || response.data
      role.value = user?.role || ''
      userId.value = user?.id ?? null
      userName.value = user?.name || ''
      podeGerenciarRede.value = false
      if (user?.role === 'family') {
        try {
          const elders = await api.get('/elders')
          podeGerenciarRede.value = (elders.data?.data || []).some((elder) => elder.is_manager)
        } catch { podeGerenciarRede.value = false }
      }
    } catch {
      if (!perfilAnterior || perfilAnterior === 'loading') {
        role.value = ''
        userId.value = null
        userName.value = ''
        podeGerenciarRede.value = false
      }
    } finally {
      carregamentoPerfil = null
    }
  })()

  return carregamentoPerfil
}

onMounted(carregarPerfil)
watch(() => route.path, (path) => {
  if (paginasPublicas.includes(path)) {
    role.value = ''
    userId.value = null
    userName.value = ''
    podeGerenciarRede.value = false
    return
  }
  if (!role.value) carregarPerfil()
})

function alternarMenu() {
  menuRecolhido.value = !menuRecolhido.value
}

function alternarGerenciamento() {
  gerenciamentoAberto.value = !gerenciamentoAberto.value
}

function alternarConsulta() {
  consultaAberta.value = !consultaAberta.value
}

function navegar(caminho) {
  router.push(caminho)
}

async function logout() {
  try {
    await api.post('/auth/logout')
    router.push('/login')
  } catch (error) {
    console.error('Erro ao sair:', error)
    alert('Não foi possível encerrar a sessão. Tente novamente.')
  }
}
</script>

<template>
  <router-view v-if="paginasPublicas.includes(route.path)" />

  <div
    v-else
    class="app-layout"
    :class="{ 'menu-recolhido': menuRecolhido }"
  >
    <aside class="app-sidebar">

      <div class="app-brand">
        <HeartPulse :size="30" />

        <div class="brand-text">
          <h2>Ideale</h2>
          <span>HOME CARE</span>
        </div>
      </div>

      <button
        class="app-toggle"
        @click="alternarMenu"
        :title="menuRecolhido ? 'Expandir menu' : 'Recolher menu'"
      >
        <Menu :size="21" />
      </button>

      <p class="app-menu-label">{{ tituloMenu }}</p>

      <nav class="app-navigation">

        <div v-if="menuGerenciar.length" class="menu-grupo">
          <button
            class="menu-grupo-titulo"
            @click="menuRecolhido ? navegar(menuGerenciar[0].caminho) : alternarGerenciamento()"
            :title="menuRecolhido ? 'Gerenciar' : ''"
          >
            <ClipboardList :size="19" />
            <span>Gerenciar</span>
            <ChevronDown
              v-if="!menuRecolhido"
              :size="16" :class="{ 'seta-aberta': gerenciamentoAberto }"
            />
          </button>

          <div v-if="gerenciamentoAberto && !menuRecolhido" class="menu-subitens">
            <router-link
              v-for="item in menuGerenciar"
              :key="item.caminho"
              :to="item.caminho"
              :class="{ selecionado: route.path === item.caminho }"
            >
              <component :is="item.icone" :size="17" />
              <span>{{ item.nome }}</span>
            </router-link>
          </div>
        </div>

        <div v-if="menuConsulta.length" class="menu-grupo">
          <button
            class="menu-grupo-titulo"
            @click="menuRecolhido ? navegar(menuConsulta[0].caminho) : alternarConsulta()"
            :title="menuRecolhido ? 'Somente consulta' : ''"
          >
            <Users :size="19" />
            <span>Somente consulta</span>
            <ChevronDown
              v-if="!menuRecolhido"
              :size="16" :class="{ 'seta-aberta': consultaAberta }"
            />
          </button>

          <div v-if="consultaAberta && !menuRecolhido" class="menu-subitens">
            <router-link
              v-for="item in menuConsulta"
              :key="item.caminho"
              :to="item.caminho"
              :class="{ selecionado: route.path === item.caminho }"
            >
              <component :is="item.icone" :size="17" />
              <span>{{ item.nome }}</span>
            </router-link>
          </div>
        </div>

      </nav>

      <div class="app-sidebar-bottom">
        <div class="app-avatar">{{ inicialPerfil }}</div>

        <div class="app-profile">
          <strong>{{ userName || 'Usuário' }}</strong>
          <span>{{ nomePerfil }}</span>
        </div>

        <button @click="logout" title="Sair">
          <LogOut :size="19" />
        </button>
      </div>

    </aside>

    <main class="app-content">
      <router-view />
    </main>
  </div>
</template>

<style>
.app-layout {
  display: flex;
  min-height: 100vh;
  background: #f5f7fb;
}

.app-sidebar {
  position: relative;
  width: 250px;
  min-width: 250px;
  min-height: 100vh;
  background: #172b4d;
  color: white;
  padding: 30px 20px;
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  transition: width 0.25s ease, min-width 0.25s ease;
}

.app-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 25px;
}

.brand-text h2 {
  margin: 0;
  font-size: 22px;
}

.brand-text span {
  font-size: 10px;
  letter-spacing: 2px;
}

.app-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 12px;
  margin-bottom: 20px;
  background: transparent;
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
}

.app-toggle:hover {
  background: rgba(255, 255, 255, 0.1);
}

.app-menu-label {
  font-size: 11px;
  color: #aab8ce;
  margin-bottom: 12px;
}

.app-navigation {
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.app-navigation > a,
.menu-grupo-titulo,
.menu-subitens a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 13px 12px;
  color: #dce5f3;
  text-decoration: none;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  transition: 0.2s;
}

.app-navigation > a:hover,
.app-navigation > a.selecionado,
.menu-subitens a:hover,
.menu-subitens a.selecionado,
.menu-grupo-titulo:hover {
  background: #29466f;
  color: white;
}

.menu-grupo-titulo {
  width: 100%;
  background: transparent;
  cursor: pointer;
  text-align: left;
}

.menu-grupo-titulo span {
  flex: 1;
}

.menu-grupo-titulo svg:last-child {
  transition: transform 0.2s ease;
}

.menu-grupo-titulo .seta-aberta {
  transform: rotate(180deg);
}

.menu-subitens {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 5px 0 8px 14px;
  padding-left: 10px;
  border-left: 1px solid rgba(255,255,255,0.2);
}

.menu-subitens a {
  padding: 11px 10px;
  font-size: 13px;
  gap: 10px;
}

.app-sidebar-bottom {
  margin-top: auto;
  padding-top: 25px;
  border-top: 1px solid rgba(255,255,255,0.15);
  display: flex;
  align-items: center;
  gap: 10px;
}

.app-avatar {
  width: 36px;
  height: 36px;
  background: #426b9f;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}

.app-profile {
  display: flex;
  flex-direction: column;
  flex: 1;
  font-size: 12px;
}

.app-profile span {
  color: #aab8ce;
  font-size: 10px;
}

.app-sidebar-bottom button {
  background: transparent;
  border: none;
  color: white;
  cursor: pointer;
}

.app-content {
  flex: 1;
  min-width: 0;
}

.menu-recolhido .app-sidebar {
  width: 78px;
  min-width: 78px;
  padding: 25px 10px;
}

.menu-recolhido .brand-text,
.menu-recolhido .app-menu-label,
.menu-recolhido .app-navigation span,
.menu-recolhido .app-profile,
.menu-recolhido .app-sidebar-bottom button,
.menu-recolhido .menu-grupo-titulo svg:last-child {
  display: none;
}

.menu-recolhido .app-brand,
.menu-recolhido .app-toggle,
.menu-recolhido .app-navigation > a,
.menu-recolhido .menu-grupo-titulo,
.menu-recolhido .app-sidebar-bottom {
  justify-content: center;
}

.menu-recolhido .app-toggle {
  padding: 12px 5px;
}

.menu-recolhido .app-navigation > a,
.menu-recolhido .menu-grupo-titulo {
  padding: 13px 5px;
}

.menu-recolhido .app-sidebar-bottom {
  justify-content: center;
}

@media (max-width: 700px) {
  .app-sidebar {
    width: 78px;
    min-width: 78px;
    padding: 25px 10px;
  }

  .app-sidebar .brand-text,
  .app-sidebar .app-menu-label,
  .app-sidebar .app-navigation span,
  .app-sidebar .app-profile,
  .app-sidebar .app-sidebar-bottom button,
  .app-sidebar .menu-grupo-titulo svg:last-child {
    display: none;
  }

  .app-brand,
  .app-navigation > a,
  .menu-grupo-titulo,
  .app-sidebar-bottom {
    justify-content: center;
  }

  .app-navigation > a,
  .menu-grupo-titulo {
    padding: 13px 5px;
  }

  .menu-subitens {
    display: none;
  }
}
</style>
