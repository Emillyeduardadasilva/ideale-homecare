
<script setup>
import { ref, computed, onMounted, inject } from 'vue'

import {
  Users,
  CalendarDays,
  Pill,
  ClipboardList,
  Bell,
  Search,
  Menu,
  Plus,
  Clock,
  CheckCircle2,
  Phone
} from '@lucide/vue'

import { useRouter } from 'vue-router'
import api from '../services/api'
import { normalizarItem } from '../services/cuidados'

const router = useRouter()
const nomeUsuario = inject('currentUserName', ref(''))
const inicialUsuario = computed(() => nomeUsuario.value.trim().charAt(0).toLocaleUpperCase('pt-BR') || 'I')

const pacientes = ref([])
const compromissos = ref([])
const carregando = ref(false)
const erro = ref('')

const hoje = new Date()

const saudacao = computed(() => {
  const hora = hoje.getHours()

  if (hora < 12) return 'Bom dia!'
  if (hora < 18) return 'Boa tarde!'
  return 'Boa noite!'
})

const dataAtual = computed(() => {
  return hoje.toLocaleDateString('pt-BR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long'
  }).toUpperCase()
})

const pacientesAtivos = computed(() =>
  pacientes.value.filter(p => p.active)
)

const tarefas = computed(() =>
  compromissos.value.filter(item => item.type === 'task')
)

const medicamentos = computed(() =>
  compromissos.value.filter(item => item.type === 'medication')
)

const visitas = computed(() =>
  compromissos.value.filter(item => item.type === 'visit')
)

const tarefasPendentes = computed(() =>
  tarefas.value.filter(item => item.status === 'pending').length
)

const itensDeCuidado = computed(() =>
  compromissos.value.filter(item =>
    ['task', 'medication', 'visit'].includes(item.type)
  )
)

const itensConcluidos = computed(() =>
  itensDeCuidado.value.filter(item =>
    ['completed', 'done', 'administered', 'taken'].includes(item.status)
  ).length
)

const totalCuidados = computed(() => itensDeCuidado.value.length)

const percentualConcluido = computed(() => {
  if (!totalCuidados.value) return 0

  return Math.round(
    (itensConcluidos.value / totalCuidados.value) * 100
  )
})

const agendaHoje = computed(() => {
  return [...compromissos.value]
    .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))
    .slice(0, 8)
})

function formatarHorario(data, timezone) {
  if (!data) return '--:--'

  try {
    return new Intl.DateTimeFormat('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timezone || undefined
    }).format(new Date(data))
  } catch {
    return new Date(data).toLocaleTimeString('pt-BR', {
      hour: '2-digit',
      minute: '2-digit'
    })
  }
}

function nomeTipo(tipo) {
  const tipos = {
    task: 'Tarefa',
    medication: 'Medicamento',
    visit: 'Visita profissional',
    event: 'Evento'
  }

  return tipos[tipo] || 'Compromisso'
}

function statusTexto(status) {
  const textos = {
    pending: 'Pendente',
    completed: 'Concluído',
    done: 'Concluído',
    administered: 'Administrado',
    taken: 'Administrado',
    scheduled: 'Agendado',
    confirmed: 'Confirmado'
  }

  return textos[status] || status || 'Programado'
}

function statusConcluido(status) {
  return [
    'completed',
    'done',
    'administered',
    'taken'
  ].includes(status)
}

function classeMarcador(tipo) {
  const classes = {
    task: 'blue-marker',
    medication: 'green-marker',
    visit: 'purple-marker',
    event: 'orange-marker'
  }

  return classes[tipo] || 'blue-marker'
}

function descricaoCompromisso(item) {
  if (item.type === 'medication') {
    return item.medication_name || 'Medicação programada'
  }

  return nomeTipo(item.type)
}

async function carregarDashboard() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await api.get('/elders')

    pacientes.value = resposta.data.data || []

    const ativos = pacientesAtivos.value

    const resultados = await Promise.allSettled(
      ativos.map(paciente =>
        api.get(`/elders/${paciente.id}/daily-view`)
      )
    )

    const lista = []

    resultados.forEach((resultado, indice) => {
      if (resultado.status !== 'fulfilled') {
        console.error(
          `Erro ao carregar agenda do paciente ${ativos[indice].name}:`,
          resultado.reason
        )
        return
      }

      const dados = resultado.value.data.data
      const itens = dados.items || []

      itens.forEach(item => {
        lista.push({
          ...item,
          elder_name: dados.elder?.name || ativos[indice].name,
          timezone: dados.timezone
        })
      })
    })

    compromissos.value = lista.map(normalizarItem)

  } catch (error) {
    console.error('Erro ao carregar Dashboard:', error)

    erro.value = error.response?.data?.message ||
      'Não foi possível carregar os dados do Dashboard.'
  } finally {
    carregando.value = false
  }
}

onMounted(carregarDashboard)
</script>

