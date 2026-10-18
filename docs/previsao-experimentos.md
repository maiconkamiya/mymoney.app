# Previsão: resultado dos experimentos (issue #121)

Rodado em 2026-10-18 na produção com `php artisan forecast:train --experiments`: 298.318 velas de 1 minuto dos últimos 30 dias, 7 pares, backtest walk-forward em 4 janelas, custo de ida e volta de 0,20% (20 bps) e os quatro critérios de validação de `services/forecast/forecast/backtest.py` (mínimo de 30 operações, acurácia pelo menos 1 ponto acima da linha de base, Brier menor que o da linha de base e retorno líquido médio positivo).

## Resultado

**Nenhuma das 13 variações passou nos quatro critérios.** O modelo segue não validado e o Quantitativo segue votando "aguardar" com confiança zero, que é o comportamento seguro.

| variação | acurácia | base | Brier | Brier base | operações | líquido/operação | líquido por janela |
|---|---|---|---|---|---|---|---|
| base (como em produção) | 52,4% | 51,9% | 0,2829 | 0,2493 | 132.794 | -0,180% | -0,19 / -0,17 / -0,19 / -0,14 |
| calibrado | 52,4% | 51,9% | 0,2757 | 0,2493 | 147.779 | -0,180% | -0,18 / -0,18 / -0,19 / -0,17 |
| calibrado, limiar 0,60 | 52,4% | 51,9% | 0,2757 | 0,2493 | 96.399 | -0,178% | -0,18 / -0,16 / -0,18 / -0,12 |
| calibrado, limiar 0,65 | 52,4% | 51,9% | 0,2757 | 0,2493 | 82.368 | -0,182% | -0,18 / -0,15 / -0,24 / -0,09 |
| filtro de volatilidade | 52,4% | 51,9% | 0,2829 | 0,2493 | 106.703 | -0,174% | -0,18 / -0,17 / -0,19 / -0,14 |
| variáveis extras | 52,4% | 51,9% | 0,2800 | 0,2493 | 157.864 | -0,184% | -0,19 / -0,19 / -0,20 / -0,16 |
| extras + calibrado | 52,3% | 51,9% | 0,2751 | 0,2493 | 162.167 | -0,185% | -0,18 / -0,19 / -0,20 / -0,18 |
| horizonte 30 | 52,5% | 52,1% | 0,2860 | 0,2499 | 143.003 | -0,180% | -0,19 / -0,17 / -0,20 / -0,16 |
| horizonte 60 | 51,9% | 51,3% | 0,2737 | 0,2502 | 134.817 | -0,169% | -0,16 / -0,23 / -0,17 / -0,11 |
| horizonte 240 | 50,5% | 49,4% | 0,2692 | 0,2647 | 144.957 | -0,122% | -0,04 / -0,09 / -0,23 / -0,28 |
| h30: extras + calibrado + volatilidade | 52,4% | 52,1% | 0,2813 | 0,2499 | 117.598 | -0,181% | -0,18 / -0,20 / -0,34 / -0,17 |
| h60: extras + calibrado + volatilidade | 50,4% | 51,3% | 0,2733 | 0,2502 | 97.619 | -0,162% | -0,13 / -0,30 / -0,16 / -0,14 |
| h240: extras + calibrado + volatilidade | 52,2% | 49,4% | 0,2630 | 0,2647 | 127.095 | -0,176% | -0,00 / +0,05 / -0,24 / -0,55 |

## Leitura

- **O custo decide.** O retorno bruto médio por operação (líquido + 0,20%) fica perto de +0,02% nos horizontes curtos e chega a +0,08% no horizonte 240. Qualquer variação precisaria de um movimento previsto vários múltiplos do custo de 0,20%.
- **Nos horizontes curtos o sinal é quase nulo.** A acurácia passa a linha de base por 0,4 a 0,6 ponto e o Brier fica pior que o de uma probabilidade constante (a calibração o reduz de 0,2829 para 0,2757, sem chegar a 0,2493).
- **O h240 combinado quase passa, mas não é confiável.** É a única variação com acurácia (+2,8 pontos) e Brier melhores que a linha de base. Falha no retorno líquido e as janelas vão de +0,05% a -0,55%, com a pior justamente na mais recente. Com 30 dias há cerca de 180 períodos independentes de 4 horas por par, pouco para concluir algo, e 13 variações testadas aumentam a chance de uma parecer boa por acaso.
- **A calibração quase não ajuda.** A hipótese é deriva de regime (calibra nos 20% finais do treino e testa no bloco seguinte) ou rótulos sobrepostos nas velas de 1 minuto com horizonte maior que 1.

## Conclusão e próximos passos possíveis

Com variáveis só de preço e volume em velas de 1 minuto e custo de 0,20%, não há vantagem mensurável. O portão fez o papel dele. O que ainda poderia mudar o quadro, sem afrouxar nenhum critério:

1. Conferir a taxa real da conta na Binance e rodar `--fee-bps` com ela (por exemplo, com desconto em BNB).
2. Acumular 90 dias ou mais de histórico (`market:backfill-binance --days=90`) e testar horizontes longos com velas de 1 hora.
3. Investigar a calibração com rótulos não sobrepostos.

Repetir o experimento depois de uma semana de velas novas é a forma de confirmar qualquer variação que passe.
