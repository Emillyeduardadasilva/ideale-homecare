
<script setup>
import { inject, ref, computed, onMounted, watch } from 'vue'
import {
  CalendarDays,
  Clock,
  Pill,
  ClipboardList,
  Users,
  CheckCircle2,
  RefreshCw,
  ArrowLeft
} from '@lucide/vue'

import { useRouter } from 'vue-router'
import api from '../services/api'
import { normalizarItem } from '../services/cuidados'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))

const pacientes = ref([])
const compromissos = ref([])
const carregando = ref(false)
const erro = ref('')

function dataLocal() {
  const agora = new Date()
  const ano = agora.getFullYear()
  const mes = String(agora.getMonth() + 1).padStart(2, '0')
  const dia = String(agora.getDate()).padStart(2, '0')

  return `${ano}-${mes}-${dia}`
}

const dataSelecionada = ref(dataLocal())

const dataFormatada = computed(() => {
  if (!dataSelecionada.value) return ''

  const [ano, mes, dia] = dataSelecionada.value.split('-')

  return `${dia}/${mes}/${ano}`
})

const totalTarefas = computed(() =>
  compromissos.value.filter(item => item.type === 'task').length
)

const totalMedicamentos = computed(() =>
  compromissos.value.filter(item => item.type === 'medication').length
)

const totalVisitas = computed(() =>
  compromissos.value.filter(item => item.type === 'visit').length
)
const visitasAguardando = computed(() =>
  compromissos.value.filter(item => item.type === 'visit' && item.status === 'scheduled').length
)
const visitaAtualizando = ref(null)

const agendaOrdenada = computed(() => {
  return [...compromissos.value].sort((a, b) => {
    return new Date(a.starts_at) - new Date(b.starts_at)
  })
})

function horario(data, timezone) {
  if (!data) return '--:--'

  try {
    return new Intl.DateTimeFormat('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timezone || undefined
    }).format(new Date(data))
  } catch {
    return '--:--'
  }
}

