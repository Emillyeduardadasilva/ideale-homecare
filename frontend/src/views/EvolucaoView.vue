
<script setup>
import { inject, ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowLeft,
  HeartPulse,
  Plus,
  Activity,
  ClipboardList,
  Thermometer,
  Droplets,
  Gauge,
  CalendarDays,
  UserRound,
  LoaderCircle,
  RefreshCw
} from '@lucide/vue'
import api from '../services/api'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))

const pacientes = ref([])
const pacienteId = ref('')
const observacoes = ref([])
const medicoes = ref([])

const carregando = ref(false)
const salvando = ref(false)
const erro = ref('')
const mensagem = ref('')

const aba = ref('evolucao')

function dataLocalAtual() {
  const agora = new Date()
  const ajuste = agora.getTimezoneOffset() * 60000
  return new Date(agora.getTime() - ajuste).toISOString().slice(0, 16)
}

function novaObservacao() {
  return {
    text: '',
    occurred_at: dataLocalAtual(),
    tags: ''
  }
}

function novaMedicao() {
  return {
    type: 'blood_pressure',
    value: '',
    secondary_value: '',
    measured_at: dataLocalAtual()
  }
}

const formularioObservacao = ref(novaObservacao())
const formularioMedicao = ref(novaMedicao())

const pacienteSelecionado = computed(() =>
  pacientes.value.find(p => String(p.id) === String(pacienteId.value))
)

const historico = computed(() => {
  const registros = [
    ...observacoes.value.map(item => ({
      ...item,
      categoria: 'observacao',
      data: item.occurred_at
    })),
    ...medicoes.value.map(item => ({
      ...item,
      categoria: 'medicao',
      data: item.measured_at
    }))
  ]

  return registros.sort((a, b) =>
    new Date(b.data) - new Date(a.data)
  )
})

function mensagemErro(error, padrao) {
  const erros = error.response?.data?.errors

  if (erros) {
    const primeiro = Object.values(erros).flat()[0]
    if (primeiro) return primeiro
  }

  return error.response?.data?.message || padrao
}

async function carregarPacientes() {
  erro.value = ''

  try {
    const resposta = await api.get('/elders')
    pacientes.value = resposta.data.data || []

    if (pacientes.value.length > 0) {
      pacienteId.value = String(pacientes.value[0].id)
    }
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível carregar os pacientes.')
  }
}

async function carregarHistorico() {
  if (!pacienteId.value) {
    observacoes.value = []
    medicoes.value = []
    return
  }

  carregando.value = true
  erro.value = ''

  try {
    const [resObservacoes, resMedicoes] = await Promise.all([
      api.get(`/elders/${pacienteId.value}/observations`),
      api.get(`/elders/${pacienteId.value}/measurements`)
    ])

    observacoes.value = resObservacoes.data.data || []
    medicoes.value = resMedicoes.data.data || []
  } catch (error) {
    erro.value = mensagemErro(
      error,
      'Não foi possível carregar o histórico do paciente.'
    )
  } finally {
    carregando.value = false
  }
}

async function salvarObservacao() {
  erro.value = ''
  mensagem.value = ''

  if (!formularioObservacao.value.text.trim()) {
    erro.value = 'Escreva a observação antes de salvar.'
    return
  }

  if (!formularioObservacao.value.occurred_at) {
    erro.value = 'Informe a data e o horário.'
    return
  }

  salvando.value = true

  try {
    const tags = formularioObservacao.value.tags
      .split(',')
      .map(tag => tag.trim())
      .filter(Boolean)

    await api.post(`/elders/${pacienteId.value}/observations`, {
      text: formularioObservacao.value.text.trim(),
      occurred_at: new Date(
        formularioObservacao.value.occurred_at
      ).toISOString(),
      tags: tags.length ? tags : null
    })

    formularioObservacao.value = novaObservacao()
    mensagem.value = 'Evolução registrada com sucesso!'
    await carregarHistorico()
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível salvar a evolução.')
  } finally {
    salvando.value = false
  }
}

