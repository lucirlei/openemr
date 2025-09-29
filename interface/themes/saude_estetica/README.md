# Guia de Estilos "Saúde & Estética"

O tema **Saúde & Estética** introduz um design system baseado em Bootstrap 5 com foco em clínicas de estética, oferecendo componentes modulares Twig/JavaScript, uma paleta sensorial e um modo apresentação para exibição de resultados aos pacientes.

## Estrutura

- `style-guide.html.twig`: showcase do design system com exemplos de cores, tipografia e componentes reutilizáveis.
- `scss/` e `css/`: variáveis SASS e CSS compilado com tokens de design do tema.
- `components/`: componentes Twig modulares para painel do dashboard, agenda e ponto de venda (POS).
- `js/`: módulos ES compatíveis com Webpack/Rollup para consumir APIs REST e renderizar os componentes.
- `assets/icons`: ícones vetoriais específicos para estética.
- `assets/palette.json`: tokens de cor compatíveis com ferramentas de handoff.

## Integração

1. Inclua `css/theme.css` ou processe `scss/saude_estetica.scss` no pipeline Sass do OpenEMR.
2. Importe os módulos `js/*.js` no bundle front-end ou use carregamento dinâmico com `<script type="module">`.
3. Renderize os componentes Twig passando os dados provenientes das novas APIs REST (`/apis/saude-estetica/*`).
4. Ative o modo apresentação em páginas Twig incluídas no módulo visual (ex.: `templates/saude_estetica/presentation_mode.html.twig`).

## Testes de Responsividade

Os componentes foram projetados para se adaptar do mobile ao desktop. Para testes cross-browser utilize:

```bash
npm run lint:styles
npm run test:panels
npx playwright test --project='Desktop Chrome, Mobile Safari'
```

> ⚠️ Os comandos pressupõem a configuração prévia dos utilitários correspondentes.

## Licenciamento de Ícones

Os ícones SVG foram criados para este tema e podem ser usados livremente dentro do projeto OpenEMR.
