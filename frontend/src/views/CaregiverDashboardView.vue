<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Activity, CalendarDays, ClipboardList, Clock, Pill, RefreshCw, Users } from '@lucide/vue'
import api from '../services/api'
import { normalizarItem } from '../services/cuidados'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))
const idosos = ref([])
const compromissos = ref([])
const carregando = ref(false)
const erro = ref('')
const falhasParciais = ref(0)

const dataHoje = new Date()
const saudacao = computed(() => {
  const hora = dataHoje.getHours()
  return hora < 12 ? 'Bom dia' : hora < 18 ? 'Boa tarde' : 'Boa noite'
})
const dataFormatada = computed(() => dataHoje.toLocaleDateString('pt-BR', {
  weekday: 'long', day: '2-digit', month: 'long'
}))
const tarefasHoje = computed(() => compromissos.value.filter((item) => item.type === 'task'))
const tarefasPendentes = computed(() => tarefasHoje.value.filter((item) => item.status === 'pending').length)
const medicamentosHoje = computed(() => compromissos.value.filter((item) => item.type === 'medication'))
const visitasHoje = computed(() => compromissos.value.filter((item) => item.type === 'visit'))
const visitasAguardando = computed(() => visitasHoje.value.filter((item) => item.status === 'scheduled').length)
const visitaAtualizando = ref(null)
const proximosCuidados = computed(() => [...compromissos.value]
  .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))
  .slice(0, 12))

function hora(data, timezone) {
  if (!data) return '--:--'
  try {
    return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: timezone || undefined }).format(new Date(data))
  } catch {
    return new Date(data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  }
}

function nomeTipo(tipo) {
  return ({ task: 'Tarefa', medication: 'Medicamento', visit: 'Visita', event: 'Evento' })[tipo] || 'Cuidado'
}

function textoStatus(status) {
  return ({ pending: 'Pendente', completed: 'Concluída', done: 'Concluída', administered: 'Administrado', taken: 'Administrado', scheduled: 'Agendada', confirmed: 'Confirmada' })[status] || status || 'Programado'
}

async function carregarPainel() {
  carregando.value = true
  erro.value = ''
  falhasParciais.value = 0
  try {
    const response = await api.get('/elders')
    idosos.value = (response.data.data || []).filter((elder) => elder.active)

    if (userRole.value === 'ideale_professional') {
      const agenda = await api.get('/caregiver/agenda')
      compromissos.value = (agenda.data.data || []).map(normalizarItem)
      return
    }

    const daily = await Promise.allSettled(idosos.value.map((elder) => api.get(`/elders/${elder.id}/daily-view`)))
    const items = []
    daily.forEach((result, index) => {
      if (result.status !== 'fulfilled') {
        falhasParciais.value += 1
        return
      }
      const day = result.value.data.data
      for (const item of day.items || []) {
        items.push({ ...item, elder_name: day.elder?.name || idosos.value[index].name, timezone: day.timezone })
      }
    })
    compromissos.value = items.map(normalizarItem)
  } catch (e) {
    erro.value = e.response?.data?.message || 'Não foi possível carregar seus atendimentos.'
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
    await carregarPainel()
  } catch (e) {
    erro.value = e.response?.data?.message || 'Não foi possível atualizar este atendimento.'
  } finally {
    visitaAtualizando.value = null
  }
}

onMounted(carregarPainel)
watch(userRole, (role) => {
  if (role === 'ideale_professional') carregarPainel()
})
</script>

