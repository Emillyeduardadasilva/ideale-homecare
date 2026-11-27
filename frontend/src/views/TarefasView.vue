
<script setup>
import { inject, ref, computed, onMounted } from 'vue'
import {
  ClipboardList,
  ArrowLeft,
  Plus,
  Clock,
  CheckCircle2,
  RefreshCw,
  X
} from '@lucide/vue'

import api from '../services/api'
import { useRouter } from 'vue-router'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))
const userId = inject('currentUserId', ref(null))

const pacientes = ref([])
const pacienteId = ref('')
const tarefas = ref([])
const responsaveis = ref([])
const carregando = ref(false)
const salvando = ref(false)
const mostrarFormulario = ref(false)
const erro = ref('')
const sucesso = ref('')

function dataLocal() {
  const hoje = new Date()
  const ano = hoje.getFullYear()
  const mes = String(hoje.getMonth() + 1).padStart(2, '0')
  const dia = String(hoje.getDate()).padStart(2, '0')

  return `${ano}-${mes}-${dia}`
}

function formularioInicial() {
  return {
    title: '',
    description: '',
    date: dataLocal(),
    time: '08:00',
    assignees: []
  }
}

const formulario = ref(formularioInicial())

const pendentes = computed(() =>
  tarefas.value.filter(t => t.status === 'pending')
)

const concluidas = computed(() =>
  tarefas.value.filter(t => t.status === 'completed')
)

const pacienteSelecionado = computed(() =>
  pacientes.value.find(p => String(p.id) === String(pacienteId.value))
)

const podeCriarTarefas = computed(() =>
  userRole.value === 'family' && pacienteSelecionado.value?.is_manager === true
)

// Mesma regra da API: familiar só conclui se for responsável; idoso e profissional vinculado podem concluir.
function podeConcluirTarefa(tarefa) {
  if (tarefa.status !== 'pending') return false
  if (['elder', 'ideale_professional'].includes(userRole.value)) return true
  if (userRole.value !== 'family') return false
  return (tarefa.assignees || []).some(r => String(r.id) === String(userId.value))
}

async function carregarPacientes() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await api.get('/elders')

    pacientes.value = (resposta.data.data || [])
      .filter(p => p.active !== false)

    if (pacientes.value.length) {
      pacienteId.value = pacientes.value[0].id
      await carregarDados()
    }
  } catch (e) {
    erro.value = e.response?.data?.message ||
      'Não foi possível carregar os pacientes.'
  } finally {
    carregando.value = false
  }
}

async function carregarDados() {
  if (!pacienteId.value) return

  carregando.value = true
  erro.value = ''
  sucesso.value = ''

  try {
    const resTarefas = await api.get(`/elders/${pacienteId.value}/tasks`)
    const resResponsaveis = podeCriarTarefas.value
      ? await api.get(`/elders/${pacienteId.value}/memberships`)
      : { data: { data: [] } }

    tarefas.value =
      resTarefas.data.data?.data ||
      resTarefas.data.data ||
      []

    responsaveis.value = (resResponsaveis.data.data || [])
      .filter(r =>
        (r.active === true || r.active === 1) &&
        ['manager', 'relative', 'team'].includes(r.role)
      )

    formulario.value.assignees = []
  } catch (e) {
    console.error('Erro ao carregar tarefas:', e)

    erro.value = e.response?.data?.message ||
      'Erro ao carregar as tarefas e os responsáveis.'
  } finally {
    carregando.value = false
  }
}

async function salvarTarefa() {
  erro.value = ''
  sucesso.value = ''

  if (!formulario.value.title.trim()) {
    erro.value = 'Informe o nome da tarefa.'
    return
  }

  if (!formulario.value.assignees.length) {
    erro.value = 'Selecione pelo menos um responsável.'
    return
  }

  salvando.value = true

  try {
    const dueAt = new Date(
      `${formulario.value.date}T${formulario.value.time}:00`
    ).toISOString()

    await api.post(`/elders/${pacienteId.value}/tasks`, {
      title: formulario.value.title.trim(),
      description: formulario.value.description || null,
      due_at: dueAt,
      assignees: formulario.value.assignees
    })

    sucesso.value = 'Tarefa cadastrada com sucesso!'
    mostrarFormulario.value = false
    formulario.value = formularioInicial()

    await carregarDados()
    sucesso.value = 'Tarefa cadastrada com sucesso!'
  } catch (e) {
    console.error('Erro ao salvar tarefa:', e)

    erro.value = e.response?.data?.message ||
      'Não foi possível salvar a tarefa.'
  } finally {
    salvando.value = false
  }
}

