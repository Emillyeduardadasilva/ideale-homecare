
import axios from 'axios'

const baseURL = import.meta.env.VITE_API_BASE_URL || (import.meta.env.PROD ? '/api/v1' : 'http://localhost:8000/api/v1')

const api = axios.create({
  baseURL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json'
  }
})

// A sessão usa a mesma origem da API em desenvolvimento e na hospedagem.
export function initializeCsrf() {
  const apiUrl = new URL(baseURL, window.location.origin)
  return api.get(new URL('/sanctum/csrf-cookie', apiUrl).href)
}

export default api
