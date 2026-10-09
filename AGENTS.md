# Regras do Projeto

## Mensagens de Commit

Ao criar um commit, siga estas regras obrigatórias:

- Sempre em **inglês**.
- Toda **minúscula** (lowercase).
- No **presente do indicativo** na **terceira pessoa do singular**.

Exemplos válidos:

- `adds build claim endpoint`
- `fixes artifact upload size handling`
- `updates runner documentation`

Exemplos inválidos:

- `Add build claim endpoint` (não é minúscula)
- `added build claim endpoint` (passado)
- `add build claim endpoint` (não é terceira pessoa)

## Estrutura

- `api/` — Laravel 13 (API do painel em `/api/v1`, API do runner em `/api/runner/v1`).
- `web/` — React 19 + TypeScript + Vite + Tailwind 4.
- `runner/` — PowerShell 5.1 (runner Windows; sem dependências externas).
- `docker/` — imagens e configurações; `docs/` — operação e acesso remoto.

## Comandos

```bash
docker compose exec api php artisan test      # testes de backend (MySQL de teste)
cd web && npm test && npm run build && npm run lint
powershell -ExecutionPolicy Bypass -File runner\tests\run-tests.ps1   # no Windows
```
