
<script setup>
import { inject, ref, computed, onMounted } from 'vue'
import {
  Pill,
  ArrowLeft,
  Plus,
  Clock,
  CheckCircle2,
  RefreshCw,
  X
} from '@lucide/vue'

import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))

const pacientes = ref([])
const pacienteSelecionado = ref('')
const medicamentos = ref([])
const ocorrencias = ref([])
const carregando = ref(false)
const salvando = ref(false)
const erro = ref('')
const mensagem = ref('')
const mostrarFormulario = ref(false)

function dataLocal() {
  const agora = new Date()
  const ano = agora.getFullYear()
  const mes = String(agora.getMonth() + 1).padStart(2, '0')
  const dia = String(agora.getDate()).padStart(2, '0')
  return `${ano}-${mes}-${dia}`
}

const dataSelecionada = ref(dataLocal())

const formulario = ref({
  name: '',
  dosage: '',
  instructions: '',
  starts_on: dataLocal(),
  ends_on: '',
  times: ['08:00']
})

const pacienteAtual = computed(() =>
  pacientes.value.find(p => String(p.id) === String(pacienteSelecionado.value))
)

const medicamentosAtivos = computed(() =>
  medicamentos.value.filter(m => m.active)
)

const ocorrenciasPendentes = computed(() =>
  ocorrencias.value.filter(o => o.status === 'pending').length
)

function formatarData(data) {
  if (!data) return '--'
  const [ano, mes, dia] = data.split('-')
  return `${dia}/${mes}/${ano}`
}

