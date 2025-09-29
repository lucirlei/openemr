import { apiClient } from './apiClient.js';

const TEMPLATE_SELECTOR = '[data-component="agenda-panel"]';

function buildAppointment(item) {
    return `
        <article class="d-flex flex-column flex-md-row gap-3">
            <div class="badge bg-light text-primary px-3 py-2 align-self-start">
                <div class="fw-semibold">${item.time}</div>
                <small class="text-muted">${item.duration}</small>
            </div>
            <div class="flex-fill">
                <h5 class="mb-1">${item.client}</h5>
                <p class="mb-1 text-muted">${item.procedure}</p>
                <div class="d-flex flex-wrap gap-2">
                    ${item.tags
                        .map((tag) => `<span class="se-chip">${tag}</span>`)
                        .join('')}
                </div>
            </div>
        </article>
    `;
}

function renderAgenda(container, data) {
    const appointments = data.appointments ?? [];
    const emptyState = `
        <div class="text-center py-4 text-muted">
            <img src="${container.dataset.emptyIllustration}" alt="" class="img-fluid mb-3" style="max-width: 160px;">
            <p class="mb-0">Nenhum agendamento para o período selecionado.</p>
        </div>
    `;

    container.querySelector('[data-region="appointments"]').innerHTML =
        appointments.length > 0 ? appointments.map(buildAppointment).join('') : emptyState;

    container.querySelector('[data-region="meta"]').innerHTML = `
        <span class="se-chip">${appointments.length} atendimentos</span>
        <span class="se-chip">Ticket médio ${data.avgTicket ?? '—'}</span>
    `;
}

export async function hydrateAgendaPanels(root = document) {
    const panels = Array.from(root.querySelectorAll(TEMPLATE_SELECTOR));

    for (const panel of panels) {
        panel.classList.add('is-loading');
        try {
            const data = await apiClient.getAgenda({
                date: panel.dataset.date,
                professionalId: panel.dataset.professionalId,
                clinicId: panel.dataset.clinicId,
            });
            renderAgenda(panel, data);
            panel.classList.add('is-ready');
        } catch (error) {
            console.error('Erro ao carregar agenda', error);
            panel.classList.add('has-error');
            panel.querySelector('[data-region="appointments"]').innerHTML = `
                <div class="alert alert-danger mb-0">${error.message}</div>
            `;
        } finally {
            panel.classList.remove('is-loading');
        }
    }
}

document.addEventListener('DOMContentLoaded', () => hydrateAgendaPanels());