async function concluirTarefa(tarefa) {
  erro.value = ''
  sucesso.value = ''

  try {
    await api.post(
      `/elders/${pacienteId.value}/tasks/${tarefa.id}/complete`
    )

    sucesso.value = 'Tarefa concluída!'
    await carregarDados()
    sucesso.value = 'Tarefa concluída!'
  } catch (e) {
    console.error('Erro ao concluir tarefa:', e)

    erro.value = e.response?.data?.message ||
      'Não foi possível concluir a tarefa.'
  }
}

function nomeResponsaveis(tarefa) {
  return (tarefa.assignees || [])
    .map(r => r.name)
    .filter(Boolean)
    .join(', ') || 'Responsável não informado'
}

onMounted(carregarPacientes)
</script>

<template>
  <div class="tarefas-page">
    <header class="topo">
      <button class="voltar" @click="router.push('/')">
        <ArrowLeft :size="18" />
        Voltar
      </button>

      <div class="titulo">
        <ClipboardList :size="28" />
        <div>
          <h1>Tarefas</h1>
          <p>Organização dos cuidados diários</p>
        </div>
      </div>

      <button
        v-if="podeCriarTarefas"
        class="adicionar"
        @click="mostrarFormulario = true"
        :disabled="!pacientes.length"
      >
        <Plus :size="18" />
        Nova tarefa
      </button>
    </header>

    <main>
      <div v-if="erro" class="mensagem erro">
        {{ erro }}
      </div>

      <div v-if="sucesso" class="mensagem sucesso">
        {{ sucesso }}
      </div>

      <section class="filtro">
        <label>Selecione o paciente</label>

        <select
          v-model="pacienteId"
          @change="carregarDados"
        >
          <option
            v-for="p in pacientes"
            :key="p.id"
            :value="p.id"
          >
            {{ p.name }}
          </option>
        </select>
      </section>

      <div class="resumo">
        <div class="card-resumo">
          <span>Pendentes</span>
          <strong>{{ pendentes.length }}</strong>
        </div>

        <div class="card-resumo">
          <span>Concluídas</span>
          <strong>{{ concluidas.length }}</strong>
        </div>
      </div>

      <section class="lista">
        <div class="lista-topo">
          <h2>Cuidados programados</h2>

          <button
            @click="carregarDados"
            class="atualizar"
            :disabled="carregando"
          >
            <RefreshCw :size="17" />
          </button>
        </div>

        <div v-if="carregando" class="vazio">
          Carregando tarefas...
        </div>

        <div
          v-else-if="!tarefas.length"
          class="vazio"
        >
          Nenhuma tarefa cadastrada para este paciente.
        </div>

        <article
          v-for="tarefa in tarefas"
          :key="tarefa.id"
          class="tarefa"
        >
          <div class="icone">
            <CheckCircle2 :size="22" />
          </div>

          <div class="detalhes">
            <h3>{{ tarefa.title }}</h3>

            <p v-if="tarefa.description">
              {{ tarefa.description }}
            </p>

            <small>
              <Clock :size="14" />
              {{ new Date(tarefa.due_at).toLocaleString('pt-BR') }}
            </small>

            <small>
              Responsável: {{ nomeResponsaveis(tarefa) }}
            </small>
          </div>

          <button
            v-if="podeConcluirTarefa(tarefa)"
            class="concluir"
            @click="concluirTarefa(tarefa)"
          >
            Concluir
          </button>

          <span
            v-else-if="tarefa.status === 'completed'"
            class="status"
          >
            Concluída
          </span>

          <span
            v-else-if="tarefa.status === 'pending'"
            class="status"
          >
            Pendente
          </span>

          <span v-else class="status cancelada">
            Cancelada
          </span>
        </article>
      </section>
    </main>

    <div
      v-if="mostrarFormulario && podeCriarTarefas"
      class="modal-fundo"
    >
      <form
        class="modal"
        @submit.prevent="salvarTarefa"
      >
        <div class="modal-topo">
          <h2>Nova tarefa</h2>

          <button
            type="button"
            @click="mostrarFormulario = false"
          >
            <X :size="20" />
          </button>
        </div>

        <label>Nome da tarefa</label>

        <input
          v-model="formulario.title"
          required
          maxlength="200"
          placeholder="Ex.: Acompanhar alimentação"
        />

        <label>Descrição</label>

        <textarea
          v-model="formulario.description"
          placeholder="Detalhes do cuidado"
        ></textarea>

        <div class="campos">
          <div>
            <label>Data</label>

            <input
              v-model="formulario.date"
              type="date"
              required
            />
          </div>

          <div>
            <label>Horário</label>

            <input
              v-model="formulario.time"
              type="time"
              required
            />
          </div>
        </div>

        <label>Responsáveis</label>

        <div
          v-if="!responsaveis.length"
          class="aviso"
        >
          Nenhum responsável familiar ativo encontrado.
        </div>

        <label
          v-for="r in responsaveis"
          :key="r.user_id"
          class="responsavel"
        >
          <input
            type="checkbox"
            :value="r.user_id"
            v-model="formulario.assignees"
          />

          {{ r.name }}
        </label>

        <button
          class="salvar"
          type="submit"
          :disabled="salvando || !responsaveis.length"
        >
          {{ salvando ? 'Salvando...' : 'Salvar tarefa' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.tarefas-page {
  min-height: 100vh;
  background: #f8f5f6;
  padding: 30px;
  color: #443b3e;
}

.topo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
  flex-wrap: wrap;
  margin-bottom: 30px;
}

.voltar,
.atualizar {
  background: white;
  border: 1px solid #eadfe3;
  padding: 10px;
  border-radius: 9px;
  cursor: pointer;
  display: flex;
  gap: 8px;
  align-items: center;
}

.titulo {
  display: flex;
  align-items: center;
  gap: 12px;
  color: #a64d70;
}

h1, h2, h3, p {
  margin-top: 0;
}

.titulo h1 {
  margin-bottom: 4px;
}

.titulo p {
  color: #887b80;
  margin: 0;
}

.adicionar,
.salvar {
  background: #a64d70;
  color: white;
  border: 0;
  border-radius: 9px;
  padding: 12px 18px;
  cursor: pointer;
  display: flex;
  gap: 8px;
  align-items: center;
}

.adicionar:disabled {
  opacity: .5;
  cursor: not-allowed;
}

main {
  max-width: 1100px;
  margin: auto;
}

.filtro,
.lista {
  background: white;
  border-radius: 14px;
  padding: 22px;
  margin-bottom: 20px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
}

select,
input:not([type=checkbox]),
textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 12px;
  border: 1px solid #e6dce0;
  border-radius: 8px;
  margin-bottom: 15px;
  background: white;
}

