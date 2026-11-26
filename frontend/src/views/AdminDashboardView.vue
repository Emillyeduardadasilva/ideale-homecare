<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'

const resumo = ref(null)
const usuarios = ref([])
const idosos = ref([])
const carregando = ref(false)
const erro = ref('')
const busca = ref('')
const atualizandoId = ref(null)
const adminId = ref(null)

const contasFiltradas = computed(() => {
  const termo = busca.value.trim().toLocaleLowerCase('pt-BR')
  if (!termo) return usuarios.value
  return usuarios.value.filter((u) => `${u.name} ${u.email} ${u.role}`.toLocaleLowerCase('pt-BR').includes(termo))
})

const indicadores = computed(() => {
  if (!resumo.value) return []
  return [
    ['Contas', resumo.value.accounts],
    ['Familiares', resumo.value.families],
    ['Equipe de cuidado', resumo.value.care_staff],
    ['Idosos ativos', resumo.value.active_elders],
    ['Tarefas', resumo.value.tasks],
    ['Medicamentos', resumo.value.medications],
    ['Registros de evolução', resumo.value.observations],
    ['Medições', resumo.value.measurements],
    ['Visitas', resumo.value.visits],
    ['Lançamentos financeiros', resumo.value.finance_entries]
  ]
})

function papel(role) {
  return ({
    family: 'Familiar', elder: 'Idoso', ideale_admin: 'Equipe administrativa',
    ideale_professional: 'Profissional', system_admin: 'Administrador do sistema'
  })[role] || role
}

async function carregar() {
  carregando.value = true
  erro.value = ''
  try {
    const [overview, users, elders, me] = await Promise.all([
      api.get('/admin/overview'), api.get('/admin/users'), api.get('/admin/elders'), api.get('/auth/me')
    ])
    resumo.value = overview.data.data
    usuarios.value = users.data.data || []
    idosos.value = elders.data.data || []
    const current = me.data?.data || me.data?.user || me.data
    adminId.value = current?.id ?? null
  } catch (e) {
    erro.value = e.response?.data?.message || 'Não foi possível carregar a visão administrativa.'
  } finally { carregando.value = false }
}

async function alternarConta(usuario) {
  atualizandoId.value = usuario.id
  erro.value = ''
  try {
    const response = await api.patch(`/admin/users/${usuario.id}/active`, { active: !usuario.active })
    usuario.active = response.data.data.active
    resumo.value.active_accounts += usuario.active ? 1 : -1
  } catch (e) {
    erro.value = e.response?.data?.message || 'Não foi possível alterar o status desta conta.'
  } finally { atualizandoId.value = null }
}

onMounted(carregar)
</script>

<template>
  <main class="admin-page">
    <header class="admin-header">
      <div><span class="eyebrow">ADMINISTRAÇÃO GLOBAL</span><h1>Visão administrativa</h1><p>Consulta geral do sistema e gestão de acesso às contas.</p></div>
      <button type="button" :disabled="carregando" @click="carregar">{{ carregando ? 'Atualizando...' : 'Atualizar dados' }}</button>
    </header>
    <p v-if="erro" class="error" role="alert">{{ erro }}</p>
    <section class="metrics" aria-label="Indicadores gerais">
      <article v-for="item in indicadores" :key="item[0]" class="metric"><span>{{ item[0] }}</span><strong>{{ item[1] ?? '—' }}</strong></article>
    </section>
    <section class="panel">
      <div class="panel-heading"><div><h2>Contas do sistema</h2><p>Ative ou suspenda o acesso. A alteração fica registrada na auditoria.</p></div><input v-model="busca" type="search" placeholder="Buscar nome, e-mail ou perfil"></div>
      <div class="table-scroll"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>E-mail confirmado</th><th>Acesso</th></tr></thead><tbody>
        <tr v-for="usuario in contasFiltradas" :key="usuario.id"><td>{{ usuario.name }}</td><td>{{ usuario.email }}</td><td>{{ papel(usuario.role) }}</td><td>{{ usuario.active ? 'Ativa' : 'Suspensa' }}</td><td>{{ usuario.email_verified_at ? 'Sim' : 'Não' }}</td><td><button class="status-button" :disabled="atualizandoId === usuario.id || usuario.id === adminId" @click="alternarConta(usuario)">{{ usuario.id === adminId ? 'Sua conta' : atualizandoId === usuario.id ? 'Salvando...' : usuario.active ? 'Suspender' : 'Reativar' }}</button></td></tr>
        <tr v-if="!contasFiltradas.length"><td colspan="6" class="empty">Nenhuma conta encontrada.</td></tr>
      </tbody></table></div>
    </section>
    <section class="panel">
      <div class="panel-heading"><div><h2>Idosos cadastrados</h2><p>Visão global; detalhes de cuidado são consultivos para este perfil.</p></div></div>
      <div class="table-scroll"><table><thead><tr><th>Nome</th><th>Nascimento</th><th>Status</th><th>Vínculos ativos</th></tr></thead><tbody>
        <tr v-for="idoso in idosos" :key="idoso.id"><td>{{ idoso.name }}</td><td>{{ idoso.birth_date || '—' }}</td><td>{{ idoso.active ? 'Ativo' : 'Inativo' }}</td><td>{{ idoso.active_memberships_count ?? '—' }}</td></tr>
        <tr v-if="!idosos.length"><td colspan="4" class="empty">Nenhum idoso cadastrado.</td></tr>
      </tbody></table></div>
    </section>
  </main>
</template>

<style scoped>
.admin-page{max-width:1440px;margin:0 auto;padding:28px;color:#263238}.admin-header,.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.admin-header{margin-bottom:24px}.eyebrow{color:#a64d70;font-size:12px;font-weight:700;letter-spacing:.08em}h1{margin:6px 0;color:#29343a}h2{margin:0 0 6px;font-size:19px}.admin-header p,.panel-heading p{margin:0;color:#68747a}.admin-header button,.status-button{border:0;border-radius:8px;background:#a64d70;color:#fff;padding:10px 14px;cursor:pointer}.admin-header button:disabled,.status-button:disabled{opacity:.6}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:22px}.metric,.panel{background:#fff;border:1px solid #ece8ea;border-radius:12px;box-shadow:0 3px 12px #00000008}.metric{padding:16px}.metric span{display:block;color:#68747a;font-size:13px}.metric strong{display:block;margin-top:8px;font-size:25px;color:#a64d70}.panel{margin:18px 0;padding:20px}.panel-heading{margin-bottom:14px}.panel-heading input{max-width:280px;width:100%;padding:10px;border:1px solid #ddd;border-radius:8px}.table-scroll{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:11px;border-bottom:1px solid #eee;font-size:14px}th{color:#58656a;font-weight:600}.status-button{padding:7px 10px;font-size:12px}.empty{text-align:center;color:#777}.error{padding:12px;background:#fff0f0;color:#a12626;border-radius:8px}@media(max-width:720px){.admin-page{padding:16px}.admin-header,.panel-heading{align-items:stretch;flex-direction:column}.panel-heading input{max-width:none}th,td{white-space:nowrap}}
</style>
