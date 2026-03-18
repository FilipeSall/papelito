# Auditoria Tecnica do Marketplace WordPress/WooCommerce

Data da auditoria: 2026-03-16

## Escopo e metodo
- Banco local MariaDB consultado diretamente via `docker exec papelito-db mariadb`.
- Runtime local validado via `docker exec papelito-web curl`.
- Codigo revisado em `wp-config.php`, plugin customizado `plugin_papelito` e tema `jupiterx-child`.
- Logs revisados em `public_html/wp-content/debug.log` e `public_html/wp-content/uploads/wc-logs`.
- Postura adotada para plugins nao utilizados: conservadora.

## Resumo executivo
- O ambiente tem 51 plugins ativos e um stack pesado para um marketplace com apenas 11 vendedores ativos, 624 produtos publicados e forte sinal de duplicacao operacional de produtos.
- O tema ativo no banco e `jupiterx`, nao `jupiterx-child`. Toda customizacao aberta hoje no child theme esta inativa em runtime ate a troca de `stylesheet/template`.
- Existem riscos prioritarios de producao: `ALLOW_UNFILTERED_UPLOADS` ligado, backups grandes dentro do webroot, `WP_DEBUG_LOG` habilitado, `wp-file-manager` ativo e erro recorrente de Dokan por tabela ausente.
- O plugin customizado `plugin_papelito` concentra os principais gaps de seguranca e custo operacional: ausencia de nonce, uso extensivo de `$_POST` e `$_COOKIE` sem `wp_unslash`, `error_log()` em fluxo publico, `setcookie()` sem flags modernas, e queries ineficientes em `pre_get_posts`.
- O front publico carrega ativos demais para usuarios anonimos. A home carrega GTM duas vezes, ativos do Dokan aparecem no HTML publico e a pagina de conta injeta script via CDN.

## Fatos confirmados
| Item | Evidencia |
| --- | --- |
| Tema ativo | `stylesheet = jupiterx`, `template = jupiterx` |
| Plugins ativos | 51 entradas em `wp_options.active_plugins` |
| Backups expostos em `wp-content` | `ai1wm-backups` 2.5G, `updraft` 431M |
| Logs grandes | `debug.log` 15M, `uploads/wc-logs` 22M |
| PHP local | `PHP 7.4.33` |
| Vendedores ativos | 11 usuarios com role `seller` |
| Produtos publicados | 624 `product` com status `publish` |
| Duplicacao de catalogo | varios titulos com 13-17 ocorrencias identicas |
| Dokan Pro | `dokan_pro_active_modules = a:0:{}` |
| Redirection | `wp_redirection_items = 0` |
| SellKit | `wp_sellkit_contact_segmentation = 998`, `wp_sellkit_funnel_contact = 0` |
| ActiveCampaign | `wp_wc_activecampaign = 149` |
| GTM na home | `GTM-TJLW2TT` aparece 2 vezes no HTML |
| Dokan no front publico | string `dokan-` aparece 38 vezes no HTML da home |
| Spam do plugin customizado | 3784 ocorrencias de logs de CEP no `debug.log` |
| Email templates duplicados | `yaymail_template = 47`, `viwec_template = 14` |

