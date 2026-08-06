import type { Spec } from '../api/client'
import { nomeRecurso } from './recursos'

/**
 * O estado da construção em palavras (A2.V3).
 *
 * ## Por que isto não vive só na cena
 *
 * O selo que a `ColonyScene` desenha é **pixel de canvas**: leitor de tela não o alcança, e a cena
 * inteira existe do jeito que existe justamente porque o alvo de clique é DOM e não canvas (ver o
 * docblock de `ColonyScene`). Um estado que só aparece pintado seria invisível para quem navega por
 * teclado ou leitor — o mesmo erro que o D-59 evitou nos cliques, repetido na informação.
 *
 * Por isso o texto mora aqui, e as duas pontas o consomem: o `aria-label` do botão de cada slot e o
 * painel de detalhe, que é onde cabe explicar **por quê**.
 */

/** Uma ou duas palavras, para entrar no nome acessível do botão sem alongá-lo demais. */
export function resumoDoEstado(spec: Spec): string | null {
  switch (spec.estado) {
    case 'melhorando':
      return 'melhorando'
    case 'travada':
      return 'produção travada'
    case 'sem_insumo':
      return 'parada por falta de insumo'
    default:
      // `erguendo` já é dito por "em obra", que o nome do botão sempre carregou.
      return null
  }
}

/**
 * A frase inteira, com o motivo — só o painel tem espaço para ela.
 *
 * ⚠️ O texto de `travada` afirma **perda de oportunidade, nunca de estoque**, porque é isso que o §14
 * promete: *"ao encher, a produção para; nada transborda e vira desperdício"*. Dizer "está
 * transbordando" ou "está desperdiçando recurso" seria descrever um jogo diferente do que roda.
 */
export function explicacaoDoEstado(spec: Spec): string | null {
  const cheios = (spec.recursos_no_teto ?? []).map(nomeRecurso)
  const faltando = (spec.insumos_em_falta ?? []).map(nomeRecurso)

  /*
   * A boca fechada vem primeiro, como no servidor. E a frase evita a palavra "escassez": o que
   * falta quase sempre é **energia**, e energia não está "em falta" no sentido de estoque — a
   * colônia gera e gasta tudo por hora. Dizer "sem energia guardada" é o que descreve o mundo.
   */
  if (spec.estado === 'sem_insumo') {
    const soEnergia = faltando.length === 1 && spec.insumos_em_falta?.[0] === 'energia'

    return soEnergia
      ? 'Parada: não sobra energia guardada para um lote. Toda construção consome energia por hora, e a receita precisa de um excedente — suba o Reator de Energia ou desligue consumo.'
      : `Parada por falta de ${listar(faltando)}. Ela está de pé e não converte nada até o insumo chegar.`
  }

  if (spec.estado === 'travada') {
    return `Produção travada: ${listar(cheios)} ${
      cheios.length === 1 ? 'está' : 'estão'
    } no teto do depósito. Ela continua de pé e não rende nada — o que produziria agora é oportunidade perdida, não estoque perdido.`
  }

  /*
   * Melhorando E com algo no teto ao mesmo tempo: ela continua produzindo no nível atual enquanto
   * sobe, então os dois fatos são verdade juntos. O servidor manda a lista mesmo quando o estado
   * principal é outro, e seria uma pena calá-la aqui.
   */
  if (spec.estado === 'melhorando' && cheios.length > 0) {
    return `Subindo de nível. Enquanto isso ela produz no nível atual — mas ${listar(cheios)} ${
      cheios.length === 1 ? 'está' : 'estão'
    } no teto do depósito, então esse rendimento está parado.`
  }

  if (spec.estado === 'melhorando') {
    return 'Subindo de nível. Ela continua produzindo no nível atual enquanto a obra corre.'
  }

  // `produzindo` não vira frase: o normal não precisa se anunciar, e um aviso para cada construção
  // saudável faria o painel gritar o tempo todo — e o que grita sempre não é ouvido nunca.
  return null
}

function listar(nomes: string[]): string {
  if (nomes.length <= 1) return nomes[0] ?? ''
  return `${nomes.slice(0, -1).join(', ')} e ${nomes[nomes.length - 1]}`
}
