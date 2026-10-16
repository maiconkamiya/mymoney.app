# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.1.0] – 2026-10-16

### Adicionado

- **Controle de versão do sistema.** O menu lateral passa a mostrar a versão e o commit em uso, e o suporte consegue ler a versão que respondeu pelo cabeçalho `X-MyMoney-Versao`.
- **Página Novidades.** Em `/novidades` ficam as últimas versões, com o que mudou em cada uma, da mais recente para a mais antiga.
- **Aviso de nova versão.** Depois de uma atualização, cada pessoa vê um aviso discreto uma única vez, com o atalho para as novidades.