## Top 10 riscos
| Severidade | Risco | Impacto | Prova encontrada | Recomendacao objetiva | Esforco | Dependencia de negocio |
| --- | --- | --- | --- | --- | --- | --- |
| Critica | `wp-file-manager` ativo em producao | Amplia superficie de ataque e historicamente e um plugin sensivel | Plugin ativo em `active_plugins` | Desativar e remover se nao houver uso operacional real | Baixo | Confirmar se alguem ainda usa acesso por file manager |
| Critica | `ALLOW_UNFILTERED_UPLOADS` ativo | Permite uploads inseguros e piora o risco de execucao arbitraria | `public_html/wp-config.php:116` | Desligar em producao e liberar uploads especiais por fluxo controlado | Baixo | Verificar se existe dependencia de upload SVG ou outro formato |
| Critica | Backups dentro do webroot | Risco de exposicao acidental, crescimento de disco e impacto em backup/deploy | `ai1wm-backups` 2.5G e `updraft` 431M em `wp-content` | Mover backups para storage externo/fora do webroot e limpar residuos antigos | Medio | Confirmar ferramenta oficial de backup do cliente |
| Alta | Dokan inconsistente | Relatorios quebrados e comportamento imprevisivel no dashboard | `debug.log` mostra erro para tabela ausente `wp_dokan_order_stats`; `dokan_pro_active_modules = a:0:{}` | Revalidar a instalacao do Dokan Pro, rodar migracoes necessarias ou simplificar para Dokan Lite se o Pro nao for usado | Medio | Confirmar se o cliente usa modulos pagos do Dokan |
| Alta | Tema filho inativo | Codigo customizado nao afeta o site e cria falsa sensacao de cobertura | Banco aponta `jupiterx`, nao `jupiterx-child` | Decidir entre ativar o child theme com validacao previa ou migrar customizacoes para plugin/tema ativo | Medio | Confirmar estrategia de tema do projeto |
| Alta | Plugin customizado logando fluxo publico | `debug.log` cresce sem necessidade e mascara erros reais | 3784 logs de CEP; `error_log()` em [products_filter.php](public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php) | Remover logs publicos e usar logging estruturado somente em ambiente de debug | Baixo | Nenhuma |
| Alta | Ausencia de nonce/capability robusta em codigo customizado | Risco de alteracao indevida de dados no admin e mau manuseio de entrada | Salvamentos em [plugin_papelito.php](public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php) e [functions.php](public_html/wp-content/themes/jupiterx-child/functions.php) sem nonce | Adicionar nonce, `current_user_can`, `wp_unslash` e sanitizacao consistente | Medio | Nenhuma |
| Alta | GTM duplicado | Distorce analytics e aumenta JS desnecessario | Home contem GTM hardcoded e plugin `gtm4wp` ao mesmo tempo | Consolidar em uma unica origem de GTM | Baixo | Confirmar qual implementacao o marketing quer manter |
| Media | Sobreposicao de templates de email | Complexidade de manutencao, conflito visual e custo de teste | 47 templates YayMail e 14 templates VIWEC publicados/ativos | Manter uma unica stack de email customizer | Medio | Confirmar qual editor o time usa no admin |
| Media | Stack de marketing e automacao inchada | Mais JS/CSS, mais filas, mais falhas e maior custo de upgrade | SellKit, ActiveCampaign, Woo Cart Abandonment, GTM, Loyalty e varias notices/logs | Mapear o funil real e consolidar plugins por objetivo | Medio | Confirmar fluxo real de CRM e automacao |

## Matriz unica de plugins e residuos

### Essencial
| Plugin ou diretorio | Estado | Evidencia | Recomendacao |
| --- | --- | --- | --- |
| `woocommerce` | Essencial | Loja, carrinho e checkout configurados e funcionando | Manter |
| `dokan-lite` | Essencial | Paginas `Dashboard`, `Store List` e fluxo marketplace existem | Manter |
| `elementor` | Essencial | 2278 registros `_elementor_data` | Manter |
| `elementor-pro` | Essencial | Formularios e recursos pro em uso | Manter |
| `jupiterx-core` | Essencial | Tema ativo e stack visual dependem dele | Manter |
| `plugin_papelito` | Essencial | Regras de CEP e cadastro customizado do negocio | Manter e refatorar |
| `pagarme-payments-for-woocommerce` | Essencial | Gateway de pagamento ativo no stack | Manter |
| `jet-engine` | Essencial | CSS carregado no front e stack Crocoblock em uso | Manter |
| `jet-smart-filters` | Essencial | Marketplace com filtragem e stack Jet em uso | Manter |
| `jet-woo-builder` | Essencial | 1773 metas `_jet_woo_template` e 2 posts `jet-woo-builder` | Manter |
| `jetwoo-widgets-for-elementor` | Essencial | Complementa o builder Woo do Crocoblock | Manter |
| `woocommerce-extra-checkout-fields-for-brazil` | Essencial | Campos brasileiros aparecem no HTML do carrinho/checkout | Manter |
| `litespeed-cache` | Essencial | `object-cache.php` ativo em `wp-content` | Manter, mas revisar configuracao |

