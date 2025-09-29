# Catálogo de Modelos LBF

Este diretório contém modelos prontos de formulários _Layout Based Forms_ (LBF) focados em procedimentos estéticos, avaliação corporal e programas de emagrecimento. Os arquivos `.json` descrevem grupos, campos e metadados que podem ser importados pelo assistente administrativo de LBF para gerar novas fichas rapidamente.

## Modelos Disponíveis

| Arquivo | Descrição | Uso sugerido |
|---------|-----------|--------------|
| `bioimpedancia.json` | Avaliação de bioimpedância com campos biométricos e resumo de composição corporal. | Consultas iniciais de acompanhamento nutricional/estético. |
| `avaliacao_corporal.json` | Checklist fotográfico e medidas antropométricas detalhadas. | Clínicas de harmonização corporal e acompanhamento de resultados. |
| `plano_emagrecimento.json` | Plano de emagrecimento com metas, intervenções e follow-up multidisciplinar. | Programas de perda de peso e reeducação alimentar. |

Cada arquivo traz, opcionalmente, termos de consentimento específicos e listas de verificação de riscos que podem ser anexadas automaticamente ao prontuário via fluxo de assinatura eletrônica.

## Estrutura do JSON

```json
{
  "form_id": "LBFbioimp",
  "title": "Bioimpedância Corporal",
  "category": "Estética",
  "description": "Avaliação de composição corporal e alertas nutricionais",
  "groups": [
    {
      "id": "",
      "title": "Bioimpedância Corporal",
      "subtitle": "Avaliação inicial",
      "sequence": 10,
      "columns": 2
    },
    {
      "id": "1METRIC",
      "title": "Métricas",
      "sequence": 20,
      "columns": 2
    }
  ],
  "fields": [
    {
      "id": "weight",
      "group": "1METRIC",
      "title": "Peso (kg)",
      "sequence": 10,
      "data_type": 2,
      "uor": 1,
      "length": 6,
      "max_length": 6,
      "description": "Peso atual"
    }
  ],
  "consents": [
    {
      "title": "Consentimento Bioimpedância",
      "risks": [
        "Alterações na hidratação corporal podem distorcer resultados",
        "Contraindicado para gestantes e portadores de marcapasso"
      ]
    }
  ]
}
```

Os campos `groups` e `fields` são convertidos para `layout_group_properties` e `layout_options`. O assistente aceita sobreposição de identificadores, títulos, categoria e opções de assinatura eletrônica.

## Como utilizar

1. Acesse **Administração → Formulários → Assistente LBF**.
2. Escolha o modelo desejado e defina os identificadores da nova ficha.
3. Confirme para criar automaticamente a estrutura da ficha, registrar o consentimento e habilitar a assinatura eletrônica.
4. Ajuste os campos pelo editor LBF tradicional, se necessário.

> **Dica:** mantenha os identificadores (`form_id`, `group.id`, `field.id`) curtos e únicos. Utilize apenas caracteres alfanuméricos e sublinhados.

## Adicionando novos modelos

1. Copie um arquivo existente, altere `form_id`, `title`, grupos e campos.
2. Atualize a descrição e o checklist de riscos.
3. Salve o JSON neste diretório. O assistente detecta novos arquivos automaticamente.

