
<script setup>
import { inject, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowLeft,
  Plus,
  Search,
  Users,
  UserRound,
  X,
  HeartPulse,
  LoaderCircle
} from '@lucide/vue'
import api from '../services/api'

const router = useRouter()
const userRole = inject('currentUserRole', ref(''))

const pacientes = ref([])
const carregando = ref(false)
const salvando = ref(false)
const erro = ref('')
const mensagem = ref('')
const busca = ref('')
const mostrarFormulario = ref(false)

const formulario = ref({
  name: '',
  birth_date: '',
  timezone: 'America/Cuiaba',
  medical_information: ''
})

async function carregarPacientes() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await api.get('/elders')
    pacientes.value = resposta.data.data || []
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível carregar os pacientes.'
  } finally {
    carregando.value = false
  }
}

async function cadastrarPaciente() {
  erro.value = ''
  mensagem.value = ''

  if (!formulario.value.name.trim() || !formulario.value.birth_date) {
    erro.value = 'Preencha o nome e a data de nascimento.'
    return
  }

  salvando.value = true

  try {
    await api.post('/elders', {
      name: formulario.value.name.trim(),
      birth_date: formulario.value.birth_date,
      timezone: formulario.value.timezone,
      medical_information: formulario.value.medical_information || null
    })

    formulario.value = {
      name: '',
      birth_date: '',
      timezone: 'America/Cuiaba',
      medical_information: ''
    }

    mostrarFormulario.value = false
    mensagem.value = 'Paciente cadastrado com sucesso!'

    await carregarPacientes()
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível cadastrar o paciente.'
  } finally {
    salvando.value = false
  }
}

const pacientesFiltrados = () => {
  const termo = busca.value.toLowerCase().trim()

  if (!termo) return pacientes.value

  return pacientes.value.filter(paciente =>
    paciente.name.toLowerCase().includes(termo)
  )
}

function formatarData(data) {
  if (!data) return '-'

  const [ano, mes, dia] = data.split('-')
  return `${dia}/${mes}/${ano}`
}

onMounted(carregarPacientes)
</script>

<template>
  <div class="patients-page">
    <header class="patients-header">
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

    <main class="patients-content">
      <section class="page-heading">
        <div>
          <span class="eyebrow">GESTÃO DE CUIDADOS</span>
          <h1>Pacientes</h1>
          <p>Cadastre e acompanhe os pacientes da Ideale Home Care.</p>
        </div>

      <button v-if="userRole === 'family'" class="add-button" @click="mostrarFormulario = true">
          <Plus :size="19" />
          Novo paciente
        </button>
      </section>

      <div v-if="mensagem" class="success-message">
        {{ mensagem }}
      </div>

      <div v-if="erro" class="error-message">
        {{ erro }}
      </div>

      <section class="summary-card">
        <div class="summary-icon">
          <Users :size="25" />
        </div>
        <div>
          <span>Pacientes cadastrados</span>
          <strong>{{ pacientes.length }}</strong>
        </div>
      </section>

      <section class="patients-panel">
        <div class="panel-heading">
          <div>
            <h2>Lista de pacientes</h2>
            <p>Todos os pacientes vinculados à sua conta.</p>
          </div>

          <div class="search-box">
            <Search :size="18" />
            <input
              v-model="busca"
              placeholder="Buscar paciente..."
              type="search"
            />
          </div>
        </div>

        <div v-if="carregando" class="empty-state">
          <LoaderCircle class="loading-icon" :size="28" />
          <p>Carregando pacientes...</p>
        </div>

        <div v-else-if="pacientesFiltrados().length === 0" class="empty-state">
          <UserRound :size="38" />
          <h3>Nenhum paciente encontrado</h3>
          <p>Cadastre um novo paciente para começar.</p>
        </div>

        <div v-else class="patients-list">
          <article
            v-for="paciente in pacientesFiltrados()"
            :key="paciente.id"
            class="patient-item"
          >
            <div class="patient-avatar">
              <UserRound :size="23" />
            </div>

            <div class="patient-info">
              <strong>{{ paciente.name }}</strong>
              <span>
                Nascimento: {{ formatarData(paciente.birth_date) }}
              </span>
            </div>

            <span
              class="patient-status"
              :class="{ inactive: !paciente.active }"
            >
              {{ paciente.active ? 'Ativo' : 'Inativo' }}
            </span>
          </article>
        </div>
      </section>
    </main>

    <div
      v-if="mostrarFormulario && userRole === 'family'"
      class="modal-overlay"
      @click.self="mostrarFormulario = false"
    >
      <section class="patient-modal">
        <div class="modal-heading">
          <div>
            <h2>Novo paciente</h2>
            <p>Preencha os dados para cadastrar.</p>
          </div>

          <button
            class="close-button"
            @click="mostrarFormulario = false"
          >
            <X />
          </button>
        </div>

        <form @submit.prevent="cadastrarPaciente">
          <label>Nome completo *</label>
          <input
            v-model="formulario.name"
            type="text"
            maxlength="200"
            placeholder="Digite o nome do paciente"
            required
          />

          <label>Data de nascimento *</label>
          <input
            v-model="formulario.birth_date"
            type="date"
            :max="new Date().toISOString().slice(0, 10)"
            required
          />

          <label>Fuso horário</label>
          <select v-model="formulario.timezone" required>
            <option value="America/Cuiaba">Cuiabá</option>
            <option value="America/Sao_Paulo">São Paulo</option>
            <option value="America/Manaus">Manaus</option>
            <option value="America/Rio_Branco">Rio Branco</option>
            <option value="America/Belem">Belém</option>
          </select>

          <label>Informações médicas</label>
          <textarea
            v-model="formulario.medical_information"
            rows="4"
            maxlength="10000"
            placeholder="Alergias, condições e observações importantes..."
          ></textarea>

          <p v-if="erro" class="error-message">{{ erro }}</p>

          <div class="modal-actions">
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
              {{ salvando ? 'Salvando...' : 'Cadastrar paciente' }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <footer>
      Ideale Home Care · Gestão de cuidados com carinho.
    </footer>
  </div>
</template>

<style scoped>
.patients-page {
  min-height: 100vh;
  background: #f8f7f8;
  color: #34313a;
}

.patients-header {
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
.close-button {
  display: flex;
  align-items: center;
  gap: 8px;
  border: none;
  background: transparent;
  color: #777;
  cursor: pointer;
}

.patients-content {
  max-width: 1200px;
  margin: auto;
  padding: 40px 30px;
}

.page-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 30px;
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

.page-heading p,
.panel-heading p {
  color: #888;
  margin: 0;
}

.add-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: #a64d70;
  color: white;
  border: none;
  border-radius: 9px;
  padding: 12px 18px;
  cursor: pointer;
  font-weight: 600;
}

.add-button:hover {
  background: #913e60;
}

.add-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 16px;
  background: white;
  padding: 22px;
  border: 1px solid #eee9ec;
  border-radius: 12px;
  width: 250px;
  margin-bottom: 25px;
}