### Usar com ressalvas
| Plugin ou diretorio | Estado | Evidencia | Recomendacao |
| --- | --- | --- | --- |
| `dokan-pro` | Usar com ressalvas | Ativo, mas com modulos vazios e erro de tabela ausente | Revalidar necessidade e instalacao |
| `activecampaign-for-woocommerce` | Usar com ressalvas | Tabela `wp_wc_activecampaign` com 149 registros e logs Woo | Manter so se CRM realmente usado |
| `advanced-custom-fields` | Usar com ressalvas | Ativo no stack; possivel dependencia de conteudo | Confirmar fields em uso antes de mexer |
| `age-gate` | Usar com ressalvas | Mercado sugere requisito regulatorio | Validar se o gate esta realmente aparecendo no front |
| `all-in-one-wp-migration` | Usar com ressalvas | Backups `.wpress` existentes | Tirar de producao se o uso for apenas operacional |
| `all-in-one-wp-migration-unlimited-extension` | Usar com ressalvas | Mesma familia de backup e restore | Mesmo tratamento do item acima |
| `code-snippets` | Usar com ressalvas | Pode esconder logica critica fora do versionamento | Auditar snippets antes de manter |
| `dokan-menu-hider` | Usar com ressalvas | Ajuste administrativo do Dokan | Manter so se houver menus realmente ocultos em uso |
| `duplicate-post` | Usar com ressalvas | Ferramenta editorial/admin | Manter somente se o time usa no dia a dia |
| `duracelltomi-google-tag-manager` | Usar com ressalvas | GTM esta em uso, mas duplicado | Consolidar com script hardcoded |
| `dynamic-visibility-for-elementor` | Usar com ressalvas | CSS carregado no front | Validar necessidade pagina a pagina |
| `jet-blog` | Usar com ressalvas | Pode estar sustentando a pagina de blog | Validar widgets usados no template |
| `jet-elements` | Usar com ressalvas | Pacote de widgets Elementor frequentemente acoplado ao tema | Validar widgets realmente usados |
| `jet-search` | Usar com ressalvas | Possivel dependencia de busca do catalogo | Confirmar uso na UI |
| `loja5-woo-total-express` | Usar com ressalvas | Integracao logistica especifica | Confirmar contrato e uso operacional |
| `loco-translate` | Usar com ressalvas | Utilitario admin | Melhor em homologacao do que em producao |
| `sellkit` | Usar com ressalvas | Cookie e tabela de segmentacao ativos; notices no log | Manter so se o funil estiver ativo |
| `sellkit-pro` | Usar com ressalvas | Complementa o SellKit, mas gera notices | Mesmo tratamento do item acima |
| `simple-history` | Usar com ressalvas | Utilitario admin | Manter se houver necessidade de auditoria interna |
| `user-role-editor` | Usar com ressalvas | Utilitario admin sensivel | Restringir uso a administradores confiaveis |
| `woo-cart-abandonment-recovery` | Usar com ressalvas | Plugin ativo de automacao de funil | Confirmar se nao sobrepoe ActiveCampaign/SellKit |
| `woo-order-export-lite` | Usar com ressalvas | Utilitario operacional | Manter se o financeiro/operacao usa exportacao |
| `woocommerce-correios` | Usar com ressalvas | Integracao logistica brasileira | Validar se ainda e usada junto com outras transportadoras |
| `wp-loyalty-rules` | Usar com ressalvas | Roles customizadas e assets no front | Mapear regras reais e impacto no checkout |
| `wp-loyalty-translate` | Usar com ressalvas | Plugin complementar do loyalty | Manter apenas junto do principal |
| `wp-mail-smtp` | Usar com ressalvas | Infra de envio de email | Manter, mas validar credenciais e monitoramento |
| `yaymail-addon-for-dokan` | Usar com ressalvas | Faz sentido somente com YayMail | Depende da decisao sobre stack de email |
| `yaymail-pro` | Usar com ressalvas | 47 templates publicados | Candidato a stack principal de emails se aprovado |

### Suspeito de redundancia
| Plugin ou diretorio | Estado | Evidencia | Recomendacao |
| --- | --- | --- | --- |
| `email-template-customizer-for-woo` | Suspeito de redundancia | 14 `viwec_template` coexistem com 47 templates YayMail | Escolher VIWEC ou YayMail, nao ambos |
| `insert-headers-and-footers` | Suspeito de redundancia | GTM ja esta hardcoded e tambem via plugin GTM | Consolidar para uma unica camada de tags |
| `optimole-wp` | Suspeito de redundancia | Stack ja usa LiteSpeed Cache/object cache | Manter somente se houver ganho real em imagem/CDN |
| `otter-blocks` | Suspeito de redundancia | Site e majoritariamente Elementor/JupiterX | Validar se ha blocos Otter ativos no conteudo |
| `redirection` | Suspeito de redundancia | `wp_redirection_items = 0` | Pode ser removido se nao houver uso futuro planejado |
| `woo-update-manager` | Suspeito de redundancia | Plugin auxiliar pouco transparente para upgrades | Validar se controla licencas ou updates do cliente |

