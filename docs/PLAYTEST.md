# O primeiro teste com pessoas de verdade

> Escrito em 15/10/2026 (D-267). Até aqui, toda conta humana do servidor era do dono do jogo, e o resto
> era bot (D-265): o Fertways nunca foi jogado por alguém de fora. Este roteiro é para a primeira vez.

## O que o teste quer responder

Uma pergunta só, e as outras saem dela: **quem chega consegue começar a jogar sozinho — e volta no
dia seguinte?**

Não é teste de balanceamento nem de carga. São poucas pessoas, por pouco tempo, e o que importa é
**onde elas travam**: a primeira hora diz se o jogo se explica; a primeira semana, se ele chama de volta.

## Antes de chamar alguém

1. **O deploy em dia.** O lote D-263 a D-267 traz o que este teste precisa: o "Próximo passo" da
   tutoria (inclusive no celular), o sorteio de missões que só entrega o que a colônia consegue
   cumprir, os textos sem jargão, e a marca de conta da equipe. Ver `docs/PROMPT_DEPLOY_VPS.md`.
2. **Marcar as suas contas como equipe — antes do primeiro testador.** No servidor:

   ```sh
   sudo -u fertways /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan fertways:equipe --todos-os-atuais
   ```

   Isso tira da medida de retenção toda conta que existe hoje (as suas). Quem se cadastrar depois
   conta como jogador. Se você criar uma conta sua durante o teste, marque-a:
   `fertways:equipe --marcar=seu-email@...`. Os bots já ficam fora sozinhos, pelo domínio.
3. **Decidir o que dizer sobre os bots.** Mais da metade das colônias do mundo são simuladas (D-238).
   Recomendação: dizer aos testadores, no convite. Quem descobre sozinho que o vizinho é um robô se
   sente enganado; quem já sabia acha curioso.
4. **Não mexer no balanceamento durante o teste.** Se for preciso mudar um número no painel, anote
   o quê, quando e por quê — uma mudança no meio confunde a leitura do resultado.

## Quem chamar

- **5 a 10 pessoas.** Menos que 5 não mostra padrão; mais que 10 você não consegue acompanhar.
- **Misture perfis:** quem joga estratégia (já conhece o gênero) e quem não joga (mostra o que o jogo
  não explica). Pelo menos **dois pelo celular** — o jogo é mobile-first, e o celular esconde coisas
  que o computador mostra (o D-266 achou duas).
- **Ninguém que já viu o jogo.** Quem acompanhou o desenvolvimento sabe o que você quis dizer.

## Quanto tempo

**Duas semanas**, com data de início e de fim combinadas. Peça **10 a 15 minutos por dia** — não
como obrigação: se alguém some, isso também é resultado.

## O convite (pronto para mandar)

> Estou fazendo um jogo de estratégia no navegador, o **Fertways**: você funda uma colônia num
> planeta, produz, negocia e transporta recursos — e tudo leva tempo de verdade, então dá para jogar
> uns minutos por dia.
>
> Queria que você jogasse por **duas semanas, de [data] a [data]**, uns 10 minutos por dia, e me
> contasse o que achou. Funciona no computador e no celular: **https://fertways.tars.art.br**
>
> Duas coisas: algumas colônias do mundo são simuladas por um programa, para o planeta não ficar
> vazio. E nos **primeiros 30 minutos, tente sozinho** — não me pergunte nada. Se travar, é
> exatamente isso que eu preciso saber; anote e me conte depois.
>
> Dentro do jogo tem a aba **Bugs/Melhorias**: qualquer coisa que confundir, irritar ou quebrar, manda
> por ali na hora, mesmo que pareça bobagem.

## O que o testador recebe depois de entrar (se pedir ajuda)

> - Ao fundar, escolha qualquer círculo escuro do disco "Perto do Mercado".
> - O jogo mostra o **Próximo passo** no topo da colônia — siga por ele.
> - As obras e as viagens levam minutos a horas: comece algo e volte depois.
> - **Missões** dá Fert$ e XP; **Capital** é onde ficam o Mercado e o Espaçoporto.

## A rotina do operador durante o teste

| quando | o quê |
|---|---|
| todo dia | ler a aba **Feedback** do painel; responder em até 24 h (quem é respondido continua mandando) |
| dia 1 | conferir que todos conseguiram fundar — quem não fundou, perguntar por quê, sem dar a resposta |
| dias 3, 7 e 14 | rodar o relatório de retenção (abaixo) e guardar a saída |
| dia 14 | a entrevista final |

O relatório, só leitura, na cópia de deploy:

```sh
sudo -u fertways /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan fertways:retencao
```

Ele também aparece no painel, em **Métricas → Onde os jogadores param**. Depois de marcar a equipe,
os números são **só dos testadores**.

## O que olhar

- **O funil:** de quantos cadastros, quantos fundaram, agiram, voltaram no dia seguinte, ainda jogavam
  depois de 7 dias. A maior queda entre dois degraus é o primeiro problema a atacar.
- **Onde cada um parou** e **qual foi o último ato** — é o melhor palpite do que a pessoa tentava
  fazer quando desistiu.
- **O feedback**, agrupado por tema. Três pessoas reclamando da mesma tela valem mais que uma
  reclamando de dez.

## A entrevista final (5 perguntas, 10 minutos)

1. Nos primeiros minutos, o que você achou que tinha de fazer? Em algum momento não soube o que fazer?
2. O que te fez voltar no dia seguinte — ou o que te fez não voltar?
3. Qual foi o momento mais legal? E o mais chato?
4. Teve algo que você queria fazer e não conseguiu, ou não achou onde?
5. Você chamaria um amigo para jogar? Por quê?

Anote as respostas com as palavras da pessoa, não com as suas.

## Depois

Traga para a conversa: as três saídas do `fertways:retencao`, o feedback recebido e as notas da
entrevista. Com isso dá para decidir o próximo trabalho com base no que aconteceu — e não em palpite.
