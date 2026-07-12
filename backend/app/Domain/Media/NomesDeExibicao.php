<?php

namespace App\Domain\Media;

/**
 * O nome humano de cada coisa do catálogo.
 *
 * Os slugs do banco não têm acento (`refinaria_quimica`), e derivá-los produz "Refinaria quimica" —
 * um painel que erra o nome dos prédios que gere não serve. Estes nomes já existiam dentro do
 * gerador do GDD (`tools/gdd-v36.php`), e **é dele que esta lista sai**: uma segunda cópia faria a
 * tela e o documento divergirem no dia em que alguém corrigisse só um dos dois.
 *
 * O gerador continua sendo o dono da lista; aqui ela é lida.
 */
class NomesDeExibicao
{
    /**
     * @return array<string,string>
     */
    public static function mapa(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        /*
         * O gerador do GDD é um script, não uma classe — ele roda o Laravel e cospe HTML. Não dá para
         * `require` sem executá-lo. Então lemos a função `nomesProprios()` de lá por reflexão do
         * texto? Não: seria frágil e ilegível.
         *
         * A lista vive aqui, e o gerador do GDD passa a usá-la (ver `tools/gdd-v36.php`). Um lugar
         * só, e a guarda do gerador — que falha alto se uma construção nova não tiver nome — continua
         * valendo para as duas pontas.
         */
        return $cache = [
            // As 17 construções da colônia (§17.1, §17.2, §21.9).
            'gerador_de_atmosfera' => 'Gerador de Atmosfera',
            'estrutura_de_sobrevivencia' => 'Estrutura de Sobrevivência',
            'fazenda' => 'Fazenda',
            'reator_de_energia' => 'Reator de Energia',
            'captacao_de_agua' => 'Captação de Água',
            'oficina' => 'Oficina',
            'refinaria_quimica' => 'Refinaria Química',
            'laboratorio' => 'Laboratório',
            'antena_de_comunicacao' => 'Antena de Comunicação',
            'torre_de_defesa' => 'Torre de Defesa',
            'mercado_local' => 'Mercado Local',
            'quartel' => 'Quartel',
            'plataforma_de_pouso' => 'Plataforma de Pouso',
            'central_de_transportes' => 'Central de Transportes',
            'mina_local' => 'Mina Local',
            'destilaria' => 'Destilaria',
            'tanque_de_combustivel' => 'Tanque de Combustível',

            // As estruturas da zona neutra (§17.4, D-66 e D-67).
            'posto_de_comando' => 'Posto de Comando',
            'deposito_de_zona_neutra' => 'Depósito de Zona Neutra',
            'muralha_de_perimetro' => 'Muralha de Perímetro',
            'torre_de_vigia' => 'Torre de Vigia',
            'bastiao' => 'Bastião',
            'abrigo_de_robos' => 'Abrigo de Robôs',
            'refinaria_de_campo' => 'Refinaria de Campo',
            'estacionamento_da_zona' => 'Estacionamento da Zona',
            'cemiterio_de_robos' => 'Cemitério de Robôs',

            // Veículos e unidades.
            'furgao_de_comercio' => 'Furgão de Comércio',
            'caminhao_de_carga' => 'Caminhão de Carga',
            'nave_de_transporte_planetaria' => 'Nave de Transporte Planetária',
            'drone_de_exploracao' => 'Drone de Exploração',
            'sentinela' => 'Sentinela',
            'robo_minerador' => 'Robô Minerador',
            'infiltrador' => 'Infiltrador',
            'predador' => 'Predador',

            // Recursos, citados fora das tabelas geradas do GDD.
            'ligas_metalicas' => 'Ligas Metálicas',
            'componentes_eletronicos' => 'Componentes Eletrônicos',
            'compostos_quimicos' => 'Compostos Químicos',
            'metal_bruto' => 'Metal Bruto',
            'biocombustivel' => 'Biocombustível',
            'oxigenio' => 'Oxigênio',
            'agua' => 'Água',
            'biomassa' => 'Biomassa',
            'energia' => 'Energia',

            // Classes tributárias.
            'primario' => 'Primário',
            'secundario' => 'Secundário',
            'raro' => 'Raro',
        ];
    }

    public static function de(string $slug): string
    {
        return self::mapa()[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
    }
}