### Candidato forte a remocao
| Plugin ou diretorio | Estado | Evidencia | Recomendacao |
| --- | --- | --- | --- |
| `wp-file-manager` | Candidato forte a remocao | Alto risco e sem justificativa tecnica forte para producao | Remover |
| `child-theme-wizard` | Candidato forte a remocao | So faz sentido na criacao inicial do child theme | Remover |
| `wordpress-importer` | Candidato forte a remocao | Ferramenta de migracao pontual, nao de runtime | Remover |
| `wp-rollback` | Candidato forte a remocao | Ferramenta administrativa de excecao | Remover de producao |
| `__MACOSX` | Candidato forte a remocao | Residuo de zip no diretorio de plugins | Remover |
| `elementor-3.35.7-local-backup` | Candidato forte a remocao | Backup residual com 76M fora do fluxo de plugin ativo | Remover |
| `includes` | Candidato forte a remocao | Diretorio generico residual em `plugins/` | Remover apos validar origem |
| `vendor` | Candidato forte a remocao | Diretorio generico residual em `plugins/` | Remover apos validar origem |

## Sobreposicoes a consolidar
| Grupo | Evidencia | Risco | Recomendacao |
| --- | --- | --- | --- |
| YayMail Pro + YayMail Dokan + Email Template Customizer for Woo | 47 templates YayMail e 14 VIWEC coexistem | Duplicidade de templates e testes de email | Escolher uma unica stack |
| LiteSpeed Cache + Optimole | Cache/object cache ja ativos; Optimole tambem ativo | Sobreposicao de otimizacao e complexidade de troubleshooting | Manter um stack principal de performance |
| Insert Headers and Footers + GTM plugin + GTM hardcoded | GTM aparece 2 vezes na home | Distorcao de analytics | Centralizar em uma unica implementacao |
| SellKit + Woo Cart Abandonment + ActiveCampaign | Tabelas, cookies e logs mostram tres camadas de automacao | Mais scripts, filas, conflitos e custo de manutencao | Desenhar funil unico por objetivo |

## Auditoria do codigo customizado

### `plugin_papelito`
| Arquivo | Achado | Evidencia | Risco | Recomendacao |
| --- | --- | --- | --- | --- |
| `public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php` | Filtro principal faz `get_users()` e `get_user_meta()` em loop no `pre_get_posts` | linhas 28-64 | Custo alto por request de catalogo | Reescrever para consulta indexada/cacheada por faixa de CEP |
| `public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php` | `error_log()` em fluxo publico | linhas 35-36, 47-48, 58, 62, 67 | Polui `debug.log` e mascara falhas reais | Remover logs ou condicionar por ambiente |
| `public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php` | Uso de `$_COOKIE` sem `wp_unslash` e validacao forte | linhas 19-23, 75-76 | Entrada externa fraca | Normalizar leitura de cookie e validar formato de CEP |
| `public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php` | SQL de relacionados concatenado no `where` | linhas 115-123 | Acoplamento fragil e manutencao dificil | Trocar por filtro mais seguro ou args nativos |
| `public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php` | Duplicacao automatica de produtos no `user_register` | linhas 127-183 | Explosao de catalogo, duplicatas e custo operacional | Migrar para acao explicita e idempotente |
| `public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php` | Salvamento de perfil sem nonce dedicado | linhas 154-204 | Integridade e seguranca do admin | Adicionar nonce e sanitizacao consistente |
| `public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php` | `setcookie()` sem `httponly`, `secure`, `samesite` | linhas 207-220 | Cookie fraco para dado funcional do catalogo | Usar `setcookie` com array de opcoes e flags modernas |
| `public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php` | Script externo via CDN no admin | linhas 224-228 | Dependencia externa e risco de disponibilidade | Servir localmente ou empacotar via assets do projeto |
| `public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php` | Escrita de log dentro do plugin | linhas 231-240 | Acumulo de arquivo e permissao sensivel | Usar logger central ou `WC_Logger` |

