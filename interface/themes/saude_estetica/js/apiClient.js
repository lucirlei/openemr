/**
 * Cliente REST para o design system Saúde & Estética.
 * Permite substituir facilmente o backend via injeção de dependência.
 */
export class SaudeEsteticaApiClient {
    constructor({ baseUrl = '/apis/saude-estetica', fetchImpl = window.fetch.bind(window) } = {}) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.fetchImpl = fetchImpl;
    }

    async request(path, { method = 'GET', query, body } = {}) {
        const url = new URL(`${this.baseUrl}${path}`, window.location.origin);
        if (query) {
            Object.entries(query).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    url.searchParams.set(key, value);
                }
            });
        }

        const response = await this.fetchImpl(url.toString(), {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            const message = await this._safeErrorMessage(response);
            throw new Error(`Erro ao chamar ${url}: ${message}`);
        }

        if (response.status === 204) {
            return null;
        }

        return response.json();
    }

    getDashboardCards(query) {
        return this.request('/dashboard', { query });
    }

    getAgenda({ date, professionalId, clinicId } = {}) {
        return this.request('/agenda', { query: { date, professionalId, clinicId } });
    }

    getPosSnapshot({ date, locationId } = {}) {
        return this.request('/pos', { query: { date, locationId } });
    }

    getPresentationSummary({ encounterId }) {
        return this.request('/presentation', { query: { encounterId } });
    }

    async _safeErrorMessage(response) {
        try {
            const data = await response.json();
            return data.message || response.statusText;
        } catch (error) {
            return response.statusText;
        }
    }
}

export const apiClient = new SaudeEsteticaApiClient();
