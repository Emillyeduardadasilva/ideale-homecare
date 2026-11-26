
<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

import {
  Bell,
  CheckCircle2,
  Clock,
  Pill,
  CalendarDays,
  RefreshCw,
  Check,
  BellRing,
  ArrowLeft,
  LayoutDashboard
} from '@lucide/vue'

import api from '../services/api'
import { listenForCareReminders } from '../services/realtime'

const router = useRouter()

const notificacoes = ref([])
const pacientes = ref([])
const carregando = ref(false)
const erro = ref('')
const filtro = ref('todas')
const pushDisponivel = ref(false)
const permissaoPush = ref('default')
const pushPublicKey = ref('')
const pushAtivo = ref(false)
const pushCarregando = ref(false)
const pushErro = ref('')
const pushSucesso = ref('')
const pushRegistration = ref(null)
const pushSubscription = ref(null)
const pushSubscriptionId = ref(null)
let pararRealtime = () => {}

const totalNaoLidas = computed(() =>
  notificacoes.value.filter(item => !item.read_at).length
)

const notificacoesFiltradas = computed(() => {
  if (filtro.value === 'nao-lidas') {
    return notificacoes.value.filter(item => !item.read_at)
  }

  if (filtro.value === 'lidas') {
    return notificacoes.value.filter(item => item.read_at)
  }

  return notificacoes.value
})

function nomePaciente(id) {
  const paciente = pacientes.value.find(p => p.id === id)
  return paciente?.name || 'Paciente'
}

function tipoNotificacao(item) {
  const tipo = item.reminder?.source_type

  if (tipo === 'medication') return 'Medicamento'
  if (tipo === 'visit') return 'Consulta ou visita'

  return 'Lembrete'
}

function descricaoNotificacao(item) {
  const tipo = item.reminder?.source_type

  if (tipo === 'medication') {
    return 'Existe um horário de medicamento que requer atenção.'
  }

  if (tipo === 'visit') {
    return 'Existe um compromisso de atendimento programado.'
  }

  return 'Você possui um novo lembrete de cuidado.'
}

function iconeNotificacao(item) {
  return item.reminder?.source_type === 'medication'
    ? Pill
    : CalendarDays
}

function formatarData(data) {
  if (!data) return 'Data não disponível'

  const valor = new Date(data)

  if (Number.isNaN(valor.getTime())) {
    return 'Data não disponível'
  }

  return valor.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

async function carregarNotificacoes() {
  carregando.value = true
  erro.value = ''

  try {
    const [respostaNotificacoes, respostaPacientes] = await Promise.all([
      api.get('/notifications'),
      api.get('/elders')
    ])

    notificacoes.value = respostaNotificacoes.data.data || []
    pacientes.value = respostaPacientes.data.data || []

  } catch (error) {
    console.error('Erro ao carregar notificações:', error)

    erro.value = error.response?.data?.message ||
      'Não foi possível carregar as notificações.'
  } finally {
    carregando.value = false
  }
}

async function marcarComoLida(item) {
  if (item.read_at) return

  try {
    const resposta = await api.post(
      `/notifications/${item.id}/read`
    )

    item.read_at = resposta.data.data?.read_at ||
      new Date().toISOString()

  } catch (error) {
    console.error('Erro ao marcar notificação:', error)

    alert('Não foi possível marcar a notificação como lida.')
  }
}

async function marcarTodasComoLidas() {
  for (const item of notificacoes.value) {
    if (!item.read_at) {
      await marcarComoLida(item)
    }
  }
}

function chavePublicaParaBytes(chave) {
  const base64 = chave.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64.padEnd(base64.length + ((4 - base64.length % 4) % 4), '=')
  return Uint8Array.from(atob(padded), caractere => caractere.charCodeAt(0))
}

async function obterChavePush() {
  const resposta = await api.get('/push/public-key')
  pushPublicKey.value = resposta.data.public_key || ''
  return pushPublicKey.value
}

async function salvarInscricaoPush(inscricao) {
  const dados = inscricao.toJSON()
  const resposta = await api.post('/push/subscriptions', {
    endpoint: dados.endpoint,
    keys: dados.keys
  })
  pushSubscription.value = inscricao
  pushSubscriptionId.value = resposta.data.data.id
  pushAtivo.value = true
}

async function prepararPush() {
  pushDisponivel.value = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
  if (!pushDisponivel.value) return
  permissaoPush.value = Notification.permission

  try {
    await obterChavePush()
    if (!pushPublicKey.value) return
    pushRegistration.value = await navigator.serviceWorker.register('/sw.js')
    const inscricao = await pushRegistration.value.pushManager.getSubscription()
    if (inscricao && Notification.permission === 'granted') {
      await salvarInscricaoPush(inscricao)
    }
  } catch (error) {
    pushErro.value = error.response?.data?.message || 'Não foi possível preparar as notificações neste dispositivo.'
  }
}

async function ativarPush() {
  pushCarregando.value = true
  pushErro.value = ''
  pushSucesso.value = ''
  let novaInscricao = null
  try {
    const chave = pushPublicKey.value || await obterChavePush()
    if (!chave) throw new Error('O servidor ainda não configurou a chave de notificações push.')
    const permissao = await Notification.requestPermission()
    permissaoPush.value = permissao
    if (permissao !== 'granted') throw new Error('Permita notificações nas configurações do navegador para ativar os avisos.')
    pushRegistration.value ||= await navigator.serviceWorker.register('/sw.js')
    const existente = await pushRegistration.value.pushManager.getSubscription()
    novaInscricao = existente || await pushRegistration.value.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: chavePublicaParaBytes(chave)
    })
    await salvarInscricaoPush(novaInscricao)
    pushSucesso.value = 'Notificações ativadas neste dispositivo.'
  } catch (error) {
    if (novaInscricao && !pushSubscriptionId.value) await novaInscricao.unsubscribe().catch(() => {})
    pushErro.value = error.response?.data?.message || error.message || 'Não foi possível ativar as notificações.'
  } finally {
    pushCarregando.value = false
  }
}