function tipoTexto(tipo) {
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

function concluido(status) {
  return [
    'completed',
    'done',
    'administered',
    'taken'
  ].includes(status)
}

function titulo(item) {
  if (item.type === 'medication') {
    return item.medication_name || item.title || 'Medicamento'
  }

  return item.title || tipoTexto(item.type)
}

async function carregarAgenda() {
  carregando.value = true
  erro.value = ''

  try {
    if (userRole.value === 'ideale_professional') {
      const resposta = await api.get('/caregiver/agenda', {
        params: { date: dataSelecionada.value }
      })
      compromissos.value = (resposta.data.data || []).map(normalizarItem)
      pacientes.value = [...new Map(compromissos.value.map(item => [item.elder_id, {
        id: item.elder_id, name: item.elder_name, active: true
      }])).values()]
      return
    }

    const resposta = await api.get('/elders')
    pacientes.value = resposta.data.data || []

    const ativos = pacientes.value.filter(p => p.active)

    const resultados = await Promise.allSettled(
      ativos.map(paciente =>
        api.get(`/elders/${paciente.id}/daily-view`, {
          params: {
            date: dataSelecionada.value
          }
        })
      )
    )

    const lista = []

    resultados.forEach((resultado, indice) => {
      if (resultado.status !== 'fulfilled') {
        console.error(
          'Erro ao carregar agenda:',
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
    console.error('Erro ao carregar agenda:', error)

    erro.value = error.response?.data?.message ||
      'Não foi possível carregar a agenda.'
  } finally {
    carregando.value = false
  }
}

async function alterarStatusVisita(item, status) {
  visitaAtualizando.value = item.source_id
  erro.value = ''
  try {
    await api.post(`/elders/${item.elder_id}/visits/${item.source_id}/status`, {
      status,
      version: item.version
    })
    await carregarAgenda()
  } catch (error) {
    erro.value = error.response?.data?.message || 'Não foi possível atualizar este atendimento.'
  } finally {
    visitaAtualizando.value = null
  }
}

onMounted(carregarAgenda)
watch(userRole, (role) => {
  if (role === 'ideale_professional') carregarAgenda()
})
</script>

<template>
  <main class="agenda-page">
    <header class="agenda-header">
      <div>
        <button class="back-button" @click="router.push('/')">
          <ArrowLeft :size="17" />
          Voltar ao Dashboard
        </button>

        <h1>
          <CalendarDays />
          Agenda
        </h1>

        <p v-if="userRole === 'ideale_professional'">Sua agenda reúne as visitas atribuídas a você nos diferentes pacientes.</p>
        <p v-else>Agenda unificada de eventos familiares e atendimentos dos pacientes.</p>
      </div>

      <button class="refresh-button" @click="carregarAgenda">
        <RefreshCw :size="17" />
        Atualizar
      </button>
    </header>

    <section class="agenda-date">
      <label for="data-agenda">Selecionar data</label>
      <input
        id="data-agenda"
        type="date"
        v-model="dataSelecionada"
        @change="carregarAgenda"
      />
      <strong>{{ dataFormatada }}</strong>
    </section>

    <section class="agenda-stats">
      <article v-if="userRole !== 'ideale_professional'">
        <ClipboardList />
        <span>Tarefas</span>
        <strong>{{ totalTarefas }}</strong>
      </article>

      <article v-if="userRole !== 'ideale_professional'">
        <Pill />
        <span>Medicamentos</span>
        <strong>{{ totalMedicamentos }}</strong>
      </article>

      <article>
        <Users />
        <span>Visitas</span>
        <strong>{{ totalVisitas }}</strong>
      </article>

      <article v-if="userRole === 'ideale_professional'">
        <Clock />
        <span>Aguardando confirmação</span>
        <strong>{{ visitasAguardando }}</strong>
      </article>
    </section>

    <section class="agenda-list">
      <h2>Compromissos do dia</h2>

      <div v-if="carregando" class="agenda-message">
        Carregando agenda...
      </div>

      <div v-else-if="erro" class="agenda-error">
        {{ erro }}
      </div>

      <div
        v-else-if="agendaOrdenada.length === 0"
        class="agenda-message"
      >
        Nenhum compromisso encontrado nesta data.
      </div>

      <article
        v-for="(item, index) in agendaOrdenada"
        :key="`${item.elder_name}-${item.type}-${item.id || index}`"
        class="agenda-card"
      >
        <div class="agenda-card-time">
          <Clock :size="19" />
          <strong>
            {{ horario(item.starts_at, item.timezone) }}
          </strong>
        </div>

        <div class="agenda-card-info">
          <strong>{{ titulo(item) }}</strong>
          <span>{{ item.elder_name }}</span>
          <small>{{ tipoTexto(item.type) }}</small>
        </div>

        <span
          class="agenda-status"
          :class="{ completed: concluido(item.status) }"
        >
          <CheckCircle2 :size="15" />
          {{ statusTexto(item.status) }}
        </span>

        <div v-if="userRole === 'ideale_professional' && item.type === 'visit' && item.allowed_actions?.length" class="agenda-card-actions">
          <button
            v-if="item.allowed_actions.includes('confirm')"
            type="button"
            :disabled="visitaAtualizando === item.source_id"
            @click="alterarStatusVisita(item, 'confirmed')"
          >{{ visitaAtualizando === item.source_id ? 'Salvando...' : 'Confirmar visita' }}</button>
          <button
            v-if="item.allowed_actions.includes('complete')"
            type="button"
            :disabled="visitaAtualizando === item.source_id"
            @click="alterarStatusVisita(item, 'completed')"
          >{{ visitaAtualizando === item.source_id ? 'Salvando...' : 'Concluir atendimento' }}</button>
        </div>
      </article>
    </section>
  </main>
</template>

<style scoped>
.agenda-page {
  min-height: 100vh;
  padding: 35px;
  background: #f7f8fa;
  color: #344054;
}

.agenda-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
}

.back-button,
.refresh-button {
  display: flex;
  align-items: center;
  gap: 8px;
  border: none;
  background: transparent;
  color: #68758a;
  cursor: pointer;
  margin-bottom: 15px;
}

.agenda-header h1 {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 28px;
  color: #344054;
  margin: 0 0 8px;
}

.agenda-header p {
  color: #8993a4;
  margin: 0;
}

.refresh-button {
  background: #a64d70;
  color: white;
  padding: 12px 18px;
  border-radius: 9px;
}

.agenda-date {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 15px;
  background: white;
  padding: 20px;
  border-radius: 12px;
  margin-bottom: 22px;
}

.agenda-date label {
  font-weight: 600;
}

.agenda-date input {
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 7px;
}

.agenda-date strong {
  color: #a64d70;
}

.agenda-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 18px;
  margin-bottom: 25px;
}

.agenda-stats article {
  background: white;
  padding: 22px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.agenda-stats article svg {
  color: #a64d70;
}

.agenda-stats article strong {
  margin-left: auto;
  font-size: 24px;
}

.agenda-list {
  background: white;
  padding: 25px;
  border-radius: 12px;
}

.agenda-list h2 {
  font-size: 19px;
  margin: 0 0 22px;
}

.agenda-card {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 18px 5px;
  border-bottom: 1px solid #edf0f3;
}

.agenda-card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.agenda-card-actions button {
  border: 0;
  border-radius: 7px;
  padding: 8px 11px;
  background: #a64d70;
  color: #fff;
  cursor: pointer;
}

.agenda-card-actions button:disabled {
  opacity: .6;
  cursor: wait;
}

.agenda-card-time {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 85px;
  color: #a64d70;
}

.agenda-card-info {
  display: flex;
  flex-direction: column;
  gap: 5px;
  flex: 1;
}

.agenda-card-info span {
  color: #68758a;
}

.agenda-card-info small {
  color: #a0a8b5;
}

.agenda-status {
  display: flex;
  align-items: center;
  gap: 5px;
  color: #b47b31;
  font-size: 13px;
}

.agenda-status.completed {
  color: #26966b;
}

.agenda-message,
.agenda-error {
  text-align: center;
  padding: 35px 15px;
  color: #8993a4;
}

.agenda-error {
  color: #b43d48;
}

@media (max-width: 700px) {
  .agenda-page {
    padding: 18px;
  }

  .agenda-header {
    align-items: flex-start;
    gap: 10px;
  }

  .agenda-stats {
    grid-template-columns: 1fr;
  }

  .agenda-card {
    flex-wrap: wrap;
  }

  .agenda-status {
    margin-left: 105px;
  }
}
</style>
