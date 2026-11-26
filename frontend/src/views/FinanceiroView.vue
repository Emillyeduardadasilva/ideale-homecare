
<script setup>
import { ref, computed, onMounted } from 'vue'
import { Wallet, TrendingUp, TrendingDown, Plus, Search, Trash2, DollarSign, CalendarDays, X, Receipt } from '@lucide/vue'
import api from '../services/api'

const mostrarFormulario = ref(false)
const busca = ref('')
const filtroTipo = ref('todos')
const descricao = ref('')
const valor = ref('')
const tipo = ref('expense')
const categoria = ref('Outros')
const vencimento = ref('')
const situacao = ref('pending')
const movimentacoes = ref([])
const carregando = ref(false)
const salvando = ref(false)
const erro = ref('')
const processandoId = ref(null)

const receitas = computed(() => movimentacoes.value.filter(item => item.type === 'income' && item.status === 'paid').reduce((total, item) => total + Number(item.amount), 0))
const despesas = computed(() => movimentacoes.value.filter(item => item.type === 'expense' && item.status === 'paid').reduce((total, item) => total + Number(item.amount), 0))
const saldo = computed(() => receitas.value - despesas.value)
const pendentes = computed(() => movimentacoes.value.filter(item => item.status === 'pending').reduce((total, item) => total + Number(item.amount), 0))
const movimentacoesFiltradas = computed(() => movimentacoes.value.filter(item => item.description.toLowerCase().includes(busca.value.toLowerCase()) && (filtroTipo.value === 'todos' || item.type === filtroTipo.value)))