function formatarHorario(data) {
  if (!data) return '--:--'

  return new Date(data).toLocaleTimeString('pt-BR', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

// A API envia só o medication_id na dose; o nome vem da lista de medicamentos já carregada.
function nomeMedicamento(item) {
  return item.medication?.name || item.medication_name ||
    medicamentos.value.find(m => m.id === item.medication_id)?.name || 'Medicamento'
}

function nomeStatus(status) {
  const nomes = {
    pending: 'Pendente',
    confirmed: 'Administrado',
    cancelled: 'Cancelado'
  }

  return nomes[status] || status
}

async function carregarPacientes() {
  try {
    const resposta = await api.get('/elders')
    pacientes.value = (resposta.data.data || []).filter(p => p.active)

    if (pacientes.value.length && !pacienteSelecionado.value) {
      pacienteSelecionado.value = String(pacientes.value[0].id)
    }

    if (pacienteSelecionado.value) {
      await carregarDados()
    }
  } catch (error) {
    console.error(error)
    erro.value = 'Não foi possível carregar os pacientes.'
  }
}

async function carregarDados() {
  if (!pacienteSelecionado.value) return

  carregando.value = true
  erro.value = ''

  try {
    const id = pacienteSelecionado.value

    const [respostaMedicamentos, respostaOcorrencias] = await Promise.all([
      api.get(`/elders/${id}/medications`),
      api.get(`/elders/${id}/medication-occurrences`, {
        params: { date: dataSelecionada.value }
      })
    ])

    medicamentos.value = respostaMedicamentos.data.data || []
    ocorrencias.value = respostaOcorrencias.data.data || []

  } catch (error) {
    console.error(error)
    erro.value = error.response?.data?.message ||
      'Não foi possível carregar os medicamentos.'
  } finally {
    carregando.value = false
  }
}

function abrirFormulario() {
  formulario.value = {
    name: '',
    dosage: '',
    instructions: '',
    starts_on: dataLocal(),
    ends_on: '',
    times: ['08:00']
  }

  mensagem.value = ''
  mostrarFormulario.value = true
}

function adicionarHorario() {
  if (formulario.value.times.length >= 24) return
  formulario.value.times.push('12:00')
}

function removerHorario(indice) {
  if (formulario.value.times.length <= 1) return
  formulario.value.times.splice(indice, 1)
}

async function salvarMedicamento() {
  if (!pacienteSelecionado.value) {
    erro.value = 'Selecione um paciente.'
    return
  }

  salvando.value = true
  erro.value = ''

  try {
    await api.post(
      `/elders/${pacienteSelecionado.value}/medications`,
      {
        name: formulario.value.name,
        dosage: formulario.value.dosage,
        instructions: formulario.value.instructions || null,
        starts_on: formulario.value.starts_on,
        ends_on: formulario.value.ends_on || null,
        times: formulario.value.times
      }
    )

    mostrarFormulario.value = false
    mensagem.value = 'Medicamento cadastrado com sucesso!'

    await carregarDados()

  } catch (error) {
    console.error(error)

    erro.value = error.response?.data?.message ||
      Object.values(error.response?.data?.errors || {}).flat().join(' ') ||
      'Não foi possível cadastrar o medicamento.'
  } finally {
    salvando.value = false
  }
}

async function confirmarAdministracao(ocorrencia) {
  if (!confirm('Confirma que este medicamento foi administrado?')) return

  erro.value = ''

  try {
    await api.post(
      `/elders/${pacienteSelecionado.value}/medication-occurrences/${ocorrencia.id}/confirm`
    )

    mensagem.value = 'Administração confirmada com sucesso!'
    await carregarDados()

  } catch (error) {
    console.error(error)

    erro.value = error.response?.data?.message ||
      'Não foi possível confirmar a administração.'
  }
}

onMounted(carregarPacientes)
</script>

<template>
  <main class="med-page">
    <header class="med-header">
      <div>
        <button class="back-button" @click="router.push('/')">
          <ArrowLeft :size="17" />
          Voltar ao Dashboard
        </button>

        <h1><Pill /> Medicamentos</h1>
        <p>Controle os medicamentos e horários dos pacientes.</p>
      </div>

      <button v-if="userRole === 'family'" class="add-button" @click="abrirFormulario">
        <Plus :size="18" />
        Novo medicamento
      </button>
    </header>

    <section class="med-filter">
      <div>
        <label>Paciente</label>
        <select v-model="pacienteSelecionado" @change="carregarDados">
          <option value="">Selecione um paciente</option>
          <option
            v-for="paciente in pacientes"
            :key="paciente.id"
            :value="String(paciente.id)"
          >
            {{ paciente.name }}
          </option>
        </select>
      </div>

      <div>
        <label>Data das administrações</label>
        <input
          type="date"
          v-model="dataSelecionada"
          @change="carregarDados"
        />
      </div>
    </section>

    <div v-if="mensagem" class="success-message">
      {{ mensagem }}
    </div>

    <div v-if="erro" class="error-message">
      {{ erro }}
    </div>

    <section class="med-stats">
      <article>
        <Pill />
        <span>Medicamentos ativos</span>
        <strong>{{ medicamentosAtivos.length }}</strong>
      </article>

      <article>
        <Clock />
        <span>Administrações pendentes</span>
        <strong>{{ ocorrenciasPendentes }}</strong>
      </article>

      <article>
        <CheckCircle2 />
        <span>Administrados</span>
        <strong>
          {{ ocorrencias.filter(o => o.status === 'confirmed').length }}
        </strong>
      </article>
    </section>

    <section class="med-section">
      <div class="section-title">
        <h2>Medicamentos cadastrados</h2>
        <button @click="carregarDados">
          <RefreshCw :size="16" /> Atualizar
        </button>
      </div>

      <div v-if="carregando" class="empty-message">
        Carregando medicamentos...
      </div>

      <div
        v-else-if="!medicamentos.length"
        class="empty-message"
      >
        Nenhum medicamento cadastrado para este paciente.
      </div>

      <article
        v-for="med in medicamentos"
        :key="med.id"
        class="med-card"
      >
        <div class="med-icon"><Pill /></div>

        <div class="med-info">
          <strong>{{ med.name }}</strong>
          <span>Dosagem: {{ med.dosage }}</span>
          <span>
            Início: {{ formatarData(med.starts_on) }}
            <template v-if="med.ends_on">
              · Término: {{ formatarData(med.ends_on) }}
            </template>
          </span>
          <p v-if="med.instructions">{{ med.instructions }}</p>

          <div class="med-times">
            <span
              v-for="horario in (med.schedules || [])"
              :key="horario.id"
            >
              <Clock :size="13" />
              {{ (horario.local_time || horario.time)?.slice(0, 5) || '--:--' }}
            </span>
          </div>
        </div>

        <span class="active-label">
          {{ med.active ? 'Ativo' : 'Inativo' }}
        </span>
      </article>
    </section>

    <section class="med-section">
      <h2>Administrações do dia</h2>

      <div v-if="!ocorrencias.length" class="empty-message">
        Nenhuma administração programada para esta data.
      </div>

      <article
        v-for="item in ocorrencias"
        :key="item.id"
        class="occurrence-card"
      >
        <div class="occurrence-time">
          <Clock />
          <strong>{{ formatarHorario(item.scheduled_at) }}</strong>
        </div>

        <div class="occurrence-info">
          <strong>
            {{ nomeMedicamento(item) }}
          </strong>
          <span>{{ item.medication?.dosage || item.dosage || '' }}</span>
          <small>{{ nomeStatus(item.status) }}</small>
        </div>

        <button
          v-if="item.status === 'pending' && ['family', 'ideale_professional'].includes(userRole)"
          class="confirm-button"
          @click="confirmarAdministracao(item)"
        >
          <CheckCircle2 :size="15" />
          Confirmar
        </button>

        <span v-else class="confirmed-label">
          {{ nomeStatus(item.status) }}
        </span>
      </article>
    </section>

    <div v-if="mostrarFormulario && userRole === 'family'" class="modal-overlay">
      <form class="med-form" @submit.prevent="salvarMedicamento">
        <div class="form-heading">
          <h2>Novo medicamento</h2>
          <button type="button" @click="mostrarFormulario = false">
            <X />
          </button>
        </div>

        <label>Nome do medicamento *</label>
        <input v-model="formulario.name" required maxlength="200" />

        <label>Dosagem *</label>
        <input
          v-model="formulario.dosage"
          required
          maxlength="200"
          placeholder="Ex.: 500 mg"
        />

        <label>Data de início *</label>
        <input
          type="date"
          v-model="formulario.starts_on"
          required
        />

        <label>Data de término</label>
        <input
          type="date"
          v-model="formulario.ends_on"
          :min="formulario.starts_on"
        />

        <label>Horários *</label>

        <div
          v-for="(horario, indice) in formulario.times"
          :key="indice"
          class="time-input"
        >
          <input
            type="time"
            v-model="formulario.times[indice]"
            required
          />

          <button
            type="button"
            class="remove-time"
            @click="removerHorario(indice)"
            :disabled="formulario.times.length <= 1"
          >
            <X :size="16" />
          </button>
        </div>

        <button
          type="button"
          class="add-time"
          @click="adicionarHorario"
          :disabled="formulario.times.length >= 24"
        >
          <Plus :size="15" />
          Adicionar horário
        </button>

        <label>Instruções</label>
        <textarea
          v-model="formulario.instructions"
          rows="3"
          maxlength="5000"
          placeholder="Orientações adicionais..."
        ></textarea>

        <div class="form-actions">
          <button
            type="button"
            class="cancel-button"
            @click="mostrarFormulario = false"
          >
            Cancelar
          </button>

          <button
            type="submit"
            class="add-button"
            :disabled="salvando"
          >
            {{ salvando ? 'Salvando...' : 'Salvar medicamento' }}
          </button>
        </div>
      </form>
    </div>
  </main>
</template>

<style scoped>
.med-page {
  min-height: 100vh;
  padding: 35px;
  background: #f7f8fa;
  color: #344054;
}

.med-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
  gap: 15px;
}

.med-header h1 {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0 0 8px;
}

.med-header p {
  color: #8993a4;
}

.back-button,
.section-title button {
  border: none;
  background: transparent;
  color: #68758a;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.add-button,
.confirm-button {
  border: none;
  border-radius: 9px;
  padding: 12px 17px;
  background: #a64d70;
  color: white;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
}

.med-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  background: white;
  padding: 20px;
  border-radius: 12px;
  margin-bottom: 20px;
}

.med-filter div {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.med-filter select,
.med-filter input,
.med-form input,
.med-form textarea {
  padding: 11px;
  border: 1px solid #ddd;
  border-radius: 7px;
  min-width: 210px;
  background: white;
}

.med-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 18px;
  margin-bottom: 25px;
}

.med-stats article {
  background: white;
  padding: 22px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.med-stats svg {
  color: #a64d70;
}

.med-stats strong {
  margin-left: auto;
  font-size: 24px;
}

.med-section {
  background: white;
  padding: 25px;
  border-radius: 12px;
  margin-bottom: 22px;
}

.med-section h2 {
  font-size: 19px;
  margin: 0 0 20px;
}

.section-title {
  display: flex;
  justify-content: space-between;
}

.med-card,
.occurrence-card {
  display: flex;
  align-items: center;
  gap: 17px;
  padding: 18px 5px;
  border-bottom: 1px solid #edf0f3;
}

.med-icon {
  background: #f9edf2;
  color: #a64d70;
  padding: 13px;
  border-radius: 10px;
}

.med-info,
.occurrence-info {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}

.med-info span,
.occurrence-info span {
  color: #68758a;
  font-size: 14px;
}

.med-info p {
  color: #8993a4;
  margin: 4px 0;
}

.med-times {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin-top: 5px;
}

.med-times span {
  display: flex;
  align-items: center;
  gap: 5px;
  background: #f7f8fa;
  padding: 6px 9px;
  border-radius: 7px;
}

.active-label,
.confirmed-label {
  color: #26966b;
  font-size: 13px;
}

.occurrence-time {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 85px;
  color: #a64d70;
}

.confirm-button {
  padding: 9px 12px;
  font-size: 13px;
}

.empty-message {
  text-align: center;
  padding: 30px;
  color: #8993a4;
}

.success-message,
.error-message {
  padding: 13px;
  border-radius: 8px;
  margin-bottom: 15px;
}

.success-message {
  background: #e9f8f0;
  color: #21845d;
}

.error-message {
  background: #fff0f0;
  color: #b43d48;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: #0007;
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 15px;
  z-index: 100;
  overflow-y: auto;
}

.med-form {
  background: white;
  width: 100%;
  max-width: 520px;
  max-height: 95vh;
  overflow-y: auto;
  padding: 25px;
  border-radius: 14px;
  display: flex;
  flex-direction: column;
  gap: 11px;
}

.form-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.form-heading h2 {
  margin: 0;
}

.form-heading button,
.remove-time {
  border: none;
  background: transparent;
  cursor: pointer;
}

.med-form label {
  font-weight: 600;
  font-size: 14px;
}

.med-form input,
.med-form textarea {
  width: 100%;
  box-sizing: border-box;
}

.time-input {
  display: flex;
  align-items: center;
  gap: 8px;
}

.remove-time {
  color: #b43d48;
}

.add-time {
  align-self: flex-start;
  border: none;
  background: #f9edf2;
  color: #a64d70;
  border-radius: 7px;
  padding: 9px;
  display: flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 12px;
}

.cancel-button {
  border: 1px solid #ddd;
  background: white;
  padding: 11px 15px;
  border-radius: 8px;
  cursor: pointer;
}

button:disabled {
  opacity: .5;
  cursor: not-allowed;
}

@media (max-width: 700px) {
  .med-page {
    padding: 18px;
  }

  .med-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .med-stats {
    grid-template-columns: 1fr;
  }

  .med-card,
  .occurrence-card {
    flex-wrap: wrap;
  }
}
</style>
