<?php

/*
 * As mensagens de validação em português (D-285).
 *
 * O jogo é em pt-BR, mas a validação respondia em inglês — "The nickname field must only contain
 * letters, numbers, dashes, and underscores" foi o primeiro contato de um novato com o jogo. Estão
 * aqui as regras que as rotas do jogo e do painel usam; o que faltar cai no inglês do Laravel, pelo
 * `fallback_locale`, em vez de quebrar.
 */
return [
    'accepted' => 'É preciso aceitar :attribute.',
    'alpha_dash' => ':Attribute só pode ter letras, números, traço (-) e sublinhado (_).',
    'array' => ':Attribute precisa ser uma lista.',
    'boolean' => ':Attribute precisa ser sim ou não.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'date' => ':Attribute não é uma data válida.',
    'email' => ':Attribute precisa ser um e-mail válido.',
    'exists' => ':Attribute não existe.',
    'in' => ':Attribute não é uma opção válida.',
    'integer' => ':Attribute precisa ser um número inteiro.',
    'numeric' => ':Attribute precisa ser um número.',
    'present' => ':Attribute precisa ser enviado.',
    'required' => 'Preencha :attribute.',
    'string' => ':Attribute precisa ser texto.',
    'unique' => 'Já existe :attribute igual a este.',
    'max' => [
        'array' => ':Attribute pode ter no máximo :max itens.',
        'numeric' => ':Attribute pode ser no máximo :max.',
        'string' => ':Attribute pode ter no máximo :max caracteres.',
    ],
    'min' => [
        'array' => ':Attribute precisa ter pelo menos :min itens.',
        'numeric' => ':Attribute precisa ser pelo menos :min.',
        'string' => ':Attribute precisa ter pelo menos :min caracteres.',
    ],
    'password' => [
        'letters' => 'A senha precisa ter pelo menos uma letra.',
        'mixed' => 'A senha precisa ter letras maiúsculas e minúsculas.',
        'numbers' => 'A senha precisa ter pelo menos um número.',
        'symbols' => 'A senha precisa ter pelo menos um símbolo.',
        'uncompromised' => 'Esta senha apareceu num vazamento de dados. Escolha outra.',
    ],

    'attributes' => [
        'name' => 'o nome',
        'nickname' => 'o apelido',
        'email' => 'o e-mail',
        'password' => 'a senha',
        'colony_name' => 'o nome da colônia',
        'senha_atual' => 'a senha atual',
        'body' => 'a mensagem',
        'pergunta' => 'a pergunta',
        'resposta' => 'a resposta',
        'planeta' => 'o planeta',
        'quantidade' => 'a quantidade',
        'zona' => 'a zona',
    ],
];