<template>
  <main class="care-dashboard">
    <header class="care-header">
      <div>
        <span class="care-date">{{ dataFormatada }}</span>
        <h1>{{ saudacao }}, equipe de cuidado</h1>
        <p v-if="userRole === 'ideale_professional'">Sua agenda reúne os atendimentos atribuídos a você nos diferentes pacientes.</p>
        <p v-else>Acompanhe os idosos vinculados à sua conta e os cuidados programados para hoje.</p>
      </div>
      <button class="refresh-button" :disabled="carregando" @click="carregarPainel">
        <RefreshCw :size="17" :class="{ spinning: carregando }" /> Atualizar
      </button>
    </header>

    <p v-if="erro" class="error-message" role="alert">{{ erro }}</p>
    <p v-if="falhasParciais" class="partial-message" role="status">Algumas agendas não puderam ser carregadas. Tente atualizar.</p>

    <section class="care-stats">
      <article><Users /><span>Idosos vinculados</span><strong>{{ carregando ? '…' : idosos.length }}</strong></article>
      <article v-if="userRole !== 'ideale_professional'"><ClipboardList /><span>Tarefas de hoje</span><strong>{{ carregando ? '…' : tarefasHoje.length }}</strong><small>{{ tarefasPendentes }} pendentes</small></article>
      <article v-if="userRole !== 'ideale_professional'"><Pill /><span>Medicamentos</span><strong>{{ carregando ? '…' : medicamentosHoje.length }}</strong><small>Programados para hoje</small></article>
      <article><CalendarDays /><span>Visitas</span><strong>{{ carregando ? '…' : visitasHoje.length }}</strong><small>Na agenda de hoje</small></article>
      <article v-if="userRole === 'ideale_professional'"><Clock /><span>Aguardando confirmação</span><strong>{{ visitasAguardando }}</strong></article>
    </section>

    <section class="care-grid">
      <article class="care-panel">
        <div class="panel-heading"><div><h2>Minha agenda</h2><p v-if="userRole === 'ideale_professional'">Visitas atribuídas a você em todos os pacientes vinculados.</p><p v-else>Compromissos dos idosos vinculados.</p></div><button class="text-button" @click="router.push('/agenda')">Abrir agenda</button></div>
        <div v-if="carregando" class="empty-state">Carregando agenda…</div>
        <div v-else-if="!proximosCuidados.length" class="empty-state">Nenhum cuidado programado para hoje.</div>
        <div v-for="item in proximosCuidados" :key="`${item.type}:${item.source_id || item.id}:${item.elder_name}`" class="schedule-row">
          <time>{{ hora(item.starts_at, item.timezone) }}</time>
          <div class="schedule-icon"><Activity v-if="item.type === 'visit'"/><Pill v-else-if="item.type === 'medication'"/><ClipboardList v-else/></div>
          <div class="schedule-info"><strong>{{ item.title || nomeTipo(item.type) }}</strong><span>{{ item.elder_name }} · {{ nomeTipo(item.type) }}</span></div>
          <span class="schedule-status">{{ textoStatus(item.status) }}</span>
          <div v-if="userRole === 'ideale_professional' && item.allowed_actions?.length" class="visit-actions">
            <button v-if="item.allowed_actions.includes('confirm')" :disabled="visitaAtualizando === item.source_id" @click="alterarStatusVisita(item, 'confirmed')">{{ visitaAtualizando === item.source_id ? 'Salvando…' : 'Confirmar' }}</button>
            <button v-if="item.allowed_actions.includes('complete')" :disabled="visitaAtualizando === item.source_id" @click="alterarStatusVisita(item, 'completed')">{{ visitaAtualizando === item.source_id ? 'Salvando…' : 'Concluir atendimento' }}</button>
          </div>
        </div>
      </article>

      <article class="care-panel elders-panel">
        <div class="panel-heading"><div><h2>Idosos vinculados</h2><p>O acesso é limitado às redes que enviaram convite.</p></div></div>
        <div v-if="carregando" class="empty-state">Carregando vínculos…</div>
        <div v-else-if="!idosos.length" class="empty-state">Nenhum vínculo ativo. Peça ao familiar responsável para enviar um convite.</div>
        <div v-for="idoso in idosos" :key="idoso.id" class="elder-row"><div class="elder-avatar"><Users :size="18"/></div><div><strong>{{ idoso.name }}</strong><span>{{ idoso.birth_date ? new Date(`${idoso.birth_date}T12:00:00`).toLocaleDateString('pt-BR') : 'Paciente acompanhado' }}</span></div></div>
        <div class="quick-links"><button @click="router.push('/pacientes')">Ver pacientes</button><button @click="router.push('/tarefas')">Ver tarefas</button><button @click="router.push('/medicamentos')">Medicamentos</button></div>
      </article>
    </section>
    <footer class="care-footer"><Clock :size="15"/> Os registros são informados pela rede de cuidado e devem ser conferidos com o familiar responsável.</footer>
  </main>
</template>

<style scoped>
.care-dashboard{max-width:1440px;margin:0 auto;padding:28px;color:#263238}.care-header,.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.care-header{margin-bottom:24px}.care-date{color:#a64d70;font-size:13px;text-transform:capitalize}.care-header h1{margin:8px 0;color:#29343a;font-size:30px}.care-header p,.panel-heading p{margin:0;color:#68747a}.refresh-button,.text-button,.quick-links button{display:inline-flex;align-items:center;gap:8px;border:1px solid #e5d6dc;border-radius:8px;background:#fff;color:#9c4568;padding:10px 13px;cursor:pointer}.refresh-button:disabled{opacity:.6}.care-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}.care-stats article,.care-panel{border:1px solid #eee7ea;border-radius:13px;background:#fff;box-shadow:0 3px 12px #00000008}.care-stats article{display:grid;grid-template-columns:30px 1fr;gap:2px 10px;padding:17px}.care-stats article>svg{grid-row:span 3;color:#a64d70}.care-stats article span,.care-stats small{color:#68747a;font-size:13px}.care-stats strong{font-size:26px;color:#29343a}.care-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:18px}.care-panel{padding:20px}.panel-heading{margin-bottom:14px}.panel-heading h2{margin:0 0 5px;font-size:19px}.schedule-row{display:grid;grid-template-columns:55px 34px minmax(0,1fr) auto;align-items:center;gap:12px;padding:13px 0;border-top:1px solid #f0edef}.schedule-row time{font-weight:700;color:#a64d70}.schedule-icon{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#f8f0f3;color:#a64d70}.schedule-info strong,.elder-row strong{display:block;color:#303b40}.schedule-info span,.elder-row span{display:block;margin-top:4px;color:#718087;font-size:13px}.schedule-status{color:#6c7276;font-size:12px}.elder-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-top:1px solid #f0edef}.elder-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:#f8f0f3;color:#a64d70}.quick-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.quick-links button{font-size:12px}.empty-state{padding:28px 10px;text-align:center;color:#7b8589}.care-footer{display:flex;align-items:center;gap:8px;margin-top:20px;color:#798489;font-size:12px}.error-message,.partial-message{padding:12px 15px;border-radius:8px;margin-bottom:15px}.error-message{background:#fff0f0;color:#a12626}.partial-message{background:#fff7e8;color:#8a5b00}.spinning{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:900px){.care-grid{grid-template-columns:1fr}}@media(max-width:600px){.care-dashboard{padding:16px}.care-header{align-items:flex-start;flex-direction:column}.care-header h1{font-size:24px}.schedule-row{grid-template-columns:48px 30px minmax(0,1fr)}.schedule-status{grid-column:3}}
.visit-actions{grid-column:3/-1;display:flex;flex-wrap:wrap;gap:8px}.visit-actions button{border:0;border-radius:7px;padding:7px 10px;background:#a64d70;color:#fff;font-size:12px;cursor:pointer}.visit-actions button:disabled{opacity:.6;cursor:wait}
</style>
