
<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import {
  Users,
  UserPlus,
  Mail,
  ShieldCheck,
  Trash2,
  RefreshCw,
  Copy,
  HeartPulse
} from '@lucide/vue'

import api from '../services/api'

const pacientes = ref([])
const pacienteId = ref('')
const membros = ref([])
const convites = ref([])

const carregando = ref(false)
const enviando = ref(false)
const erro = ref('')
const sucesso = ref('')

const email = ref('')
const papel = ref('relative')

const conviteCriado = ref(null)

const pacienteSelecionado = computed(() =>
  pacientes.value.find(p => String(p.id) === String(pacienteId.value))
)

const membrosAtivos = computed(() =>
  membros.value.filter(m => m.active)
)

function nomePapel(role) {
  const papeis = {
    manager: 'Gestor da rede',
    relative: 'Familiar',
    team: 'Profissional',
    elder: 'Paciente'
  }

  return papeis[role] || role
}

function statusConvite(convite) {
  if (convite.accepted_at) return 'Aceito'
  if (convite.revoked_at) return 'Revogado'

  if (convite.expires_at && new Date(convite.expires_at) < new Date()) {
    return 'Expirado'
  }

  return 'Pendente'
}

function formatarData(data) {
  if (!data) return '--'

  return new Date(data).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}

async function carregarPacientes() {
  erro.value = ''

  try {
    const resposta = await api.get('/elders')
    pacientes.value = resposta.data.data || []

    if (pacientes.value.length) {
      pacienteId.value = String(pacientes.value[0].id)
    }
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível carregar os pacientes.'
  }
}

async function carregarRede() {
  if (!pacienteId.value) return

  carregando.value = true
  erro.value = ''
  sucesso.value = ''
  conviteCriado.value = null

  try {
    const id = pacienteId.value

    const [resMembros, resConvites] = await Promise.all([
      api.get(`/elders/${id}/memberships`),
      api.get(`/elders/${id}/invitations`)
    ])

    membros.value = resMembros.data.data || []
    convites.value = resConvites.data.data || []
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível carregar a rede de cuidadores.'
  } finally {
    carregando.value = false
  }
}

async function enviarConvite() {
  if (!pacienteId.value || !email.value.trim()) return

  enviando.value = true
  erro.value = ''
  sucesso.value = ''
  conviteCriado.value = null

  try {
    const resposta = await api.post(
      `/elders/${pacienteId.value}/invitations`,
      {
        email: email.value.trim(),
        role: papel.value
      }
    )

    conviteCriado.value = {
      url: resposta.data.invitation_url,
      token: resposta.data.invitation_token
    }

    sucesso.value = 'Convite criado com sucesso!'
    email.value = ''

    await carregarRede()
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível enviar o convite.'
  } finally {
    enviando.value = false
  }
}

async function removerMembro(membro) {
  if (!confirm(`Deseja remover o acesso de ${membro.name}?`)) return

  erro.value = ''
  sucesso.value = ''

  try {
    await api.delete(
      `/elders/${pacienteId.value}/memberships/${membro.id}`
    )

    sucesso.value = 'Acesso removido com sucesso.'
    await carregarRede()
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível remover o acesso.'
  }
}

async function revogarConvite(convite) {
  if (!confirm(`Deseja revogar o convite para ${convite.email}?`)) return

  erro.value = ''
  sucesso.value = ''

  try {
    await api.delete(
      `/elders/${pacienteId.value}/invitations/${convite.id}`
    )

    sucesso.value = 'Convite revogado com sucesso.'
    await carregarRede()
  } catch (error) {
    erro.value = error.response?.data?.message ||
      'Não foi possível revogar o convite.'
  }
}

async function copiarConvite() {
  if (!conviteCriado.value?.url) return

  try {
    await navigator.clipboard.writeText(conviteCriado.value.url)
    sucesso.value = 'Link do convite copiado!'
  } catch {
    erro.value = 'Não foi possível copiar o link.'
  }
}

watch(pacienteId, () => {
  carregarRede()
})

onMounted(carregarPacientes)
</script>

