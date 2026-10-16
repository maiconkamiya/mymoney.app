<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lê o `CHANGELOG.md` da raiz (Keep a Changelog) para a página "Novidades"
 *.
 *
 * É um parser deliberadamente simples, só para o formato que o projeto usa:
 * `## [X.Y.Z] – data`, seções `### Adicionado` e itens `- texto`. Devolve
 * TEXTO PURO: nada de HTML vai para a tela (o Vue só transforma `**negrito**`
 * em `<strong>` por nós próprios, nunca por `v-html` do que vem daqui).
 */
class ChangelogDoSistema
{
    public function __construct(private readonly ?string $caminho = null) {}

    /**
     * @return list<array{versao: string, data: string|null, resumo: string|null, secoes: list<array{titulo: string, itens: list<string>}>}>
     */
    public function ultimasVersoes(int $limite = 5): array
    {
        return array_slice($this->versoes(), 0, max(0, $limite));
    }

    /**
     * @return list<array{versao: string, data: string|null, resumo: string|null, secoes: list<array{titulo: string, itens: list<string>}>}>
     */
    public function versoes(): array
    {
        $caminho = $this->caminho ?? base_path('CHANGELOG.md');
        if (! is_file($caminho)) {
            return [];
        }

        $conteudo = @file_get_contents($caminho);

        return $conteudo === false ? [] : $this->analisar($conteudo);
    }

    /**
     * @return list<array{versao: string, data: string|null, resumo: string|null, secoes: list<array{titulo: string, itens: list<string>}>}>
     */
    public function analisar(string $conteudo): array
    {
        $blocos = [];

        // 1) Quebra o arquivo em blocos, um por título `## [X.Y.Z]`. Títulos como "[Não lançado]"
        //    fecham o bloco anterior mas não abrem versão: só versões numeradas entram.
        $atual = null;
        foreach (preg_split('/\R/u', $conteudo) ?: [] as $linha) {
            if (preg_match('/^##\s*\[([^\]]+)\]\s*(?:[–—-]\s*(\S.*?))?\s*$/u', $linha, $m) === 1) {
                if ($atual !== null) {
                    $blocos[] = $atual;
                }
                $atual = preg_match('/^\d+\.\d+\.\d+/', $m[1]) === 1
                    ? ['versao' => $m[1], 'data' => ($m[2] ?? '') !== '' ? $m[2] : null, 'linhas' => []]
                    : null;

                continue;
            }

            if ($atual !== null) {
                $atual['linhas'][] = $linha;
            }
        }
        if ($atual !== null) {
            $blocos[] = $atual;
        }

        return array_map(fn (array $bloco): array => $this->analisarBloco($bloco['versao'], $bloco['data'], $bloco['linhas']), $blocos);
    }

    /**
     * @param  list<string>  $linhas
     * @return array{versao: string, data: string|null, resumo: string|null, secoes: list<array{titulo: string, itens: list<string>}>}
     */
    private function analisarBloco(string $versao, ?string $data, array $linhas): array
    {
        $secoes = [];
        $resumo = [];

        foreach ($linhas as $linha) {
            if (preg_match('/^###\s+(.+?)\s*$/u', $linha, $m) === 1) {
                $secoes[] = ['titulo' => $m[1], 'itens' => []];

                continue;
            }

            $ultima = array_key_last($secoes);

            if ($ultima !== null && preg_match('/^\s*[-*]\s+(.+?)\s*$/u', $linha, $m) === 1) {
                $secoes[$ultima]['itens'][] = $m[1];

                continue;
            }

            $texto = trim($linha);
            if ($texto === '' || preg_match('/^\[[^\]]+\]:\s/', $texto) === 1) {
                continue;
            }

            if ($ultima === null) {
                $resumo[] = $texto;

                continue;
            }

            // Linha de continuação do último item da seção.
            $ultimoItem = array_key_last($secoes[$ultima]['itens']);
            if ($ultimoItem !== null) {
                $secoes[$ultima]['itens'][$ultimoItem] .= ' '.$texto;
            }
        }

        $textoResumo = implode(' ', $resumo);

        return [
            'versao' => $versao,
            'data' => $data,
            'resumo' => $textoResumo === '' ? null : $textoResumo,
            'secoes' => $secoes,
        ];
    }
}
