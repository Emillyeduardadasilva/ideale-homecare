// Copia o build do frontend para dentro do backend Laravel, para publicar tudo no mesmo endereço.
// public/ recebe os arquivos estáticos; resources/spa/index.html é entregue pela rota de fallback.
import { cpSync, existsSync, mkdirSync, rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(raiz, 'dist')
const backend = join(raiz, '..', 'backend')
const publico = join(backend, 'public')
const spa = join(backend, 'resources', 'spa')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Build não encontrado em frontend/dist. Rode "npm run build:hostgator".')
  process.exit(1)
}

rmSync(join(publico, 'assets'), { recursive: true, force: true })
cpSync(join(dist, 'assets'), join(publico, 'assets'), { recursive: true })
for (const arquivo of ['favicon.svg', 'sw.js']) {
  if (existsSync(join(dist, arquivo))) cpSync(join(dist, arquivo), join(publico, arquivo))
}
mkdirSync(spa, { recursive: true })
cpSync(join(dist, 'index.html'), join(spa, 'index.html'))

console.log('Frontend copiado para backend/public e backend/resources/spa.')
