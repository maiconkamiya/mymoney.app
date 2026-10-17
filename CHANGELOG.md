# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.3.43] - 2026-10-17

### Corrigido

- **A previsão própria agora chega de fato ao agente Quantitativo.** O sistema enviava ao serviço de previsão uma vela a menos do que ele precisa, então toda consulta era recusada e o cenário ficava sem previsão. Agora são enviadas as 61 velas mais recentes. Os demais agentes continuam vendo as mesmas 60 velas de antes.

## [0.3.42] - 2026-10-17

### Corrigido

- **Par com "Entrada liberada" que não comprava agora diz o motivo.** Depois que o gráfico liberava a entrada, o modo ainda podia ser barrado por: o par já estar na carteira (por exemplo, saldo da conta Binance), limite de ciclos abertos, pausa depois de um stop, valor mínimo do par, guarda de risco (limite de posições, valor máximo por ordem) ou recusa da corretora, e nada disso aparecia. Agora o card mostra "Entrada liberada, mas bloqueada: ..." em cada par, o "Último bloqueio" lista todos os pares da última rodada e some quando um ciclo abre.
- **Ordem real que a guarda de risco encolhia e a Binance recusava.** Quando o limite por ordem do perfil de risco reduzia o aporte para abaixo do valor mínimo do par (por exemplo, 5 USDT virando cerca de 1 USDT numa carteira de 20 USDT), o modo enviava a ordem mesmo assim e a Binance recusava a cada minuto. Agora ele não envia, e o card diz quanto o aporte seria reduzido e o que ajustar (saldo ou limite por ordem do perfil).
- **Quantidade arredondada para cima no passo do par.** O valor da ordem não cai mais abaixo do aporte ou do mínimo do par por causa do arredondamento, e os filtros de pares criados pela sincronização da conta são lidos da Binance antes de ordenar.
- **`php artisan micro:run --explain` confere as mesmas barreiras.** Para cada par com ENTRA, mostra "a ordem sairia no próximo minuto" ou "BLOQUEADO: motivo", sem enviar ordem.

## [0.3.41] - 2026-10-17

### Adicionado

- **A carteira se atualiza sozinha por websocket.** Saldo, posições, ordens, sinais e ciclos de micro operações mudam na tela assim que acontecem no servidor (agendador, fila ou coletor), sem F5 e sem perder o ponto da rolagem.

### Alterado

- **Ações da carteira não recarregam mais a tela inteira.** Confirmar ou descartar um sinal, alterar o saldo inicial, salvar as micro operações e a parada geral atualizam só os blocos afetados e mantêm a rolagem.

## [0.3.40] - 2026-10-17

### Adicionado

- **O card "Micro operações" explica por que nada aconteceu.** Mostra a hora da última execução do motor (com aviso vermelho se ele não rodou nos últimos 3 minutos e o serviço a conferir), o motivo de cada ativo não entrar e, para cada par em foco, o alcance típico em 60 minutos.
- **Aviso de margem alta demais.** Quando o alvo bruto da margem escolhida é maior do que os pares em foco costumam andar em 60 minutos, o card avisa e sugere a margem líquida máxima que cabe. Exemplo: 0,50% líquido vira alvo bruto de 0,80%, que o BTC não alcança em uma hora.
- **Comando `php artisan micro:run --explain`.** Mostra no terminal o estado de cada carteira com o modo ligado e a leitura de cada ativo, sem enviar ordem.

### Alterado

- **Entradas mais frequentes e mais realistas.** Além de perto do suporte, o agente agora também entra num recuo dentro da tendência de alta (preço de volta perto da média de 9 minutos). Em compensação, não entra mais em par cujo alvo bruto não cabe no movimento típico do tempo limite: antes ele comprava e esperava um alvo que não vinha.

## [0.3.39] - 2026-10-17

### Adicionado

- **Previsão própria no ar na produção.** O agente Quantitativo passa a poder usar o modelo de previsão do próprio sistema (sem IA externa) como um serviço permanente, que sobe sozinho e reinicia se cair. O modelo só pesa no voto depois de passar no teste com dados que ele não viu; enquanto não passar, o Quantitativo continua em "aguardar", como hoje.
- **Treino automático toda semana.** Com a previsão ligada, o modelo é treinado de novo todo domingo de madrugada com os candles mais recentes e a API passa a usar a versão nova sem reiniciar.
- **Histórico de cotações para treinar.** Novo comando `market:backfill-binance` baixa da Binance os últimos dias de candles de 1 minuto dos ativos cripto, para o modelo não precisar esperar semanas de coleta. Rodar de novo não duplica nada.

