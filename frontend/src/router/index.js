
import { createRouter, createWebHistory } from 'vue-router'
import api from '../services/api'

import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import AcceptInvitationView from '../views/AcceptInvitationView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'
import CaregiverDashboardView from '../views/CaregiverDashboardView.vue'
import PacientesView from '../views/PacientesView.vue'
import AgendaView from '../views/AgendaView.vue'
import MedicamentosView from '../views/MedicamentosView.vue'
import TarefasView from '../views/TarefasView.vue'
import EvolucaoView from '../views/EvolucaoView.vue'
import RedeCuidadoresView from '../views/RedeCuidadoresView.vue'
import NotificacoesView from '../views/NotificacoesView.vue'
import RelatoriosView from '../views/RelatoriosView.vue'
import FinanceiroView from '../views/FinanceiroView.vue'
import PerfilView from '../views/PerfilView.vue'

const routes = [
  { path: '/login', name: 'login', component: LoginView },
  { path: '/cadastro', name: 'register', component: RegisterView },
  { path: '/aceitar-convite', name: 'accept-invitation', component: AcceptInvitationView },
  { path: '/recuperar-senha', name: 'forgot-password', component: ForgotPasswordView },
  { path: '/reset-password', name: 'reset-password', component: ResetPasswordView },
  { path: '/admin', name: 'admin', component: AdminDashboardView },
  { path: '/cuidador', name: 'caregiver-dashboard', component: CaregiverDashboardView },
  { path: '/', name: 'dashboard', component: DashboardView },
  { path: '/pacientes', name: 'pacientes', component: PacientesView },
  { path: '/agenda', name: 'agenda', component: AgendaView },
  { path: '/medicamentos', name: 'medicamentos', component: MedicamentosView },
  { path: '/tarefas', name: 'tarefas', component: TarefasView },
  { path: '/evolucao', name: 'evolucao', component: EvolucaoView },
  { path: '/rede-cuidadores', name: 'rede-cuidadores', component: RedeCuidadoresView },
  { path: '/notificacoes', name: 'notificacoes', component: NotificacoesView },
  { path: '/relatorios', name: 'relatorios', component: RelatoriosView },
  { path: '/financeiro', name: 'financeiro', component: FinanceiroView },
  { path: '/perfil', name: 'perfil', component: PerfilView },
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to) => {
  try {
    const response = await api.get('/auth/me')
    const user = response.data?.data || response.data?.user || response.data

    if (to.name === 'login' || to.name === 'register') {
      return { name: 'dashboard' }
    }
    if (user?.role === 'system_admin' && to.name === 'dashboard') {
      return { name: 'admin' }
    }
    if (user?.role === 'system_admin' && to.name === 'caregiver-dashboard') {
      return { name: 'admin' }
    }
    if (user?.role !== 'system_admin' && to.name === 'admin') {
      return { name: 'dashboard' }
    }
    if (['ideale_admin', 'ideale_professional'].includes(user?.role) && to.name === 'dashboard') {
      return { name: 'caregiver-dashboard' }
    }
    if (!['ideale_admin', 'ideale_professional'].includes(user?.role) && to.name === 'caregiver-dashboard') {
      return { name: user?.role === 'system_admin' ? 'admin' : 'dashboard' }
    }
    if (user?.role === 'system_admin' && ['rede-cuidadores', 'financeiro'].includes(to.name)) {
      return { name: 'admin' }
    }
    if (['ideale_admin', 'ideale_professional'].includes(user?.role) && ['rede-cuidadores', 'financeiro'].includes(to.name)) {
      return { name: 'dashboard' }
    }
  } catch (error) {
    if ([401, 419].includes(error?.response?.status)
      && !['login', 'register', 'accept-invitation', 'forgot-password', 'reset-password'].includes(to.name)) {
      return { name: 'login' }
    }
  }
})

export default router
