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
        $versoes = [];
        $versaoAtual = null;
        $secaoAtual = null;
        $resumo = [];
        $ultimoItem = null;

        $fechar = function () use (&$versoes, &$versaoAtual, &$secaoAtual, &$resumo, &$ultimoItem): void {
            if ($versaoAtual !== null) {
                if ($secaoAtual !== null) {
                    $versaoAtual['secoes'][] = $secaoAtual;
                }
                $texto = trim(implode(' ', $resumo));
                $versaoAtual['resumo'] = $texto === '' ? null : $texto;
                $versoes[] = $versaoAtual;
            }
            $versaoAtual = null;
            $secaoAtual = null;
            $resumo = [];
            $ultimoItem = null;
        };

        foreach (preg_split('/\R/u', $conteudo) ?: [] as $linha) {
            if (preg_match('/^##\s*\[([^\]]+)\]\s*(?:[–—-]\s*(\S.*?))?\s*$/u', $linha, $m) === 1) {
                $fechar();

                // "[Não lançado]" e afins não são uma versão: só versões numeradas entram.
                if (preg_match('/^\d+\.\d+\.\d+/', $m[1]) === 1) {
                    $versaoAtual = [
                        'versao' => $m[1],
                        'data' => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
                        'resumo' => null,
                        'secoes' => [],
                    ];
                }

                continue;
            }

            if ($versaoAtual === null) {
                continue;
            }

            if (preg_match('/^###\s+(.+?)\s*$/u', $linha, $m) === 1) {
                if ($secaoAtual !== null) {
                    $versaoAtual['secoes'][] = $secaoAtual;
                }
                $secaoAtual = ['titulo' => $m[1], 'itens' => []];
                $ultimoItem = null;

                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+?)\s*$/u', $linha, $m) === 1 && $secaoAtual !== null) {
                $secaoAtual['itens'][] = $m[1];
                $ultimoItem = array_key_last($secaoAtual['itens']);

                continue;
            }

            $texto = trim($linha);
            if ($texto === '' || preg_match('/^\[[^\]]+\]:\s/', $texto) === 1) {
                continue;
            }

            if ($secaoAtual === null) {
                $resumo[] = $texto;
            } elseif ($ultimoItem !== null) {
                $secaoAtual['itens'][$ultimoItem] .= ' '.$texto;
            }
        }

        $fechar();

        return $versoes;
    }
}
