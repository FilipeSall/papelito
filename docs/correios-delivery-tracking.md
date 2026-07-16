# Confirmação de entrega pelos Correios

## Fonte de verdade

O backend consulta `GET /srorastro/v1/objetos/{codigoObjeto}?resultado=T`. Somente a combinação oficial `BDE/01` conclui um pacote como entregue. O pedido é projetado como `entregue` apenas quando todos os envios de saída ativos estiverem entregues.

O vendedor e o comprador não podem enviar `enviado` ou `entregue` pelas APIs do Papelito. O frontend apenas apresenta o snapshot persistido pelo backend.

## Persistência

- `wp_papelito_shipments`: associação imutável entre pedido, vendor, pré-postagem e código S10;
- `wp_papelito_tracking_events`: evento bruto, origem e fingerprint idempotente;
- `_papelito_logistics_status`: projeção logística separada do estado comercial/operacional;
- notas do WooCommerce: trilha humana das transições projetadas.

A instalação ocorre automaticamente com `PAPELITO_DB_VERSION=1.5`.

## Agendamento

O hook `papelito_correios_tracking_poll_due` roda a cada cinco minutos pelo Action Scheduler. Quando ele não estiver disponível, usa WP-Cron. Cada execução processa até 40 envios e cada objeto é reconsultado em 10 minutos quando saiu para entrega ou em 30 minutos nos demais estados. Falhas usam backoff exponencial até seis horas.

Para produção, configure um cron real que acione o WordPress; não dependa exclusivamente de tráfego HTTP para disparar WP-Cron.

## Credenciais

São reutilizadas as variáveis já existentes, sempre no servidor:

- `PAPELITO_CORREIOS_USERNAME`;
- `PAPELITO_CORREIOS_ACCESS_CODE`;
- `PAPELITO_CORREIOS_POSTING_CARD`;
- `PAPELITO_CORREIOS_CONTRACT`;
- `PAPELITO_CORREIOS_ENV` (`staging` ou `production`).

Nunca envie essas credenciais ao Next.js ou ao navegador.

## Pré-postagem e etiqueta

O endpoint `POST /papelito/v1/vendor/me/orders/{id}/shipments` chama o filtro server-side `papelito_correios_generate_prepostage`. O adapter deve devolver:

```php
array(
	'prepost_id'    => 'identificador retornado pelos Correios',
	'tracking_code' => 'AA123456789BR',
	'service_code'  => 'codigo contratado',
)
```

O adapter não recebe payload do frontend. Ele deve montar todos os dados a partir do pedido, dos itens e do cadastro validado do vendor.

Esse adapter permanece desabilitado até que o contrato confirme o serviço `86720 — API PRE POSTAGEM` e que o schema vigente seja exportado do CWS autenticado. O manual público não contém o schema completo; por isso nenhum campo de criação foi presumido no plugin. Depois da habilitação, o adapter deve:

1. criar a pré-postagem com o schema oficial vigente;
2. solicitar o rótulo usando as mesmas credenciais;
3. consultar a pré-postagem e obter o código atribuído pelos Correios;
4. devolver apenas os três campos acima ao núcleo de rastreamento.

Até a habilitação, somente um administrador com `manage_woocommerce` pode associar um código por `POST /papelito/v1/admin/orders/{id}/shipments`, deixando nota de auditoria. O vendor nunca pode informar um código arbitrário.

## Eventos adicionais e exceções

O mapa padrão contém apenas combinações demonstradas no manual oficial público:

- `PO/01`: postado;
- `RO/01`: em trânsito;
- `OEC/03`: saiu para entrega;
- `BDE/01`: entregue.

Eventos desconhecidos são armazenados, mas não alteram o estado. Após obter no CWS a tabela oficial do contrato, acrescente combinações pelo filtro `papelito_correios_tracking_event_map`, usando os estados internos já previstos (`pickup_available`, `delivery_failed`, `returning`, `returned` ou `lost`). Nunca classifique genericamente um evento terminal como entrega.

## Operação e auditoria

- `GET /papelito/v1/admin/tracking/health`: contadores de pendências, erros e envios estagnados;
- `GET /papelito/v1/admin/orders/{id}/tracking-events`: histórico bruto do pedido;
- eventos duplicados são recusados por chave única;
- eventos antigos permanecem auditáveis, mas não regridem a projeção;
- códigos S10 não podem ser associados a dois pedidos;
- pedidos cancelados ou reembolsados não são reclassificados comercialmente por um evento posterior, embora o evento logístico continue registrado.

Webhook não está habilitado. Ele só deve ser adicionado depois de os Correios fornecerem para o contrato a especificação oficial de autenticação, payload, reenvio e validação de origem. O polling continua sendo a reconciliação obrigatória mesmo em um futuro modelo híbrido.
