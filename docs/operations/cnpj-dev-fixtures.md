# Fixtures de CNPJ para o cadastro B2B local

Referência canônica das empresas fictícias usadas para percorrer o cadastro B2B em ambiente local:
credenciais completas, regras de digitação, diagnóstico de erro e como acrescentar um cenário.

Implementação em [`includes/cnpj_dev_fixtures.php`](../../public_html/wp-content/plugins/plugin_papelito/includes/cnpj_dev_fixtures.php),
teste em `tests/test-cnpj-dev-fixtures.php`. Para subir o ambiente, ver
[context/local-environment.md](../context/local-environment.md).

## Por que isso existe

O cadastro só avança quando o QSA devolvido pela Receita bate com o CPF, o nome e a data de
nascimento digitados. Sem ser sócio de uma empresa real, o fluxo sempre morre em
`papelito_b2b_qsa_mismatch`. O módulo intercepta a consulta HTTP dos providers e devolve um payload
fictício **no formato bruto da BrasilAPI** — a normalização continua sendo feita pelo adapter de
produção, então a simulação não diverge do contrato interno.

## Como ligar

No `.env` (gitignorado), junto das flags do modelo B2B:

```env
PAPELITO_B2B_COMPANY_MODEL_ENABLED=true
PAPELITO_B2B_COMPANY_WRITES_ENABLED=true
PAPELITO_CNPJ_DEV_FIXTURES_ENABLED=true
```

E recrie **apenas** o container do WordPress:

```bash
docker compose up -d --force-recreate web
```

O gate é **duplo (AND)**: ambiente em `local`/`development` **e** a flag. Nenhuma das duas basta
sozinha. Ligar a variável em produção não tem efeito nenhum — fora do gate as fixtures nem são
carregadas e os filtros não são registrados.

Preencher o `.env` não basta: a variável precisa estar no bloco `environment:` do serviço `web` do
`docker-compose.yml`, senão `getenv()` volta vazio dentro do container. Confira que chegou:

```bash
docker exec papelito-web php -r 'foreach(["WP_ENVIRONMENT_TYPE","PAPELITO_CNPJ_DEV_FIXTURES_ENABLED","PAPELITO_B2B_COMPANY_MODEL_ENABLED","PAPELITO_B2B_COMPANY_WRITES_ENABLED"] as $k){printf("%-40s = %s\n",$k,var_export(getenv($k),true));}'
```

## Os cenários

| | Cenário 1 — caminho limpo | Cenário 2 — exige documento | Cenário 3 — caminho limpo, DF |
|---|---|---|---|
| **CNPJ** | `99.999.001/0001-59` | `99.999.002/0001-01` | `99.999.003/0001-48` |
| **Nome completo** | `Joana Fixture de Almeida` | `Ricardo Mock de Souza` | `Marcos Stub de Oliveira` |
| **CPF** | `123.456.789-09` | `987.654.321-00` | `111.444.777-35` |
| **Nascimento** | `1985-03-12` | `1992-11-05` | `1975-05-22` |
| **CEP** | `01310-100` | `20040-002` | `71200-030` |
| Razão social (vem do mock) | PAPELANDIA DISTRIBUIDORA DE PAPEIS LTDA | IMPERIO DO PAPEL COMERCIO LTDA | CERRADO PAPEIS E SUPRIMENTOS LTDA |
| Nome fantasia | PAPELANDIA | IMPERIO DO PAPEL | CERRADO PAPEIS |
| Logradouro | Avenida Paulista, 1000 — Sala 12 | Avenida Rio Branco, 250 — Andar 3 | SIA Trecho 3, 625 — Galpão 2 |
| Bairro / cidade / UF | Bela Vista — São Paulo/SP | Centro — Rio de Janeiro/RJ | Zona Industrial — Brasília/DF |
| Telefone | (11) 4002-8922 | (21) 4002-8922 | (61) 4002-8922 |
| E-mail da empresa | contato@papelandia.fixture.test | contato@imperiodopapel.fixture.test | contato@cerradopapeis.fixture.test |
| CNAE | 4647801 (atacado) | 4761003 (varejo) | 4647801 (atacado) |
| Porte / Simples | DEMAIS / não | ME / sim | DEMAIS / não |
| Capital social | 250.000 | 80.000 | 400.000 |
| CPF mascarado no QSA | `***456789**` | *ausente, de propósito* | `***444777**` |
| Faixa etária no QSA | 5 (41 a 50) | 4 (31 a 40) | 6 (51 a 60) |
| **`review_path`** | `qsa_review` | `document_required` | `qsa_review` |

