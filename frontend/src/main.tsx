import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App'

/**
 * As telas têm URL própria desde o D-67: `/mapa`, `/capital`, `/zona/12`.
 *
 * Antes eram *popups* — overlays sobre o jogo, sem endereço. O botão Voltar do navegador saía do
 * jogo inteiro, recarregar a página largava o colono na colônia, e não havia como mandar um link a
 * alguém.
 *
 * ⚠️ Isto **exige** o `.htaccess` de `frontend/public/`: sem ele o Apache responde 404 a `/mapa`,
 * porque esse caminho não existe em disco. Conferido antes de escrever — dava 404 em produção.
 */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
)
