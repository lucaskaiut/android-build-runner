# Runner Windows — Local Build Platform

Executa builds reais no Windows: consulta a API por polling, faz checkout do
commit exato, executa o script do perfil, envia logs em tempo real e faz upload
dos artefatos. Todas as conexões são de saída — **nenhuma porta é aberta**.

## Requisitos

| Ferramenta | Obrigatória | Observação |
| --- | --- | --- |
| Windows 10/11 + PowerShell 5.1 | sim | já vem no sistema |
| Git for Windows | sim | com acesso SSH ao repositório (chave em `%USERPROFILE%\.ssh`) |
| Node.js + npm | para projetos JS/Expo | mesma versão usada pelo app |
| JDK 17 | para builds Android | configure `JAVA_HOME` |
| Android SDK | para builds Android | `ANDROID_HOME` com `platforms;android-XX`, `build-tools;XX`, `platform-tools` |
| Expo CLI | via dependências do projeto | `npx expo` |

Validação automatizada:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\validate-prereqs.ps1 -RepoUrl git@github.com:org/repo.git
```

O comando usa `git ls-remote` para confirmar acesso real ao repositório.

## Instalação

1. Copie a pasta `runner/` para a máquina (ex.: `C:\LocalBuildPlatform\runner`).
2. Gere um token no servidor: `docker compose exec api php artisan runner:create "PC-BUILD-01"`.
3. Rode o instalador (pede o token sem eco e cria a configuração com ACL restrita):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install.ps1 `
  -ApiUrl "http://localhost:8100/api/runner/v1" `
  -WorkspaceRoot "D:\LocalBuilds\projects" `
  -LogDirectory "D:\LocalBuilds\logs"
```

Arquivo gerado: `%ProgramData%\LocalBuildPlatform\runner\config.json`.

## Configuração (`config.json`)

| Chave | Padrão | Descrição |
| --- | --- | --- |
| `api_url` | `http://localhost:8100/api/runner/v1` | raiz da API do runner (aceita HTTPS) |
| `token` | — | token do runner (ou variável `RUNNER_TOKEN`) |
| `name` | nome do computador | nome legível |
| `poll_interval_seconds` | 10 | intervalo de consulta por builds |
| `idle_heartbeat_seconds` | 30 | heartbeat quando ocioso |
| `build_heartbeat_seconds` | 15 | heartbeat durante a build |
| `log_batch_interval_seconds` | 1 | intervalo de envio de logs |
| `log_batch_max_lines` / `log_batch_max_bytes` | 200 / 262144 | limites por lote |
| `max_pending_log_lines` | 5000 | limite de backlog em memória |
| `workspace_root` | `C:\LocalBuilds\projects` | raiz dos workspaces |
| `log_directory` | `C:\LocalBuilds\logs` | logs do runner |
| `default_timeout_seconds` | 3600 | fallback (o perfil manda) |
| `api_timeout_seconds` | 30 | timeout HTTP |
| `api_retry_max` | 5 | tentativas para chamadas normais |
| `complete_retry_max` | 10 | tentativas para concluir uma build |
| `use_system_proxy` | false | use proxy do sistema para a API |
| `git_ssh_command` | — | ex.: `C:/Windows/System32/OpenSSH/ssh.exe -i C:/Users/build/.ssh/id_ed25519` |
| `extra_headers` | {} | ex.: service token do Cloudflare Access |
| `cleanup_successful_builds` | false | remove o workspace após sucesso |

Variáveis de ambiente sobrescrevem o arquivo: `RUNNER_API_URL`, `RUNNER_TOKEN`,
`RUNNER_NAME`, `RUNNER_WORKSPACE_ROOT`, `RUNNER_LOG_DIRECTORY`.

## Execução

```powershell
# Diagnóstico (config + ferramentas detectadas)
powershell -ExecutionPolicy Bypass -File src\BuildRunner.ps1 -Diagnose

# Interativo (Ctrl+C encerra; a build em andamento é reconciliada pela API)
powershell -ExecutionPolicy Bypass -File scripts\start-runner.ps1

# Executar uma build e sair (útil para testes)
powershell -ExecutionPolicy Bypass -File scripts\start-runner.ps1 -MaxBuilds 1
```

### Como serviço (Task Scheduler)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install-service.ps1 -StartNow
```

A tarefa é criada no logon do usuário atual com `RunLevel Highest` — isso garante
o mesmo PATH, chaves SSH e caches (`~/.gradle`, `~/.npm`) do usuário que valida
os builds manualmente. **`LocalSystem` não tem esse ambiente** e não deve ser
usado sem revisar PATH/chaves do sistema.

Alternativas para execução sem logon: agendador com conta dedicada ("executar
estando o usuário conectado ou não") ou um wrapper de serviço (ex.: NSSM) com a
conta de build. Em qualquer caso, o script a executar é:

```text
powershell.exe -NoProfile -ExecutionPolicy Bypass -File <runner>\src\BuildRunner.ps1 -ConfigPath <config.json>
```

Gerenciamento:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\runner-status.ps1
powershell -ExecutionPolicy Bypass -File scripts\stop-runner.ps1 -Force
powershell -ExecutionPolicy Bypass -File scripts\uninstall-service.ps1
```