O e-mail e a senha do candidato são livres — use qualquer coisa, o e-mail cai no Mailpit
(http://localhost:8025). O que precisa bater com o QSA é **nome, CPF e nascimento**.

O cenário 3 existe para exercitar CEP do Distrito Federal (SIA, Zona Industrial do Guará) sem
depender de vendor de SP ou RJ. O CEP é real e confere no ViaCEP.

Os três terminam em `pending_manual_review` e exigem aprovação em `/admin/empresas`. O cenário 2
pede antes um documento em `/cadastro/analise` — **PDF, JPG ou PNG válido e menor que 10 MB**
(`papelito_company_document_spec()`).

## Regras de digitação: o que é estrito e o que não é

Ao contrário do que a mensagem de erro sugere, os três campos **não** têm o mesmo rigor.

| Campo | Rigor | Detalhe |
|---|---|---|
| **Nome completo** | **estrito** | `papelito_company_names_match()` exige os mesmos tokens na mesma ordem. Só tolera inserir/remover as partículas `de/da/do/das/dos/e`. Sem similaridade, sem prefixo, sem ordem trocada. Acento e caixa são normalizados. |
| CPF | tolerante ao formato | Comparado pela máscara **posicional** `***456789**`, que expõe os dígitos **4 a 9** — não os últimos. Pode digitar com ou sem pontuação. |
| Nascimento | muito tolerante | Comparado só por **faixa etária** (dez anos de largura). Para a Joana, qualquer data entre `1975-09-01` e `1985-08-31` passa. |

Na prática, quase todo `qsa_mismatch` é **erro no nome**. Casos verificados contra o matcher real:

| O que foi digitado | Resultado |
|---|---|
| `Joana Fixture Almeida` (sem a partícula) | passa |
| `JOÃNA FIXTURE DE ALMEIDA` (acento/caixa) | passa |
| `123.456.789-09` (CPF com máscara) | passa |
| nascimento um dia diferente | passa |
| `Joana de Almeida` (faltou sobrenome) | **mismatch** |
| `Almeida Joana Fixture de` (ordem trocada) | **mismatch** |
| CPF ou nascimento de outro cenário | **mismatch** |

## Testar sem passar pela UI

Vai direto no WordPress, pulando o front — útil para separar problema de dado de problema de tela:

```bash
curl -s -X POST http://localhost:8080/wp-json/papelito/v1/company-applications \
  -H 'Content-Type: application/json' \
  -d '{"email":"marcos.teste@fixture.test","password":"SenhaForte#2026",
       "full_name":"Marcos Stub de Oliveira","phone":"61988887777",
       "cpf":"111.444.777-35","birth_date":"1975-05-22","cnpj":"99999003000148",
       "cep":"71200030","street":"SIA Trecho 3","number":"625","complement":"Galpao 2",
       "neighborhood":"Zona Industrial","city":"Brasília","state":"DF"}' | python3 -m json.tool
```

Resposta esperada: **201** com `"reviewPath": "qsa_review"`. Lembre de liberar o CNPJ depois
(seção abaixo) — a candidatura criada aqui ocupa o cadastro.

## Erros e o que significam

| Sintoma | Causa | O que fazer |
|---|---|---|
| `papelito_b2b_qsa_mismatch` (422) | nome, CPF ou nascimento não batem com o QSA | conferir o **nome** primeiro (ver tabela de rigor) |
| **`Verifique os dados informados.`** na tela | é o texto genérico do front | o motivo real está no corpo da resposta; ver a armadilha abaixo |
| 409 | já existe candidatura aberta para o CNPJ | liberar o CNPJ (seção abaixo) |
| 429 | rate limit de **5/60s por IP** em `/company-applications` | esperar um minuto |
| `papelito_b2b_registry_inactive` (422) **na aprovação** | a flag foi desligada entre a candidatura e a aprovação, e a reconsulta foi para os providers reais | religar a flag e reaprovar; nada corrompe |
| `papelito_b2b_qsa_unavailable` (503) | fixture não interceptou; a consulta saiu para a rede | conferir gate duplo e se o container foi recriado |

> **Armadilha do 422 no front.** Em `app/cadastro/etapa-2/page.tsx`, o ramo de 422 lê apenas
> `body.data.errors` e **descarta `body.message`**. Os `WP_Error` de negócio (`qsa_mismatch`,
> `registry_inactive`, `underage_birth_date`) só mandam `data.status`, sem `errors` — então a
> mensagem útil nunca chega na tela e sobra o genérico. Para ver o motivo real: aba Network do
> navegador, ou o `curl` acima.

## Liberar um CNPJ para repetir o teste

Cada CNPJ serve a **um cadastro por vez** (`UNIQUE (canonical_cnpj, is_open)` +
`papelito_company_find_by_cnpj()`). Pelo admin, remova empresa e usuário. Direto no banco:

```bash
docker exec papelito-db mariadb -uroot -proot_local_123 papelito_local -e "
SELECT id, canonical_cnpj, review_path, application_status, is_open, created_at
  FROM wp_papelito_company_pre_account_applications
 WHERE canonical_cnpj IN ('99999001000159','99999002000101','99999003000148');"
```

Depois apague pelo `id` conferido, e verifique que não sobrou empresa nem usuário:

```bash
docker exec papelito-db mariadb -uroot -proot_local_123 papelito_local -e "
DELETE FROM wp_papelito_company_pre_account_applications WHERE id = <ID>;
SELECT id, cnpj FROM wp_papelito_companies WHERE cnpj = '<CNPJ>';
SELECT ID, user_email FROM wp_users WHERE user_email LIKE '%@fixture.test';"
```

`wp db query` **não funciona** aqui — o WP-CLI do container falha com `TLS/SSL error: SSL is
required`. Use o cliente do container `papelito-db`, como acima.

Não é preciso limpar transient. O cache das fixtures é isolado por namespace
(`papelito_cnpj_devfix_<rev>_<cnpj>`, via o filtro `papelito_cnpj_cache_key`): a chave canônica
nunca recebe dado fictício, então desligar a flag dá cache miss em vez de continuar servindo
`active` por 7 dias. O `<rev>` vem do conteúdo das fixtures — editar uma invalida o cache dela
sozinha.

## Acrescentar um cenário novo

1. **Gere o CNPJ** com DV válido, seguindo a série (`99999004…`, `99999005…`).
2. **Escolha um CPF válido** e derive a máscara **posicional**: `'***' . substr($cpf, 3, 6) . '**'`.
   Precisa ter 11 caracteres e ao menos 6 dígitos visíveis, senão `qsa_sufficient` fica falso.
3. **Calcule a faixa etária** a partir do nascimento — ela é comparada contra a idade de **hoje**.
   Prefira uma data logo depois de a pessoa entrar na faixa, para o cenário durar quase dez anos.
4. Acrescente a entrada em `papelito_cnpj_dev_fixtures()`.
5. **Atualize `tests/test-cnpj-dev-fixtures.php`**: ele trava a contagem de fixtures
   (`carrega as N fixtures` e a matriz de boot). Sem isso a suíte quebra.
6. Rode `php tests/test-cnpj-dev-fixtures.php` e confirme `RESULT: all assertions passed`.

Script de apoio para os passos 1–3:

```php
<?php
define('ABSPATH','/tmp/');
require 'public_html/wp-content/plugins/plugin_papelito/includes/cnpj_validation.php';

$base = '999990040001';
for ($d = 0; $d < 100; $d++) {
    $c = $base . sprintf('%02d', $d);
    if (papelito_validate_cnpj($c)) { echo "CNPJ: $c\n"; break; }
}

$cpf = '11144477735';
echo 'CPF valido: ', var_export(papelito_validate_cpf($cpf), true), "\n";
echo 'Mascara:    ', '***' . substr($cpf, 3, 6) . "**\n";

$nasc  = '1975-05-22';
$idade = (new DateTimeImmutable($nasc))->diff(new DateTimeImmutable('now'))->y;
echo "Idade hoje: $idade\n";
```

Faixas: 1 = 0–12 · 2 = 13–20 · 3 = 21–30 · 4 = 31–40 · 5 = 41–50 · 6 = 51–60 · 7 = 61–70 ·
8 = 71–80 · 9 = 80+.

## Manutenção: quando os cenários expiram

`codigo_faixa_etaria` é comparado contra a idade calculada **hoje**. Quando o sócio fictício muda de
faixa, o cenário passa a dar `qsa_mismatch` sem que nada tenha sido alterado no código.

| Cenário | Faixa | Quebra em |
|---|---|---|
| 1 — Joana | 5 | 12/03/2036 |
| 2 — Ricardo | 4 | 05/11/2033 |
| 3 — Marcos | 6 | 22/05/2036 |

Trocar o CPF ou a data de nascimento de um cenário exige **recalcular a máscara e a faixa juntas**.

## Garantias de segurança

- **Zero egress.** O responder devolve `404` para qualquer host que não seja o da BrasilAPI —
  inclusive um provider que venha a ser adicionado depois. Nenhum CNPJ fictício sai pela rede.
  `php tests/test-cnpj-dev-fixtures.php` verifica isso contando as chamadas.
- **Fora do gate o dado nem existe em memória**: `papelito_cnpj_dev_fixtures()` devolve array vazio
  mesmo que alguém chame a função diretamente.
- Os documentos são sintéticos e nunca saem do MySQL local.
