# Operação, backup e retenção

## Backup

Os dados persistidos são: **volume MySQL** (`mysql_data`), **artefatos** em
`api/storage/app/artifacts/` (diretório do host via bind mount) e os arquivos
`.env` (compose e `api/.env`).

### Banco de dados

```bash
cd build-runner

# Dump consistente (sem parar o serviço)
docker compose exec -T mysql sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers build_platform' \
  > backup-$(date +%Y%m%d-%H%M).sql
```

### Artefatos

```bash
tar -czf artifacts-$(date +%Y%m%d).tar.gz -C api/storage/app artifacts
```

Guarde os backups fora do volume do Docker (ex.: `D:\Backups` no host Windows)
e teste a restauração periodicamente.

### Restauração

```bash
# 1. Restaurar o dump
docker compose exec -T mysql sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" build_platform' < backup-AAAAMMDD-HHMM.sql

# 2. Restaurar os artefatos (mesmos caminhos relativos)
tar -xzf artifacts-AAAAMMDD.tar.gz -C api/storage/app
```

Após restaurar, os downloads continuam funcionando porque os registros guardam o
caminho relativo (`artifacts/<build_id>/<ulid>.<ext>`), validado contra a raiz
permitida.

## Retenção

A retenção **nunca roda automaticamente**: os comandos são explícitos e seguros
por padrão (simulação).

| Recurso | Comando | Padrão | Observação |
| --- | --- | --- | --- |
| Artefatos | `php artisan artifacts:prune --days=30` | 30 dias | remove arquivos + registros de builds finalizadas |
| Logs | `php artisan logs:prune --days=90` | 90 dias | remove linhas de log de builds finalizadas |
| Builds antigas | manualmente via SQL | — | só após podar artefatos/logs, se desejado |
| Workspaces no Windows | `scripts\clean-workspaces.ps1 -Days 30` | — | ignora workspaces com processo ativo |

Sempre execute primeiro com `--dry-run` (artefatos/logs) ou `-WhatIf`
(workspaces) e confira o que será removido. Os valores padrão podem ser
alterados por variáveis de ambiente (`BUILD_ARTIFACT_RETENTION_DAYS`,
`BUILD_LOG_RETENTION_DAYS`, `BUILD_RECORD_RETENTION_DAYS`).

Nada é apagado para builds ativas ou com lease válida.

## Manutenção

```bash
# Painel de estado (banco, armazenamento, contagens, runners)
docker compose exec api php artisan platform:diagnose

# Reconciliar leases/timeouts imediatamente
docker compose exec api php artisan builds:reap

# Verificar o agendador
docker compose logs scheduler --tail 20

# Atualizar a aplicação
docker compose build api && docker compose up -d api nginx scheduler web
docker compose exec api php artisan migrate --force
```

O serviço `scheduler` executa `builds:reap` a cada minuto. Se ele estiver
parado, builds com lease expirada permanecem ativas até uma execução manual.

## Observabilidade

- Logs da aplicação Laravel: `api/storage/logs/laravel.log`
  (`docker compose exec api tail -f storage/logs/laravel.log`).
- Logs do agendador: `docker compose logs scheduler`.
- Auditoria de operações sensíveis: tabela `audit_logs` (quem, quando, o quê).
- Entregas de webhook: tabela `github_webhook_deliveries` e o painel do projeto.
- Diagnóstico do runner: `runner\scripts\runner-status.ps1` e
  `runner\logs\runner.log`.

## Recuperação de falhas comuns

| Falha | Comportamento |
| --- | --- |
| API indisponível | runner faz backoff exponencial e continua tentando; builds ativas são reconciliadas pela lease |
| MySQL reiniciado | containers aguardam o healthcheck; scheduler/API retomam |
| Runner reiniciado no meio da build | a API não reexecuta às cegas: a lease expira e a build vira `failed` com registro do motivo |
| Build duplicada em webhook | idempotência por `X-GitHub-Delivery` (contador de reentrega) |
| Docker reiniciado | dados persistem no volume; migrations são idempotentes na subida |
| Upload de artefato interrompido | retry automático no runner; a API é idempotente por `(build, sha256, tamanho, caminho)` |
| Logs duplicados em retry | ignorados por `(batch_id, line_no)` |