<template>
  <div class="dashboard">
    <main class="main-content">

      <header class="topbar">
        <button class="icon-button" title="Menu">
          <Menu />
        </button>

        <div class="topbar-actions">
          <button class="icon-button">
            <Search />
          </button>

          <button
            class="icon-button notification"
            title="Abrir notificações"
            @click="router.push('/notificacoes')"
          >
            <Bell />
            <span></span>
          </button>

          <div class="profile-avatar">{{ inicialUsuario }}</div>
        </div>
      </header>

      <section class="welcome">
        <div>
          <span class="eyebrow">{{ dataAtual }}</span>
          <h1>{{ saudacao }} 👋</h1>
          <p>Acompanhe e gerencie os cuidados dos seus pacientes.</p>
        </div>

        <button
          class="primary-button"
          @click="router.push('/pacientes')"
        >
          <Plus :size="18" />
          Novo paciente
        </button>
      </section>

      <div v-if="erro" class="dashboard-error">
        {{ erro }}
      </div>

      <section class="stats-grid">

        <article class="stat-card">
          <div class="stat-icon green"><Users /></div>
          <span>Pacientes ativos</span>
          <h2>{{ carregando ? '...' : pacientesAtivos.length }}</h2>
          <small>Pacientes cadastrados</small>
        </article>

        <article class="stat-card">
          <div class="stat-icon blue"><ClipboardList /></div>
          <span>Tarefas de hoje</span>
          <h2>{{ carregando ? '...' : tarefas.length }}</h2>
          <small>{{ tarefasPendentes }} pendentes</small>
        </article>

        <article class="stat-card">
          <div class="stat-icon orange"><Pill /></div>
          <span>Medicamentos</span>
          <h2>{{ carregando ? '...' : medicamentos.length }}</h2>
          <small>Programados para hoje</small>
        </article>

        <article class="stat-card">
          <div class="stat-icon purple"><CalendarDays /></div>
          <span>Visitas hoje</span>
          <h2>{{ carregando ? '...' : visitas.length }}</h2>
          <small>Atendimentos agendados</small>
        </article>

      </section>

      <section class="content-grid">

        <article class="panel agenda-panel">

          <div class="panel-heading">
            <div>
              <h2>Agenda de hoje</h2>
              <p>Confira os próximos cuidados programados.</p>
            </div>

            <button
              class="text-button"
              @click="carregarDashboard"
            >
              Atualizar
            </button>
          </div>

          <div v-if="carregando" class="empty-dashboard">
            Carregando compromissos...
          </div>

          <div
            v-else-if="agendaHoje.length === 0"
            class="empty-dashboard"
          >
            Nenhum compromisso encontrado para hoje.
          </div>

          <div
            v-for="item in agendaHoje"
            :key="item.id"
            class="agenda-item"
          >
            <div class="agenda-time">
              {{ formatarHorario(item.starts_at, item.timezone) }}
            </div>

            <div
              class="agenda-marker"
              :class="classeMarcador(item.type)"
            ></div>

            <div class="agenda-info">
              <strong>
                {{ item.type === 'medication' && item.medication_name
                  ? item.medication_name
                  : item.title }}
              </strong>

              <span>
                {{ item.elder_name }} · {{ descricaoCompromisso(item) }}
              </span>
            </div>

            <span
              class="status"
              :class="statusConcluido(item.status) ? 'done' : 'pending'"
            >
              <CheckCircle2
                v-if="statusConcluido(item.status)"
                :size="14"
              />

              <Clock v-else :size="14" />

              {{ statusTexto(item.status) }}
            </span>
          </div>

        </article>

        <article class="panel progress-panel">

          <div class="panel-heading">
            <div>
              <h2>Resumo diário</h2>
              <p>Progresso dos cuidados</p>
            </div>
          </div>

          <div class="progress-number">
            {{ carregando ? '...' : percentualConcluido + '%' }}
          </div>

          <p class="progress-description">
            Dos cuidados concluídos hoje
          </p>

          <div class="progress-track">
            <div
              class="progress-fill"
              :style="{ width: percentualConcluido + '%' }"
            ></div>
          </div>

          <div class="progress-footer">
            <span>{{ itensConcluidos }} concluídos</span>
            <strong>{{ totalCuidados }} cuidados</strong>
          </div>

          <div class="help-card">
            <div class="help-icon"><Phone /></div>

            <div>
              <strong>Precisa de ajuda?</strong>
              <p>Entre em contato com a equipe de suporte.</p>
            </div>
          </div>

        </article>

      </section>

      <footer>
        Ideale Home Care · Gestão de cuidados com carinho.
      </footer>

    </main>
  </div>
</template>

<style scoped>
.dashboard-error {
  background: #fff0f0;
  color: #b43d48;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.empty-dashboard {
  text-align: center;
  color: #999;
  padding: 35px 15px;
}

.progress-fill {
  transition: width 0.3s ease;
}
</style>
