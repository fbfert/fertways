import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import fs from 'node:fs'
import path from 'node:path'

/**
 * Serve `/media` no ambiente de desenvolvimento (docs/decisoes.md D-68).
 *
 * Em produção as imagens vêm de um **symlink** em `public_html/media` → `/home/fertways/media`, que é
 * onde elas moram: **fora do repositório e fora da árvore de deploy** (o `deploy.sh` aborta se achar
 * arquivo não rastreado na árvore, e 52 MB de PNG no git é para sempre). O Apache as serve direto.
 *
 * O Vite não sabe disso. Sem este plugin, `/media/...` seria um 404 no desenvolvimento: a arte não
 * apareceria, e o e2e passaria em **verde sobre uma colônia de hexágonos** — exatamente a classe de
 * falso-verde que o D-63 documentou. O servidor de desenvolvimento passa a servir a mesma pasta.
 *
 * ⚠️ **Não é um `publicDir`.** Pôr as imagens em `public/` faria o `vite build` copiar os 52 MB para
 * o `dist/`, e o deploy os despejaria por cima do symlink — duplicando tudo e quebrando a ideia.
 */
function servirMedia() {
  const raiz = process.env.VITE_MEDIA_DIR ?? '/home/fertways/media'

  return {
    name: 'fertways-media',
    configureServer(server: {
      middlewares: {
        use: (
          rota: string,
          h: (req: { url?: string }, res: any, next: () => void) => void,
        ) => void
      }
    }) {
      server.middlewares.use('/media', (req, res, next) => {
        // `decodeURIComponent` e depois `normalize`: um pedido a `/media/../../etc/passwd` tem de
        // morrer aqui, e não virar um `readFile` fora da pasta.
        const relativo = path.normalize(decodeURIComponent((req.url ?? '').split('?')[0]))
        const arquivo = path.join(raiz, relativo)

        if (!arquivo.startsWith(raiz + path.sep) || !fs.existsSync(arquivo)) {
          return next()
        }

        res.setHeader('Content-Type', 'image/png')
        fs.createReadStream(arquivo).pipe(res)
      })
    },
  }
}

export default defineConfig({
  plugins: [react(), tailwindcss(), servirMedia()],
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