async function desativarPush() {
  pushCarregando.value = true
  pushErro.value = ''
  pushSucesso.value = ''
  try {
    const inscricao = pushSubscription.value || await pushRegistration.value?.pushManager.getSubscription()
    if (inscricao && !pushSubscriptionId.value) await salvarInscricaoPush(inscricao)
    if (pushSubscriptionId.value) await api.delete(`/push/subscriptions/${pushSubscriptionId.value}`)
    if (inscricao) await inscricao.unsubscribe()
    pushSubscription.value = null
    pushSubscriptionId.value = null
    pushAtivo.value = false
    pushSucesso.value = 'Notificações desativadas neste dispositivo.'
  } catch (error) {
    pushErro.value = error.response?.data?.message || 'Não foi possível desativar as notificações.'
  } finally {
    pushCarregando.value = false
  }
}

onMounted(() => {
  carregarNotificacoes()
  prepararPush()
  api.get('/auth/me').then(resposta => {
    pararRealtime = listenForCareReminders(resposta.data.data.id, () => carregarNotificacoes())
  }).catch(() => {})
})

onUnmounted(() => pararRealtime())
</script>

<template>
  <div class="notifications-page">

    <header class="page-header">
      <div class="header-left">
        <button
          class="back-button"
          @click="router.push('/')"
          title="Voltar ao Dashboard"
        >
          <ArrowLeft :size="19" />
          <span>Voltar ao Dashboard</span>
        </button>

        <div class="page-title">
          <span class="eyebrow">CENTRAL DE AVISOS</span>
          <h1>Notificações</h1>
          <p>Acompanhe os avisos e lembretes dos seus pacientes.</p>
        </div>
      </div>

      <button class="refresh-button" @click="carregarNotificacoes">
        <RefreshCw :size="17" />
        Atualizar
      </button>
    </header>

    <section class="push-card">
      <div class="push-copy">
        <strong>Notificações neste dispositivo</strong>
        <span v-if="!pushDisponivel">Este navegador não oferece notificações push.</span>
        <span v-else-if="permissaoPush === 'denied'">A permissão foi bloqueada nas configurações do navegador.</span>
        <span v-else-if="!pushPublicKey">O servidor ainda não configurou o serviço de notificações push.</span>
        <span v-else-if="pushAtivo">Este dispositivo está inscrito para receber avisos.</span>
        <span v-else>Ative para receber lembretes mesmo quando o app estiver fechado.</span>
      </div>
      <button v-if="pushDisponivel && pushPublicKey && !pushAtivo && permissaoPush !== 'denied'" class="push-button" :disabled="pushCarregando" @click="ativarPush">
        <BellRing :size="17" /> {{ pushCarregando ? 'Ativando...' : 'Ativar neste dispositivo' }}
      </button>
      <button v-else-if="pushAtivo" class="push-button secondary" :disabled="pushCarregando" @click="desativarPush">
        {{ pushCarregando ? 'Atualizando...' : 'Desativar neste dispositivo' }}
      </button>
      <p v-if="pushErro" class="push-message error-message" role="alert">{{ pushErro }}</p>
      <p v-else-if="pushSucesso" class="push-message success-message" role="status">{{ pushSucesso }}</p>
    </section>

    <section class="summary-card">
      <div class="summary-icon">
        <BellRing :size="27" />
      </div>

      <div>
        <span>Notificações não lidas</span>
        <strong>{{ totalNaoLidas }}</strong>
      </div>
    </section>

    <section class="notifications-panel">
      <div class="panel-header">
        <div>
          <h2>Seus avisos</h2>
          <p>{{ notificacoes.length }} notificações recebidas</p>
        </div>

        <button
          v-if="totalNaoLidas > 0"
          class="mark-all-button"
          @click="marcarTodasComoLidas"
        >
          <Check :size="16" />
          Marcar todas como lidas
        </button>
      </div>

      <div class="filters">
        <button
          :class="{ selected: filtro === 'todas' }"
          @click="filtro = 'todas'"
        >
          Todas
        </button>

        <button
          :class="{ selected: filtro === 'nao-lidas' }"
          @click="filtro = 'nao-lidas'"
        >
          Não lidas
        </button>

        <button
          :class="{ selected: filtro === 'lidas' }"
          @click="filtro = 'lidas'"
        >
          Lidas
        </button>
      </div>

      <div v-if="erro" class="error-message">
        {{ erro }}
      </div>

      <div v-else-if="carregando" class="empty-state">
        Carregando notificações...
      </div>

      <div
        v-else-if="notificacoesFiltradas.length === 0"
        class="empty-state"
      >
        <Bell :size="38" />
        <strong>Nenhuma notificação encontrada</strong>
        <span>Quando houver novos avisos, eles aparecerão aqui.</span>
      </div>

      <div v-else class="notification-list">
        <article
          v-for="item in notificacoesFiltradas"
          :key="item.id"
          class="notification-item"
          :class="{ unread: !item.read_at }"
        >
          <div class="notification-icon">
            <component :is="iconeNotificacao(item)" :size="22" />
          </div>

          <div class="notification-content">
            <div class="notification-title">
              <strong>{{ tipoNotificacao(item) }}</strong>

              <span v-if="!item.read_at" class="unread-badge">
                Não lida
              </span>

              <span v-else class="read-badge">
                <CheckCircle2 :size="13" />
                Lida
              </span>
            </div>

            <p>{{ descricaoNotificacao(item) }}</p>

            <div class="notification-details">
              <span>
                <CheckCircle2 :size="14" />
                {{ nomePaciente(item.elder_id) }}
              </span>

              <span>
                <Clock :size="14" />
                {{ formatarData(item.reminder?.due_at) }}
              </span>
            </div>
          </div>

          <button
            v-if="!item.read_at"
            class="read-button"
            @click="marcarComoLida(item)"
          >
            Marcar como lida
          </button>
        </article>
      </div>
    </section>

    <footer class="page-footer">
      <button @click="router.push('/')">
        <LayoutDashboard :size="16" />
        Ir para o Dashboard
      </button>
      <span>Ideale Home Care · Gestão de cuidados com carinho.</span>
    </footer>

  </div>
