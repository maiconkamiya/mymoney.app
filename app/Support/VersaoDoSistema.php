<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Versão do MyMoney.App e commit do build.
 *
 * A fonte da versão é o `version` do `package.json`. O `npm run build` grava
 * `public/build/versao.json` (`{versao, commit, gerado_em}`, ver
 * `tools/versoes/gerar-versao.mjs`), que é o que identifica o build que está
 * no ar. Sem esse arquivo (ambiente de teste, build antigo), cai na versão do
 * `package.json` com o commit `sem-commit`.
 *
 * Registrada como `scoped`: uma leitura de arquivo por request/job, e um
 * deploy novo é visto sem reiniciar worker de longa vida.
 */
class VersaoDoSistema
{
    public const SEM_COMMIT = 'sem-commit';

    public const VERSAO_DESCONHECIDA = '0.0.0';

    /** @var array{versao: string, commit: string, gerado_em: string|null}|null */
    private ?array $dados = null;

    public function __construct(
        private readonly ?string $caminhoVersaoJson = null,
        private readonly ?string $caminhoPackageJson = null,
    ) {}

    public function versao(): string
    {
        return $this->dados()['versao'];
    }

    public function commit(): string
    {
        return $this->dados()['commit'];
    }

    public function geradoEm(): ?string
    {
        return $this->dados()['gerado_em'];
    }

    /**
     * Formato do cabeçalho `X-MyMoney-Versao`: `1.0.0 (ab07d43)`.
     */
    public function rotuloParaCabecalho(): string
    {
        return sprintf('%s (%s)', $this->versao(), $this->commit());
    }

    /**
     * Formato da tela: `1.0.0 · ab07d43`.
     */
    public function rotuloParaTela(): string
    {
        return sprintf('%s · %s', $this->versao(), $this->commit());
    }

    /**
     * `true` quando `$versao` é posterior a `$outra`. Uma versão ausente ou
     * vazia conta como anterior a qualquer uma (quem nunca viu nada vê a
     * atual como nova).
     */
    public static function ehPosterior(string $versao, ?string $outra): bool
    {
        if ($outra === null || trim($outra) === '') {
            return true;
        }

        return version_compare($versao, $outra, '>');
    }

    /**
     * @return array{versao: string, commit: string, gerado_em: string|null}
     */
    private function dados(): array
    {
        return $this->dados ??= $this->lerVersaoJson() ?? $this->lerPackageJson();
    }

    /**
     * @return array{versao: string, commit: string, gerado_em: string|null}|null
     */
    private function lerVersaoJson(): ?array
    {
        $json = $this->lerJson($this->caminhoVersaoJson ?? public_path('build/versao.json'));

        $versao = $json['versao'] ?? null;
        if (! is_string($versao) || trim($versao) === '') {
            return null;
        }

        $commit = $json['commit'] ?? null;
        $geradoEm = $json['gerado_em'] ?? null;

        return [
            'versao' => trim($versao),
            'commit' => is_string($commit) && trim($commit) !== '' ? trim($commit) : self::SEM_COMMIT,
            'gerado_em' => is_string($geradoEm) ? $geradoEm : null,
        ];
    }

    /**
     * @return array{versao: string, commit: string, gerado_em: string|null}
     */
    private function lerPackageJson(): array
    {
        $json = $this->lerJson($this->caminhoPackageJson ?? base_path('package.json'));
        $versao = $json['version'] ?? null;

        return [
            'versao' => is_string($versao) && trim($versao) !== '' ? trim($versao) : self::VERSAO_DESCONHECIDA,
            'commit' => self::SEM_COMMIT,
            'gerado_em' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lerJson(string $caminho): array
    {
        if (! is_file($caminho)) {
            return [];
        }

        $conteudo = @file_get_contents($caminho);
        if ($conteudo === false) {
            return [];
        }

        $json = json_decode($conteudo, true);

        return is_array($json) ? $json : [];
    }
}
