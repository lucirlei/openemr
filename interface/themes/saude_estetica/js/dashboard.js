import { apiClient } from './apiClient.js';

const TEMPLATE_SELECTOR = '[data-component="dashboard-panel"]';

function buildStats(stats) {
    return stats
        .map(({ label, value, trend }) => `
            <div class="se-stat">
                <span class="se-stat__label">${label}</span>
                <span class="se-stat__value">${value}</span>
                ${trend ? `<span class="se-chip">${trend}</span>` : ''}
            </div>
        `)
        .join('');
}

function buildTimeline(timeline) {
    return `
        <div class="se-timeline">
            ${timeline
                .map(
                    (item) => `
                        <div class="se-timeline__item">
                            <span class="se-timeline__dot" aria-hidden="true"></span>
                            <h5 class="mb-1">${item.title}</h5>
                            <p class="text-muted mb-0">${item.date} · ${item.description}</p>
                        </div>
                    `
                )
                .join('')}
        </div>
    `;
}

function buildActions(actions) {
    return actions
        .map(
            (action) => `
                <a class="btn se-gradient-button" href="${action.href}" data-action="${action.id}">
                    ${action.label}
                </a>
            `
        )
        .join('');
}

function renderPanel(container, data) {
    const statsMarkup = buildStats(data.stats ?? []);
    const timelineMarkup = buildTimeline(data.timeline ?? []);
    const actionsMarkup = buildActions(data.actions ?? []);

    container.querySelector('[data-region="stats"]').innerHTML = statsMarkup;
    container.querySelector('[data-region="timeline"]').innerHTML = timelineMarkup;
    container.querySelector('[data-region="actions"]').innerHTML = actionsMarkup;
}

export async function hydrateDashboardPanels(root = document) {
    const panels = Array.from(root.querySelectorAll(TEMPLATE_SELECTOR));

    await Promise.all(
        panels.map(async (panel) => {
            panel.classList.add('is-loading');

            try {
                const data = await apiClient.getDashboardCards({
                    clinicId: panel.dataset.clinicId,
                    professionalId: panel.dataset.professionalId,
                });
                renderPanel(panel, data);
                panel.classList.add('is-ready');
            } catch (error) {
                console.error('Erro ao carregar painel do dashboard', error);
                panel.classList.add('has-error');
                panel.querySelector('[data-region="stats"]').innerHTML = `
                    <div class="alert alert-danger mb-0" role="alert">
                        ${error.message}
                    </div>
                `;
            } finally {
                panel.classList.remove('is-loading');
            }
        })
    );
}

document.addEventListener('DOMContentLoaded', () => hydrateDashboardPanels());
