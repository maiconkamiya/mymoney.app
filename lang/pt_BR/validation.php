<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensagens de validação
    |--------------------------------------------------------------------------
    |
    | Regras sem tradução aqui caem no inglês do framework (fallback_locale).
    |
    */

    'accepted' => 'O campo :attribute precisa ser aceito.',
    'accepted_if' => 'O campo :attribute precisa ser aceito quando :other for :value.',
    'active_url' => 'O campo :attribute precisa ser uma URL válida.',
    'after' => 'O campo :attribute precisa ser uma data depois de :date.',
    'after_or_equal' => 'O campo :attribute precisa ser uma data igual ou depois de :date.',
    'alpha' => 'O campo :attribute só pode ter letras.',
    'alpha_dash' => 'O campo :attribute só pode ter letras, números, hífens e sublinhados.',
    'alpha_num' => 'O campo :attribute só pode ter letras e números.',
    'array' => 'O campo :attribute precisa ser uma lista.',
    'ascii' => 'O campo :attribute só pode ter letras, números e símbolos simples.',
    'before' => 'O campo :attribute precisa ser uma data antes de :date.',
    'before_or_equal' => 'O campo :attribute precisa ser uma data igual ou antes de :date.',
    'between' => [
        'array' => 'O campo :attribute precisa ter entre :min e :max itens.',
        'file' => 'O arquivo :attribute precisa ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute precisa estar entre :min e :max.',
        'string' => 'O campo :attribute precisa ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute precisa ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'current_password' => 'A senha está incorreta.',
    'date' => 'O campo :attribute precisa ser uma data válida.',
    'date_equals' => 'O campo :attribute precisa ser a data :date.',
    'date_format' => 'O campo :attribute precisa seguir o formato :format.',
    'decimal' => 'O campo :attribute precisa ter :decimal casas decimais.',
    'declined' => 'O campo :attribute precisa ser recusado.',
    'different' => 'Os campos :attribute e :other precisam ser diferentes.',
    'digits' => 'O campo :attribute precisa ter :digits dígitos.',
    'digits_between' => 'O campo :attribute precisa ter entre :min e :max dígitos.',
    'distinct' => 'O campo :attribute tem um valor repetido.',
    'email' => 'O campo :attribute precisa ser um e-mail válido.',
    'ends_with' => 'O campo :attribute precisa terminar com: :values.',
    'enum' => 'O valor escolhido para :attribute é inválido.',
    'exists' => 'O valor escolhido para :attribute é inválido.',
    'file' => 'O campo :attribute precisa ser um arquivo.',
    'filled' => 'O campo :attribute precisa ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute precisa ter mais de :value itens.',
        'file' => 'O arquivo :attribute precisa ter mais de :value kilobytes.',
        'numeric' => 'O campo :attribute precisa ser maior que :value.',
        'string' => 'O campo :attribute precisa ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute precisa ter :value itens ou mais.',
        'file' => 'O arquivo :attribute precisa ter :value kilobytes ou mais.',
        'numeric' => 'O campo :attribute precisa ser maior ou igual a :value.',
        'string' => 'O campo :attribute precisa ter :value caracteres ou mais.',
    ],
    'image' => 'O campo :attribute precisa ser uma imagem.',
    'in' => 'O valor escolhido para :attribute é inválido.',
    'in_array' => 'O campo :attribute precisa existir em :other.',
    'integer' => 'O campo :attribute precisa ser um número inteiro.',
    'ip' => 'O campo :attribute precisa ser um endereço IP válido.',
    'json' => 'O campo :attribute precisa ser um JSON válido.',
    'lowercase' => 'O campo :attribute precisa estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute precisa ter menos de :value itens.',
        'file' => 'O arquivo :attribute precisa ter menos de :value kilobytes.',
        'numeric' => 'O campo :attribute precisa ser menor que :value.',
        'string' => 'O campo :attribute precisa ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute precisa ter no máximo :value itens.',
        'file' => 'O arquivo :attribute precisa ter no máximo :value kilobytes.',
        'numeric' => 'O campo :attribute precisa ser menor ou igual a :value.',
        'string' => 'O campo :attribute precisa ter no máximo :value caracteres.',
    ],
    'max' => [
        'array' => 'O campo :attribute pode ter no máximo :max itens.',
        'file' => 'O arquivo :attribute pode ter no máximo :max kilobytes.',
        'numeric' => 'O campo :attribute pode ser no máximo :max.',
        'string' => 'O campo :attribute pode ter no máximo :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute pode ter no máximo :max dígitos.',
    'mimes' => 'O arquivo :attribute precisa ser do tipo: :values.',
    'mimetypes' => 'O arquivo :attribute precisa ser do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute precisa ter pelo menos :min itens.',
        'file' => 'O arquivo :attribute precisa ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute precisa ser pelo menos :min.',
        'string' => 'O campo :attribute precisa ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute precisa ter pelo menos :min dígitos.',
    'multiple_of' => 'O campo :attribute precisa ser múltiplo de :value.',
    'not_in' => 'O valor escolhido para :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute precisa ser um número.',
    'password' => [
        'letters' => 'O campo :attribute precisa ter pelo menos uma letra.',
        'mixed' => 'O campo :attribute precisa ter letras maiúsculas e minúsculas.',
        'numbers' => 'O campo :attribute precisa ter pelo menos um número.',
        'symbols' => 'O campo :attribute precisa ter pelo menos um símbolo.',
        'uncompromised' => 'Esta :attribute apareceu em um vazamento de dados. Escolha outra.',
    ],
    'present' => 'O campo :attribute precisa estar presente.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other for :value.',
    'required_unless' => 'O campo :attribute é obrigatório, a menos que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values estiver preenchido.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estiverem preenchidos.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não estiver preenchido.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values estiver preenchido.',
    'same' => 'Os campos :attribute e :other precisam ser iguais.',
    'size' => [
        'array' => 'O campo :attribute precisa ter :size itens.',
        'file' => 'O arquivo :attribute precisa ter :size kilobytes.',
        'numeric' => 'O campo :attribute precisa ser :size.',
        'string' => 'O campo :attribute precisa ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute precisa começar com: :values.',
    'string' => 'O campo :attribute precisa ser um texto.',
    'timezone' => 'O campo :attribute precisa ser um fuso horário válido.',
    'unique' => 'Este :attribute já está em uso.',
    'uploaded' => 'Não foi possível enviar o arquivo :attribute.',
    'uppercase' => 'O campo :attribute precisa estar em maiúsculas.',
    'url' => 'O campo :attribute precisa ser uma URL válida.',
    'ulid' => 'O campo :attribute precisa ser um ULID válido.',
    'uuid' => 'O campo :attribute precisa ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensagens personalizadas
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomes dos campos
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'password' => 'senha',
        'password_confirmation' => 'confirmação da senha',
        'current_password' => 'senha atual',
        'code' => 'código',
        'recovery_code' => 'código de recuperação',
        'initial_cash' => 'valor inicial',
        'starts_at' => 'início',
        'ends_at' => 'fim',
        'market' => 'mercado',
    ],

];
