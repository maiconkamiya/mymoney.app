# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.2.0] - 2026-10-16

### Adicionado

- **Nova página inicial.** Apresenta o MyMoney com cotações de Bitcoin, Ethereum e Solana em real vindas da Binance, um exemplo do consenso dos agentes votando sobre esses preços, as regras de risco, um simulador de carteira e as dúvidas mais comuns.
- **Mercado ao vivo no painel.** O painel mostra o gráfico de BTC, ETH e SOL em real atualizado a cada negócio, com o indicador de conexão; sem conexão com a Binance, reproduz negócios reais gravados e avisa isso.
- **Resumo no painel.** Patrimônio por mercado, resultado médio das carteiras, carteiras em execução e sinais aguardando sua decisão ficam no topo.

### Alterado

- **Visual novo em todo o sistema.** Tema escuro grafite com acento cobalto, fonte Geist, cantos arredondados e números alinhados; altas em verde e baixas em vermelho em todas as telas.
- **Telas de entrada.** Login, cadastro e recuperação de senha ganharam um layout dividido com foto.
- **"Dashboard" agora se chama "Painel"** no menu e no título da página.

## [0.1.0] - 2026-10-16

### Adicionado

- **Controle de versão do sistema.** O menu lateral passa a mostrar a versão e o commit em uso, e o suporte consegue ler a versão que respondeu pelo cabeçalho `X-MyMoney-Versao`.
- **Página Novidades.** Em `/novidades` ficam as últimas versões, com o que mudou em cada uma, da mais recente para a mais antiga.
- **Aviso de nova versão.** Depois de uma atualização, cada pessoa vê um aviso discreto uma única vez, com o atalho para as novidades.