.summary-icon,
.patient-avatar {
  width: 48px;
  height: 48px;
  background: #f9edf2;
  color: #a64d70;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.summary-card span,
.summary-card strong {
  display: block;
}

.summary-card span {
  color: #888;
  font-size: 12px;
}

.summary-card strong {
  font-size: 25px;
  margin-top: 4px;
}

.patients-panel {
  background: white;
  border: 1px solid #eee9ec;
  border-radius: 13px;
  padding: 25px;
}

.panel-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  margin-bottom: 25px;
}

.panel-heading h2 {
  margin: 0 0 7px;
  font-size: 19px;
}

.search-box {
  display: flex;
  align-items: center;
  gap: 9px;
  border: 1px solid #e8e3e6;
  border-radius: 8px;
  padding: 10px 12px;
  color: #999;
}

.search-box input {
  border: none;
  outline: none;
  width: 190px;
}

.patient-item {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 17px 5px;
  border-top: 1px solid #f0edef;
}

.patient-info {
  flex: 1;
}

.patient-info strong,
.patient-info span {
  display: block;
}

.patient-info span {
  font-size: 13px;
  color: #888;
  margin-top: 5px;
}

.patient-status {
  color: #38865c;
  background: #eaf6ee;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12px;
}

.patient-status.inactive {
  color: #888;
  background: #f0edef;
}

.empty-state {
  text-align: center;
  padding: 45px 15px;
  color: #999;
}

.empty-state h3 {
  color: #555;
}

.loading-icon {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(30, 25, 30, 0.45);
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 20px;
  z-index: 10;
}

.patient-modal {
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  overflow-y: auto;
  background: white;
  border-radius: 15px;
  padding: 28px;
}

.modal-heading {
  display: flex;
  justify-content: space-between;
  margin-bottom: 22px;
}

.modal-heading h2 {
  margin: 0 0 6px;
}

.modal-heading p {
  color: #888;
  margin: 0;
}

.patient-modal form label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin: 16px 0 7px;
}

.patient-modal input,
.patient-modal select,
.patient-modal textarea {
  box-sizing: border-box;
  width: 100%;
  padding: 12px;
  border: 1px solid #e5dfe3;
  border-radius: 8px;
  font: inherit;
  outline-color: #a64d70;
}

.patient-modal textarea {
  resize: vertical;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}

.cancel-button {
  border: 1px solid #ddd;
  background: white;
  border-radius: 8px;
  padding: 12px 17px;
  cursor: pointer;
}

.success-message,
.error-message {
  padding: 12px 15px;
  border-radius: 8px;
  margin-bottom: 18px;
  font-size: 14px;
}

.success-message {
  color: #28764b;
  background: #eaf6ee;
}

.error-message {
  color: #b43d48;
  background: #fff0f0;
}

footer {
  text-align: center;
  padding: 25px;
  color: #999;
  font-size: 12px;
}

@media (max-width: 650px) {
  .patients-content {
    padding: 25px 16px;
  }

  .page-heading,
  .panel-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .summary-card {
    width: auto;
  }

  .search-box input {
    width: 100%;
  }

  .patient-modal {
    padding: 20px;
  }
}
</style>
