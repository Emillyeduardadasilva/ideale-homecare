import axios from 'axios'

export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1').replace(/\/+$/, '')

// O cookie CSRF do Sanctum fica na raiz do backend, fora de /api/v1.
const BACKEND_ORIGIN = API_BASE_URL.replace(/\/api\/v1$/, '')

const api = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json'
  }
})

export function prepararCsrf() {
  return axios.get(`${BACKEND_ORIGIN}/sanctum/csrf-cookie`, { withCredentials: true })
}

export default api
