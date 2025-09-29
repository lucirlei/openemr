# Avaliação de filas e serverless para disparos de marketing

Este documento descreve como o novo **CRM Message Bus** pode ser integrado a
infraestruturas de mensageria ou funções serverless para ampliar os disparos de
marketing automatizados.

## Cenário atual

* O serviço `CrmMessageBus` envia eventos (`crm.lead.created`,
  `crm.lead.updated`, `crm.lead.rewarded`) para o `CrmIntegrationService`, que
  persiste um log em `crm_interactions` e expõe uma fila de webhooks.
* Os novos containers `crm-service` e `billing-service` permitem isolar
  workloads específicos sem impactar o OpenEMR principal.

## Opções de mensageria

### RabbitMQ (self-hosted)

* **Prós:** controle total, suporte a rotas tópicas, plugins para retries.
* **Contras:** exige operação 24/7, atualizações de segurança e monitoramento.
* **Uso sugerido:** eventos de alta cadência (leads importados em massa) onde a
  latência precisa ser inferior a 1 segundo.

### Redis Streams

* **Prós:** já disponível em muitos ambientes, simples para filas FIFO.
* **Contras:** não possui DLQ nativa; indicado apenas para workloads médios.
* **Uso sugerido:** integração rápida com o módulo de Agenda para pré-agendar
  follow-ups de leads.

## Opções serverless

### AWS EventBridge + Lambda

* **Prós:** escala automática, billing por execução, filtros de eventos.
* **Contras:** dependência de nuvem pública, precisa de VPC/Secrets para acesso
  ao banco.
* **Uso sugerido:** disparos de campanhas omnichannel (SMS/e-mail) com latência
  tolerante (>5s) e enriquecimento por serviços externos (por exemplo, scoring
  em IA).

### Google Cloud Pub/Sub + Cloud Functions

* **Prós:** tolera alto volume, integra com BigQuery para analytics.
* **Contras:** custo maior em regiões fora dos EUA; precisa de Service
  Accounts dedicadas.
* **Uso sugerido:** campanhas que dependem de dados históricos de faturamento
  agregados pelo `billing-service`.

## Recomendações

1. **Curto prazo:** utilizar RabbitMQ comunitário (ou mesmo Redis Streams) para
   orquestrar leads → agenda → PDV com confirmação síncrona.
2. **Médio prazo:** publicar eventos estratégicos (`crm.lead.rewarded`) em um
   barramento serverless para disparos de marketing e enriquecimento com IA.
3. **Monitoramento:** configurar dashboards (Prometheus/Grafana ou CloudWatch)
   para acompanhar filas, tempo médio de processamento e falhas por destino.
4. **Segurança:** armazenar segredos de webhooks em cofres (AWS Secrets Manager,
   Hashicorp Vault) e assinar eventos com chave dedicada por destino.

Com esta abordagem híbrida é possível balancear custo, latência e resiliência,
permitindo que marketing digital opere em ciclos curtos sem sobrecarregar o
core clínico.
