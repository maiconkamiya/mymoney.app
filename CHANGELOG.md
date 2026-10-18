# Changelog

Todas as mudanças do MyMoney.App que quem usa o sistema percebe ficam neste arquivo, escritas para essa pessoa. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e a numeração segue o [SemVer](https://semver.org/lang/pt-BR/): correção sobe o último número, funcionalidade sobe o do meio e quebra de contrato (dados incompatíveis, funcionalidade removida) sobe o primeiro.

A fonte da versão é o campo `version` do `package.json`. A página "Novidades" do sistema mostra as últimas versões deste arquivo. A regra de subida e a checagem estão no `CLAUDE.md`, seção "Versão e release".

## [0.3.81] - 2026-10-18

### Alterado

- **Micro operações em tempo real.** O motor das micro operações não espera mais a rodada de cada minuto: com o serviço novo `mymoney-micro` ligado, o stop e o alvo dos ciclos abertos são conferidos a cada segundo e a venda sai na hora, e as entradas são avaliadas a cada 3 segundos. Quando um ciclo fecha, o par já pode receber o próximo na mesma passada. Os limites do card, o teto diário da carteira real, a guarda de risco e o "Parar tudo" continuam valendo em toda ordem. O card mostra "Motor em tempo real" com a hora da última avaliação; se o serviço parar, a rodada por minuto volta sozinha e o card avisa.

## [0.3.80] - 2026-10-18

### Corrigido

- **O stop das micro operações volta a vender na carteira real.** Com aporte de 5 USDT, a compra ficava só 1% acima do mínimo de 5 USDT da Binance, e qualquer queda maior deixava a venda no stop abaixo do mínimo: a Binance não aceita e a posição ficava presa. Agora a compra é dimensionada para a venda no stop ainda valer o mínimo, com 0,5% de folga para o deslize (com stop de 1% a compra vai a cerca de 5,08 USDT; com aporte maior, nada muda). Se o aporte não permitir isso, o par não entra e Pares em foco e o `micro:run --explain` mostram o motivo "aporte não cobre o stop".
- **Aviso de posição abaixo do mínimo da Binance.** Uma posição que já caiu abaixo do mínimo mostra no card de Micro operações e em Posições abertas: "Abaixo do mínimo da Binance: o app não consegue vender; saída só pelo alvo ou pela conversão de saldo pequeno na Binance". A venda limitada no alvo continua registrada, e o aviso de venda bloqueada no registro de auditoria deixa de se repetir a cada minuto.

## [0.3.79] - 2026-10-18

### Adicionado

- **Clonar a carteira como simulada.** Na lateral da carteira, a seção "Clonar como simulada" cria uma carteira simulada com os mesmos ativos acompanhados e o mesmo card Micro operações (aporte, operações por dia, margem, meta e perda do dia, regra de entrada), começando com o patrimônio de agora em caixa. As micro operações já nascem ligadas e os sinais dos agentes esperam a sua confirmação. Posições, ordens, histórico, credencial da Binance e a execução real ficam na carteira de origem: nenhuma ordem da simulada vai à corretora. Ao confirmar, a tela abre a carteira nova sem recarregar.

## [0.3.78] - 2026-10-18

### Adicionado

- **Regra de entrada "Atual + alta em 24 h e 4 h" nas micro operações.** No card Micro operações, o campo "Regra de entrada" ganha uma terceira opção: a regra atual, com as mesmas saídas, que só compra se o par estiver acima do preço de 24 horas atrás e de 4 horas atrás. Os cards de Pares em foco mostram essa quinta condição com ✓ ou ✗ e a variação de cada período. Na carteira simulada pode ser escolhida já; na carteira real só depois de passar no backtest semanal. As carteiras existentes continuam na regra atual.

## [0.3.77] - 2026-10-18

### Corrigido

- **Taxa da venda no alvo das micro operações.** Quando a venda limitada no alvo executava na Binance, a taxa dela ficava zerada, e o Líquido do histórico, o resultado do dia, a meta e a perda máxima do dia pareciam melhores do que eram. Agora o app lê a taxa que a Binance cobrou, convertida para USDT quando ela vem em BNB ou na própria moeda; se a Binance não informar, conta a taxa padrão de 0,1% em vez de zero. As vendas antigas que ficaram sem taxa são corrigidas uma vez com `php artisan binance:fill-fees` (use `--dry-run` para ver antes o que muda).

## [0.3.76] - 2026-10-18

### Corrigido

- **O enxame não vende mais a posição de uma micro operação em andamento.** Enquanto um ciclo das micro operações está aberto num par, os agentes não analisam esse par na carteira, e quem decide a venda é o alvo, o stop e o tempo do ciclo. Hoje o enxame chegou a vender uma micro operação 2 segundos depois da compra. Se uma venda for aprovada pelos agentes nesse meio tempo, ela aparece no card de Sinais como rejeitada com o motivo "Ciclo micro aberto" e nada é vendido. Fim de negociação, "Parar tudo" e os botões "Liquidar com lucro" e "Liquidar agora" continuam vendendo. Também poupa tokens. Dá para voltar ao comportamento antigo em Configurações > Custos e serviços, chave "Enxame espera o ciclo micro", que começa **ligada**.

## [0.3.75] - 2026-10-18

### Corrigido

- **Erro 500 na carteira e vendas das micro operações paradas.** Quando a corretora recusava a venda limitada de uma micro operação, a página da carteira passava a dar erro 500 e o robô das micro operações parava de registrar vendas a cada minuto. Agora o motivo da recusa aparece no card como antes, a venda é tentada de novo no prazo certo e a carteira volta a abrir sozinha depois da atualização, sem nenhum passo manual.

## [0.3.74] - 2026-10-18

### Adicionado

- **Página do ativo, como na Binance.** Na tela Ativos, clicar num par de cripto abre a página dele com o **Histórico de preços** em 24h, 7d, 1m, 3m e 1y: a variação de cada período, o gráfico, a abertura, a máxima e a mínima. Os dados vêm das velas públicas da Binance, sem custo.
- **Sentimento do mercado cripto.** A mesma página mostra o índice Fear & Greed de 0 (medo extremo) a 100 (ganância extrema), com o valor de ontem, da semana passada e do mês passado. Vale para o mercado cripto inteiro e muda uma vez por dia. Gratuito.
- **"Por que o ativo está em alta ou em queda hoje?"** Uma análise curta escrita pela IA (Haiku) com as velas, o sentimento e as notícias coletadas, todo dia às 11:00 UTC para os ativos acompanhados e pelo botão "Atualizar análise". Começa **desligada**: liga em Configurações > Custos e serviços, chave "Análise do dia dos ativos". Custa cerca de US$ 0,0005 por análise e não depende da chave "Agentes em ação", porque não opera nada.

## [0.3.73] - 2026-10-18

### Alterado

- **Placar dos agentes some com os agentes desligados.** Com a chave "Agentes em ação" desligada em Configurações > Custos e serviços, o "Placar dos agentes" sai da seção Desempenho da carteira. Ao religar a chave, ele volta sem recarregar a página.

## [0.3.72] - 2026-10-18

### Adicionado

- **Data de fim da negociação do ativo.** Na tela Ativos, a coluna "Fim da negociação" recebe a data em que a Binance vai deixar de negociar o par. A partir da 00:00 UTC desse dia (quando a corretora costuma suspender o par) o app não compra mais o ativo, não o manda aos agentes, às micro operações nem ao explorador, cancela a venda limitada pendente e vende a mercado o que houver em todas as carteiras, reais e simuladas. A venda entra no histórico com o motivo "Fim de negociação". Só o administrador do sistema define ou tira a data.
- **Aviso do ativo em encerramento.** A lista de Ativos marca o par com "Encerra em" ou "Negociação encerrada"; na carteira, a posição aberta e os "Ativos acompanhados" mostram a data. Quando o saldo fica abaixo do mínimo da corretora (5 USDT) e a venda não sai pela API, a posição mostra o motivo em vez de falhar em silêncio.

## [0.3.71] - 2026-10-18

### Adicionado

- **Chave "Agentes em ação" em Configurações > Custos e serviços.** Desligada, o enxame de IA para de analisar pares: nem a varredura, nem o gatilho de movimento de preço, nem as notícias, nem o explorador chamam mais os agentes, e o gasto de IA dos sinais vai a zero. Na carteira somem os cards "Agentes em ação", "Tokens da IA" e "Sinais" (este fica enquanto houver sinal aprovado esperando) e, na carteira simulada, a seção "Execução dos sinais"; no lugar fica uma linha discreta com o link para a configuração. Na tela Ativos, "Mandar aos agentes" e o envio automático saem de cena. As micro operações não usam IA e seguem iguais, e as posições já abertas pelos agentes continuam com stop e alvo. Ligar ou desligar vale na hora, e a carteira aberta se atualiza sem recarregar a página. A chave começa ligada.

## [0.3.70] - 2026-10-18

### Adicionado

- **Teste das micro operações com o histórico.** O comando `php artisan micro:backtest` refaz, minuto a minuto, o que o motor teria feito nas velas guardadas (30 dias por padrão), com taxa e deslize, e diz por par quantas entradas teriam saído, quantas no alvo, no stop e no tempo, e o resultado líquido médio por operação. A regra só passa se ganhar na parte final do período (que ela não "viu" antes), ganhar de entradas sorteadas e ficar positiva em pelo menos 3 de 4 janelas. O teste roda sozinho todo domingo às 05:00 e guarda o resultado.
- **Nova regra de entrada "Tendência com recuo".** No card Micro operações, o campo "Regra de entrada" escolhe entre a regra atual (velas de 1 minuto) e a nova, que só compra no recuo de uma alta de 1 hora, com alvo e stop pela volatilidade de 15 minutos e até 4 horas por ciclo. O card mostra o último teste de cada regra. Na carteira real a regra nova só pode ser escolhida depois de passar no teste; na simulada pode ser escolhida a qualquer momento. A regra atual continua como está.

## [0.3.69] - 2026-10-18

### Alterado

- **Poeira e moedas fora do app viram uma linha com tooltip.** Na seção "Posições abertas" da carteira real, a lista de moedas em poeira e das moedas sem cotação (como BRL e ETHW) sai do texto corrido: aparece só "Poeira: N ativo(s), X USDT" e "Fora do app: N ativo(s)", e a lista completa abre no ícone de informação.
- **O detalhe do total da conta Binance também abre em tooltip**, no ícone ao lado de "Total na conta Binance". No computador abre ao passar o mouse; no celular, com um toque. Listas longas rolam dentro do tooltip.

## [0.3.68] - 2026-10-18

### Alterado

- **Pares em foco em cards.** Nas micro operações, cada par em foco virou um card com a faixa média por minuto e o alcance em 60 minutos em destaque, uma barra que compara a volatilidade entre os pares e o estado do par: pronto para entrar, bloqueado (com o motivo), falta 1 condição ou faltam várias. O card lista as quatro condições de entrada (tendência de alta, perto do suporte ou recuo, alvo alcançável e espaço até a resistência), marca as que passam e mostra os números das que barram a entrada. Antes aparecia só o primeiro motivo. Os cards se atualizam sozinhos a cada minuto, sem recarregar a tela.

## [0.3.67] - 2026-10-18

### Alterado

- **Gráficos novos, feitos com o ApexCharts.** Os gráficos de "Ativos em tempo real" e de "Desempenho" na carteira, e o gráfico do mercado no Painel e na página inicial, ganharam o mesmo visual, com dica ao passar o mouse mostrando a hora e o preço (ou a data e o patrimônio). Seguem o tema claro ou escuro e continuam se atualizando sozinhos, sem recarregar a página.
- **Linha ou velas nos ativos da carteira.** Um seletor "Linha | Velas" em "Ativos em tempo real" troca todos os gráficos entre a linha de fechamento e as velas de 1 minuto; a escolha fica lembrada no navegador.
- **Preço médio, stop, alvo (ou venda) e teto sempre à vista.** O gráfico ajusta a escala para que essas linhas nunca fiquem fora dele, e os rótulos ganharam fundo para não serem cortados pela linha do preço.

## [0.3.66] - 2026-10-18

### Adicionado

- **Custos e serviços em Configurações.** Uma página nova liga e desliga o que gasta IA e as rotinas sem uso, sem deploy: modelo do Cético (Opus ou Sonnet), intervalo da varredura do enxame (15, 30 ou 60 min), Risco só quando há compra ou venda na primeira rodada, pular o Cético quando a regra de entrada tardia já barraria a compra, cenário compacto (menos tokens), frase do explorador, explorador de oportunidades, serviço de previsão, cotações da B3 e notícias da B3. Cada chave explica quanto economiza e o que se perde. Nada muda até alguém mexer: tudo começa como estava. Só o administrador do sistema altera, e cada mudança fica no registro de auditoria. A guarda de risco, os limites de dinheiro real e o Parar tudo não mudam por aqui.

## [0.3.65] - 2026-10-18

### Adicionado

- **Tokens da IA na carteira.** Abaixo de "Agentes em ação", um quadro mostra quantos tokens os agentes gastaram nos sinais da carteira hoje, na semana, no mês e a estimativa do mês (a média diária do mês projetada até o último dia), com o custo em dólar e o número de chamadas. Passe o mouse para ver entrada e saída separadas. Os números se atualizam sozinhos a cada voto novo, sem recarregar a tela, e contam desde a primeira chamada gravada na carteira. O explorador de oportunidades e a triagem de notícias são da conta toda e não entram nesse total.

## [0.3.64] - 2026-10-18

### Alterado

- **Micro operações de volta ao conteúdo principal da carteira.** O card das micro operações saiu da coluna à direita e fica logo abaixo de "Ativos em tempo real", sempre aberto. A coluna à direita continua com os outros cards, e a seção aberta de início passa a ser "Posições abertas".

## [0.3.63] - 2026-10-18

### Corrigido

- **Ativo marcado em "Ativos acompanhados" aparece nos gráficos sem F5.** Ao salvar, o card "Ativos em tempo real" busca a lista nova na hora, passa a receber as cotações do par novo e troca a série curta pelo histórico assim que as velas dele terminam de baixar.
- **A lista de ativos para marcar se atualiza sozinha.** Par cadastrado ou removido na tela Ativos, ou acompanhado pelo explorador, entra e sai da lista de todas as carteiras do mercado sem recarregar a tela, e os gráficos das carteiras que acompanham todos os ativos também mudam.
- **O placar dos agentes se atualiza na tela** quando a taxa de acerto e o peso são recalculados, sem recarregar.

## [0.3.62] - 2026-10-18

### Adicionado

- **Total na conta Binance abaixo do Patrimônio.** Na carteira real, o card "Patrimônio" mostra quanto a conta Binance tem no total, somando todas as carteiras dela (Spot, Fundos, Earn, Margem, Futuros, robôs), o mesmo que o saldo estimado da Binance. Em "Por que é diferente?" aparece de onde vem a diferença: poeira abaixo de 1 USDT, moedas que o app não negocia, cada outra carteira e a variação de cotação desde a última sincronização. O patrimônio do app continua sendo o que ele opera na Spot.
- **Alterar o valor investido na carteira real.** O botão "Alterar valor investido" corrige a base do "Resultado", que antes ficava com o saldo da primeira sincronização (por exemplo 1,02 USDT, antes do aporte). Caixa, posições e histórico continuam vindo da Binance, e a mudança fica registrada com o valor antigo e o novo.

### Corrigido

- **A base do resultado aparece com 2 casas** em vez de 8 (1,02 em vez de 1,0199052).
- **A perda do dia passa a contar a partir do patrimônio da noite anterior.** Antes, até o registro diário das 23h55, ela era medida contra o saldo inicial da carteira. Na carteira real, o valor investido também não entra mais no pico de patrimônio usado pela guarda de risco.

## [0.3.61] - 2026-10-18

### Adicionado

- **Sincronizar a carteira também traz as ordens da Binance.** O botão "Sincronizar agora" e a sincronização automática de 5 em 5 minutos leem as últimas 20 ordens de cada par da carteira (ativos na conta, ativos acompanhados e pares negociados pelo app na última semana). A seção "Posições abertas" mostra a lista "Ordens na Binance" com lado, tipo, quantidade executada, preço, valor, data e estado (aberta, executada, parcial, cancelada, expirada ou recusada), diz se a ordem é do app ou foi feita fora dele e permite filtrar por par. Se a Binance recusar a leitura de um par, o motivo aparece e os saldos sincronizam do mesmo jeito.
- **Venda registrada que a Binance executou entra na sincronização.** Antes de ler os saldos, a sincronização confere todas as vendas limitadas do app ainda abertas: a executada fecha a posição com o resultado real e a cancelada sai da lista, também com a parada geral ligada.

## [0.3.60] - 2026-10-18

### Corrigido

- **A comparação de variações do modelo de previsão deixa de parar na segunda variação.** Na primeira janela do teste algumas variáveis ainda não têm nenhum valor, e o cálculo falhava. Agora essas variáveis são ignoradas naquela janela e a tabela completa é impressa.

## [0.3.59] - 2026-10-18

### Corrigido

- **Venda por sinal dos agentes recusada pela Binance (quantidade fora do passo do par).** A venda pedida pelos agentes ia com a quantidade exata da posição, como 39,922 DUSK, e a Binance recusava porque o DUSK só aceita moedas inteiras. Agora toda venda sai arredondada para baixo no passo do par (39 DUSK), e a venda registrada da posição é cancelada antes, para liberar as moedas.
- **Venda abaixo do mínimo de 5 USDT não é mais enviada.** Venda por sinal, stop, tempo limite e "Liquidar agora" conferem o valor das moedas inteiras contra o mínimo do par antes de enviar. Abaixo dele, nada é cancelado nem enviado e o motivo aparece no sinal ou no aviso do botão.
- **O card diz por que uma posição está sem venda registrada.** Quando o modo não registra a venda de uma posição, a linha mostra o motivo: parada geral ou "Parar tudo" ligados, micro operações desligadas, execução real sem CONFIRMO, ativo fora dos acompanhados, posição aberta por sinal dos agentes ou marcada em "Liquidar com lucro".

## [0.3.58] - 2026-10-18

### Alterado

- **Carteira com sidebar.** A tela da carteira ocupa a largura toda da janela. "Ativos em tempo real", "Agentes em ação", "Desempenho" e "Sinais" ficam no conteúdo principal; os demais cards (execução com dinheiro real, micro operações, posições abertas, ativos acompanhados, execução dos sinais e excluir carteira) passam para uma coluna à direita, em seções que abrem uma por vez. A tela lembra a seção aberta em cada carteira. Em telas estreitas a coluna vai para baixo do conteúdo. Nada muda no funcionamento dos cards, e eles continuam atualizando em tempo real mesmo fechados.

## [0.3.57] - 2026-10-18

### Adicionado

- **Explorador de oportunidades na tela Ativos.** A cada 15 minutos o sistema olha todos os pares em USDT da Binance e mostra as 10 melhores oportunidades fora dos ativos acompanhados, com nota, motivo, volume e spread. Ficam de fora moedas estáveis, tokens alavancados, pares com pouco volume ou spread largo e altas que já dispararam ou estão esticadas. Uma IA barata explica cada par em uma frase.
- **Acompanhar ou mandar aos agentes com um clique.** "Acompanhar" coloca o par nos ativos acompanhados da carteira escolhida e baixa 30 dias de velas. "Mandar aos agentes" pede uma análise completa do par uma vez, sem acompanhar.
- **Envio automático das 3 melhores, por carteira.** Ligando a opção na tela Ativos, as 3 melhores de cada varredura vão sozinhas aos agentes daquela carteira, com nota mínima, no máximo 12 envios por dia e uma vez a cada 6 horas por par. O explorador nunca compra: a compra, se vier, passa pelo consenso, pela guarda de risco e pelos limites da carteira real.
- **Pares achados não entram sozinhos nas carteiras.** Um par cadastrado só pelo explorador aparece como "Achado", não entra nas carteiras que acompanham todos os ativos nem nas micro operações, e sai sozinho depois de 24 horas fora do topo se ninguém o acompanhar.

## [0.3.56] - 2026-10-18

### Adicionado

- **Os agentes passam a estimar o teto provável de uma alta.** A leitura do gráfico calcula até onde o preço tende a ir (a resistência mais próxima; sem ela, a máxima recente; sem ela, o preço mais 2 ATR), a folga até esse teto, o quanto o preço já está acima da média de 21 e desde o último fundo, e se o volume cai enquanto o preço sobe. Os agentes de Risco e o Cético agora recebem esses números.
- **Compra tardia é rejeitada.** Um sinal de compra aprovado pelos agentes é rejeitado com o motivo "Entrada tardia" quando o ganho até o teto provável é menor que 1,5 vez o risco até o stop, ou quando o preço está mais de 3 ATR acima da média de 21. Vale só para os sinais dos agentes; as micro operações seguem como antes.
- **Teto no sinal e no gráfico.** O card do sinal mostra o teto provável ao lado de entrada, stop e alvo, e "Como o score foi calculado" mostra a conta da entrada tardia. Os gráficos da carteira desenham a linha "teto" do último sinal de compra das últimas 2 horas.

## [0.3.55] - 2026-10-18

### Adicionado

- **Chance de alcançar o alvo em cada posição das micro operações.** Cada posição aberta no card mostra "Chance de alcançar o alvo de X em N min", quanto falta em % e em faixas médias de 1 minuto, e o motivo. A conta usa as velas de 1 minuto dos últimos 30 dias do próprio par: quantas vezes, partindo de momentos com volatilidade parecida com a de agora, o preço subiu a mesma distância dentro do tempo que resta do ciclo (ou 60 minutos para posição sem ciclo). É uma frequência do passado, não uma previsão, e não usa IA. Atualiza a cada minuto sem recarregar a página.
- **Botão "Liquidar agora".** Vende a posição inteira a mercado pelo preço de agora, mesmo com prejuízo. Antes de vender, a tela mostra quanto você recebe e o resultado estimado com a taxa. A venda registrada na corretora é cancelada antes, a guarda de risco e o "Parar tudo" continuam valendo, e a saída aparece no histórico como "Liquidado à mão". Vale para posição com ou sem ciclo.

## [0.3.54] - 2026-10-18

### Adicionado

- **Comparação de variações do modelo de previsão.** Novo modo do treino (`php artisan forecast:train --experiments`) que testa, no mesmo backtest e com os mesmos critérios, o modelo com probabilidades calibradas, com variáveis extras, com filtro de volatilidade e com horizontes de 30, 60 e 240 minutos. Mostra uma tabela com acerto, erro de probabilidade, número de operações e retorno líquido por janela, e diz se alguma variação passou. Não grava modelo nem muda o que o Quantitativo vota hoje.

## [0.3.53] - 2026-10-18

### Corrigido

- **O treino do modelo de previsão volta a funcionar.** O treino parava com erro ao ler as datas das velas e das notícias, então o modelo nunca era retreinado pelo comando nem na rotina de domingo. Agora as datas são lidas corretamente, mesmo quando vêm com fusos diferentes.

## [0.3.52] - 2026-10-18

### Corrigido

- **Toda posição aberta das micro operações tem venda registrada na Binance.** Antes, só a compra feita pelo próprio modo ganhava a ordem de venda, e uma única vez. Agora, a cada minuto, toda posição aberta de um ativo acompanhado fica com uma venda limitada registrada: a do ciclo no alvo e a posição que veio da conta (ou cuja venda foi recusada) pelo custo pago mais o custo da ida e volta mais a margem líquida do card. Ativos marcados em "Liquidar com lucro" e posições abertas por sinal dos agentes seguem como antes.
- **Venda recusada aparece no card e é tentada de novo.** O card mostra "Venda registrada na corretora a X" ou o motivo da recusa e a hora da próxima tentativa. A primeira nova tentativa sai no minuto seguinte; se a Binance continuar recusando, o intervalo cresce até 15 minutos.
- **Venda recusada por quantidade fora do passo do par.** Com duas posições abertas do mesmo par, a venda era limitada pela posição errada e saía com quantidade quebrada, que a Binance recusa. Agora a venda é limitada pela própria posição e arredondada para baixo no passo do par.
- **Ciclo encerrado sem venda não mostra resultado inventado.** Quando as moedas saem da conta sem venda feita pelo sistema, o ciclo aparece como "sem venda no app" e "Saiu da conta", sem preço de saída nem lucro ou prejuízo.

## [0.3.51] - 2026-10-18

### Corrigido

- **Venda de um ciclo antigo fechando o ciclo novo das micro operações.** Quando a venda registrada de um ciclo que já tinha sido encerrado executava na Binance, o sistema lançava essa venda no ciclo aberto do mesmo par e fechava esse ciclo como "Alvo", com o preço da venda antiga e um prejuízo que não existiu. Agora cada venda só conta para a posição que a registrou, e o ciclo fecha com a própria venda.
- **Parada geral acionada sozinha a cada 5 minutos.** A venda que executava sem posição aberta ficava sem a execução registrada, e a conferência com a Binance via divergência e acionava a parada geral de novo. A execução agora é sempre registrada, e a atualização corrige as vendas que ficaram assim. Depois dela, a parada pode ser desligada na tela.
- **Consulta às ordens durante a parada geral.** Com a parada ligada, o sistema não conseguia nem consultar as ordens na Binance, e as vendas registradas que executavam nesse período não eram lançadas. Consultar continua permitido; só o envio de ordens fica bloqueado.

## [0.3.50] - 2026-10-18

### Corrigido

- **"Sincronizar agora" não recarrega mais a tela inteira.** A carteira fica onde estava e só os blocos que mudam (saldo, posições, sincronização com a Binance, micro operações) são atualizados. O mesmo vale para "Executar sinais aprovados sem confirmação", os botões da execução real ("Parar tudo", liberar ordens, CONFIRMO, aviso por e-mail) e o "Salvar" dos ativos acompanhados. Se a sincronização falhar, o motivo aparece ao lado do botão.
- **Telas abertas a partir da carteira também não recarregam.** O filtro do histórico de sinais e os botões da tela da Binance (sincronizar, trocar e remover a chave) mantêm a rolagem e o que estava na tela.

## [0.3.49] - 2026-10-18

### Corrigido

- **Linhas dos gráficos em tempo real acompanham as micro operações.** O preço médio e o stop dos gráficos de "Ativos em tempo real" só mudavam com F5. Agora, quando a micro operação compra, registra a venda, muda o stop ou fecha o ciclo, as linhas se redesenham sozinhas na hora, sem recarregar a página.

### Adicionado

- **Linha do alvo no gráfico.** Cada posição aberta mostra também a linha verde do preço de saída: "venda" com o preço da ordem registrada na Binance, ou "alvo" quando ainda não há ordem registrada.

## [0.3.48] - 2026-10-18

### Corrigido

- **Posições da Conta Binance fechando e reabrindo sozinhas.** A cada sincronização da conta, as moedas que continuavam no saldo eram encerradas com o motivo "conta" e voltavam na rodada seguinte com o preço do momento como preço médio. Isso perdia o custo real da compra e encerrava ciclos das micro operações como se tivessem sido vendidos. Agora a posição continua a mesma enquanto a moeda estiver na conta, com o preço médio original, e só é encerrada quando a moeda sai do saldo. A sincronização automática e o botão "Sincronizar" também não rodam mais ao mesmo tempo na mesma carteira.

## [0.3.47] - 2026-10-18

### Corrigido

- **A carteira atualiza sozinha, sem F5.** A hora da última execução do motor, o motivo de cada par em foco e o "Liquidar com lucro" agora se atualizam a cada minuto. Mudanças no caixa feitas por uma ordem, o "Parar tudo", os ativos acompanhados alterados em outra aba e o patrimônio do gráfico de desempenho também chegam na hora.
- **Preço de agora nas posições abertas.** A tabela de posições ganhou as colunas "Preço agora" e "Resultado", e o valor da posição e o patrimônio andam junto com a cotação recebida do servidor, sem recarregar. Na carteira simulada o valor da posição deixou de aparecer vazio.
- **Cotações sem atraso.** As cotações dos gráficos não esperam mais as rodadas dos agentes para chegar à tela.

### Adicionado

- **Indicador de conexão em tempo real.** No topo da carteira aparece "Tempo real", "Conectando", "Sem tempo real" ou "Canal recusado". Se a conexão cair, a tela se atualiza a cada 30 segundos até ela voltar e, quando volta, recarrega tudo o que pode ter mudado no intervalo. Voltar para a aba depois de um tempo também recarrega.
- **Aviso do motor parado sem F5.** Se o motor das micro operações parar de avisar por mais de 3 minutos, o aviso de agendador parado aparece sozinho.

## [0.3.46] - 2026-10-17

### Alterado

- **A venda das micro operações fica registrada logo depois da compra.** Assim que a compra é executada, o sistema já deixa uma ordem de venda limitada no preço do alvo (custo de compra mais a margem de custo e a margem de lucro), aberta na Binance até executar. Na carteira simulada a venda acontece no preço do alvo quando a cotação chega lá. O card mostra "venda registrada a" com o preço. O stop e o tempo limite de 60 minutos continuam valendo: quando disparam, cancelam a venda registrada antes de vender a mercado. A sincronização da conta não encerra mais um ciclo que tem venda registrada aberta.
- **"Operações por dia" conta só as compras.** A venda no alvo faz parte da mesma operação e não gasta o limite.

## [0.3.45] - 2026-10-17

### Adicionado

- **Liquidar com lucro nas micro operações.** O card "Micro operações" lista as posições da carteira com o botão "Liquidar com lucro". Marcado, o ativo é vendido assim que o preço cobrir o custo de compra e o custo da ida e volta, e o modo não compra mais esse ativo até você clicar em "Voltar a comprar". O card mostra o custo, o preço a partir do qual vende e o preço de agora. Vale mesmo com as micro operações desligadas; na carteira real exige a execução automática confirmada (CONFIRMO).

### Corrigido

- **Vendas na carteira real que a Binance recusava.** A venda agora usa o saldo livre da moeda na conta (a Binance cobra a taxa da compra na própria moeda, então sobra um pouco menos do que foi comprado) e arredonda a quantidade para baixo no passo do par. A sobra de menos de 1 USDT que não dá para vender encerra a posição. Vale para alvo, stop, tempo limite e liquidação.

## [0.3.44] - 2026-10-17

### Corrigido

- **A previsão própria agora chega de fato ao agente Quantitativo.** O sistema enviava ao serviço de previsão uma vela a menos do que ele precisa, então toda consulta era recusada e o cenário ficava sem previsão. Agora são enviadas as 61 velas mais recentes. Os demais agentes continuam vendo as mesmas 60 velas de antes.

## [0.3.43] - 2026-10-17

### Adicionado

- **Aporte da carteira nas micro operações.** Novo campo no card "Micro operações", de 1 a 100%, com a parte do saldo em USDT que o modo pode manter em ciclos abertos. O card mostra quanto está em uso. Cada venda devolve o saldo e libera novas compras.

### Alterado

- **A ordem da micro operação tem o valor que você definiu.** Com 5 USDT por operação, cada compra sai de 5 USDT enquanto houver saldo e aporte da carteira, sem o corte de 5% por ordem do perfil de risco (que continua valendo para os sinais dos agentes). Seguem valendo o saldo disponível, o máximo por ordem e por dia da conta real, a perda do dia, o limite de posições abertas e o "Parar tudo".

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
