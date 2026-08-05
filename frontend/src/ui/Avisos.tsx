import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import type { Aviso } from '../api/client'

/**
 * A faixa de avisos (A2.V2 — docs/decisoes.md D-211).
 *
 * ## O que ela resolve
 *
 * Os sinais do jogo estavam espalhados por seis telas: o combate só no Quartel, o cerco só em
 * Minhas Zonas, a manutenção atrasada só ao abrir a zona, a vaga do Laboratório só na Pesquisa.
 * Quem não abrisse a tela certa não ficava sabendo — e o §1.1 promete um jogo que não exige login
 * constante, o que só é honesto se o que importa estiver à vista ao voltar.
 *
 * ## ⚠️ O que ela deliberadamente NÃO mostra
 *
 * "População no teto" dispara para **28 das 29 colônias** e "sem colonos livres" para 19. Medidos
 * antes de existirem, os dois foram cortados: um aviso que quase todos veem sempre deixa de ser
 * aviso e vira moldura — e ensina o jogador a ignorar a faixa inteira, levando junto os que
 * importam. Os dois já moram no painel de População, que é onde a informação pertence.
 *
 * ## Silêncio é um estado válido
 *
 * Sem avisos, a faixa **some**. Um "está tudo bem" permanente ocuparia espaço para não dizer nada,
 * e faria o jogador parar de olhar justamente para o lugar onde a má notícia vai aparecer.
 */

const CORES: Record<Aviso['severidade'], string> = {
  urgente: 'border-perigo bg-perigo/5 text-perigo',
  atencao: 'border-rust bg-rust/5 text-rust',
  oportunidade: 'border-ink-soft/30 bg-sand-light text-ink-soft',
}

/*
 * ⚠️ O símbolo antes do título SAIU (pedido do usuário, 2026-08-05). Era `▲`/`!`/`·` por severidade.
 *
 * A regra do deck que o justificava continua valendo — *estado nunca se anuncia só por cor*, porque
 * a paleta é quente por identidade e o vermelho fica a 14° do `rust` da marca. O que mudou é de onde
 * vem o segundo canal: **o título de cada aviso é uma frase inteira** ("Biomassa encheu e parou de
 * produzir", "Sua zona está sob cerco"), e o próprio `docs/design-tokens.md` diz que é assim que a
 * regra se cumpre — *"o componente `Erro` escreve a palavra 'Erro' pelo mesmo motivo"*. A palavra
 * carrega o aviso quando a cor falha; o glifo era um terceiro canal, não o segundo.
 */

export function Avisos({ aoAbrirResumo }: { aoAbrirResumo?: () => void }) {
  const [avisos, setAvisos] = useState<Aviso[]>([])

  const carregar = useCallback(async () => {
    try {
      setAvisos((await api.avisos()).avisos)
    } catch {
      // Uma falha aqui não pode derrubar a colônia: a faixa some, o jogo continua.
      setAvisos([])
    }
  }, [])

  useEffect(() => {
    void carregar()
    /*
     * O mundo anda no tick, de minuto em minuto, e um cerco começa sem o jogador clicar em nada.
     * Meio minuto é folgado o bastante para não martelar a API e apertado o bastante para o aviso
     * chegar antes do estrago — a mesma cadência de `MinhasZonas`.
     */
    const t = setInterval(() => void carregar(), 30_000)

    return () => clearInterval(t)
  }, [carregar])

  if (avisos.length === 0 && !aoAbrirResumo) return null

  return (
    <div className="mb-3 flex flex-col gap-1.5" data-secao="avisos">
      {avisos.map((a) => (
        <div
          key={a.codigo}
          data-aviso={a.codigo}
          data-severidade={a.severidade}
          className={`border-l-4 px-3 py-1.5 text-sm ${CORES[a.severidade]}`}
        >
          <strong>{a.titulo}</strong>
          <span className="text-ink-soft ml-2 text-xs">{a.detalhe}</span>
        </div>
      ))}

      {/*
        "Desde sua última visita" (A2.V2). Ele se abre sozinho no login e, até aqui, **não tinha
        como ser reaberto**: fechado por engano, a janela ia embora com ele — e o §5.1 desenhou o
        resumo justamente para quem volta depois de dias.
      */}
      {aoAbrirResumo && (
        <button
          type="button"
          onClick={aoAbrirResumo}
          data-reabrir-resumo
          className="text-ink-soft/70 hover:text-rust self-start text-xs underline"
        >
          Ver o que aconteceu desde sua última visita
        </button>
      )}
    </div>
  )
}