<template>
  <div class="rede-page">
    <header class="page-header">
      <div class="header-icon">
        <HeartPulse :size="30" />
      </div>

      <div>
        <h1>Rede de Cuidadores</h1>
        <p>Gerencie familiares e profissionais vinculados aos pacientes.</p>
      </div>
    </header>

    <div v-if="erro" class="message error-message">
      {{ erro }}
    </div>

    <div v-if="sucesso" class="message success-message">
      {{ sucesso }}
    </div>

    <section class="panel">
      <label for="paciente">Selecione o paciente</label>

      <select id="paciente" v-model="pacienteId">
        <option value="" disabled>Selecione um paciente</option>
        <option
          v-for="paciente in pacientes"
          :key="paciente.id"
          :value="String(paciente.id)"
        >
          {{ paciente.name }}
        </option>
      </select>

      <p v-if="!pacientes.length" class="empty">
        Nenhum paciente cadastrado.
      </p>
    </section>

    <section class="panel">
      <div class="section-heading">
        <div>
          <h2><UserPlus :size="20" /> Convidar participante</h2>
          <p>Envie um convite para participar da rede de cuidados.</p>
        </div>
      </div>

      <form @submit.prevent="enviarConvite">
        <div class="form-grid">
          <div>
            <label for="email">E-mail do convidado</label>
            <input
              id="email"
              v-model="email"
              type="email"
              placeholder="nome@email.com"
              required
            />
          </div>

          <div>
            <label for="papel">Tipo de participação</label>
            <select id="papel" v-model="papel">
              <option value="relative">Familiar</option>
              <option value="team">Profissional da equipe</option>
              <option value="elder">Paciente</option>
            </select>
          </div>
        </div>

        <button
          class="primary-button"
          type="submit"
          :disabled="enviando || !pacienteId"
        >
          <Mail :size="18" />
          {{ enviando ? 'Enviando...' : 'Enviar convite' }}
        </button>
      </form>

      <div v-if="conviteCriado" class="invitation-result">
        <strong>Link para aceitar o convite:</strong>
        <p>{{ conviteCriado.url }}</p>

        <button class="secondary-button" @click="copiarConvite">
          <Copy :size="16" />
          Copiar link
        </button>

        <small>
          Compartilhe esse link com a pessoa convidada. Ela deverá entrar
          com a conta correspondente ao e-mail informado.
        </small>
      </div>
    </section>

    <section class="panel">
      <div class="section-heading">
        <div>
          <h2><Users :size="20" /> Participantes da rede</h2>
          <p>{{ membrosAtivos.length }} participantes ativos</p>
        </div>

        <button class="secondary-button" @click="carregarRede">
          <RefreshCw :size="16" />
          Atualizar
        </button>
      </div>

      <div v-if="carregando" class="empty">
        Carregando participantes...
      </div>

      <div v-else-if="!membrosAtivos.length" class="empty">
        Nenhum participante vinculado a este paciente.
      </div>

      <div v-else class="member-list">
        <article
          v-for="membro in membrosAtivos"
          :key="membro.id"
          class="member-item"
        >
          <div class="member-avatar">
            <Users :size="21" />
          </div>

          <div class="member-info">
            <strong>{{ membro.name }}</strong>
            <span>{{ membro.email }}</span>
            <small>{{ nomePapel(membro.role) }}</small>
          </div>

          <ShieldCheck
            v-if="membro.role === 'manager'"
            class="manager-icon"
            :size="22"
          />

          <button
            v-else
            class="remove-button"
            title="Remover acesso"
            @click="removerMembro(membro)"
          >
            <Trash2 :size="18" />
          </button>
        </article>
      </div>
    </section>

    <section class="panel">
      <div class="section-heading">
        <div>
          <h2><Mail :size="20" /> Convites enviados</h2>
          <p>Acompanhe os convites da rede.</p>
        </div>
      </div>

      <div v-if="!convites.length" class="empty">
        Nenhum convite encontrado.
      </div>

      <div v-else class="invitation-list">
        <article
          v-for="convite in convites"
          :key="convite.id"
          class="invitation-item"
        >
          <div>
            <strong>{{ convite.email }}</strong>
            <span>{{ nomePapel(convite.role) }}</span>
            <small>Enviado em {{ formatarData(convite.created_at) }}</small>
          </div>

          <div class="invitation-actions">
            <span
              class="invitation-status"
              :class="statusConvite(convite).toLowerCase()"
            >
              {{ statusConvite(convite) }}
            </span>

            <button
              v-if="!convite.accepted_at && !convite.revoked_at"
              class="remove-button"
              title="Revogar convite"
              @click="revogarConvite(convite)"
            >
              <Trash2 :size="17" />
            </button>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>