### `user_registration.php`
| Arquivo | Achado | Evidencia | Risco | Recomendacao |
| --- | --- | --- | --- | --- |
| `public_html/wp-content/plugins/plugin_papelito/includes/user_registration.php` | `$_POST` usado sem `wp_unslash` em varios campos | linhas 50, 63, 76, 175-176 e seguintes | Sanitizacao incompleta | Padronizar `wp_unslash` + sanitizacao por tipo |
| `public_html/wp-content/plugins/plugin_papelito/includes/user_registration.php` | Validacao e persistencia misturam logica de cadastro e edicao | linhas 37-260 | Codigo fragil e dificil de evoluir | Separar fluxo de cadastro, conta e admin |
| `public_html/wp-content/plugins/plugin_papelito/includes/user_registration.php` | Dependencia de CDN para `jquery.mask` no front | linhas 182-189 | Bloqueio externo e piora de performance | Empacotar localmente |
| `public_html/wp-content/plugins/plugin_papelito/includes/user_registration.php` | Constante `brazilian_states` tem `PN` em vez de `PR` | linha 22 | Dado incorreto de estado | Corrigir lista de estados |

### `jupiterx-child/functions.php`
| Arquivo | Achado | Evidencia | Risco | Recomendacao |
| --- | --- | --- | --- | --- |
| `public_html/wp-content/themes/jupiterx-child/functions.php` | Metabox salva `$_POST` sem nonce/capability/sanitizacao | linhas 31-57 | Gap classico de seguranca admin | Corrigir se o child theme voltar a ser usado |
| `public_html/wp-content/themes/jupiterx-child/functions.php` | Child theme inativo | tema ativo no banco e `jupiterx` | Divida tecnica invisivel | Decidir se o child theme vai voltar a ser parte do runtime |

## Smoke tests locais
| Fluxo | Resultado | Evidencia |
| --- | --- | --- |
| Home | Carrega, mas com GTM duplicado e muitos ativos de plugins | GTM aparece 2x; `dokan-` aparece 38x no HTML |
| Minha Conta | Carrega e injeta script do plugin customizado via CDN | HTML contem `cdnjs.cloudflare.com/ajax/libs/jquery.mask` e JS do `plugin_papelito` |
| Dashboard Dokan | Redireciona anonimo para `/minha-conta/` | `HTTP 302 Location: /minha-conta/` |
| Checkout | Redireciona para carrinho vazio sem erro fatal | `HTTP 302 Location: /carrinho/` |

## Top 10 melhorias de performance e manutencao
1. Consolidar a stack de email em uma unica ferramenta.
2. Consolidar GTM em uma unica origem.
3. Tirar backups e logs do webroot.
4. Desativar ferramentas administrativas que nao precisam existir em producao.
5. Reescrever o filtro de produtos por CEP com estrategia cacheada ou tabela auxiliar.
6. Transformar a duplicacao automatica de produtos em rotina controlada e idempotente.
7. Revisar o carregamento de assets de plugins no front anonimo.
8. Reduzir dependencias externas via CDN para scripts basicos.
9. Corrigir a inconsistencia do Dokan antes de qualquer upgrade grande.
10. Decidir a estrategia oficial de tema para eliminar codigo morto.

## Validacoes pendentes no admin
- Confirmar qual editor de email o time realmente usa: YayMail ou VIWEC.
- Confirmar se `Dokan Pro` entrega algum modulo pago que o cliente usa hoje.
- Confirmar se `all-in-one-wp-migration` e `Updraft` precisam coexistir em producao.
- Confirmar se `SellKit`, `Woo Cart Abandonment` e `ActiveCampaign` estao todos ativos no funil real.
- Confirmar se existe snippet critico no `Code Snippets` que nao esta versionado no repositorio.

## Aceite sugerido para a proxima fase
- Remocoes seguras imediatas: `wp-file-manager`, `child-theme-wizard`, `wordpress-importer`, `wp-rollback`, `__MACOSX`, `elementor-3.35.7-local-backup`, `includes`, `vendor`.
- Consolidacoes prioritarias: GTM, stack de email, stack de automacao, stack de performance.
- Correcao tecnica prioritaria: `plugin_papelito`, `wp-config.php`, inconsistencia do Dokan e estrategia de tema.