</template>

<style scoped>
.notifications-page {
  width: 100%;
  padding: 28px;
  color: #344054;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 20px;
  margin-bottom: 25px;
}

.header-left {
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.back-button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  width: fit-content;
  padding: 9px 13px;
  border: 1px solid #e8e9e6;
  border-radius: 9px;
  background: white;
  color: #52665b;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: 0.2s;
}

.back-button:hover {
  background: #f8edf2;
  color: #a64d70;
  border-color: #efd6e0;
}

.eyebrow {
  color: #a64d70;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1.5px;
}

.page-header h1 {
  margin: 7px 0;
  font-size: 28px;
  color: #293b35;
}

.page-header p,
.panel-header p {
  color: #89928d;
  font-size: 14px;
  margin: 0;
}

.refresh-button,
.mark-all-button,
.read-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid #e8e9e6;
  background: white;
  color: #52665b;
  padding: 10px 15px;
  border-radius: 9px;
  cursor: pointer;
  font-weight: 600;
}

.refresh-button:hover,
.mark-all-button:hover {
  background: #f5f8f5;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 18px;
  background: white;
  border: 1px solid #edf0ed;
  padding: 22px;
  border-radius: 14px;
  margin-bottom: 24px;
}

.push-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  background: white;
  border: 1px solid #edf0ed;
  padding: 18px 22px;
  border-radius: 14px;
  margin-bottom: 18px;
}

