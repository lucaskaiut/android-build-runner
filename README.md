# Local Build Platform

Plataforma própria para executar builds de aplicativos Expo / React Native em um
computador Windows da rede local, com painel web, API, fila no MySQL e runner
nativo iniciando todas as conexões de saída.

O fluxo é semelhante, em escopo reduzido, ao EAS Build / Vercel:

**cadastro de projeto → build manual (ou webhook) → execução real no Windows →
logs em tempo real → resultado → download do artefato.**

## Arquitetura

```text
                         INTERNET (opcional)
                            |
                    Cloudflare Tunnel
                            |
                            v
+------------------------------------------------------------+
|                     WSL2 / Docker Desktop                  |
|                                                            |
|  +----------------+      +-----------------------------+   |
|  | Frontend React | ---> | API Laravel (PHP-FPM)       |   |
|  | Vite :5174     |      | + Nginx :8100               |   |
|  +----------------+      +--------------+--------------+   |
|                                          |                  |
|                             +------------v-------------+    |
|                             | MySQL 8.4 (volume)       |    |
|                             | projetos, perfis, builds |    |
|                             | logs, artefatos, runners |    |
|                             +--------------------------+    |
|                                                            |
|  +---------------------------------------------+           |
|  | scheduler (php artisan schedule:work)       |           |
|  | reaper de leases/timeouts + retenção        |           |
|  +---------------------------------------------+           |
+----------------------------------+-------------------------+
                                   |
                       HTTP iniciado pelo runner
                     (polling com o token do runner)
                                   |
                                   v
+------------------------------------------------------------+
|                     Windows nativo                         |
|                                                            |
|  +------------------------------------------------------+  |
|  | Runner PowerShell                                    |  |
|  | Git + SSH | Node | Java | Android SDK | Expo/Gradle  |  |
|  | workspaces isolados por build                        |  |
|  | logs locais + artefatos                              |  |
|  +------------------------------------------------------+  |
|                                                            |
|  Nenhuma porta de entrada é aberta.                        |
+------------------------------------------------------------+
```

Decisões importantes:

- **MySQL é o único banco** (nada de SQLite). A fila é coordenada com
  `SELECT ... FOR UPDATE SKIP LOCKED` — dois runners nunca recebem a mesma build.
- **Nenhuma build roda em container**: o runner é um processo Windows.
- **O runner nunca acessa o MySQL**: tudo passa pela API HTTP autenticada por
  token próprio (revogável/rotacionável).
- **SSE** para logs em tempo real, com retomada por cursor (id incremental) e
  ingestão idempotente por lote.
- **Artefatos são enviados pelo runner à API** e servidos apenas para usuários
  autenticados, com validação de caminho e autorização por build.

## Estrutura

```text
build-runner/
├── api/                 Laravel 13 (API REST /api/v1 + API do runner /api/runner/v1)
├── web/                 React 19 + TypeScript + Vite + Tailwind 4
├── runner/              Runner Windows em PowerShell 5.1 + scripts + testes
├── docker/              Dockerfiles e configurações (php, nginx, web, mysql)
├── docs/                Cloudflare Tunnel e operação
├── docker-compose.yml
└── .env.example
```

## Pré-requisitos

**No WSL2 / Docker Desktop:**

- Docker Desktop com integração WSL2
- Docker Compose v2

**No Windows (máquina que executa builds):**

- PowerShell 5.1 (nativo)
- Git for Windows com acesso SSH ao repositório (`git@github.com:org/repo.git`)
- Node.js + npm
- JDK 17 (Temurin/OpenJDK) e `JAVA_HOME`
- Android SDK (`ANDROID_HOME`/`ANDROID_SDK_ROOT` com `platforms;android-XX` e
  `build-tools;XX`)
- (Expo) `npx expo` disponível via dependências do projeto

Valide com:

```powershell
powershell -ExecutionPolicy Bypass -File runner\scripts\validate-prereqs.ps1 -RepoUrl git@github.com:org/repo.git
```

## Início rápido

### 1. Subir a aplicação (WSL2)

