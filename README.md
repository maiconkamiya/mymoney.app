# MyMoney

Painel em Laravel 13 + Inertia + Vue onde um grupo de agentes de IA analisa, desafia e aprova cada operação em B3 e cripto. A primeira meta é medir, em carteiras simuladas com valor X por período Y, se o sistema tem vantagem real antes de usar dinheiro de verdade.

## Como funciona

1. O worker de mercado grava candles de 1 minuto (`market:sync-binance`, a cada minuto).
2. O coletor busca notícias por RSS, remove duplicadas e classifica cada uma com um modelo rápido. As relevantes ganham uma análise com o modelo forte e disparam uma rodada de agentes.
3. O scanner (`signals:scan`, a cada 15 minutos) abre um sinal para cada carteira em execução.
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

Sem chave de API os agentes usam `AGENTS_DRIVER=fake` e votam sempre "aguardar". Para usar Claude, defina `AGENTS_DRIVER=anthropic` e `ANTHROPIC_API_KEY`. Os modelos ficam em `AGENTS_FAST_MODEL` e `AGENTS_STRONG_MODEL`, e o custo de cada voto é gravado em `agent_votes.cost`.

Comandos úteis:

| Comando                             | O que faz                                                                                  |
| ----------------------------------- | ------------------------------------------------------------------------------------------ |
| `php artisan signals:scan BTCUSDT`  | Abre sinais para um ativo agora                                                            |
| `php artisan trading:halt "motivo"` | Parada geral: nenhuma ordem sai                                                            |
| `php artisan trading:halt --resume` | Retoma a negociação                                                                        |
| `php artisan positions:check-exits` | Vende posições que bateram stop ou alvo e finaliza carteiras vencidas (roda a cada minuto) |
| `php artisan test`                  | Roda os testes                                                                             |

## Onde está cada parte

| Pasta                           | Conteúdo                                                                       |
| ------------------------------- | ------------------------------------------------------------------------------ |
| `app/Agents`                    | Agentes, cenário, rodada de desafio, consenso e clientes de LLM                |
| `app/Trading`                   | Interface `Broker`, `PaperBroker`, guarda de risco, executor e parada geral    |
| `app/News`                      | Fontes RSS, embeddings, deduplicação semântica, busca de semelhantes e triagem |
| `app/MarketData`                | Candles e cotações da Binance                                                  |
| `config/agents.php`             | Prompts de cada papel, modelos e preços                                        |
| `config/trading.php`            | Brokers, taxas, slippage e horário do pregão                                   |
| `resources/js/pages/portfolios` | Telas de carteiras e sinais                                                    |

## O que ainda falta

- `BinanceBroker` e `Mt5Broker` recusam qualquer ordem. Eles entram na fase 5 da especificação.
- Cotações da B3, leilões, feriados e o agente de fundamentos com dados reais (fase 4).
- Serviço Python de previsão; o agente quantitativo vota "aguardar" até ele existir.
- Snapshots diários de patrimônio, relatório de desempenho, acerto por agente e relatório de IR (fase 3).
- WebSocket com Reverb para gráficos ao vivo; hoje o painel atualiza ao recarregar.

## Notícias: embeddings, deduplicação e pgvector

Cada notícia coletada ganha um embedding (título mais o começo do corpo) antes da triagem pelo LLM. Se ela for semanticamente parecida com uma notícia das últimas `NEWS_DEDUP_WINDOW_HOURS` horas (similaridade de cosseno >= `NEWS_DEDUP_THRESHOLD`), é gravada com `duplicate_of_id` e não passa pela triagem. O agente de notícias também recebe em `similarNews` notícias parecidas do passado e quanto o preço andou nas 24 horas seguintes (nunca com dados posteriores a `now()`).

| Variável                     | Padrão                                   | Para que serve                                                  |
| ---------------------------- | ---------------------------------------- | --------------------------------------------------------------- |
| `NEWS_EMBEDDINGS_DRIVER`     | `fake`                                   | `fake` (sem chave, usado nos testes) ou `http` (formato OpenAI) |
| `NEWS_EMBEDDINGS_KEY`        |                                          | Chave do provedor (ex.: Voyage AI), só no `.env`                |
| `NEWS_EMBEDDINGS_URL`        | `https://api.voyageai.com/v1/embeddings` | Endpoint de embeddings                                          |
| `NEWS_EMBEDDINGS_MODEL`      | `voyage-3`                               | Modelo do provedor                                              |
| `NEWS_EMBEDDINGS_DIMENSIONS` | `1024`                                   | Tamanho do vetor; precisa bater com o modelo e com a coluna     |
| `NEWS_DEDUP_THRESHOLD`       | `0.92`                                   | Limiar de similaridade para considerar duplicata                |
| `NEWS_VECTOR_STORE`          | `auto`                                   | `auto`, `memory` ou `pgvector`                                  |

**Ativar o pgvector (produção):** use Postgres com a extensão disponível (imagem `pgvector/pgvector` ou `CREATE EXTENSION vector` com permissão), configure `DB_CONNECTION=pgsql` e rode `php artisan migrate`. A migração converte `news_items.embedding` para `vector(NEWS_EMBEDDINGS_DIMENSIONS)` e cria um índice HNSW de cosseno; a busca passa a usar o operador `<=>` do banco. Defina as dimensões antes de migrar: trocar de modelo depois exige recriar a coluna e os embeddings. Em SQLite (testes e desenvolvimento) o embedding fica em JSON e a busca é uma varredura em memória das últimas 2000 notícias.
