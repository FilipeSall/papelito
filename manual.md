# Manual do Ambiente Local do Papelito

## Como ligar e parar o servidor

Para ligar o ambiente local:

```bash
cd /home/sea/projetos/papelito
docker compose up -d
```

Se precisar reconstruir a imagem web junto:

```bash
cd /home/sea/projetos/papelito
docker compose up -d --build
```

Para parar o ambiente local:

```bash
cd /home/sea/projetos/papelito
docker compose down
```

Este documento explica, passo a passo, como subir e manter o ambiente local do site WordPress do cliente usando Docker.

Para versionamento, deploy e automação do fluxo com Git/VSCode, consulte também `docs/versionamento-e-deploy.md`.

O ambiente local foi preparado para rodar com:

- WordPress já copiado em `public_html`
- PHP 7.4 + Apache
- MariaDB 10.5
- phpMyAdmin
- Mailpit
- URL local padrão: `http://localhost:8080`

## 1. Resumo rápido

Se você só quer subir, parar, recriar e corrigir as URLs locais, use este bloco.

Informações essenciais:

- raiz do projeto: `/home/sea/projetos/papelito`
- domínio local padrão: `http://localhost:8080`
- admin WordPress: `http://localhost:8080/wp-admin`
- phpMyAdmin: `http://localhost:8081`
- Mailpit: `http://localhost:8025`
- dump SQL padrão: `/home/sea/projetos/papelito/db/u374715300_rhozU.sql`

A URL padrão funciona sem mexer no `/etc/hosts`.
Se você preferir usar `http://papelitobrasil.local:8080`, adicione esta entrada:

```text
127.0.0.1 papelitobrasil.local
```

### Subir o ambiente rapidamente

```bash
cd /home/sea/projetos/papelito
docker compose up -d --build
```

### Parar o ambiente rapidamente

```bash
cd /home/sea/projetos/papelito
docker compose down
```

### Remover tudo e recriar do zero

Use quando quiser apagar completamente o banco local e reconstruir o ambiente:

```bash
cd /home/sea/projetos/papelito
docker compose down -v
docker compose up -d --build
```

### Ajustar as URLs do WordPress para o ambiente local

Depois que os containers estiverem no ar, rode:

```bash
cd /home/sea/projetos/papelito
./scripts/local-wordpress-setup.sh
```

Esse script:

- importa o dump SQL se o banco estiver vazio
- troca URLs de produção para a URL local configurada
- limpa cache
- faz flush dos permalinks

Se o dump tiver outro nome:

```bash
cd /home/sea/projetos/papelito
./scripts/local-wordpress-setup.sh /caminho/para/seu-dump.sql
```

### Fluxo mais comum

Para recriar o ambiente local do zero com o dump mais recente:

```bash
cd /home/sea/projetos/papelito
docker compose down -v
docker compose up -d --build
./scripts/local-wordpress-setup.sh
```

## 2. Comandos do dia a dia

### Subir o ambiente

```bash
cd /home/sea/projetos/papelito
docker compose up -d
```

### Subir o ambiente com rebuild

Use quando alterar o `Dockerfile` ou alguma configuração da imagem web:

```bash
cd /home/sea/projetos/papelito
docker compose up -d --build
```

### Parar o ambiente

```bash
cd /home/sea/projetos/papelito
docker compose down
```

### Parar e remover tudo, incluindo o volume do banco

Use isso apenas se quiser zerar completamente o ambiente local:

```bash
cd /home/sea/projetos/papelito
docker compose down -v
```

### Reconstruir apenas o container web

```bash
cd /home/sea/projetos/papelito
docker compose up -d --build web
```

### Ver os containers desse projeto

```bash
cd /home/sea/projetos/papelito
docker compose ps
```

### Ver logs do Apache/PHP

```bash
docker logs -f papelito-web
```

### Ver logs do banco

```bash
docker logs -f papelito-db
```

### Ver logs do phpMyAdmin

```bash
docker logs -f papelito-phpmyadmin
```

## 3. Estrutura do projeto

A raiz do projeto fica em:

```bash
/home/sea/projetos/papelito
```

Estrutura principal:

```text
papelito/
├── db/                    # Dump(s) do banco exportado do cliente
├── docker/                # Configurações Docker
├── docker-compose.yml     # Orquestração dos containers
├── public_html/           # Arquivos do WordPress
└── scripts/               # Scripts auxiliares
```