async function salvarMedicao() {
  erro.value = ''
  mensagem.value = ''

  const f = formularioMedicao.value

  if (!f.value || Number(f.value) <= 0) {
    erro.value = 'Informe um valor válido para a medição.'
    return
  }

  if (f.type === 'blood_pressure' &&
      (!f.secondary_value || Number(f.secondary_value) <= 0)) {
    erro.value = 'Informe também o valor diastólico da pressão.'
    return
  }

  if (!f.measured_at) {
    erro.value = 'Informe a data e o horário da medição.'
    return
  }

  salvando.value = true

  try {
    const dados = {
      type: f.type,
      value: Number(f.value),
      measured_at: new Date(f.measured_at).toISOString()
    }

    if (f.type === 'blood_pressure') {
      dados.secondary_value = Number(f.secondary_value)
    }

    await api.post(`/elders/${pacienteId.value}/measurements`, dados)

    formularioMedicao.value = novaMedicao()
    mensagem.value = 'Medição registrada com sucesso!'
    await carregarHistorico()
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível salvar a medição.')
  } finally {
    salvando.value = false
  }
}

function formatarData(data) {
  if (!data) return '-'

  return new Date(data).toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function nomeMedicao(tipo) {
  const nomes = {
    blood_pressure: 'Pressão arterial',
    glucose: 'Glicemia',
    temperature: 'Temperatura'
  }

  return nomes[tipo] || tipo
}

function valorMedicao(item) {
  if (item.type === 'blood_pressure') {
    return `${item.value} / ${item.secondary_value} ${item.unit || 'mmHg'}`
  }

  return `${item.value} ${item.unit || ''}`
}

watch(pacienteId, () => {
  carregarHistorico()
})

onMounted(carregarPacientes)
</script>

<template>
  <div class="evolution-page">
    <header class="evolution-header">
      <div class="brand">
        <HeartPulse :size="30" />
        <div>
          <h2>Ideale</h2>
          <span>HOME CARE</span>
        </div>
      </div>

      <button class="back-button" @click="router.push('/')">
        <ArrowLeft :size="18" />
        Voltar ao Dashboard
      </button>
    </header>

    <main class="evolution-content">
      <section class="page-heading">
        <div>
          <span class="eyebrow">ACOMPANHAMENTO DO PACIENTE</span>
          <h1>Evolução do paciente</h1>
          <p>Registre os cuidados, observações e medições realizados.</p>
        </div>
      </section>

      <div v-if="erro" class="error-message">
        {{ erro }}
      </div>

      <div v-if="mensagem" class="success-message">
        {{ mensagem }}
      </div>

      <section class="patient-selector">
        <div class="selector-icon">
          <UserRound :size="24" />
        </div>

        <div class="selector-fields">
          <label for="patient">Paciente selecionado</label>
          <select id="patient" v-model="pacienteId">
            <option value="" disabled>Selecione um paciente</option>
            <option
              v-for="paciente in pacientes"
              :key="paciente.id"
              :value="String(paciente.id)"
            >
              {{ paciente.name }}
            </option>
          </select>
        </div>

        <div v-if="pacienteSelecionado" class="patient-active">
          Paciente ativo
        </div>
      </section>

      <div v-if="pacientes.length === 0" class="empty-state">
        <UserRound :size="38" />
        <h3>Nenhum paciente cadastrado</h3>
        <p>Cadastre um paciente antes de registrar sua evolução.</p>
        <button class="primary-button" @click="router.push('/pacientes')">
          Cadastrar paciente
        </button>
      </div>

      <template v-else-if="pacienteId">
        <nav class="tabs">
          <button
            :class="{ active: aba === 'evolucao' }"
            @click="aba = 'evolucao'"
          >
            <ClipboardList :size="18" />
            Evolução
          </button>

          <button
            :class="{ active: aba === 'medicoes' }"
            @click="aba = 'medicoes'"
          >
            <Activity :size="18" />
            Medições
          </button>

          <button
            :class="{ active: aba === 'historico' }"
            @click="aba = 'historico'"
          >
            <CalendarDays :size="18" />
            Histórico
          </button>
        </nav>

        <section v-if="aba === 'evolucao'" class="content-panel">
          <div class="panel-heading">
            <div class="heading-icon">
              <ClipboardList :size="22" />
            </div>
            <div>
              <h2>Novo registro de evolução</h2>
              <p>Descreva os cuidados e acontecimentos observados.</p>
            </div>
          </div>

          <form v-if="['family', 'ideale_professional'].includes(userRole)" @submit.prevent="salvarObservacao">
            <label>Data e horário do registro *</label>
            <input
              v-model="formularioObservacao.occurred_at"
              type="datetime-local"
              required
              :max="dataLocalAtual()"
            />

            <label>Descrição da evolução *</label>
            <textarea
              v-model="formularioObservacao.text"
              rows="6"
              maxlength="10000"
              placeholder="Descreva como o paciente está, os cuidados realizados, alterações observadas e outras informações importantes..."
              required
            ></textarea>

            <label>Etiquetas de classificação</label>
            <input
              v-model="formularioObservacao.tags"
              type="text"
              placeholder="Ex.: alimentação, higiene, sono, mobilidade"
            />
            <small>Separe as etiquetas por vírgula.</small>

            <div class="form-actions">
              <button class="primary-button" type="submit" :disabled="salvando">
                <LoaderCircle v-if="salvando" class="loading-icon" :size="18" />
                <Plus v-else :size="18" />
                {{ salvando ? 'Salvando...' : 'Salvar evolução' }}
              </button>
            </div>
          </form>
        </section>

        <section v-if="aba === 'medicoes'" class="content-panel">
          <div class="panel-heading">
            <div class="heading-icon">
              <Activity :size="22" />
            </div>
            <div>
              <h2>Registrar medição</h2>
              <p>Registre os sinais e valores observados no paciente.</p>
            </div>
          </div>

          <form v-if="['family', 'ideale_professional'].includes(userRole)" @submit.prevent="salvarMedicao">
            <label>Tipo de medição *</label>
            <select v-model="formularioMedicao.type">
              <option value="blood_pressure">Pressão arterial</option>
              <option value="glucose">Glicemia</option>
              <option value="temperature">Temperatura</option>
            </select>

            <div v-if="formularioMedicao.type === 'blood_pressure'" class="measurement-fields">
              <div>
                <label>Pressão sistólica *</label>
                <input
                  v-model="formularioMedicao.value"
                  type="number"
                  min="1"
                  max="9999"
                  step="0.01"
                  placeholder="Ex.: 120"
                  required
                />
                <small>Valor em mmHg</small>
              </div>

              <div>
                <label>Pressão diastólica *</label>
                <input
                  v-model="formularioMedicao.secondary_value"
                  type="number"
                  min="1"
                  max="9999"
                  step="0.01"
                  placeholder="Ex.: 80"
                  required
                />
                <small>Valor em mmHg</small>
              </div>
            </div>

            <template v-else>
              <label>
                {{ formularioMedicao.type === 'glucose' ? 'Valor da glicemia *' : 'Temperatura *' }}
              </label>
              <input
                v-model="formularioMedicao.value"
                type="number"
                min="0.01"
                max="9999"
                step="0.01"
                :placeholder="formularioMedicao.type === 'glucose' ? 'Ex.: 100' : 'Ex.: 36.5'"
                required
              />
              <small>
                {{ formularioMedicao.type === 'glucose' ? 'Valor em mg/dL' : 'Valor em °C' }}
              </small>
            </template>

            <label>Data e horário da medição *</label>
            <input
              v-model="formularioMedicao.measured_at"
              type="datetime-local"
              required
              :max="dataLocalAtual()"
            />

            <div class="form-actions">
              <button class="primary-button" type="submit" :disabled="salvando">
                <LoaderCircle v-if="salvando" class="loading-icon" :size="18" />
                <Plus v-else :size="18" />
                {{ salvando ? 'Salvando...' : 'Salvar medição' }}
              </button>
            </div>
          </form>
        </section>

        <section v-if="aba === 'historico'" class="content-panel">
          <div class="panel-heading history-heading">
            <div>
              <h2>Histórico de acompanhamento</h2>
              <p>Todos os registros deste paciente.</p>
            </div>

            <button class="refresh-button" @click="carregarHistorico">
              <RefreshCw :size="17" />
              Atualizar
            </button>
          </div>

          <div v-if="carregando" class="empty-state">
            <LoaderCircle class="loading-icon" :size="28" />
            <p>Carregando histórico...</p>
          </div>

          <div v-else-if="historico.length === 0" class="empty-state">
            <ClipboardList :size="38" />
            <h3>Nenhum registro encontrado</h3>
            <p>Os registros de evolução e medições aparecerão aqui.</p>
          </div>

          <div v-else class="history-list">
            <article
              v-for="(item, index) in historico"
              :key="`${item.categoria}-${item.id || index}`"
              class="history-item"
            >
              <div class="history-icon" :class="item.categoria">
                <ClipboardList v-if="item.categoria === 'observacao'" :size="20" />
                <Activity v-else :size="20" />
              </div>

              <div class="history-body">
                <div class="history-top">
                  <strong>
                    {{ item.categoria === 'observacao' ? 'Evolução registrada' : nomeMedicao(item.type) }}
                  </strong>
                  <time>{{ formatarData(item.data) }}</time>
                </div>

                <p v-if="item.categoria === 'observacao'">
                  {{ item.text }}
                </p>

                <p v-else class="measurement-value">
                  {{ valorMedicao(item) }}
                </p>

                <div
                  v-if="item.categoria === 'observacao' && item.tags?.length"
                  class="tags"
                >
                  <span v-for="tag in item.tags" :key="tag">
                    {{ tag }}
                  </span>
                </div>
              </div>
            </article>
          </div>
        </section>

        <section class="quick-summary">
          <div>
            <ClipboardList :size="21" />
            <span>Registros de evolução</span>
            <strong>{{ observacoes.length }}</strong>
          </div>
          <div>
            <Activity :size="21" />
            <span>Medições registradas</span>
            <strong>{{ medicoes.length }}</strong>
          </div>
        </section>
      </template>
    </main>

    <footer>
      Ideale Home Care · Gestão de cuidados com carinho.
    </footer>
  </div>
</template>

<style scoped>
.evolution-page {
  min-height: 100vh;
  background: #f8f7f8;
  color: #34313a;
}

.evolution-header {
  height: 78px;
  background: white;
  border-bottom: 1px solid #eee9ec;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 5%;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #a64d70;
}

.brand h2 {
  font-size: 21px;
  margin: 0;
}

.brand span {
  font-size: 9px;
  letter-spacing: 2px;
}

.back-button,
.refresh-button {
  display: flex;
  align-items: center;
  gap: 8px;
  border: none;
  background: transparent;
  color: #777;
  cursor: pointer;
}

.evolution-content {
  max-width: 1100px;
  margin: auto;
  padding: 40px 30px;
}

.page-heading {
  margin-bottom: 28px;
}

.eyebrow {
  color: #a64d70;
  font-size: 11px;
  letter-spacing: 1.5px;
  font-weight: 600;
}

.page-heading h1 {
  margin: 8px 0;
  font-size: 30px;
}

.page-heading p {
  color: #888;
  margin: 0;
}

.patient-selector {
  display: flex;
  align-items: center;
  gap: 15px;
  background: white;
  border: 1px solid #eee9ec;
  border-radius: 12px;
  padding: 18px 22px;
  margin-bottom: 24px;
}

.selector-icon,
.heading-icon {
  width: 46px;
  height: 46px;
  background: #f9edf2;
  color: #a64d70;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.selector-fields {
  flex: 1;
}

.selector-fields label,
.content-panel form label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
}