```bash
cd build-runner
cp .env.example .env          # opcional: ajuste portas/senhas
docker compose up -d --build
docker compose ps
```

- Painel: http://localhost:5174
- API: http://localhost:8100 (health: `curl http://localhost:8100/healthz`)
- MySQL: interno (`mysql:3306`), publicado apenas em `127.0.0.1:3307` para ferramentas locais

As migrations rodam automaticamente na subida da API. Para rodar manualmente:

```bash
docker compose exec api php artisan migrate --force
```

### 2. Criar o administrador

```bash
docker compose exec api php artisan app:create-admin
# ou não interativo (evite deixar a senha no histórico):
docker compose exec api php artisan app:create-admin --name="Admin" --email=admin@exemplo.com
```

Não existe senha padrão no código. A senha é solicitada sem eco no terminal.

### 3. Registrar o runner

```bash
docker compose exec api php artisan runner:create "PC-BUILD-01"
```

O token é exibido **uma única vez**. Guarde-o. Para rotacionar/revogar:

```bash
docker compose exec api php artisan runner:rotate-token "PC-BUILD-01"
docker compose exec api php artisan runner:revoke "PC-BUILD-01"
```

### 4. Instalar o runner no Windows

Copie a pasta `runner/` para a máquina Windows (ex.: `C:\LocalBuildPlatform\runner`).

```powershell
cd C:\LocalBuildPlatform\runner
powershell -ExecutionPolicy Bypass -File scripts\install.ps1 `
  -ApiUrl "http://localhost:8100/api/runner/v1" `
  -WorkspaceRoot "D:\LocalBuilds\projects" `
  -LogDirectory "D:\LocalBuilds\logs"
