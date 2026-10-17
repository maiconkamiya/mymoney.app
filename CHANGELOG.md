# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.3.30] - 2026-10-17
### Adicionado

- **Script de deploy para a produção.** Na máquina onde o MyMoney.App roda, `composer deploy` (ou `deploy/deploy.sh`) atualiza o sistema em um comando: baixa a versão nova da main, instala dependências só quando mudaram, refaz o build, aplica as migrations, atualiza os caches e reinicia o Reverb, o coletor da Binance e o serviço de previsão quando instalados. Antes de mexer em qualquer coisa ele confere se não há alterações locais e se o `.env` existe, e no fim mostra a versão e o estado dos serviços. `--dry-run` só lista o que seria feito. Isso também evita o erro de build "update is not exported" que aparecia quando uma rota nova era publicada com o cache de rotas antigo.

## [0.3.29] - 2026-10-17

### Corrigido

- **A carteira real mostra todos os ativos da conta Binance.** Antes, só apareciam em "Posições abertas" as moedas já cadastradas no sistema (BTC, ETH e SOL) e o resto sumia sem aviso. Agora toda moeda com saldo (livre mais o que está em ordem aberta) vira posição, com quantidade e valor estimado em USDT. Moedas novas são cadastradas sozinhas, sem entrar no scanner de sinais. Quando a moeda não tem par em USDT, o valor é estimado por BTC, BNB ou BRL.
- **Nada some: poeira e moedas sem cotação ficam listadas.** Saldos abaixo de 1 USDT aparecem agrupados como "Poeira", com o total. Moedas sem cotação ou sem par em USDT aparecem com o motivo.

### Adicionado

- **Hora da última sincronização e botão "Sincronizar agora"** direto na carteira real, com o aviso de que só a carteira Spot é lida: saldos em Earn, Funding, Margin ou Futuros da Binance não aparecem.

## [0.3.28] - 2026-10-17

### Adicionado

- **Execução automática na carteira real.** Na Conta Binance há agora o quadro "Execução automática com dinheiro real": ligado, cada sinal aprovado pelos agentes vira uma ordem real na Binance sem esperar a sua confirmação. Para ligar é preciso que a execução real esteja liberada no servidor, que a chave esteja verificada e sem permissão de saque, e que você digite **CONFIRMO** num aviso que explica o risco. Os limites valem em toda ordem automática: valor máximo por ordem, teto por dia, sem venda a descoberto, só ativos de cripto, saldo suficiente e cotação recente.
- **Botão "Parar tudo".** Na carteira real, um clique desliga a execução automática, cancela as ordens abertas que o sistema enviou à Binance e bloqueia novas ordens (inclusive as aprovadas à mão e as saídas por stop) até você clicar em "Liberar ordens". As posições abertas ficam como estão. Também existe o comando `php artisan trading:panic`.
- **Avisos de ordem real.** Quando uma ordem real sai, falha ou é recusada pela guarda de risco, aparece um aviso na hora na tela da carteira; se você marcar a opção, também chega por e-mail.
- **Mensagens da Binance em português** (saldo insuficiente, valor abaixo do mínimo, chave recusada e outras), e cada ordem real passa a ser registrada com o sinal, o score e a memória de cálculo.

### Alterado

- A carteira real deixa de recusar a execução automática; ela continua desligada até você ligar. Cada sinal gera no máximo uma ordem real, e se a Binance não confirmar o envio a carteira é bloqueada até você conferir.

## [0.3.20] - 2026-10-17

### Adicionado

- **Memória de cálculo do score em cada sinal.** Na tela da carteira, cada sinal tem agora o bloco "Como o score foi calculado": voto, confiança, peso e contribuição de cada agente, quem se absteve (fora da conta), soma dos pesos, score, limite de aprovação, concordância exigida e o resultado. O detalhamento é guardado na hora do consenso; sinais antigos não têm.

### Corrigido

- **O score do sinal não é mais puxado para zero por agente sem dados.** Quando um agente não tem em que se basear (previsão fora do ar, nenhuma notícia, sem dados fundamentalistas ou falha ao consultar o modelo), ele agora **se abstém**: fica fora da média e do peso do consenso, em vez de entrar como "aguardar" com 0% ou com uma confiança inventada. Um "aguardar" de verdade, com confiança, continua contando. Nas telas o agente aparece como "Absteve-se", com o motivo.
- **Mínimo de agentes com voto válido.** Se menos de 3 agentes votarem (configurável em `TRADING_CONSENSUS_MIN_VOTERS`), o sinal é rejeitado com o motivo "Só N agentes votaram: mínimo de 3". Quando alguns agentes se abstêm, a exigência de concordância do perfil de risco passa a valer sobre os agentes que votaram (com 3 votantes, os 3 precisam concordar), em vez de ficar impossível de atingir. O limite de aprovação e a execução não mudam.

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
