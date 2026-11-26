
<script setup>
import { ref, computed, onMounted } from 'vue'
import { FileText, CalendarDays, Users, Activity, ClipboardList, Pill, Printer, Download, Search } from '@lucide/vue'
import api from '../services/api'

function hojeLocal() {
  const agora = new Date()
  return `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, '0')}-${String(agora.getDate()).padStart(2, '0')}`
}

const hoje = hojeLocal()
const primeiroDia = `${hoje.slice(0, 8)}01`
const dataInicio = ref(primeiroDia)
const dataFim = ref(hoje)
const tipoRelatorio = ref('geral')
const pacientes = ref([])
const registros = ref([])
const carregando = ref(false)
const erro = ref('')

const registrosNoPeriodo = computed(() => registros.value.filter(item => {
  if (item.tipo === 'Medicamento' && !item.ativo) return false
  if (!item.data) return true
  const dia = diaLocal(item.data)
  return (!dataInicio.value || dia >= dataInicio.value) && (!dataFim.value || dia <= dataFim.value)
}))
const atividades = computed(() => registrosNoPeriodo.value.filter(item => item.tipo === 'Tarefa'))
const evolucoes = computed(() => registrosNoPeriodo.value.filter(item => ['Evolução', 'Medição'].includes(item.tipo)))
const medicamentos = computed(() => registros.value.filter(item => item.tipo === 'Medicamento' && item.ativo))
const pacientesAtivos = computed(() => pacientes.value.filter(item => item.active))

const indicadores = computed(() => [
  { titulo: 'Pacientes acompanhados', descricao: 'Pacientes ativos nas redes acessíveis', valor: pacientesAtivos.value.length, icone: Users },
  { titulo: 'Atividades realizadas', descricao: 'Tarefas concluídas no período', valor: atividades.value.filter(item => ['completed', 'done'].includes(item.detalhe)).length, icone: ClipboardList },
  { titulo: 'Evoluções registradas', descricao: 'Diário e medições no período', valor: evolucoes.value.length, icone: Activity },
  { titulo: 'Medicamentos cadastrados', descricao: 'Medicamentos ativos registrados', valor: medicamentos.value.length, icone: Pill }
])

const linhasRelatorio = computed(() => {
  const linhas = tipoRelatorio.value === 'pacientes'
    ? pacientesAtivos.value.map(paciente => ({ id: `paciente-${paciente.id}`, data: '', paciente: paciente.name, tipo: 'Paciente', descricao: 'Acompanhamento ativo', detalhe: '' }))
    : tipoRelatorio.value === 'medicamentos'
      ? medicamentos.value
      : registrosNoPeriodo.value.filter(item => {
        if (tipoRelatorio.value === 'atividades') return item.tipo === 'Tarefa'
        if (tipoRelatorio.value === 'evolucao') return ['Evolução', 'Medição'].includes(item.tipo)
        return true
      })
  return linhas.sort((a, b) => String(b.data).localeCompare(String(a.data)) || a.paciente.localeCompare(b.paciente))
})

const tituloRelatorio = computed(() => ({
  geral: 'Resumo do acompanhamento', pacientes: 'Pacientes acompanhados',
  atividades: 'Atividades do período', evolucao: 'Evolução dos pacientes', medicamentos: 'Medicamentos cadastrados'
}[tipoRelatorio.value]))

function formatarData(data) {
  if (!data) return '—'
  const dia = diaLocal(data)
  const [ano, mes, numero] = dia.split('-')
  return `${numero}/${mes}/${ano}`
}

