# MyMoney

Painel em Laravel 13 + Inertia + Vue onde um grupo de agentes de IA analisa, desafia e aprova cada operação em B3 e cripto. A primeira meta é medir, em carteiras simuladas com valor X por período Y, se o sistema tem vantagem real antes de usar dinheiro de verdade.

## Como funciona

1. Os workers de mercado gravam candles de 1 minuto: `market:sync-binance` (cripto, pelo endereço de dados públicos `BINANCE_MARKET_DATA_URL`, padrão `https://data-api.binance.vision`, que responde onde `api.binance.com` devolve 451) e `market:sync-b3` (B3, só durante o pregão, via brapi.dev), a cada minuto.
2. O coletor busca notícias por RSS, remove duplicadas e classifica cada uma com um modelo rápido. As relevantes ganham uma análise com o modelo forte e disparam uma rodada de agentes.
3. O scanner (`signals:scan`, a cada 15 minutos) abre um sinal para cada carteira em execução. Ele pula ativos com o mercado fechado ou sem cotação recente, para não gastar chamadas de LLM em ordens que seriam recusadas.
4. Cinco agentes votam (técnico, notícias, fundamentos, quantitativo, risco). O cético lê os votos e publica uma objeção, e cada agente pode rever o voto uma vez.
5. O consenso aprova quando o score ponderado passa do limite, pelo menos 4 votantes concordam e o agente de risco não vetou.
6. Por padrão você confirma ou descarta o sinal no painel; sinal aprovado e não decidido em `TRADING_SIGNAL_TTL_MINUTES` (60) expira. A guarda de risco, escrita em código e sem LLM, ainda pode reduzir ou bloquear a ordem.
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

### Tempo real (Reverb)

O card "Agentes em ação" da carteira se move só por eventos reais: cada passo do pipeline (agente começou a analisar, agente votou, consenso decidido, ordem enviada) é transmitido pelo Laravel Reverb em um canal privado da carteira (`portfolios.{id}.agents`, evento `agent.activity`). Sem o Reverb o app funciona igual: o card mostra o último ciclo e avisa que o tempo real está fora do ar.

Localmente, além do `composer run dev`, deixe de pé `php artisan reverb:start` e defina no `.env` as variáveis `REVERB_*` e `VITE_REVERB_*` (o `.env.example` traz o modelo; gere `REVERB_APP_ID`, `REVERB_APP_KEY` e `REVERB_APP_SECRET` com valores aleatórios). Os testes usam `BROADCAST_CONNECTION=null` e nunca sobem servidor. Em produção o Reverb é um processo permanente a mais, atrás do proxy do servidor: veja o guia de deploy.

### CI local

Não usamos GitHub Actions: `composer ci` roda `vp check`, Wayfinder, `vue-tsc`, Pint, PHPStan, PHPUnit e o build do Vite. Rode `composer hooks:install` uma vez por clone para o pre-push chamar a CI. Funciona logo depois de `composer install` e `npm install`, sem `.env` nem build prévio. O PHP precisa da extensão `pdo_sqlite` (os testes usam SQLite em memória); no Windows, descomente `extension=pdo_sqlite` no `php.ini`.

## Onde está cada parte

| Pasta                           | Conteúdo                                                                       |
| ------------------------------- | ------------------------------------------------------------------------------ |
| `app/Agents`                    | Agentes, cenário, rodada de desafio, consenso e clientes de LLM                |
| `app/Trading`                   | Interface `Broker`, `PaperBroker`, guarda de risco, executor e parada geral    |
| `app/News`                      | Fontes RSS, embeddings, deduplicação semântica, busca de semelhantes e triagem |
| `app/MarketData`                | Candles e fundamentos (Binance e brapi.dev)                                    |
| `app/Reports`                   | Snapshots, desempenho, placar dos agentes e relatório de IR                    |
| `services/forecast`             | Serviço Python de previsão (desligado por padrão; veja o README da pasta)      |
| `config/agents.php`             | Prompts de cada papel, modelos e preços                                        |
| `config/trading.php`            | Brokers, taxas, slippage e horário do pregão                                   |
| `resources/js/pages/portfolios` | Telas de carteiras e sinais                                                    |

## Fontes

As fontes Geist e Geist Mono (licença SIL OFL 1.1) vêm dos pacotes npm `@fontsource-variable/geist` e `@fontsource-variable/geist-mono`, importados em `resources/css/app.css`. O Vite copia os arquivos woff2 para `public/build`, então nada é baixado de sites externos, nem no build nem em produção. Para atualizar: `npm update @fontsource-variable/geist @fontsource-variable/geist-mono`.

## O que ainda falta

- Execução real: `BinanceBroker` e `Mt5Broker` existem, mas só enviam ordens de carteira `live`, com credencial verificada, aprovação manual e `TRADING_LIVE_ENABLED=true` (desligado por padrão).
- Provedor definitivo de cotações da B3 (hoje brapi.dev, precisa de `BRAPI_TOKEN`) e escolha entre Binance e Mercado Bitcoin.
- Ligar o serviço de previsão (`FORECAST_ENABLED=true`) depois de treinar com `php artisan forecast:train` e conferir o backtest; sem modelo validado, o agente quantitativo vota "aguardar".

## Coletor de cotações por WebSocket

`php artisan market:stream-binance` fica ouvindo o WebSocket público da Binance (endereço em `BINANCE_STREAM_URL`, padrão `wss://data-stream.binance.vision`, que responde onde `stream.binance.com` devolve 451), grava o candle de 1 minuto dos ativos cripto ativos (uma gravação por ativo por segundo) e dispara `CotacaoAtualizada` no canal público `mercado`, que o painel mostra em "Cotações do coletor" quando o Reverb está configurado. Reconecta sozinho com espera crescente. É um processo permanente: em produção precisa de um daemon (por exemplo, no Forge, com reinício automático), configurado pelo Maicon. O `market:sync-binance` por minuto continua como rede de segurança.

## Deploy em produção (Ubuntu no WSL)

A produção é o clone `~/projetos/mymoney.app`, com os serviços systemd de usuário `mymoney-web`, `-queue`, `-schedule`, `-reverb`, `-binance` (e `-forecast`, quando existir). Para publicar a main já mesclada:

```bash
composer deploy                  # ou: deploy/deploy.sh
composer deploy -- --dry-run     # só lista o que seria feito
composer deploy -- v0.3.23       # publica uma tag ou branch em vez da main
```

O script (`deploy/deploy.sh`) roda só em Linux, aborta se houver mudanças locais não commitadas ou se faltar o `.env`, faz `git fetch` e `git pull --ff-only`, instala Composer (`--no-dev`) e npm só quando o `composer.lock` ou o `package-lock.json` mudaram, limpa os caches (`optimize:clear`) antes do `npm run build`, roda `migrate --force`, `optimize` e `queue:restart`, reinicia `mymoney-reverb`, `mymoney-binance` e `mymoney-forecast` quando a unidade está instalada e mostra no fim a versão e o estado dos serviços. A limpeza de caches antes do build é necessária: o Wayfinder gera as rotas TypeScript a partir das rotas do Laravel, e com o cache de rotas antigo o build falha com `"update" is not exported`.

O script **não substitui o `composer ci`**: a CI roda na nuvem antes do PR, e o deploy só publica o que já foi mesclado. Variáveis `VITE_*` entram no bundle, então mudou o `.env`, rode o deploy de novo.

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