.selector-fields select {
  width: 100%;
  max-width: 450px;
  padding: 9px 11px;
  border: 1px solid #e5dfe3;
  border-radius: 8px;
  background: white;
  font: inherit;
}

.patient-active {
  color: #38865c;
  background: #eaf6ee;
  padding: 7px 12px;
  border-radius: 20px;
  font-size: 12px;
}

.tabs {
  display: flex;
  gap: 8px;
  border-bottom: 1px solid #e8e2e6;
  margin-bottom: 22px;
  overflow-x: auto;
}

.tabs button {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  padding: 13px 18px;
  border: none;
  border-bottom: 3px solid transparent;
  background: transparent;
  color: #888;
  cursor: pointer;
  font: inherit;
  font-weight: 600;
}

.tabs button.active {
  color: #a64d70;
  border-bottom-color: #a64d70;
}

.content-panel {
  background: white;
  border: 1px solid #eee9ec;
  border-radius: 13px;
  padding: 28px;
}

.panel-heading {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 25px;
}

.panel-heading h2 {
  margin: 0 0 6px;
  font-size: 19px;
}

.panel-heading p {
  margin: 0;
  color: #888;
  font-size: 13px;
}

.content-panel form label {
  margin-top: 18px;
}

.content-panel form input,
.content-panel form select,
.content-panel form textarea {
  box-sizing: border-box;
  width: 100%;
  padding: 12px;
  border: 1px solid #e5dfe3;
  border-radius: 8px;
  font: inherit;
  outline-color: #a64d70;
  background: white;
}

