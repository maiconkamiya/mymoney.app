# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.3.19] - 2026-10-17

### Corrigido

- **Sincronização da Binance não falha mais quando o relógio da máquina está fora de hora.** Em computadores que suspendem (como o WSL no Windows), o relógio podia adiantar ou atrasar e a Binance recusava a chamada com "Timestamp for this request was 1000ms ahead of the server's time". Agora o sistema confere a hora da Binance, ajusta o próprio relógio nas chamadas e, se mesmo assim a Binance reclamar, refaz o ajuste e tenta de novo uma vez. Se ainda falhar, a mensagem explica em português que é preciso acertar o relógio da máquina (no WSL: `sudo hwclock -s`). A tolerância da Binance pode ser mudada com `BINANCE_RECV_WINDOW` no `.env` (padrão 5000 ms).

## [0.3.18] - 2026-10-17

### Adicionado

- **Ligar ou desligar a execução automática depois de criar a carteira.** Na tela da carteira há agora um quadro com a opção "Executar sinais aprovados sem confirmação manual" e o botão Salvar. Com a opção ligada, o sinal aprovado pelo consenso dos agentes é executado na hora; desligada (o padrão), ele fica aguardando a sua confirmação. A guarda de risco vale nos dois casos. A mudança fica registrada e só o dono da carteira pode fazer. Carteiras reais, como a Conta Binance, continuam exigindo a sua aprovação em cada ordem: a opção aparece bloqueada e a execução real segue dependendo de estar liberada.

## [0.3.16] - 2026-10-17

### Alterado

- **Confirmação de e-mail desligada na instalação local.** Quem roda o MyMoney.App na própria máquina (com `APP_ENV=local`) não precisa mais confirmar o e-mail depois de criar a conta: o cadastro já entra direto no painel. Em produção a confirmação continua exigida. Para forçar um dos dois comportamentos, use `AUTH_EMAIL_VERIFICATION=true` ou `false` no `.env`.

## [0.3.12] - 2026-10-16

### Adicionado

- **Gráficos dos ativos em tempo real na carteira.** A tela da carteira ganhou o quadro "Ativos em tempo real": um gráfico por ativo que ela opera, com o preço atual, a variação da janela e a linha de preço por minuto. Ativos com posição aberta aparecem primeiro, com o preço médio e o stop marcados por linhas tracejadas e o ganho ou perda da posição. Na cripto os preços se movem a cada negócio, direto da Binance no seu navegador. Na B3 a cotação atualiza a cada 30 segundos com o pregão aberto, e com o pregão fechado o quadro avisa e mostra a última cotação. Se a conexão em tempo real cair, a página continua funcionando: aparece um aviso discreto e os gráficos se atualizam a cada 15 segundos.

## [0.3.11] - 2026-10-16

### Adicionado

- **Conta da Binance ligada à carteira.** Em Configurações > Binance você cadastra a sua chave da Binance (só leitura e negociação spot, sem saque). O sistema confere a chave na própria Binance antes de guardar, recusa chaves que permitem saque e cria a carteira **Conta Binance**, que passa a espelhar o seu saldo em USDT e os ativos que você tem na conta, atualizados a cada 5 minutos ou quando você clicar em "Sincronizar agora". Assim os agentes enxergam o que a conta realmente tem. A chave fica cifrada, é só sua e nunca volta para a tela: aparece apenas "configurada em" e a data, com opção de trocar ou remover. Enquanto a execução real estiver desligada, os agentes só leem a conta e nenhuma ordem é enviada; quando for ligada, cada ordem continua exigindo a sua aprovação e respeitando os limites.
- **Aviso claro quando a Binance bloqueia o servidor.** Se a Binance responder "bloqueado por região" (erro 451), a mensagem explica o motivo e o que ajustar. Também dá para apontar a API para outro endereço ou para o ambiente de testes da Binance, sem dinheiro real.

### Alterado

- **Cada usuário enxerga só as próprias carteiras e chaves.** Reforçamos e passamos a testar que ninguém acessa carteira, relatório, sinal, atividade dos agentes ou chave de outro usuário.

## [0.3.9] - 2026-10-16

