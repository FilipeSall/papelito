# Senha temporária na criação de vendor pelo admin

Contexto da feature que adicionou o campo **"Senha temporária"** ao formulário de
criação de vendor em `/admin/vendors` (painel do `papelito-web`), e o que falta caso
no futuro se queira **forçar a troca dessa senha no primeiro acesso**.

## O que foi implementado

A feature cruza os dois repositórios (mesma feature branch nominal).

### Frontend (`papelito-web`)

- `src/lib/admin-vendors-types.ts` — campo `temporaryPassword: string` adicionado ao
  `AdminVendorCreatePayload`.
- `src/components/layout/admin-panel/sections/vendors/vendor-create-launcher.tsx`:
  - `temporaryPassword` no estado do form (`createInitialForm`); nunca é pré-preenchido,
    mesmo ao clonar um usuário existente (`createFormFromSourceUser` apenas espalha o
    estado inicial vazio).
  - Campo na seção **"Conta"** usando o componente `Field` com `helpText` — o que renderiza
    o ícone de informação "i" existente (`InfoTooltip`). É `type="text"` (visível, **não**
    `type="password"`), `required`, `autoComplete="off"`.
  - Validação obrigatória em `validateForm` (somente não-vazio; **sem regra de
    complexidade**, por decisão de produto).
  - Enviado em `buildPayload` **sem `.trim()`** no valor (senha pode conter espaços; o
    `.trim()` é usado apenas na checagem de vazio da validação).
- A senha não é logada, nem persistida em `localStorage`/`sessionStorage`. A rota proxy
  `app/api/admin/vendors/route.ts` repassa o payload inteiro sem tocar nem logar o campo.

### Backend (`plugin_papelito`)

- `includes/revendedor_application.php`, em `papelito_admin_vendors_create_direct_vendor()`:
  - Lê `temporaryPassword` do payload e usa como `user_pass` no `wp_insert_user` para
    **usuários novos**; se vier vazio, mantém o `wp_generate_password( 32, true, true )`
    anterior.
  - O valor **não** passa por `sanitize_text_field` (corromperia caracteres válidos da
    senha). O WordPress faz o hash via `wp_hash_password` no insert.
  - Para usuários **existentes** (cliente sendo convertido em vendor) o `wp_update_user`
    **não** altera a senha — comportamento preservado. A senha temporária só se aplica a
    contas novas.
- `wp_new_user_notification()` continua sendo disparado apenas para usuários novos
  (comportamento inalterado).

## Não implementado: forçar troca no primeiro acesso

**Hoje não existe nenhum fluxo de "trocar senha no primeiro acesso"** em nenhum dos dois
repositórios (nem usermeta flag, nem middleware, nem checagem no login). O vendor pode
logar e continuar usando a senha temporária indefinidamente; a orientação para trocá-la é
apenas textual (tooltip do campo, que instrui o admin a comunicar a senha e pedir a troca).

Caso se queira **forçar a troca**, o caminho mínimo seria:

1. **Marcar a conta na criação.** Em `papelito_admin_vendors_create_direct_vendor()`,
   quando `temporaryPassword` for usado, gravar uma usermeta — por exemplo
   `papelito_must_change_password = '1'`. Limpar essa meta quando o vendor efetivamente
   trocar a senha.
2. **Expor a flag para o front.** Incluir o estado no token/sessão (similar a
   `profileComplete`, já usado em `src/lib/auth.ts` e em `next-auth.d.ts`), ou em uma query
   `customer`/endpoint REST que o middleware possa consultar.
3. **Bloquear no middleware do Next.** Em `papelito-web/middleware.ts` (que já redireciona
   por `profileComplete`), redirecionar usuários com `mustChangePassword` para uma página de
   troca de senha em rotas protegidas — espelhando o padrão de `/perfil/completar`.
4. **Endpoint de troca.** Criar um `POST /wp-json/papelito/v1/auth/change-password`
   (padrão dos demais em `auth_endpoints.php`: validação + rate limit por IP), que valide a
   senha atual, aplique `wp_set_password` e **apague** `papelito_must_change_password`.
5. **(Opcional) Regras de complexidade.** Se a troca passar a ser obrigatória, vale
   reavaliar exigir complexidade mínima de senha — tanto na nova tela quanto, por
   consistência, no campo de senha temporária do admin (hoje sem regra de complexidade).

> Observação: **não** bloquear via `wp_authenticate_user` no backend — isso quebraria a
> emissão de JWT do login headless. A barreira deve ficar no middleware do Next, após o
> login, exatamente como o guard de `profileComplete`.