## [0.3.38] - 2026-10-17

### Adicionado

- **Quantidade de operações por dia nas micro operações.** No card "Micro operações" há agora o campo "Operações por dia" (por exemplo, 20 operações de 1 dólar cada: valor por operação 1 e operações por dia 20). Ao completar a quantidade, o agente para até o dia seguinte, e a tela mostra "hoje N de M". Deixar vazio mantém sem limite.

### Alterado

- **Valor mínimo por operação conforme a carteira.** A carteira simulada aceita a partir de 1 por operação. A carteira real exige no mínimo 5 USDT (ordem mínima da Binance no spot) e, se o par pedir um mínimo maior, o agente pula esse par e mostra o motivo em "Último bloqueio". O mínimo aparece no nome do campo.

## [0.3.37] - 2026-10-17

### Alterado

- **O agente de Notícias deixa de ser consultado a cada sinal.** O voto dele passa a ser guardado por ativo e reaproveitado até chegar uma notícia nova para aquele ativo ou até passar o intervalo (6 horas por padrão, ajustável em `AGENTS_NEWS_INTERVAL_MINUTES`; `0` consulta o modelo em todo sinal). O voto reaproveitado não gasta nada de IA, inclusive na rodada de contestação do Cético.
- **Bastam 2 agentes na mesma direção para aprovar um sinal (antes eram 4).** Com poucos agentes úteis em cripto (Técnico, Risco e Cético), exigir 4 fazia todo sinal depender de unanimidade. Os perfis de risco que ainda estavam no padrão antigo passam para 2 sozinhos.
- **O Cético pode usar um modelo mais barato.** Em `AGENTS_SKEPTIC_MODEL` você escolhe o modelo só dele (por exemplo, o Sonnet, que custa metade do Opus). Sem essa opção, nada muda.

## [0.3.36] - 2026-10-17

### Adicionado

- **Micro operações em cripto.** Na tela de uma carteira simulada ou real de cripto há o quadro "Micro operações" (desligado por padrão). Você define o **aporte por operação**, a **margem de lucro líquida** por operação (já descontadas as taxas e o deslize; a tela mostra o custo da ida e volta, cerca de 0,30%, e a margem bruta do alvo), a **meta de lucro do dia** e a **perda máxima do dia**. Ligado, o sistema olha a cada minuto os pares cadastrados, ordena pelos mais voláteis e compra só quando há tendência de alta, preço perto do suporte e espaço até a resistência. A venda sai no alvo (margem mais custos), no stop ou, se o ciclo passar de 60 minutos, a mercado. Ao bater a meta ou a perda do dia, ele para até o dia seguinte. Não chama modelo de IA, então não há custo de IA por operação.
- **Registro de cada ciclo** na carteira: par, preço e hora de entrada, saída, taxas, lucro líquido e motivo da saída (alvo, stop ou tempo limite), mais o resultado do dia e os pares em foco.
- Na carteira real, as micro operações só ligam com a execução automática já confirmada (CONFIRMO) e respeitam sempre o teto por ordem, o teto por dia, a guarda de risco, o "Parar tudo" e o comando `trading:panic`. Como o teto por dia soma compra e venda, aportes grandes esgotam o teto rápido: o modo pausa quando não sobra o valor de um ciclo completo.

## [0.3.35] - 2026-10-17

### Alterado

- **A carteira mostra só o sinal mais recente de cada ativo, em cards que abrem e fecham.** Fechado, o card traz ativo, ação, score, resultado (aprovado, rejeitado, executado), hora e custo. Aberto, mostra os votos dos agentes, quem se absteve, o cálculo do score e os níveis de entrada, stop e alvo. O card mais novo já vem aberto. A lista acompanha os agentes em tempo real: sinal novo, consenso decidido e ordem concluída aparecem sem recarregar a página.

### Adicionado

- **Tela "Histórico de sinais" da carteira.** Pelo link "Ver todos os sinais" você vê todos os sinais da carteira, do mais novo para o mais antigo, com os mesmos cards fechados. Mais sinais carregam sozinhos ao chegar ao fim da lista, e há filtros por ativo e por resultado. Só o dono da carteira acessa.

