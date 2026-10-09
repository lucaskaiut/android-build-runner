# Acesso remoto com Cloudflare Tunnel

A plataforma funciona 100% localmente sem túnel. Este documento cobre o cenário
de publicar painel/API na internet sem abrir portas e sem expor o MySQL.

## Topologia recomendada (dois hostnames)

| Hostname | Destino local | Quem acessa |
| --- | --- | --- |
| `builds.seudominio.com` | `http://localhost:5174` (painel Vite) | operadores (com Cloudflare Access + login da plataforma) |
| `api-builds.seudominio.com` | `http://localhost:8100` (API/nginx) | runner e webhooks (sem Access; autenticação própria) |

O painel fala com a API pelo próprio proxy do Vite (`/api`, `/sanctum`), então o
navegador nunca precisa chamar o hostname da API — sem CORS e sem problemas de
cookie cross-site.

## 1. Instalar e autenticar o cloudflared

```bash
cloudflared tunnel login
cloudflared tunnel create local-builds
```

## 2. Configuração (`~/.cloudflared/config.yml`)

```yaml
tunnel: local-builds
credentials-file: /home/<usuario>/.cloudflared/<tunnel-id>.json

ingress:
  - hostname: builds.seudominio.com
    service: http://localhost:5174
  # API exposta para o runner e o webhook (sem Access).
  - hostname: api-builds.seudominio.com
    service: http://localhost:8100
  - service: http_status:404
```

```bash
cloudflared tunnel route dns local-builds builds.seudominio.com
cloudflared tunnel route dns local-builds api-builds.seudominio.com
cloudflared tunnel run local-builds
```

No Windows, o serviço é instalado com `cloudflared service install` — mas o
`cloudflared` deve apontar para os serviços publicados pelo Docker Desktop
(`localhost:5174` / `localhost:8100`), que já são acessíveis no host.

## 3. Cloudflare Access

- **Painel (`builds.seudominio.com`)**: crie uma aplicação Access com política
  "allow" para os e-mails autorizados. O Access é uma camada extra: o login da
  própria plataforma continua obrigatório.
- **API (`api-builds.seudominio.com`)**: **não** aplique Access interativo, pois
  o runner (automação) e o GitHub (webhook) seriam redirecionados para a tela de
  login. A API tem autenticação própria:
  - endpoints do runner exigem token individual (`Authorization: Bearer ...`);
  - webhooks exigem assinatura HMAC `X-Hub-Signature-256`.

Se quiser proteger a API com Access, use **Service Tokens** para o runner:

1. Crie um Service Token no Access e uma política "Service Auth" para o domínio da API.
2. Configure no runner:

```json
{
  "extra_headers": {
    "CF-Access-Client-Id": "<client-id>",
    "CF-Access-Client-Secret": "<client-secret>"
  }
}
```

Para o webhook do GitHub não há como enviar service token — mantenha uma
política de "Bypass" para o caminho `/api/webhooks/github/*` (a validação HMAC
continua sendo a autenticação real).

## 4. Configurar a aplicação para HTTPS

No `api/.env` (e reinicie a API):

```dotenv
APP_URL=https://api-builds.seudominio.com
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
SANCTUM_STATEFUL_DOMAINS=builds.seudominio.com
```

Observações:

- `SANCTUM_STATEFUL_DOMAINS` deve conter o host do painel (sem esquema). Se o
  domínio do painel mudar, a autenticação por sessão deixa de aceitar o Origin.
- Atrás do túnel, os cabeçalhos `X-Forwarded-*` são confiados (`TRUSTED_PROXIES=*`
  por padrão). Em redes onde a porta 8100 fica exposta, restrinja
  `TRUSTED_PROXIES` aos IPs do proxy.
- O MySQL nunca é publicado (a porta 3307 está ligada apenas a `127.0.0.1`).

## 5. Configurar o runner para a URL pública

```powershell
# %ProgramData%\LocalBuildPlatform\runner\config.json
"api_url": "https://api-builds.seudominio.com/api/runner/v1"
```

Teste: `powershell -ExecutionPolicy Bypass -File src\BuildRunner.ps1 -Diagnose`.

## 6. Webhook do GitHub apontando para a URL pública

```text
Payload URL: https://api-builds.seudominio.com/api/webhooks/github/<slug>
Content type: application/json
Secret: o segredo gerado no painel
```

## Verificação

```bash
curl -I https://builds.seudominio.com                 # painel (Access)
curl https://api-builds.seudominio.com/healthz        # API: "ok"
curl -X POST https://api-builds.seudominio.com/api/runner/v1/heartbeat \
  -H "Authorization: Bearer <token>"                  # 200 do runner
curl -X POST https://api-builds.seudominio.com/api/webhooks/github/slug \
  -d '{}'                                             # 403 (assinatura ausente), sem tela de login
```

Se qualquer chamada do runner/webhook retornar uma página HTML de login do
Cloudflare Access, a política de Access está bloqueando automação — ajuste para
Bypass/Service Auth nesse hostname ou caminho.