```

O script grava `%ProgramData%\LocalBuildPlatform\runner\config.json` com ACL
restrita (Administrators, SYSTEM e o usuário atual) e pede o token digitado.

Teste e execute:

```powershell
powershell -ExecutionPolicy Bypass -File src\BuildRunner.ps1 -Diagnose
powershell -ExecutionPolicy Bypass -File scripts\start-runner.ps1        # interativo
powershell -ExecutionPolicy Bypass -File scripts\install-service.ps1     # tarefa no logon
```

Detalhes em [runner/README.md](runner/README.md).

### 5. Cadastrar projeto, perfil e executar a primeira build

1. Acesse http://localhost:5174 e faça login.
2. **Projetos → Novo projeto**: informe nome, identificador, URL SSH do
   repositório, branch padrão e diretório de execução.
3. **Detalhes do projeto → Novo perfil**: defina nome, identificador, o script
   PowerShell de build, timeout e os padrões de artefatos esperados.
4. **Nova build**: escolha o projeto, o perfil e a referência (branch ou SHA),
   confirme. A build entra em `queued` e o runner a executa.
5. Acompanhe logs em tempo real na página da build; ao concluir, os artefatos
   aparecem com links de download.

### 6. Exemplo de perfil para Expo / React Native

Script do perfil (executado dentro do workspace, com `APP_DIR` apontando para o
diretório de execução configurado):

```powershell
npm ci
npx expo prebuild --platform android --no-install
Push-Location (Join-Path $env:APP_DIR "android")
\.\gradlew.bat assembleRelease --no-daemon
if ($LASTEXITCODE -ne 0) { Pop-Location; exit $LASTEXITCODE }
Pop-Location
```

Padrões de artefatos esperados:

```text
android/app/build/outputs/apk/release/*.apk
```

Variáveis disponíveis para o script:

| Variável | Conteúdo |
| --- | --- |
| `APP_DIR` | diretório de execução (workspace + diretório do projeto/perfil) |
| `BUILD_ID`, `BUILD_PROJECT_SLUG`, `BUILD_PROFILE` | identificação |
| `BUILD_BRANCH`, `BUILD_COMMIT` | referência e SHA exato do checkout |
| `BUILD_WORKSPACE`, `BUILD_REPO_URL` | caminhos/URL do repositório |
| `BUILD_ENV_*` / variáveis do perfil | ambiente não sensível configurado no perfil |
| `BUILD_PARAM_<CHAVE>` | parâmetros informados ao criar a build |

Nunca coloque segredos no perfil: o script e as variáveis são visíveis para os
administradores do painel. Use credenciais já configuradas na máquina (SSH,
`~/.gradle/gradle.properties`, variáveis de ambiente do usuário do serviço).

## Webhooks do GitHub

1. No painel: **Projeto → Integração com GitHub → Gerar segredo** (copiado uma
   única vez) e ative a automação.
2. Adicione o gatilho da branch (exata ou padrão `release/*`) apontando para um
   perfil ativo.
3. No GitHub: **Settings → Webhooks → Add webhook**
   - Payload URL: `https://<seu-domínio>/api/webhooks/github/<slug-do-projeto>`
   - Content type: `application/json`
   - Secret: o segredo gerado
   - Eventos: apenas `push`

Garantias implementadas: validação HMAC `X-Hub-Signature-256`, rejeição de
repositório/branch não configurados, idempotência por `X-GitHub-Delivery`
(entregas repetidas não geram builds duplicadas, apenas incrementam o contador
de reentrega), e o payload nunca altera scripts ou configurações.

## Operação

```bash
# Diagnóstico geral
docker compose exec api php artisan platform:diagnose

# Reconciliação manual (o scheduler roda a cada minuto)
docker compose exec api php artisan builds:reap

# Retenção (não roda automaticamente — veja docs/operations.md)
docker compose exec api php artisan artifacts:prune --days=30 --dry-run
docker compose exec api php artisan logs:prune --days=90 --dry-run
```

- **Lease e recuperação**: builds sem comunicação têm a lease expirada e são
  marcadas como `failed` — nunca reenfileiradas automaticamente, porque a API
  não pode provar que o processo anterior foi encerrado.
- **Cancelamento**: builds na fila são canceladas na hora; em execução, o flag é
  entregue ao runner, que encerra a árvore de processos (`taskkill /T /F`) e
  reporta `cancelled`.
- **Timeouts**: o runner aplica o timeout do perfil e a API tem um limite de
  segurança adicional. Em ambos os casos o processo é encerrado e a build falha
  com diagnóstico.

Backup, restauração e retenção: [docs/operations.md](docs/operations.md).
Acesso remoto com Cloudflare Tunnel: [docs/cloudflare-tunnel.md](docs/cloudflare-tunnel.md).

## Testes

```bash
# Backend (usa build_platform_test no MySQL; nunca toca o banco principal)
docker compose exec api php artisan test

# Runner (Windows PowerShell 5.1)
powershell -ExecutionPolicy Bypass -File runner\tests\run-tests.ps1

# Frontend
cd web && npm test && npm run build && npm run lint
```

Status atual: 91 testes de backend, 28 do runner e 12 do frontend aprovados.

## Segurança

- Painel autenticado por sessão + CSRF (Sanctum SPA); rate limiting no login.
- Runner autenticado por token individual (hash no banco), revogável e
  rotacionável; apenas o `tokenable` do próprio runner consegue operar suas builds.
- Scripts de build só podem ser editados por administradores (Gate
  `manage-platform`) e toda alteração sensível é registrada em `audit_logs`.
- Webhooks validados por HMAC, com idempotência no banco.
- Artefatos: caminhos gerados pela API, validação de contenção, download
  autorizado e nunca expostos como diretório estático.
- Segredos (token do runner, segredo do webhook) nunca aparecem nos logs nem
  nas respostas da API.

## Limitações conhecidas

- O cancelamento no Windows encerra a árvore de processos, mas daemons que
  sobrevivem ao processo pai (ex.: Gradle sem `--no-daemon`) devem ser evitados
  nos scripts. Use `--no-daemon`.
- A resolução do SHA de uma branch acontece no runner, no momento do checkout
  (a API não possui chaves de acesso aos repositórios). O SHA exato fica
  registrado na build e é validado no checkout.
- O agendador depende do serviço `scheduler` do compose; sem ele, leases
  expiradas só são reconciliadas ao executar `builds:reap` manualmente.
- Logs residem no MySQL (MVP). Para volumes muito grandes, veja a política de
  retenção em `docs/operations.md`.
