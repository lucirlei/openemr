import { apiClient } from './apiClient.js';

const TEMPLATE_SELECTOR = '[data-component="pos-panel"]';

function renderMetric(metric) {
    const trendClass = metric.trend && metric.trend.direction === 'up' ? 'text-success' : 'text-danger';
    const trendIcon = metric.trend && metric.trend.direction === 'up' ? '▲' : '▼';
    const trendValue = metric.trend ? `${trendIcon} ${metric.trend.value}` : '';

    return `
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="mb-0 text-muted">${metric.label}</p>
                <h4 class="mb-0">${metric.value}</h4>
            </div>
            <span class="fw-semibold ${trendClass}">${trendValue}</span>
        </div>
    `;
}

function renderTopProducts(products) {
    return `
        <ol class="list-unstyled mb-0">
            ${products
                .map(
                    (product) => `
                        <li class="d-flex justify-content-between py-1">
                            <span>${product.name}</span>
                            <span class="fw-semibold">${product.total}</span>
                        </li>
                    `
                )
                .join('')}
        </ol>
    `;
}

function renderPos(container, data) {
    container.querySelector('[data-region="metrics"]').innerHTML = (data.metrics ?? [])
        .map(renderMetric)
        .join('');

    container.querySelector('[data-region="top-products"]').innerHTML = renderTopProducts(data.topProducts ?? []);

    container.querySelector('[data-region="actions"]').innerHTML = `
        <button type="button" class="btn se-gradient-button" data-action="create-sale">
            Nova venda
        </button>
        <button type="button" class="btn btn-outline-primary rounded-pill" data-action="print-report">
            Relatório diário
        </button>
    `;
}

export async function hydratePosPanels(root = document) {
    const panels = Array.from(root.querySelectorAll(TEMPLATE_SELECTOR));

    await Promise.all(
        panels.map(async (panel) => {
            panel.classList.add('is-loading');
            try {
                const data = await apiClient.getPosSnapshot({
                    date: panel.dataset.date,
                    locationId: panel.dataset.locationId,
                });
                renderPos(panel, data);
                panel.classList.add('is-ready');
            } catch (error) {
                console.error('Erro ao carregar POS', error);
                panel.classList.add('has-error');
                panel.querySelector('[data-region="metrics"]').innerHTML = `
                    <div class="alert alert-danger mb-0">${error.message}</div>
                `;
            } finally {
                panel.classList.remove('is-loading');
            }
        })
    );
}

document.addEventListener('DOMContentLoaded', () => hydratePosPanels());
