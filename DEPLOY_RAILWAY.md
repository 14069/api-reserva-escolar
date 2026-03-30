# Deploy no Railway

## 1. Subir a API

- Crie um novo projeto no Railway.
- Escolha `Deploy from GitHub` ou suba esta pasta com o `Railway CLI`.
- Como existe um `Dockerfile`, o Railway vai usar essa imagem automaticamente.
- O diretório recomendado para deploy é este clone:
  `/home/agacy-junior/RESERVA_ESCOLAR/reserva_escolar_v2_app/local_api_supabase`

## 2. Variaveis de ambiente

Use o arquivo `.env.example` como base e configure:

- `RESERVA_ALLOWED_ORIGINS=https://app.seudominio.com.br`
- `RESERVA_DB_URL=postgresql://...`

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
- `RESERVA_ALLOWED_ORIGINS=https://app.seudominio.com.br`
- `RESERVA_DB_URL=postgresql://...pooler.supabase.com:5432/postgres?sslmode=require`

## 3. Dominio customizado

- No Railway, adicione `api.seudominio.com.br`.
- Crie no Cloudflare o `CNAME api` apontando para o host informado pelo Railway.
- Para o primeiro deploy, prefira `DNS only`.

## 4. Validacao

Depois do deploy:

- `GET /` deve retornar status `ok`
- `GET /health.php` pode ser usado como healthcheck simples
- `GET /check_supabase_connection.php` deve validar a conexao real com o Supabase hospedado
- `GET /login.php` deve responder `405` se acessado com metodo incorreto
- Rode `./smoke_test_api.sh https://api.seudominio.com.br` para um teste rapido

## 5. Observacao importante

Esta API agora aceita MySQL e PostgreSQL na conexao, mas a migracao completa para Supabase ainda depende de o schema do banco estar compatível com PostgreSQL.

## 6. Teste rapido de conexao

Depois de preencher o `.env.local`, rode:

```bash
php -S 127.0.0.1:8092 -t .
```

E abra:

```bash
http://127.0.0.1:8092/check_supabase_connection.php
```