function formatarMoeda(numero) {
  return Number(numero).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatarData(data) {
  if (!data) return '-'
  return new Date(`${String(data).slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR')
}

function mensagemErro(error, padrao) {
  return error.response?.data?.message || padrao
}

async function carregarMovimentacoes() {
  carregando.value = true
  erro.value = ''
  try {
    const primeira = await api.get('/finance/entries', { params: { page: 1 } })
    const itens = [...(primeira.data.data || [])]
    for (let pagina = 2; pagina <= (primeira.data.last_page || 1); pagina += 1) {
      const resposta = await api.get('/finance/entries', { params: { page: pagina } })
      itens.push(...(resposta.data.data || []))
    }
    movimentacoes.value = itens
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível carregar as movimentações.')
  } finally {
    carregando.value = false
  }
}

async function salvarMovimentacao() {
  if (!descricao.value.trim() || !valor.value || Number(valor.value) <= 0) return
  salvando.value = true
  erro.value = ''
  try {
    const resposta = await api.post('/finance/entries', {
      description: descricao.value.trim(), amount: Number(valor.value), type: tipo.value,
      category: categoria.value, due_date: vencimento.value || null, status: situacao.value
    })
    movimentacoes.value.unshift(resposta.data.data)
    limparFormulario()
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível salvar a movimentação.')
  } finally {
    salvando.value = false
  }
}

function limparFormulario() {
  descricao.value = ''
  valor.value = ''
  tipo.value = 'expense'
  categoria.value = 'Outros'
  vencimento.value = ''
  situacao.value = 'pending'
  mostrarFormulario.value = false
}

async function alterarSituacao(item) {
  processandoId.value = item.id
  erro.value = ''
  try {
    const status = item.status === 'paid' ? 'pending' : 'paid'
    const resposta = await api.put(`/finance/entries/${item.id}`, { status })
    Object.assign(item, resposta.data.data)
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível atualizar a situação.')
  } finally {
    processandoId.value = null
  }
}

async function excluirMovimentacao(id) {
  if (!confirm('Deseja realmente excluir esta movimentação?')) return
  processandoId.value = id
  erro.value = ''
  try {
    await api.delete(`/finance/entries/${id}`)
    movimentacoes.value = movimentacoes.value.filter(item => item.id !== id)
  } catch (error) {
    erro.value = mensagemErro(error, 'Não foi possível excluir a movimentação.')
  } finally {
    processandoId.value = null
  }
}

onMounted(carregarMovimentacoes)
</script>

<template>
  <div class="financeiro-page">

    <header class="financeiro-header">
      <div>
        <h1>Financeiro</h1>
        <p>Controle financeiro do Ideale Home Care.</p>
      </div>

      <button class="btn-nova" @click="mostrarFormulario = true">
        <Plus :size="19" />
        Nova movimentação
      </button>
    </header>

    <p v-if="erro" class="erro-financeiro" role="alert">{{ erro }}</p>

    <section class="cards-financeiros">

      <article class="finance-card">
        <div class="finance-icon azul">
          <Wallet :size="23" />
        </div>
        <div>
          <span>Saldo disponível</span>
          <h2>{{ formatarMoeda(saldo) }}</h2>
        </div>
      </article>

      <article class="finance-card">
        <div class="finance-icon verde">
          <TrendingUp :size="23" />
        </div>
        <div>
          <span>Receitas recebidas</span>
          <h2>{{ formatarMoeda(receitas) }}</h2>
        </div>
      </article>

      <article class="finance-card">
        <div class="finance-icon vermelho">
          <TrendingDown :size="23" />
        </div>
        <div>
          <span>Despesas pagas</span>
          <h2>{{ formatarMoeda(despesas) }}</h2>
        </div>
      </article>

      <article class="finance-card">
        <div class="finance-icon amarelo">
          <CalendarDays :size="23" />
        </div>
        <div>
          <span>Valores pendentes</span>
          <h2>{{ formatarMoeda(pendentes) }}</h2>
        </div>
      </article>

    </section>

    <section class="financeiro-conteudo">

      <div class="conteudo-header">
        <div>
          <h2>
            <Receipt :size="21" />
            Movimentações financeiras
          </h2>
          <p>Histórico de receitas e despesas.</p>
        </div>
      </div>

      <div class="filtros-financeiros">
        <div class="busca-financeira">
          <Search :size="19" />
          <input
            v-model="busca"
            type="text"
            placeholder="Buscar movimentação..."
          />
        </div>

        <select v-model="filtroTipo">
          <option value="todos">Todas as movimentações</option>
          <option value="income">Somente receitas</option>
          <option value="expense">Somente despesas</option>
        </select>
      </div>

      <div class="tabela-container">
        <table>
          <thead>
            <tr>
              <th>Descrição</th>
              <th>Categoria</th>
              <th>Vencimento</th>
              <th>Tipo</th>
              <th>Valor</th>
              <th>Situação</th>
              <th>Ações</th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="item in movimentacoesFiltradas"
              :key="item.id"
            >
              <td class="descricao-celula">
                {{ item.description }}
              </td>

              <td>{{ item.category }}</td>

              <td>{{ formatarData(item.due_date) }}</td>

              <td>
                <span :class="['tipo-tag', item.type === 'income' ? 'receita' : 'despesa']">
                  {{ item.type === 'income' ? 'Receita' : 'Despesa' }}
                </span>
              </td>

              <td class="valor-celula">
                {{ formatarMoeda(item.amount) }}
              </td>

              <td>
                <button
                  :class="['situacao-tag', item.status === 'paid' ? 'pago' : 'pendente']"
                  @click="alterarSituacao(item)"
                  :disabled="processandoId === item.id"
                  :title="'Clique para alterar a situação'"
                >
                  {{ item.status === 'paid' ? 'Pago' : 'Pendente' }}
                </button>
              </td>

              <td>
                <button
                  class="btn-excluir"
                  @click="excluirMovimentacao(item.id)"
                  :disabled="processandoId === item.id"
                  title="Excluir movimentação"
                >
                  <Trash2 :size="17" />
                </button>
              </td>
            </tr>

            <tr v-if="carregando || movimentacoesFiltradas.length === 0">
              <td colspan="7">
                <div class="sem-registros">
                  <Wallet :size="35" />
                  <strong>Nenhuma movimentação encontrada</strong>
                  <span>Cadastre uma receita ou despesa para começar.</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </section>

    <div
      v-if="mostrarFormulario"
      class="modal-overlay"
      @click.self="limparFormulario"
    >
      <div class="modal-financeiro">

        <div class="modal-header">
          <div>
            <h2>Nova movimentação</h2>
            <p>Informe os dados financeiros.</p>
          </div>

          <button class="btn-fechar" @click="limparFormulario">
            <X :size="21" />
          </button>
        </div>

        <form @submit.prevent="salvarMovimentacao">

          <div class="campo">
            <label>Descrição</label>
            <input
              v-model="descricao"
              type="text"
              placeholder="Ex: Pagamento de cuidador"
              required
            />
          </div>

          <div class="campo">
            <label>Valor (R$)</label>
            <input
              v-model="valor"
              type="number"
              min="0.01"
              step="0.01"
              placeholder="0,00"
              required
            />
          </div>

          <div class="campo">
            <label>Tipo de movimentação</label>
            <select v-model="tipo">
              <option value="expense">Despesa</option>
              <option value="income">Receita</option>
            </select>
          </div>

          <div class="campo">
            <label>Categoria</label>
            <select v-model="categoria">
              <option>Outros</option>
              <option>Pagamento de cuidador</option>
              <option>Medicamentos</option>
              <option>Materiais e equipamentos</option>
              <option>Atendimento domiciliar</option>
              <option>Transporte</option>
              <option>Alimentação</option>
              <option>Serviços</option>
            </select>
          </div>

          <div class="campo">
            <label>Data de vencimento</label>
            <input v-model="vencimento" type="date" />
          </div>

          <div class="campo">
            <label>Situação</label>
            <select v-model="situacao">
              <option value="pending">Pendente</option>
              <option value="paid">Pago</option>
            </select>
          </div>

          <div class="modal-acoes">
            <button
              type="button"
              class="btn-cancelar"
              @click="limparFormulario"
            >
              Cancelar
            </button>

            <button type="submit" class="btn-salvar" :disabled="salvando">
              <DollarSign :size="18" />
              Salvar movimentação
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</template>

<style scoped>
.financeiro-page {
  max-width: 1500px;
  margin: auto;
  padding: 30px;
  color: #26364d;
}

.erro-financeiro {
  margin: 0 0 18px;
  padding: 12px 14px;
  border-radius: 8px;
  background: #fff0f0;
  color: #a53636;
  font-size: 13px;
}

.financeiro-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 15px;
  margin-bottom: 28px;
}

.financeiro-header h1 {
  margin: 0 0 7px;
  color: #172b4d;
  font-size: 27px;
}

.financeiro-header p {
  margin: 0;
  color: #8792a2;
  font-size: 14px;
}

.btn-nova,
.btn-salvar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 17px;
  background: #23466f;
  color: white;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}

.btn-nova:hover,
.btn-salvar:hover {
  background: #183657;
}

.cards-financeiros {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 17px;
  margin-bottom: 25px;
}

.finance-card {
  background: white;
  border: 1px solid #e7ebf1;
  border-radius: 12px;
  padding: 21px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 3px 12px rgba(30, 50, 80, .04);
  min-width: 0;
}

.finance-icon {
  width: 47px;
  height: 47px;
  min-width: 47px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
}

.azul {
  background: #edf3fa;
  color: #315b8a;
}

.verde {
  background: #e8f7ee;
  color: #24945a;
}

.vermelho {
  background: #fff0ef;
  color: #d45b55;
}

.amarelo {
  background: #fff6e4;
  color: #bd8b2e;
}

.finance-card span {
  color: #8792a2;
  font-size: 12px;
}

.finance-card h2 {
  margin: 7px 0 0;
  font-size: 19px;
  color: #26364d;
  overflow-wrap: anywhere;
}

.financeiro-conteudo {
  background: white;
  border: 1px solid #e7ebf1;
  border-radius: 12px;
  padding: 23px;
}

.conteudo-header h2 {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 7px;
  color: #26364d;
  font-size: 18px;
}

.conteudo-header h2 svg {
  color: #315b8a;
}

.conteudo-header p {
  margin: 0;
  color: #8792a2;
  font-size: 13px;
}

.filtros-financeiros {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  margin: 23px 0;
}

.busca-financeira {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  max-width: 400px;
  padding: 0 12px;
  border: 1px solid #dce3ed;
  border-radius: 8px;
  color: #8792a2;
}

.busca-financeira input {
  height: 42px;
  width: 100%;
  border: none;
  outline: none;
  color: #35445a;
}

.filtros-financeiros select {
  min-width: 210px;
  padding: 0 12px;
  border: 1px solid #dce3ed;
  border-radius: 8px;
  color: #536176;
  background: white;
}

.tabela-container {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 850px;
}

th {
  background: #f7f9fc;
  color: #718096;
  text-align: left;
  padding: 14px 12px;
  font-size: 12px;
  font-weight: 600;
}

td {
  padding: 15px 12px;
  border-bottom: 1px solid #edf0f4;
  font-size: 13px;
  color: #536176;
}

.descricao-celula,
.valor-celula {
  color: #26364d;
  font-weight: 600;
}

.tipo-tag,
.situacao-tag {
  display: inline-block;
  border: none;
  border-radius: 20px;
  padding: 6px 10px;
  font-size: 11px;
  font-weight: 600;
}

.tipo-tag.receita {
  background: #e8f7ee;
  color: #24945a;
}

.tipo-tag.despesa {
  background: #fff0ef;
  color: #d45b55;
}

.situacao-tag {
  cursor: pointer;
}

.situacao-tag.pago {
  background: #e8f7ee;
  color: #24945a;
}

.situacao-tag.pendente {
  background: #fff6e4;
  color: #bd8b2e;
}

.btn-excluir {
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  color: #d45b55;
  cursor: pointer;
  padding: 6px;
}

.sem-registros {
  min-height: 190px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #a0aaba;
}

.sem-registros strong {
  color: #536176;
  font-size: 14px;
}

.sem-registros span {
  font-size: 12px;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(15, 30, 50, .55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.modal-financeiro {
  background: white;
  width: 100%;
  max-width: 540px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 25px;
  border-radius: 13px;
  box-shadow: 0 15px 45px rgba(0, 0, 0, .15);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 23px;
}

.modal-header h2 {
  margin: 0 0 6px;
  color: #172b4d;
  font-size: 20px;
}

.modal-header p {
  margin: 0;
  color: #8792a2;
  font-size: 13px;
}

.btn-fechar {
  border: none;
  background: #f1f4f8;
  color: #536176;
  border-radius: 7px;
  padding: 7px;
  cursor: pointer;
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 7px;
  margin-bottom: 16px;
}

.campo label {
  font-size: 13px;
  font-weight: 600;
  color: #536176;
}

.campo input,
.campo select {
  width: 100%;
  height: 43px;
  padding: 0 11px;
  border: 1px solid #dce3ed;
  border-radius: 7px;
  outline: none;
  color: #35445a;
  background: white;
}

.campo input:focus,
.campo select:focus {
  border-color: #426b9f;
}

.modal-acoes {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 23px;
}

.btn-cancelar {
  padding: 11px 17px;
  border: 1px solid #dce3ed;
  background: white;
  color: #536176;
  border-radius: 8px;
  cursor: pointer;
}

@media (max-width: 1100px) {
  .cards-financeiros {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 650px) {
  .financeiro-page {
    padding: 18px;
  }

  .financeiro-header {
    align-items: stretch;
    flex-direction: column;
  }

  .btn-nova {
    justify-content: center;
  }

  .cards-financeiros {
    grid-template-columns: 1fr;
  }

  .filtros-financeiros {
    flex-direction: column;
  }

  .busca-financeira {
    max-width: none;
  }

  .filtros-financeiros select {
    height: 43px;
  }

  .modal-acoes {
    flex-direction: column-reverse;
  }

  .modal-acoes button {
    width: 100%;
  }
}
</style>