function diaLocal(valor) {
  const texto = String(valor)
  if (/^\d{4}-\d{2}-\d{2}$/.test(texto)) return texto
  const data = new Date(texto)
  if (Number.isNaN(data.getTime())) return texto.slice(0, 10)
  return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}-${String(data.getDate()).padStart(2, '0')}`
}

function dataDo(item, campos) {
  for (const campo of campos) if (item?.[campo]) return item[campo]
  return ''
}

async function paginaCompleta(url) {
  const resposta = await api.get(url, { params: { page: 1 } })
  const dados = resposta.data
  const itens = [...(dados.data || [])]
  for (let pagina = 2; pagina <= (dados.last_page || 1); pagina += 1) {
    const proxima = await api.get(url, { params: { page: pagina } })
    itens.push(...(proxima.data.data || []))
  }
  return itens
}

async function carregarRelatorios() {
  if (dataInicio.value && dataFim.value && dataInicio.value > dataFim.value) {
    erro.value = 'A data inicial precisa ser anterior à data final.'
    return
  }
  carregando.value = true
  erro.value = ''
  try {
    const listaPacientes = await paginaCompleta('/elders')
    pacientes.value = listaPacientes
    const ativos = listaPacientes.filter(paciente => paciente.active)
    const respostas = await Promise.all(ativos.map(async paciente => {
      const caminhos = ['tasks', 'medications', 'observations', 'measurements']
      const [tarefas, remedios, observacoes, medicoes] = await Promise.all(caminhos.map(caminho => paginaCompleta(`/elders/${paciente.id}/${caminho}`)))
      const fazerLinha = (item, tipo, camposData, descricao, detalhe) => ({
        id: `${tipo}-${paciente.id}-${item.id}`,
        data: dataDo(item, camposData),
        paciente: paciente.name,
        tipo,
        descricao: descricao(item),
        detalhe: detalhe(item),
        ativo: item.active !== false
      })
      return [
        ...tarefas.map(item => fazerLinha(item, 'Tarefa', ['due_at', 'created_at'], value => value.title || 'Tarefa', value => value.status || '')),
        ...remedios.map(item => fazerLinha(item, 'Medicamento', [], value => value.name || value.title || 'Medicamento', value => value.dosage || '')),
        ...observacoes.map(item => fazerLinha(item, 'Evolução', ['occurred_at'], value => value.text || 'Registro de diário', value => Array.isArray(value.tags) ? value.tags.join(', ') : '')),
        ...medicoes.map(item => fazerLinha(item, 'Medição', ['measured_at'], value => ({ blood_pressure: 'Pressão arterial', glucose: 'Glicemia', temperature: 'Temperatura' }[value.type] || 'Medição'), value => `${value.value}${value.secondary_value ? ` / ${value.secondary_value}` : ''} ${value.unit || ''}`.trim()))
      ]
    }))
    registros.value = respostas.flat()
  } catch (error) {
    erro.value = error.response?.data?.message || 'Não foi possível carregar os dados dos relatórios.'
  } finally {
    carregando.value = false
  }
}

function imprimirRelatorio() {
  window.print()
}

function exportarCsv() {
  const escapar = valor => {
    let texto = String(valor ?? '')
    if (/^[=+\-@\t\r]/.test(texto)) texto = `'${texto}`
    return `"${texto.replaceAll('"', '""')}"`
  }
  const linhas = [['Data', 'Paciente', 'Tipo', 'Descrição', 'Detalhe'], ...linhasRelatorio.value.map(item => [formatarData(item.data), item.paciente, item.tipo, item.descricao, item.detalhe])]
  const csv = '\uFEFF' + linhas.map(linha => linha.map(escapar).join(';')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = `relatorio-ideale-${hoje}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

function limparFiltros() {
  dataInicio.value = primeiroDia
  dataFim.value = hoje
  tipoRelatorio.value = 'geral'
}

onMounted(carregarRelatorios)
</script>

<template>
  <div class="relatorios-page">
    <header class="relatorios-header">
      <div>
        <h1>Relatórios</h1>
        <p>Acompanhe pacientes, tarefas, evoluções e medicamentos.</p>
      </div>
      <div class="acoes-relatorio">
        <button class="btn-imprimir" @click="exportarCsv" :disabled="carregando"><Download :size="18" /> Exportar CSV</button>
        <button class="btn-imprimir" @click="imprimirRelatorio"><Printer :size="18" /> Imprimir</button>
      </div>
    </header>

    <section class="filtros-card">
      <div class="filtros-titulo"><Search :size="20" /><h2>Filtros do relatório</h2></div>
      <div class="filtros-grid">
        <div class="campo"><label for="relatorio-inicio">Data inicial</label><input id="relatorio-inicio" v-model="dataInicio" type="date"></div>
        <div class="campo"><label for="relatorio-fim">Data final</label><input id="relatorio-fim" v-model="dataFim" type="date"></div>
        <div class="campo">
          <label for="relatorio-tipo">Tipo de relatório</label>
          <select id="relatorio-tipo" v-model="tipoRelatorio">
            <option value="geral">Relatório geral</option><option value="pacientes">Pacientes</option>
            <option value="atividades">Atividades</option><option value="evolucao">Evolução</option><option value="medicamentos">Medicamentos</option>
          </select>
        </div>
        <div class="acoes-filtro"><button class="btn-limpar" @click="limparFiltros">Limpar filtros</button></div>
      </div>
    </section>

    <p v-if="erro" class="erro-relatorio" role="alert">{{ erro }}</p>

    <section class="indicadores">
      <article v-for="item in indicadores" :key="item.titulo" class="indicador-card">
        <div class="indicador-icone"><component :is="item.icone" :size="23" /></div>
        <div><h3>{{ item.titulo }}</h3><p>{{ item.descricao }}</p><strong>{{ carregando ? 'Carregando…' : item.valor }}</strong></div>
      </article>
    </section>

    <section class="relatorio-card">
      <div class="secao-titulo">
        <div><h2><FileText :size="21" />{{ tituloRelatorio }}</h2><p>{{ formatarData(dataInicio) }} até {{ formatarData(dataFim) }}</p></div>
        <span class="status-relatorio">{{ carregando ? 'Atualizando' : `${linhasRelatorio.length} registros` }}</span>
      </div>
      <div v-if="carregando && !registros.length" class="tabela-vazia"><CalendarDays :size="30" /><p>Carregando dados do sistema…</p></div>
      <div v-else-if="!linhasRelatorio.length" class="tabela-vazia"><CalendarDays :size="30" /><p>Nenhum registro encontrado para os filtros selecionados.</p></div>
      <div v-else class="tabela-relatorio-wrap">
        <table class="tabela-relatorio">
          <thead><tr><th>Data</th><th>Paciente</th><th>Tipo</th><th>Descrição</th><th>Detalhe</th></tr></thead>
          <tbody>
            <tr v-for="item in linhasRelatorio" :key="item.id">
              <td>{{ formatarData(item.data) }}</td><td>{{ item.paciente }}</td><td>{{ item.tipo }}</td><td>{{ item.descricao }}</td><td>{{ item.detalhe || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="rodape-relatorio">{{ pacientesAtivos.length }} paciente(s) ativo(s) · dados obtidos da API de cuidado.</p>
    </section>
  </div>
</template>

<style scoped>
.relatorios-page {
  padding: 30px;
  max-width: 1500px;
  margin: 0 auto;
  color: #26364d;
}

.relatorios-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  margin-bottom: 28px;
}

.relatorios-header h1 {
  margin: 0 0 7px;
  font-size: 27px;
  color: #172b4d;
}

.relatorios-header p {
  margin: 0;
  color: #7b8798;
  font-size: 14px;
}

.btn-imprimir {
  display: flex;
  align-items: center;
  gap: 9px;
  background: #23466f;
  color: white;
  border: none;
  padding: 12px 17px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
}

.btn-imprimir:hover {
  background: #183657;
}

.filtros-card,
.indicador-card,
.relatorio-card {
  background: white;
  border: 1px solid #e7ebf1;
  border-radius: 12px;
  box-shadow: 0 3px 12px rgba(30, 50, 80, 0.04);
}

.filtros-card {
  padding: 23px;
  margin-bottom: 23px;
}

.filtros-titulo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
  color: #23466f;
}

.filtros-titulo h2 {
  margin: 0;
  font-size: 17px;
  color: #26364d;
}

.filtros-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(150px, 1fr));
  gap: 16px;
  align-items: end;
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 8px;
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
  border: 1px solid #dce3ed;
  border-radius: 7px;
  padding: 0 11px;
  background: white;
  color: #35445a;
  outline: none;
}

.campo input:focus,
.campo select:focus {
  border-color: #426b9f;
}

.acoes-filtro {
  display: flex;
}

.btn-limpar {
  height: 43px;
  width: 100%;
  border: 1px solid #dce3ed;
  background: #f7f9fc;
  color: #536176;
  border-radius: 7px;
  cursor: pointer;
}

.indicadores {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 17px;
  margin-bottom: 23px;
}

.indicador-card {
  padding: 20px;
  display: flex;
  align-items: flex-start;
  gap: 14px;
  min-width: 0;
}

.indicador-icone {
  width: 44px;
  height: 44px;
  min-width: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #edf3fa;
  color: #315b8a;
  border-radius: 10px;
}

.indicador-card h3 {
  margin: 0 0 6px;
  font-size: 14px;
  color: #26364d;
}

.indicador-card p {
  margin: 0 0 10px;
  font-size: 12px;
  line-height: 1.5;
  color: #8792a2;
}

.indicador-card strong {
  font-size: 12px;
  color: #8a6b32;
}

.relatorio-card {
  padding: 23px;
  margin-bottom: 23px;
}

.secao-titulo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
  margin-bottom: 22px;
}

.secao-titulo h2 {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 7px;
  font-size: 17px;
  color: #26364d;
}

.secao-titulo h2 svg {
  color: #315b8a;
}

.secao-titulo p {
  margin: 0;
  color: #8792a2;
  font-size: 13px;
}

.status-relatorio {
  background: #edf3fa;
  color: #315b8a;
  padding: 6px 11px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
}

.resumo-vazio {
  min-height: 220px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 25px;
  background: #fafbfd;
  border: 1px dashed #dce3ed;
  border-radius: 10px;
}

.resumo-icone {
  width: 65px;
  height: 65px;
  display: flex;
  justify-content: center;
  align-items: center;
  background: #edf3fa;
  color: #315b8a;
  border-radius: 50%;
  margin-bottom: 15px;
}

.resumo-vazio h3 {
  margin: 0 0 8px;
  color: #35445a;
  font-size: 16px;
}

.resumo-vazio p {
  max-width: 450px;
  margin: 0;
  color: #8792a2;
  font-size: 13px;
  line-height: 1.6;
}

.tabela-vazia {
  min-height: 130px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 10px;
  color: #a0aaba;
  background: #fafbfd;
  border-radius: 9px;
}

.tabela-vazia p {
  margin: 0;
  font-size: 13px;
}

.acoes-relatorio {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.acoes-relatorio button:disabled {
  opacity: 0.6;
  cursor: wait;
}

.erro-relatorio {
  margin: 0 0 20px;
  padding: 12px 14px;
  border-radius: 8px;
  background: #fff0f0;
  color: #a53636;
  font-size: 13px;
}

.tabela-relatorio-wrap {
  overflow-x: auto;
}

.tabela-relatorio {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.tabela-relatorio th,
.tabela-relatorio td {
  padding: 13px 12px;
  text-align: left;
  border-bottom: 1px solid #e8edf4;
  vertical-align: top;
}

.tabela-relatorio th {
  color: #66758e;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  background: #f8fafc;
}

.rodape-relatorio {
  margin-top: 16px;
  color: #8792a2;
  font-size: 12px;
}

@media (max-width: 1050px) {
  .indicadores {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .filtros-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 650px) {
  .relatorios-page {
    padding: 18px;
  }

  .relatorios-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .indicadores,
  .filtros-grid {
    grid-template-columns: 1fr;
  }

  .btn-imprimir {
    width: 100%;
    justify-content: center;
  }

  .acoes-relatorio {
    width: 100%;
  }

  .acoes-relatorio button {
    flex: 1;
    justify-content: center;
  }
}

@media print {
  .acoes-relatorio,
  .filtros-card {
    display: none !important;
  }

  .relatorios-page {
    padding: 0;
  }

  .relatorio-card,
  .indicador-card {
    box-shadow: none;
    break-inside: avoid;
  }
}
</style>