### Adicionado

- **Cotações cripto gravadas a cada segundo.** Um novo coletor escuta os negócios de Bitcoin, Ethereum e Solana direto na Binance e atualiza o candle de 1 minuto quase em tempo real, em vez de esperar a busca de cada minuto. Cada atualização também é transmitida pelo canal "mercado", e o painel mostra, logo abaixo do gráfico, o último preço e há quanto tempo cada cotação chegou (aparece só quando as atualizações em tempo real estão ligadas). Por padrão ele usa o endereço público de dados de mercado da Binance, que responde nos lugares onde o endereço principal é bloqueado. O coletor só começa a valer quando o processo for ligado no servidor; até lá tudo continua como antes.

## [0.3.8] - 2026-10-16

### Adicionado

- **Agentes reagem na hora a quedas e altas bruscas.** Antes, os agentes só olhavam o mercado a cada 15 minutos: se o Bitcoin caísse 1,6% entre uma rodada e outra, a carteira só percebia depois. Agora, a cada cotação nova gravada, o sistema compara com o preço de 5 minutos antes; se a variação passar de 1%, abre o sinal e chama os agentes imediatamente, para cima ou para baixo. As regras de segurança continuam valendo: só carteiras em execução, mercado aberto, cotação recente, uma análise por ativo por vez e a guarda de risco com a palavra final. O motivo ("Gatilho de preço: -1,61% em 5 min.") fica no histórico do sinal. Depois de um disparo o ativo descansa 15 minutos para não repetir a análise enquanto o preço segue caindo. Limiar, janela e descanso são configuráveis no `.env`.
- **Intervalo da varredura configurável.** A varredura agendada continua a cada 15 minutos por padrão, mas agora pode ser mais curta pelo `.env`.

## [0.3.6] - 2026-10-16

### Alterado

- **Agentes em ação em tempo real.** O quadro dos agentes na carteira deixa de repetir animações por tempo: cada movimento agora acontece porque o agente realmente fez aquilo. O agente sai para ler a fonte quando começa a consultar o modelo, volta quando entrega o voto, e as mensagens (voto, objeção do cético, decisão, ordem) aparecem no instante em que são enviadas. Entre um passo e outro o quadro fica parado, e com a carteira ociosa ele mostra o último ciclo. Se a conexão em tempo real cair, o quadro avisa e passa a se atualizar a cada 15 segundos.

## [0.3.5] - 2026-10-16

### Alterado

- **Sistema todo em português.** Login, cadastro, recuperação e redefinição de senha, confirmação de e-mail, verificação em duas etapas, passkeys, configurações de perfil, segurança e aparência e o menu do usuário agora estão em português.
- **Mensagens em português.** Erros de login, avisos de formulário, limite de tentativas e os e-mails de confirmação e de redefinição de senha passam a chegar em português.

## [0.3.4] - 2026-10-16

### Adicionado

- **Agentes em ação na carteira.** A página da carteira ganhou um quadro animado que mostra os agentes lendo as fontes de informação, analisando, votando, contestando uns aos outros e entregando a decisão ao executor. Ele se atualiza sozinho a cada poucos segundos com o ciclo mais recente da carteira; sem ciclo em andamento, mostra o último e avisa que está ocioso. Abaixo do quadro há uma lista em texto com o que cada agente está fazendo, e com "reduzir movimento" ligado no sistema o quadro fica parado.

## [0.3.2] - 2026-10-16

### Corrigido

- **Cotações de cripto em servidores fora do alcance da Binance.** Em alguns países a Binance recusa a conexão, e Bitcoin, Ethereum e Solana ficavam sem cotação: as carteiras de cripto não abriam nenhum sinal. As cotações agora vêm do endereço de dados públicos da Binance, que responde nesses lugares, e os agentes voltam a analisar.

## [0.2.1] - 2026-10-16

### Alterado

- **Fontes servidas pelo próprio sistema.** As fontes Geist e Geist Mono deixam de ser baixadas de um site externo: o visual é o mesmo, mas as páginas abrem sem depender de outro servidor e sem enviar seu acesso a terceiros.

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
