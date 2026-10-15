# MyMoney

Painel em Laravel 13 + Inertia + Vue onde um grupo de agentes de IA analisa, desafia e aprova cada operação em B3 e cripto. A primeira meta é medir, em carteiras simuladas com valor X por período Y, se o sistema tem vantagem real antes de usar dinheiro de verdade.

## Como funciona

1. Os workers de mercado gravam candles de 1 minuto: `market:sync-binance` (cripto) e `market:sync-b3` (B3, só durante o pregão, via brapi.dev), a cada minuto.
2. O coletor busca notícias por RSS, remove duplicadas e classifica cada uma com um modelo rápido. As relevantes ganham uma análise com o modelo forte e disparam uma rodada de agentes.
3. O scanner (`signals:scan`, a cada 15 minutos) abre um sinal para cada carteira em execução. Ele pula ativos com o mercado fechado ou sem cotação recente, para não gastar chamadas de LLM em ordens que seriam recusadas.
4. Cinco agentes votam (técnico, notícias, fundamentos, quantitativo, risco). O cético lê os votos e publica uma objeção, e cada agente pode rever o voto uma vez.
5. O consenso aprova quando o score ponderado passa do limite, pelo menos 4 votantes concordam e o agente de risco não vetou.
6. Por padrão você confirma ou descarta o sinal no painel. A guarda de risco, escrita em código e sem LLM, ainda pode reduzir ou bloquear a ordem.
7. A ordem vai para o `Broker` da carteira. Simulação e operação real seguem o mesmo caminho; só o broker muda.
8. A posição guarda o sinal de origem, o stop e o alvo. `positions:check-exits` vende tudo quando o preço cruza um dos dois e, ao fim do período, liquida as posições e marca a carteira como finalizada (com um último snapshot). Cada ordem de saída grava o motivo (`stop`, `target`, `period_end`).

## Rodando localmente

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed    # usuário test@example.com / password, 3 criptos e 10 ações
composer run dev              # servidor, fila e Vite
php artisan schedule:work     # em outro terminal: candles, notícias e scanner
```

Sem chave de API os agentes usam `AGENTS_DRIVER=fake` e votam sempre "aguardar", então nenhum sinal é aprovado; os testes trocam o cliente por um que vota de verdade. Para usar Claude, defina `AGENTS_DRIVER=anthropic` e `ANTHROPIC_API_KEY`. Os modelos ficam em `AGENTS_FAST_MODEL` e `AGENTS_STRONG_MODEL`, e o custo de cada voto é gravado em `agent_votes.cost`.

Comandos úteis (os agendados rodam pelo `schedule:work`; a fila precisa estar de pé para notícias e análises):

| Comando                             | O que faz                                                                                     | Agenda               |
| ----------------------------------- | --------------------------------------------------------------------------------------------- | -------------------- |
| `php artisan market:sync-binance`   | Candles de 1 minuto das criptos                                                               | a cada minuto        |
| `php artisan market:sync-b3`        | Candles da B3 (só no pregão; `--force` ignora o horário) e, com `--fundamentals`, fundamentos | a cada minuto; 07:00 |
| `php artisan signals:scan BTCUSDT`  | Abre sinais para um ativo agora (sem argumento, para todos)                                   | a cada 15 minutos    |
| `php artisan positions:check-exits` | Vende posições que bateram stop ou alvo e finaliza carteiras vencidas                         | a cada minuto        |
| `php artisan trading:reconcile`     | Compara ordens reais com a corretora e aciona a parada geral se divergirem                    | a cada 5 minutos     |
| `php artisan portfolios:snapshot`   | Grava o patrimônio diário de cada carteira em execução                                        | 23:55                |
| `php artisan agents:score`          | Recalcula acerto e peso de cada agente no consenso                                            | 00:10                |
| `php artisan trading:halt "motivo"` | Parada geral: nenhuma ordem sai (`--resume` retoma)                                           | manual               |
| `php artisan test`                  | Roda os testes                                                                                | manual               |

As notícias entram por `FetchNewsJob`, uma vez por fonte de `config/news.php`, a cada 5 minutos. Horários da agenda em UTC (`config/app.php`); o pregão da B3 usa `America/Sao_Paulo`.

## Onde está cada parte

| Pasta                           | Conteúdo                                                                    |
| ------------------------------- | --------------------------------------------------------------------------- |
| `app/Agents`                    | Agentes, cenário, rodada de desafio, consenso e clientes de LLM             |
| `app/Trading`                   | Interface `Broker`, `PaperBroker`, guarda de risco, executor e parada geral |
| `app/News`                      | Fontes RSS, deduplicação e triagem de notícias                              |
| `app/MarketData`                | Candles e fundamentos (Binance e brapi.dev)                                 |
| `app/Reports`                   | Snapshots, desempenho, placar dos agentes e relatório de IR                 |
| `config/agents.php`             | Prompts de cada papel, modelos e preços                                     |
| `config/trading.php`            | Brokers, taxas, slippage e horário do pregão                                |
| `resources/js/pages/portfolios` | Telas de carteiras e sinais                                                 |

## O que ainda falta

- Execução real: `BinanceBroker` e `Mt5Broker` existem, mas só enviam ordens de carteira `live`, com credencial verificada, aprovação manual e `TRADING_LIVE_ENABLED=true` (desligado por padrão).
- Provedor definitivo de cotações da B3 (hoje brapi.dev, precisa de `BRAPI_TOKEN`) e escolha entre Binance e Mercado Bitcoin.
- Serviço Python de previsão; o agente quantitativo vota "aguardar" até ele existir.
- Deduplicação de notícias por embedding (hoje só por URL) e pgvector em produção.
- WebSocket com Reverb para gráficos ao vivo; hoje o painel atualiza ao recarregar.