## [0.3.34] - 2026-10-17

### Adicionado

- **Excluir carteira simulada.** Na lista de carteiras e na tela de cada carteira há o botão "Excluir carteira". Ele pede que você digite o nome da carteira para confirmar e apaga a carteira junto com sinais, votos dos agentes, ordens, posições e histórico, mesmo que ela ainda esteja em execução. Se houver sinal em andamento (aguardando análise, confirmação ou execução) ou ordem pendente, a exclusão é recusada com a explicação: confirme ou descarte os sinais primeiro. Carteira real, como a Conta Binance, não pode ser excluída por aqui. A exclusão fica registrada na auditoria e só o dono da carteira pode fazê-la.
- **Alterar o saldo inicial da carteira simulada.** Na tela da carteira, o botão "Alterar saldo inicial" abre um diálogo para informar o novo valor (mínimo 100). O caixa muda pela diferença e o histórico de patrimônio acompanha, como se o capital sempre tivesse sido o novo; posições, ordens e sinais ficam como estão, e o retorno passa a ser calculado sobre o novo valor. Se o novo valor deixaria o caixa negativo, a alteração é recusada. Carteira real usa o saldo da corretora e não pode ser alterada. A mudança fica registrada na auditoria, com o valor antigo e o novo.

## [0.3.33] - 2026-10-17

### Adicionado

- **O agente Técnico agora lê o gráfico como um investidor experiente.** Antes de votar, o sistema calcula, em 5 minutos, 15 minutos, 1 hora, 4 horas e 1 dia, a tendência, as médias de 9, 21 e 50 períodos, o RSI, a volatilidade (ATR), o volume relativo, os suportes e resistências (com quantas vezes o preço tocou cada um), a faixa de consolidação e os rompimentos, falsos rompimentos e testes de nível. O agente raciocina em cima desses números, sem inventar nível, e devolve o cenário esperado (alta, queda, rompimento de alta, rompimento de baixa, teste de resistência ou lateral), os suportes e resistências que usou, e um stop e um alvo coerentes com a volatilidade. Tempo gráfico sem histórico suficiente fica de fora e o agente avisa; se nenhum tem histórico, ele se abstém.
- **Cenário e níveis no sinal.** No cartão de cada sinal, o voto do Técnico mostra o cenário, os suportes, as resistências, os gráficos usados e os que ficaram sem histórico.

## [0.3.32] - 2026-10-17

### Adicionado

- **Tela "Ativos" para cadastrar criptos da Binance e papéis da B3.** No menu, "Ativos" abre uma pesquisa: em Cripto aparecem os pares em USDT negociáveis na Binance; em B3, os tickers da brapi. O ativo só é salvo depois de confirmado na fonte, e a Binance informa o lote, a variação mínima de preço e a ordem mínima, que ficam guardados. Ativo novo recebe os últimos candles na hora; o coletor de cotações passa a acompanhá-lo em até 30 segundos e o scanner abre sinais para ele no próximo ciclo, sem reiniciar nada.
- **Remover ativo.** Só sai quando não há posição aberta, sinal em andamento, ordem pendente nem carteira acompanhando; a tela mostra o motivo. Se o ativo já tem histórico, ele é desativado em vez de apagado, e pode ser cadastrado de novo depois.
- **Escolha dos ativos de cada carteira.** Na tela da carteira, o card "Ativos acompanhados" deixa o dono marcar quais ativos cadastrados entram nos sinais e nos gráficos daquela carteira. Sem nenhum marcado, a carteira acompanha todos, como antes.

## [0.3.31] - 2026-10-17

### Corrigido

- **Sincronização da Binance não falha mais quando o relógio da máquina está fora de hora.** Em computadores que suspendem (como o WSL no Windows), o relógio podia adiantar ou atrasar e a Binance recusava a chamada com "Timestamp for this request was 1000ms ahead of the server's time". Agora o sistema confere a hora da Binance, ajusta o próprio relógio nas chamadas e, se mesmo assim a Binance reclamar, refaz o ajuste e tenta de novo uma vez. Se ainda falhar, a mensagem explica em português que é preciso acertar o relógio da máquina (no WSL: `sudo hwclock -s`). A tolerância da Binance pode ser mudada com `BINANCE_RECV_WINDOW` no `.env` (padrão 5000 ms).

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