.resumo {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}

.card-resumo {
  background: white;
  padding: 22px;
  border-radius: 14px;
}

.card-resumo span {
  color: #887b80;
  display: block;
}

.card-resumo strong {
  font-size: 28px;
  color: #a64d70;
}

.lista-topo {
  display: flex;
  justify-content: space-between;
}

.tarefa {
  display: flex;
  gap: 15px;
  align-items: center;
  padding: 18px 0;
  border-top: 1px solid #f0e8eb;
}

.icone {
  color: #a64d70;
}

.detalhes {
  flex: 1;
  min-width: 0;
}

.detalhes h3 {
  margin-bottom: 5px;
}

.detalhes p {
  color: #887b80;
  margin-bottom: 8px;
}

.detalhes small {
  display: flex;
  align-items: center;
  gap: 5px;
  color: #887b80;
  margin-top: 5px;
}

.concluir {
  background: #e9f3ec;
  color: #35734a;
  border: 0;
  border-radius: 8px;
  padding: 10px;
  cursor: pointer;
}

.status {
  color: #35734a;
  font-weight: 600;
}

.cancelada {
  color: #887b80;
}

.vazio {
  padding: 30px;
  text-align: center;
  color: #887b80;
}

.mensagem {
  padding: 12px;
  border-radius: 8px;
  margin-bottom: 15px;
}

.erro,
.aviso {
  background: #fff0f0;
  color: #a33;
  padding: 12px;
  border-radius: 8px;
}

.sucesso {
  background: #e9f3ec;
  color: #35734a;
}

.modal-fundo {
  position: fixed;
  inset: 0;
  background: #0008;
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 15px;
  z-index: 10;
  overflow-y: auto;
}

.modal {
  background: white;
  padding: 25px;
  border-radius: 16px;
  width: 100%;
  max-width: 500px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-topo {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-topo button {
  border: 0;
  background: none;
  cursor: pointer;
}

.campos {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.responsavel {
  display: flex;
  align-items: center;
  gap: 9px;
  font-weight: 400;
}

.responsavel input {
  width: auto;
}

.salvar {
  width: 100%;
  justify-content: center;
  margin-top: 15px;
}

.salvar:disabled {
  opacity: .5;
  cursor: not-allowed;
}

@media (max-width: 600px) {
  .tarefas-page {
    padding: 16px;
  }

  .tarefa {
    flex-wrap: wrap;
  }

  .campos {
    grid-template-columns: 1fr;
  }
}
</style>
