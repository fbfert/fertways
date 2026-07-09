import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Proxy em vez de CORS: o front chama /central no mesmo origin e o Vite repassa para o
    // `artisan serve`. Evita configurar CORS no backend só para o ambiente de desenvolvimento.
    //
    // O `rewrite` remove o /central porque em produção quem faz isso é o Apache, que serve o
    // backend por baixo desse caminho. O `artisan serve` roda na raiz e veria /central/login
    // como rota inexistente.
    proxy: {
      '/central': {
        target: process.env.VITE_API_URL ?? 'http://127.0.0.1:8199',
        changeOrigin: true,
        rewrite: (p) => p.replace(/^\/central/, ''),
      },
    },
  },
})