## Contrato executado por build

1. Reivindica a build (claim atômico na API).
2. Cria `workspace_root\project-<id>\builds\build-<id>` e faz `git clone` +
   checkout do SHA exato (branch é resolvida e o HEAD é reportado à API).
3. Envia `start` com o SHA resolvido (validado contra o solicitado).
4. Gera `_runner\build.ps1` com as variáveis (`APP_DIR`, `BUILD_*`) e executa o
   script do perfil com stdout/stderr separados e stdin fechado (prompts falham
   rápido em vez de travar).
5. Envia lotes de log (idempotentes por `batch_id` + `line_no`) e renova a lease
   a cada envio/heartbeat.
6. Interrompe a árvore de processos em cancelamento (`taskkill /T /F`) ou timeout.
7. Localiza arquivos pelos padrões do perfil, calcula SHA-256 e envia via multipart.
8. Conclui a build (`success`/`failed`/`cancelled`); a API valida o resultado
   (código de saída e artefatos esperados) e pode rebaixar para falha.
9. Se a API já finalizou a build (reaper/cancelamento), o processo local é
   encerrado e nada é sobrescrito.

## Segredos de build

Cada projeto pode ter variáveis e arquivos secretos cadastrados no painel
(criptografados no banco e write-only). Ao executar a build, o runner:

- injeta as variáveis no ambiente do script (nomes reservados como `BUILD_*`,
  `APP_DIR`, `CI` e variáveis de sistema são ignorados);
- grava cada arquivo secreto dentro do workspace no caminho relativo informado
  (ex.: `.env`, `credentials.json`, `credentials/android/keystore.jks`),
  validando o caminho e o SHA-256 antes de escrever;
- substitui os valores das variáveis por `***` nos logs enviados à API.

## Scripts de build (exemplos)

**Expo / React Native (APK release):**

```powershell
npm ci
npx expo prebuild --platform android --no-install
Push-Location (Join-Path $env:APP_DIR "android")
\.\gradlew.bat assembleRelease --no-daemon
$code = $LASTEXITCODE
Pop-Location
if ($code -ne 0) { exit $code }
```

**Expo / React Native (AAB):**

```powershell
npm ci
npx expo prebuild --platform android --no-install
Push-Location (Join-Path $env:APP_DIR "android")
\.\gradlew.bat bundleRelease --no-daemon
$code = $LASTEXITCODE
Pop-Location
if ($code -ne 0) { exit $code }
```

Padrões de artefato:
`android/app/build/outputs/apk/release/*.apk` ou
`android/app/build/outputs/bundle/release/*.aab`.

Recomendações:

- Use `--no-daemon` no Gradle (o daemon sobrevive ao processo pai e complica o
  cancelamento).
- Cheque `$LASTEXITCODE` após comandos nativos; o wrapper propaga o último
  código nativo, mas scripts longos devem falhar cedo.
- Nunca dependa de entrada manual: o stdin é fechado e `-NonInteractive` é usado.
- Segredos devem vir da máquina (SSH, gradle.properties do usuário), nunca do perfil.

## Testes

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -Filter Config
```

Cobrem: configuração, tailing incremental de logs (UTF-8), lotes e fila de
pendências, caminhos/segurança do workspace, detecção de processo ativo, wrapper
e propagação de exit code, captura de stdout/stderr, timeout/árvore de processos,
padrões de artefato, SHA-256, retry do cliente HTTP e caminho relativo de artefato.

## Retenção e limpeza

- Workspaces: `scripts\clean-workspaces.ps1 -Days 30` (respeita processos ativos
  via `run.json`; use `-WhatIf` para simular).
- Logs do runner: rotação automática em 10 MB (`runner.log` → `runner-*.log`).
- Artefatos e logs no servidor: comandos `artifacts:prune` e `logs:prune` da API.

## Diagnóstico de falhas

| Sintoma | Onde olhar |
| --- | --- |
| Runner não aparece online | `scripts\runner-status.ps1`, conectividade com `api_url`, token válido |
| `401/403` na API | token revogado → gere novo com `runner:rotate-token` |
| `Falha no git clone` | `validate-prereqs.ps1 -RepoUrl ...`, chave SSH, `known_hosts` |
| Build presa em `running` | runner caiu → a lease expira e a API marca `failed`; ver `logs\runner.log` |
| Cancelamento não encerra | verifique se o script não ignora o processo filho; use `--no-daemon` |
| Artefato “não encontrado” | confira `APP_DIR`, o padrão do perfil e se o arquivo foi gerado |
| Logs atrasados | ver `log_batch_interval_seconds`; em redes lentas reduza o lote |

## Atualização

1. Pare o runner (`scripts\stop-runner.ps1 -Force`).
2. Substitua a pasta `runner/` pela nova versão (mantenha o `config.json`).
3. Rode `scripts\runner-status.ps1` e `src\BuildRunner.ps1 -Diagnose`.
4. Inicie novamente (`install-service.ps1 -StartNow` ou `start-runner.ps1`).

O runner envia sua versão (`VERSION`) a cada heartbeat; versões de protocolo
incompatíveis são rejeitadas com HTTP 426.
