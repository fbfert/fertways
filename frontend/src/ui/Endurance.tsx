/**
 * Os destroços da Endurance of Mankind — a área Oeste da Capital (D-63).
 *
 * **Ela conta a verdade**, que é o padrão que o D-55 fixou com o Gagarin: mostra o que o GDD publica
 * e **admite o que ainda não existe**. Um painel que prometesse missões faria o jogador esperar por
 * algo que ninguém construiu.
 *
 * ---
 *
 * **A contradição que este painel resolve.** O §3 (v3.0) diz que o telescópio Gagarin *"repousa em
 * seu casco"*. A versão sanitizada diz o **contrário**: *"O Gagarin **não** repousa sobre seu casco:
 * é um satélite orbital lançado após o pouso"*. A tabela de precedência da seção 0 já decidia — *"É
 * satélite orbital do Governo; a Endurance permanece em solo"* — e é essa a versão que o jogador lê
 * aqui. Nenhuma decisão nova: é o D-47 aplicado, e o GDD v36 já nasce com a versão certa.
 */
export function Endurance() {
  return (
    <div className="mt-5 space-y-5" data-tela="endurance">
      <blockquote className="border-rust text-ink-soft border-l-4 pl-4 text-sm italic">
        “Ela não era bonita. Era enorme, funcional e improvisada — construída com peças de sete nações
        diferentes em menos de quatro anos. Mas ela voou. E chegou.”
      </blockquote>

      <section className="border-rust/20 bg-sand border p-4">
        <div className="text-rust eyebrow">Patrimônio histórico</div>
        <h3 className="text-ink text-lg font-black">A nave que trouxe a humanidade</h3>

        <p className="text-ink-soft mt-2 text-sm leading-relaxed">
          <b>2387.</b> A Terra deixou de sustentar a vida humana depois de décadas de colapso
          climático. O Programa Arca enviou frotas improvisadas; a <b>Endurance of Mankind</b> foi a
          única a alcançar Fertways. Pousou no ponto mais plano do continente principal.
        </p>

        <p className="text-ink-soft mt-2 text-sm leading-relaxed">
          Ela <b>nunca voltará a voar</b>. É o marco narrativo do servidor — e a razão de o slot
          principal de cada colono ser inviolável: a humanidade não sobreviveu para se destruir de
          novo.
        </p>
      </section>

      <section className="border-rust/20 bg-sand border p-4">
        <h3 className="text-ink font-black">O telescópio Gagarin</h3>
        <p className="text-ink-soft mt-1 text-sm leading-relaxed">
          O Gagarin <b>não repousa sobre o casco</b> da Endurance, ao contrário do que a lenda conta:
          é um <b>satélite orbital</b>, lançado depois do pouso e propriedade do Governo de Fertways.
          Os dados dele alimentam a Central de Pesquisas e Notícias.
        </p>
      </section>

      {/*
        A honestidade que o D-55 fixou com o Gagarin: dizer o que ainda não existe, em vez de
        prometer. O GDD chama a Endurance de "fonte de peças e missões narrativas" — e nada disso
        foi construído.
      */}
      <section className="border-rust/40 bg-sand-light border border-dashed p-4">
        <h3 className="text-ink font-black">O que ainda não existe</h3>
        <p className="text-ink-soft mt-1 text-sm leading-relaxed">
          O GDD chama a Endurance de <b>fonte de peças históricas e missões narrativas</b>. Nem as
          peças nem as missões foram construídas — <b>não há nada a fazer aqui ainda</b>. Os destroços
          estão no mapa porque a Capital é um lugar, e um lugar tem história; não porque haja um botão
          escondido.
        </p>
      </section>
    </div>
  )
}
