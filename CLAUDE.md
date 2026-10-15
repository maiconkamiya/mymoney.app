# MyMoney.App: regras para agentes

Este arquivo vale para qualquer agente de IA (Claude, GPT, Gemini, Copilot ou outro) e para pessoas. O `AGENTS.md` aponta para cá. Idioma do projeto: português (issues, PRs, commits, textos da interface).

Visão do produto e arquitetura: veja o `README.md` e a especificação em https://claude.ai/code/artifact/05134a63-c1ac-4332-a8c2-c38485ef29a8.

## Fluxo de trabalho obrigatório

1. **Toda tarefa começa com uma issue.** Correção, melhoria ou nova função: antes de escrever código, abra (ou encontre) a issue no GitHub em `maiconkamiya/mymoney.app` descrevendo o problema ou objetivo e o critério de pronto. Sem issue, sem código.
2. **Uma branch por issue**, criada a partir de `main` atualizada. Nome: `tipo/N-descricao-curta`, com `tipo` em `fix`, `feat`, `chore`, `docs` ou `refactor` e `N` o número da issue. Exemplo: `feat/12-painel-ao-vivo`.
3. **Nada vai direto para `main`.** Todo deploy passa por pull request. Abra o PR como rascunho enquanto houver trabalho, e marque como pronto só com a CI local verde.
4. **A descrição do PR menciona a issue** com `Closes #N` (ou `Fixes #N` para correções), para a issue fechar no merge. Um PR que toca várias issues lista uma linha `Closes #N` para cada.
5. **O merge é humano.** Agentes não aprovam nem fazem merge de PR; quem aprova é o Maicon.

Commits seguem o estilo `tipo: descrição` (ex.: `feat: consenso com rodada de contestação`), em português.

## CI local (não usamos GitHub Actions)

Não crie workflows em `.github/workflows`. A CI roda na máquina de quem vai fazer o push:

```bash
composer ci            # vp check, vue-tsc, Pint, PHPStan, PHPUnit e build do Vite
composer hooks:install # uma vez por clone: o pre-push passa a rodar `composer ci`
```

Rode `composer ci` antes de todo push e registre no PR que ela passou. Se algo falhar, corrija a causa: não desligue teste, não adicione `@phpstan-ignore`, baseline ou `@var` para calar o PHPStan. Para formatar: `composer lint` e `npm run check:fix`.

## Regras do domínio que não se negociam

- `AGENTS_DRIVER=fake` nos testes: nenhum teste chama a API da Anthropic nem corretoras de verdade.
- A guarda de risco (`app/Trading/Risk/RiskGuard.php`) é determinística e tem a palavra final; agentes de IA nunca a contornam.
- Carteira simulada só usa o `PaperBroker`. Dinheiro real exige carteira `live`, credencial sem permissão de saque e aprovação explícita do Maicon.
- Simulações nunca podem ver dados do futuro: cotações e notícias são filtradas por `now()`.
- Segredos (chaves de API, credenciais de corretora) nunca entram no repositório, em issues ou em PRs.