<style scoped>
.rede-page {
  padding: 30px;
  max-width: 1150px;
  margin: 0 auto;
  color: #34423f;
}

.page-header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 28px;
}

.header-icon,
.member-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #e8f3ee;
  color: #528b72;
  border-radius: 14px;
  width: 54px;
  height: 54px;
}

.page-header h1 {
  margin: 0 0 6px;
  font-size: 26px;
}

.page-header p,
.section-heading p {
  margin: 0;
  color: #84908b;
  font-size: 14px;
}

.panel {
  background: white;
  border: 1px solid #edf0ed;
  border-radius: 15px;
  padding: 23px;
  margin-bottom: 20px;
  box-shadow: 0 4px 16px rgba(35, 65, 50, 0.04);
}

label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
}

input,
select {
  width: 100%;
  padding: 12px 13px;
  border: 1px solid #dfe6e1;
  border-radius: 9px;
  background: white;
  color: #34423f;
  font-size: 14px;
  outline: none;
}

input:focus,
select:focus {
  border-color: #73a68b;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 18px;
}

.section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
  margin-bottom: 20px;
}

.section-heading h2 {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 18px;
  margin: 0 0 6px;
}

.primary-button,
.secondary-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  border: none;
  border-radius: 9px;
  padding: 11px 16px;
  cursor: pointer;
  font-weight: 600;
}

.primary-button {
  background: #548d73;
  color: white;
}

.primary-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.secondary-button {
  background: #edf4ef;
  color: #4d8069;
}

.member-list,
.invitation-list {
  display: flex;
  flex-direction: column;
}

.member-item,
.invitation-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 15px 0;
  border-bottom: 1px solid #eff2ef;
}

.member-item:last-child,
.invitation-item:last-child {
  border-bottom: none;
}

.member-info,
.invitation-item > div:first-child {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
}

.member-info span,
.invitation-item span,
.member-info small,
.invitation-item small {
  color: #87918c;
  font-size: 13px;
}

.manager-icon {
  color: #548d73;
}

.remove-button {
  border: none;
  background: #fff0ef;
  color: #c45e59;
  padding: 9px;
  border-radius: 8px;
  cursor: pointer;
}

.empty {
  text-align: center;
  color: #929c96;
  padding: 25px 10px;
}

.message {
  padding: 13px 16px;
  border-radius: 9px;
  margin-bottom: 17px;
}

.error-message {
  color: #a43d3d;
  background: #fff0f0;
}

.success-message {
  color: #397456;
  background: #eaf5ed;
}

.invitation-result {
  margin-top: 20px;
  padding: 16px;
  border-radius: 10px;
  background: #f0f6f1;
  overflow-wrap: anywhere;
}

.invitation-result p {
  font-size: 13px;
  color: #548d73;
}

.invitation-result small {
  display: block;
  margin-top: 10px;
  color: #78857d;
}

.invitation-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.invitation-status {
  padding: 6px 10px;
  border-radius: 20px;
  background: #edf2ee;
  color: #62776a !important;
  white-space: nowrap;
}

.invitation-status.aceito {
  background: #e6f4e9;
  color: #397456 !important;
}

.invitation-status.revogado,
.invitation-status.expirado {
  background: #fff0ef;
  color: #b45a54 !important;
}

@media (max-width: 650px) {
  .rede-page {
    padding: 18px;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }

  .section-heading {
    align-items: flex-start;
  }

  .invitation-item {
    align-items: flex-start;
  }

  .invitation-actions {
    flex-direction: column;
    align-items: flex-end;
  }
}
</style>
