import { apiClient } from './apiClient.js';
import { hydrateDashboardPanels } from './dashboard.js';

const PRESENTATION_SELECTOR = '[data-component="presentation-mode"]';

async function loadPresentation(presentation) {
    presentation.classList.add('is-loading');
    try {
        const data = await apiClient.getPresentationSummary({
            encounterId: presentation.dataset.encounterId,
        });

        const summary = presentation.querySelector('[data-region="summary"]');
        summary.innerHTML = `
            <div class="se-card text-center mx-auto" style="max-width: 680px;">
                <h2 class="mb-3">${data.patientName}</h2>
                <p class="lead mb-4">${data.context}</p>
                <div class="se-panel-grid">
                    ${data.highlights
                        .map(
                            (item) => `
                                <div class="se-panel">
                                    <header class="se-panel__header">
                                        <div class="se-panel__icon bg-white shadow-sm">
                                            <span class="fs-3">${item.emoji ?? '✨'}</span>
                                        </div>
                                        <div>
                                            <h3 class="se-panel__title">${item.title}</h3>
                                            <p class="se-panel__subtitle mb-0">${item.subtitle}</p>
                                        </div>
                                    </header>
                                    <div class="se-panel__body">
                                        <p class="mb-0">${item.description}</p>
                                    </div>
                                </div>
                            `
                        )
                        .join('')}
                </div>
            </div>
        `;

        const recommendations = presentation.querySelector('[data-region="recommendations"]');
        recommendations.innerHTML = `
            <div class="se-card mx-auto" style="max-width: 820px;">
                <h3 class="mb-4 text-center">Próximos passos recomendados</h3>
                <ul class="list-unstyled d-grid gap-3 mb-0">
                    ${data.recommendations
                        .map(
                            (item) => `
                                <li class="p-3 rounded-4 bg-light">
                                    <div class="d-flex justify-content-between flex-wrap gap-2 align-items-baseline">
                                        <span class="fw-semibold">${item.title}</span>
                                        <span class="se-chip">${item.timeline}</span>
                                    </div>
                                    <p class="mb-0 mt-2 text-muted">${item.description}</p>
                                </li>
                            `
                        )
                        .join('')}
                </ul>
            </div>
        `;

        presentation.classList.add('is-ready');
        presentation.dispatchEvent(new CustomEvent('presentation:ready', { detail: data }));

        hydrateDashboardPanels(presentation);
    } catch (error) {
        console.error('Erro ao carregar modo apresentação', error);
        presentation.classList.add('has-error');
        presentation.querySelector('[data-region="summary"]').innerHTML = `
            <div class="alert alert-danger" role="alert">${error.message}</div>
        `;
    } finally {
        presentation.classList.remove('is-loading');
    }
}

export function initPresentationMode(root = document) {
    const presentations = Array.from(root.querySelectorAll(PRESENTATION_SELECTOR));
    presentations.forEach((presentation) => loadPresentation(presentation));
}

document.addEventListener('DOMContentLoaded', () => initPresentationMode());