.content-panel form textarea {
  resize: vertical;
}

.content-panel form small {
  display: block;
  color: #999;
  margin-top: 6px;
  font-size: 12px;
}

.measurement-fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 25px;
}

.primary-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 19px;
  border: none;
  border-radius: 9px;
  background: #a64d70;
  color: white;
  font-weight: 600;
  cursor: pointer;
}

.primary-button:hover {
  background: #913e60;
}

.primary-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.refresh-button {
  border: 1px solid #e8e3e6;
  border-radius: 8px;
  padding: 9px 12px;
}

.history-heading {
  justify-content: space-between;
}

.history-list {
  display: flex;
  flex-direction: column;
}

.history-item {
  display: flex;
  gap: 14px;
  padding: 20px 0;
  border-top: 1px solid #f0edef;
}

.history-icon {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f9edf2;
  color: #a64d70;
}

.history-icon.medicao {
  background: #edf3fa;
  color: #557da5;
}

.history-body {
  flex: 1;
  min-width: 0;
}

.history-top {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  margin-bottom: 8px;
}

.history-top strong {
  font-size: 14px;
}

.history-top time {
  color: #999;
  font-size: 12px;
  white-space: nowrap;
}

.history-body p {
  color: #666;
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  margin: 0;
}

.history-body .measurement-value {
  font-weight: 700;
  color: #557da5;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 10px;
}

