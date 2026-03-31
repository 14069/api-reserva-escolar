# Deploy no Railway

## 1. Subir a API

- Crie um novo projeto no Railway.
- Escolha `Deploy from GitHub` ou suba esta pasta com o `Railway CLI`.
- Como existe um `Dockerfile`, o Railway vai usar essa imagem automaticamente.
- O diretório recomendado para deploy é este clone:
  `/home/agacy-junior/RESERVA_ESCOLAR/reserva_escolar_api_railway`

## 2. Variaveis de ambiente

Use o arquivo `.env.example` como base e configure:

- `APP_ENV=production`
- `APP_URL=https://api.seudominio.com.br`
- `RESERVA_APP_TIMEZONE=America/Araguaina`
- `RESERVA_ALLOWED_ORIGINS=https://app.seudominio.com.br`
- `RESERVA_DB_URL=postgresql://...`
- `RESERVA_DIAGNOSTIC_TOKEN=um-token-longo-e-seguro`
- `RESERVA_CRON_TOKEN=outro-token-longo-e-seguro`

Se preferir, em vez de `RESERVA_DB_URL`, use:

- `RESERVA_DB_DRIVER=pgsql`
- `RESERVA_DB_HOST=...`
- `RESERVA_DB_PORT=5432`
- `RESERVA_DB_NAME=postgres`
- `RESERVA_DB_USERNAME=...`
- `RESERVA_DB_PASSWORD=...`
- `RESERVA_DB_SSLMODE=require`

Para desenvolvimento local com Supabase hospedado, copie:

- `.env.supabase-hosted.example` -> `.env.local`

Depois preencha a `RESERVA_DB_URL` com a Session pooler connection string do projeto online.

Para producao no Railway, use como base:

- `.env.railway.example`

Variaveis minimas no painel do Railway:

- `APP_ENV=production`
- `APP_URL=https://api.seudominio.com.br`
- `RESERVA_APP_TIMEZONE=America/Araguaina`
- `RESERVA_ALLOWED_ORIGINS=https://app.seudominio.com.br`
- `RESERVA_DB_URL=postgresql://...pooler.supabase.com:5432/postgres?sslmode=require`
- `RESERVA_DIAGNOSTIC_TOKEN=...`
- `RESERVA_CRON_TOKEN=...`

## 3. Dominio customizado

- No Railway, adicione `api.seudominio.com.br`.
- Crie no Cloudflare o `CNAME api` apontando para o host informado pelo Railway.
- Para o primeiro deploy, prefira `DNS only`.

## 4. Validacao

Depois do deploy:

- `GET /` deve retornar status `ok`
- `GET /health.php` pode ser usado como healthcheck simples
- `GET /login.php` deve responder `405` se acessado com metodo incorreto
- Rode `./smoke_test_api.sh https://api.seudominio.com.br` para um teste rapido
- Para o diagnostico de banco, use o token interno:
  `RESERVA_DIAGNOSTIC_TOKEN=... ./smoke_test_api.sh https://api.seudominio.com.br`

## 5. Observacao importante

Esta API agora aceita MySQL e PostgreSQL na conexao, mas o deploy de producao deve usar apenas a configuracao PostgreSQL/Supabase hospedada.

## 6. Teste rapido de conexao

Depois de preencher o `.env.local`, rode:

```bash
php -S 127.0.0.1:8092 -t .
```

E abra:

```bash
curl -H "X-Reserva-Diagnostic-Token: SEU_TOKEN" \
  http://127.0.0.1:8092/check_supabase_connection.php
```