.push-copy {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.push-copy strong {
  color: #293b35;
  font-size: 14px;
}

.push-copy span {
  color: #89928d;
  font-size: 12px;
}

.push-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 9px;
  background: #a64d70;
  color: white;
  font-size: 12px;
  font-weight: 600;
}

.push-button.secondary {
  background: #f8edf2;
  color: #a64d70;
}

.push-button:disabled {
  opacity: .6;
  cursor: wait;
}

.push-message {
  flex-basis: 100%;
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 12px;
}

.success-message {
  background: #eef7f0;
  color: #568366;
}

.summary-icon,
.notification-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8edf2;
  color: #a64d70;
  border-radius: 12px;
  width: 52px;
  height: 52px;
  flex-shrink: 0;
}

.summary-card div:last-child {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.summary-card span {
  color: #89928d;
  font-size: 13px;
}

.summary-card strong {
  color: #293b35;
  font-size: 26px;
}

.notifications-panel {
  background: white;
  border: 1px solid #edf0ed;
  border-radius: 14px;
  padding: 24px;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
}

.panel-header h2 {
  margin: 0 0 6px;
  color: #293b35;
  font-size: 19px;
}

.filters {
  display: flex;
  gap: 9px;
  margin: 23px 0;
  border-bottom: 1px solid #edf0ed;
  padding-bottom: 14px;
}

.filters button {
  border: none;
  background: transparent;
  color: #89928d;
  padding: 9px 15px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
}

.filters button.selected {
  background: #f8edf2;
  color: #a64d70;
}

.notification-list {
  display: flex;
  flex-direction: column;
}

.notification-item {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 19px 10px;
  border-bottom: 1px solid #f0f1ef;
}

.notification-item.unread {
  background: #fcfafb;
}

.notification-content {
  flex: 1;
  min-width: 0;
}

.notification-title {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.notification-title strong {
  color: #34483d;
  font-size: 15px;
}

.notification-content p {
  margin: 7px 0;
  color: #7c8981;
  font-size: 13px;
}

.notification-details {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}

.notification-details span {
  display: flex;
  align-items: center;
  gap: 5px;
  color: #89928d;
  font-size: 12px;
}

.unread-badge,
.read-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 20px;
}

.unread-badge {
  background: #fff0f3;
  color: #a64d70;
}

.read-badge {
  background: #eef7f0;
  color: #568366;
}

.read-button {
  font-size: 12px;
  white-space: nowrap;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 55px 15px;
  color: #a0aaa3;
  text-align: center;
}

.empty-state strong {
  color: #52665b;
}

.empty-state span {
  font-size: 13px;
}

.error-message {
  padding: 15px;
  color: #b43d48;
  background: #fff0f0;
  border-radius: 8px;
}

.page-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 15px;
  margin-top: 25px;
  color: #89928d;
  font-size: 12px;
}

.page-footer button {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border: none;
  background: transparent;
  color: #a64d70;
  font-weight: 600;
  cursor: pointer;
}

.page-footer button:hover {
  text-decoration: underline;
}

@media (max-width: 700px) {
  .notifications-page {
    padding: 17px;
  }

  .page-header,
  .panel-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .notification-item {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .notification-content {
    width: calc(100% - 70px);
  }

  .read-button {
    margin-left: 68px;
  }

  .page-footer {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