.tags span {
  padding: 5px 9px;
  border-radius: 20px;
  background: #f9edf2;
  color: #a64d70;
  font-size: 11px;
}

.quick-summary {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 22px;
}

.quick-summary > div {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px;
  border: 1px solid #eee9ec;
  border-radius: 11px;
  background: white;
  color: #a64d70;
}

.quick-summary span {
  flex: 1;
  color: #777;
  font-size: 13px;
}

.quick-summary strong {
  font-size: 22px;
  color: #34313a;
}

.empty-state {
  text-align: center;
  padding: 40px 15px;
  color: #999;
}

.empty-state h3 {
  color: #555;
}

.error-message,
.success-message {
  padding: 12px 15px;
  border-radius: 8px;
  margin-bottom: 18px;
  font-size: 14px;
}

.error-message {
  color: #b43d48;
  background: #fff0f0;
}

.success-message {
  color: #28764b;
  background: #eaf6ee;
}

.loading-icon {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

footer {
  text-align: center;
  padding: 25px;
  color: #999;
  font-size: 12px;
}

@media (max-width: 650px) {
  .evolution-content {
    padding: 25px 16px;
  }

  .patient-selector {
    flex-wrap: wrap;
  }

  .selector-fields {
    min-width: 180px;
  }

  .patient-active {
    margin-left: 60px;
  }

  .content-panel {
    padding: 20px;
  }

  .measurement-fields,
  .quick-summary {
    grid-template-columns: 1fr;
  }

  .history-top {
    flex-direction: column;
    gap: 5px;
  }

  .history-heading {
    align-items: flex-start;
  }
}
</style>