## 4. O que já está configurado

Este projeto já possui:

- `docker-compose.yml` na raiz
- imagem customizada PHP-Apache em `docker/php-apache/Dockerfile`
- configuração de PHP local em `docker/php-apache/php-local.ini`
- envio de e-mail local para Mailpit em `docker/php-apache/msmtprc`
- script de setup em `scripts/local-wordpress-setup.sh`
- `wp-config.php` configurado para o banco local

## 5. Pré-requisitos

Antes de começar, confirme:

1. Docker instalado
2. Docker Compose instalado
3. Dump SQL salvo em:

```bash
/home/sea/projetos/papelito/db/u374715300_rhozU.sql
```

Se o dump tiver outro nome, ele também pode ser usado, mas nesse caso o script deve receber o caminho manualmente.

## 6. Serviços do ambiente

Quando o ambiente estiver no ar, os serviços serão:

- Site WordPress: `http://localhost:8080`
- Admin WordPress: `http://localhost:8080/wp-admin`
- phpMyAdmin: `http://localhost:8081`
- Mailpit: `http://localhost:8025`
- Banco MariaDB exposto localmente em `127.0.0.1:3307`

## 7. Credenciais locais do banco

As credenciais configuradas no ambiente local são:

- Banco: `papelito_local`
- Usuário: `papelito`
- Senha: `papelito_local_123`
- Root: `root`
- Senha root: `root_local_123`
- Host do banco dentro do Docker: `db`

Importante:

- Dentro do WordPress, o host do banco é `db`
- Fora do Docker, se quiser acessar com cliente SQL local, use `127.0.0.1:3307`

## 8. Domínio local opcional

Por padrão, abra o site em `http://localhost:8080`.

Se quiser usar o alias `papelitobrasil.local`, adicione esta linha no arquivo `/etc/hosts`:

```text
127.0.0.1 papelitobrasil.local
```

No Linux, rode:

```bash
echo '127.0.0.1 papelitobrasil.local' | sudo tee -a /etc/hosts
```

Depois confira:

```bash
grep papelitobrasil.local /etc/hosts
```

Se aparecer a linha acima, o domínio local está configurado.

## 9. Como subir o ambiente pela primeira vez

Entre na raiz do projeto:

```bash
cd /home/sea/projetos/papelito
```

Suba os containers:

```bash
docker compose up -d --build
```

Esse comando:

- cria a imagem PHP-Apache personalizada
- sobe o MariaDB
- sobe o phpMyAdmin
- sobe o Mailpit
- sobe o container web do WordPress

Para conferir se os containers subiram:

```bash
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
```

Os containers esperados são:

- `papelito-web`
- `papelito-db`
- `papelito-phpmyadmin`
- `papelito-mailpit`

## 10. Como importar o banco e ajustar as URLs

Depois que os containers estiverem no ar, rode:

```bash
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh
```

Esse script faz automaticamente:

1. espera o banco ficar pronto
2. verifica se o banco local já tem tabelas
3. importa o SQL se o banco ainda estiver vazio
4. troca URLs de produção para a URL local com `wp search-replace`
5. limpa cache
6. faz flush dos permalinks

Se o seu dump tiver outro nome, rode assim:

```bash
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh /caminho/para/seu-dump.sql
```

## 11. Como acessar o site

Depois do setup:

- Site: `http://papelitobrasil.local:8080`
- Admin: `http://papelitobrasil.local:8080/wp-admin`

O login do WordPress será o mesmo usuário que já existe no banco importado.

## 12. Como acessar o phpMyAdmin

Abra:

```text
http://localhost:8081
```

Use:

- Servidor: `db` ou deixe o padrão do container
- Usuário: `root`
- Senha: `root_local_123`

Ou, se preferir:

- Usuário: `papelito`
- Senha: `papelito_local_123`

## 13. Como visualizar e-mails do WordPress localmente

Todos os e-mails locais devem ir para o Mailpit.

Abra:

```text
http://localhost:8025
```

Isso é útil para:

- testes de WooCommerce
- e-mails de senha
- notificações do WordPress
- e-mails de plugins

## 14. Como resetar totalmente o banco local

Se quiser refazer a importação do zero:

1. derrube os containers e apague o volume

```bash
cd /home/sea/projetos/papelito
docker compose down -v
```

2. suba novamente

```bash
docker compose up -d --build
```

3. rode novamente o script

```bash
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh
```

## 15. Como verificar se o WordPress está apontando para o local

Entre no container web e consulte a URL:

```bash
docker exec papelito-web wp option get siteurl --allow-root
```

O retorno esperado é:

```text
http://papelitobrasil.local:8080
```

Também pode conferir:

```bash
docker exec papelito-web wp option get home --allow-root
```

## 16. Arquivos importantes do ambiente

### Orquestração Docker

```text
/home/sea/projetos/papelito/docker-compose.yml
```

### Imagem PHP-Apache

```text
/home/sea/projetos/papelito/docker/php-apache/Dockerfile
```

### Configuração de PHP local

```text
/home/sea/projetos/papelito/docker/php-apache/php-local.ini
```

### Configuração de e-mail local

```text
/home/sea/projetos/papelito/docker/php-apache/msmtprc
```

### Script de setup

```text
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh
```

### Configuração principal do WordPress

```text
/home/sea/projetos/papelito/public_html/wp-config.php
```

## 17. O que o `wp-config.php` local está fazendo

O `wp-config.php` foi adaptado para este ambiente local com:

- `DB_NAME = papelito_local`
- `DB_USER = papelito`
- `DB_PASSWORD = papelito_local_123`
- `DB_HOST = db`
- `WP_HOME = http://papelitobrasil.local:8080`
- `WP_SITEURL = http://papelitobrasil.local:8080`
- `WP_ENVIRONMENT_TYPE = local`
- `WP_CACHE = false`

Isso é importante porque o arquivo original do cliente apontava para a hospedagem da Hostinger.

## 18. Fluxo recomendado sempre que receber um banco novo do cliente

Quando receber um dump novo:

1. salve o `.sql` dentro de `backup/`
2. derrube o ambiente com volume, se quiser substituir completamente o banco anterior
3. suba novamente os containers
4. rode o script `local-wordpress-setup.sh`
5. abra o site
6. valide frontend, admin, Elementor e WooCommerce

## 19. Checklist de validação após subir

Depois que o ambiente estiver pronto, valide:

1. home carrega
2. `/wp-admin` abre
3. login funciona
4. imagens carregam
5. páginas internas carregam
6. Elementor abre
7. páginas de produto, carrinho e checkout carregam
8. o domínio usado é `papelitobrasil.local:8080`
9. e-mails de teste aparecem no Mailpit

## 20. Problemas comuns

### O domínio `papelitobrasil.local` não abre

Causa provável:

- o `/etc/hosts` não foi configurado

Solução:

```bash
echo '127.0.0.1 papelitobrasil.local' | sudo tee -a /etc/hosts
```

### O site abre, mas continua puxando links do domínio de produção

Causa provável:

- o script de `search-replace` não rodou
- o banco foi trocado depois da primeira importação

Solução:

```bash
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh
```

### O banco não importa

Causa provável:

- dump ausente
- nome do dump diferente do esperado
- SQL inválido ou incompleto

Solução:

```bash
/home/sea/projetos/papelito/scripts/local-wordpress-setup.sh /caminho/para/arquivo.sql
```

### O WordPress acusa erro de banco

Verifique:

```bash
docker ps
docker logs papelito-db
docker logs papelito-web
```

### Plugins tentam enviar e-mail real

O ambiente já está preparado para usar Mailpit, mas alguns plugins podem ter configuração própria. Se notar comportamento estranho, revise no admin:

- SMTP
- gateways de pagamento
- webhooks
- integrações de frete
- plugins de automação

### WooCommerce ou plugin não consegue escrever log

Foi aplicada permissão local para `wp-content/uploads`, mas se algum plugin criar nova pasta com bloqueio, reaplique:

```bash
chmod -R a+rwX /home/sea/projetos/papelito/public_html/wp-content/uploads
chmod a+rw /home/sea/projetos/papelito/public_html/wp-content/debug.log
```

## 21. Observações importantes

- Este ambiente é local e não deve ser usado para disparar integrações reais.
- Pagamentos, webhooks e integrações externas devem ser tratados com cautela.
- O banco local é uma cópia do cliente; portanto, tenha cuidado com dados reais.
- O projeto não está dentro de um repositório Git nesta pasta.
