<?php

/**
 * Saúde & Estética CRM dashboard
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__DIR__, 2) . '/interface/globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\Crm\CrmCampaignService;
use OpenEMR\Services\Crm\CrmLeadService;
use OpenEMR\Validators\ProcessingResult;

$leadService = new CrmLeadService();
$campaignService = new CrmCampaignService();

$alerts = [];
$errors = [];

$pipelineStages = [
    'captured' => xlt('Captado'),
    'qualified' => xlt('Qualificado'),
    'consultation' => xlt('Consulta'),
    'proposal' => xlt('Proposta'),
    'won' => xlt('Ganho'),
    'lost' => xlt('Perdido'),
];

$csrfToken = CsrfUtils::collectCsrfToken();
$isAjaxRequest = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1');

function crm_format_messages(ProcessingResult $result): array
{
    $messages = [];
    if (!$result->isValid()) {
        foreach ((array) $result->getValidationMessages() as $field => $items) {
            if (is_array($items)) {
                foreach ($items as $message) {
                    $messages[] = $field . ': ' . $message;
                }
            } else {
                $messages[] = $field . ': ' . $items;
            }
        }
    }
    if ($result->hasInternalErrors()) {
        foreach ($result->getInternalErrors() as $error) {
            $messages[] = (string) $error;
        }
    }
    return $messages;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['crm_csrf'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'create_lead':
            $leadData = [
                'full_name' => $_POST['full_name'] ?? '',
                'email' => $_POST['email'] ?? null,
                'phone' => $_POST['phone'] ?? null,
                'status' => $_POST['status'] ?? 'new',
                'source' => $_POST['source'] ?? 'internal',
                'pipeline_stage' => $_POST['pipeline_stage'] ?? 'captured',
                'notes' => $_POST['notes'] ?? null,
            ];
            if (!empty($_POST['campaign_uuid'])) {
                $campaign = $campaignService->getCampaignByUuid($_POST['campaign_uuid']);
                if (!empty($campaign)) {
                    $leadData['campaign_id'] = $campaign['id'];
                }
            }
            $result = $leadService->createLead($leadData);
            if ($result->hasErrors()) {
                $errors = array_merge($errors, crm_format_messages($result));
            } else {
                $alerts[] = xlt('Lead cadastrado com sucesso.');
            }
            break;
        case 'update_pipeline':
            $uuid = trim((string) ($_POST['lead_uuid'] ?? ''));
            $stage = trim((string) ($_POST['new_stage'] ?? ''));
            if ($uuid === '' || $stage === '') {
                if ($isAjaxRequest) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => xlt('Dados incompletos para atualizar o pipeline.'),
                    ]);
                    exit;
                }
                if ($uuid === '') {
                    $errors[] = xlt('Selecione um lead para atualizar.');
                }
                if ($stage === '') {
                    $errors[] = xlt('Selecione a etapa do pipeline.');
                }
                break;
            }

            $result = $leadService->updateLead($uuid, ['pipeline_stage' => $stage]);
            if ($result->hasErrors()) {
                $messages = crm_format_messages($result);
                if ($isAjaxRequest) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => $messages[0] ?? xlt('Não foi possível atualizar o pipeline.'),
                    ]);
                    exit;
                }
                $errors = array_merge($errors, $messages);
            } else {
                if ($isAjaxRequest) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => xlt('Pipeline atualizado.'),
                        'data' => [
                            'lead_uuid' => $uuid,
                            'pipeline_stage' => $stage,
                        ],
                    ]);
                    exit;
                }
                $alerts[] = xlt('Pipeline atualizado.');
            }
            break;
        case 'create_campaign':
            $automationConfig = null;
            if (!empty($_POST['automation_config'])) {
                $decoded = json_decode($_POST['automation_config'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $automationConfig = $decoded;
                } else {
                    $errors[] = xlt('JSON inválido para automação.');
                }
            }
            if (empty($errors)) {
                $campaignData = [
                    'name' => $_POST['campaign_name'] ?? '',
                    'status' => $_POST['campaign_status'] ?? 'draft',
                    'start_date' => $_POST['campaign_start'] ?? null,
                    'end_date' => $_POST['campaign_end'] ?? null,
                    'budget' => $_POST['campaign_budget'] ?? null,
                    'description' => $_POST['campaign_description'] ?? null,
                ];
                if ($automationConfig !== null) {
                    $campaignData['automation_config'] = $automationConfig;
                }
                $result = $campaignService->createCampaign($campaignData);
                if ($result->hasErrors()) {
                    $errors = array_merge($errors, crm_format_messages($result));
                } else {
                    $alerts[] = xlt('Campanha criada com sucesso.');
                }
            }
            break;
        case 'award_points':
            $uuid = $_POST['reward_lead_uuid'] ?? '';
            $points = (int) ($_POST['reward_points'] ?? 0);
            $reason = $_POST['reward_reason'] ?? xlt('Ação manual');
            $type = $_POST['reward_type'] ?? 'manual';
            if ($uuid && $points) {
                $result = $leadService->awardPoints($uuid, $points, $reason, $type);
                if ($result->hasErrors()) {
                    $errors = array_merge($errors, crm_format_messages($result));
                } else {
                    $alerts[] = xlt('Pontuação atualizada.');
                }
            }
            break;
    }
}

$metrics = $leadService->getDashboardMetrics();
$leaderboard = $leadService->getLoyaltyLeaderboard();
$campaignOptions = $campaignService->getActiveCampaignOptions();

$kanbanLeads = [];
$recentLeads = [];
$kanbanColumns = [];
$kanbanLeadsResult = $leadService->listLeads(['limit' => 250]);
if ($kanbanLeadsResult->hasErrors()) {
    $errors = array_merge($errors, crm_format_messages($kanbanLeadsResult));
} else {
    $kanbanLeads = $kanbanLeadsResult->getData();
    $recentLeads = array_slice($kanbanLeads, 0, 15);
}

foreach ($pipelineStages as $stageKey => $stageLabel) {
    $kanbanColumns[$stageKey] = [
        'key' => $stageKey,
        'label' => $stageLabel,
        'leads' => [],
    ];
}

$extraColumns = [];
foreach ($kanbanLeads as $lead) {
    $stageKey = $lead['pipeline_stage'] ?? 'captured';
    if ($stageKey === '') {
        $stageKey = 'captured';
    }

    if (!isset($kanbanColumns[$stageKey])) {
        if (!isset($extraColumns[$stageKey])) {
            $prettyLabel = ucwords(str_replace(['_', '-'], ' ', (string) $stageKey));
            $extraColumns[$stageKey] = [
                'key' => $stageKey,
                'label' => $prettyLabel,
                'leads' => [],
            ];
        }
        $extraColumns[$stageKey]['leads'][] = $lead;
        continue;
    }

    $kanbanColumns[$stageKey]['leads'][] = $lead;
}

foreach ($extraColumns as $extraKey => $extraColumn) {
    $kanbanColumns[$extraKey] = $extraColumn;
}

$campaignListResult = $campaignService->listCampaigns();
if ($campaignListResult->hasErrors()) {
    $errors = array_merge($errors, crm_format_messages($campaignListResult));
}
$campaignList = $campaignListResult->getData();

Header::setupHeader(['jquery-ui', 'datatables', 'moment']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title><?php echo xlt('Saúde & Estética CRM'); ?></title>
    <link rel="stylesheet" href="<?php echo attr($GLOBALS['webroot'] . '/modules/saude_estetica_crm/assets/css/crm.css'); ?>" />
</head>
<body class="body_top">
    <div class="container-fluid crm-container">
        <h1 class="crm-title"><?php echo xlt('Saúde & Estética CRM'); ?></h1>

        <?php foreach ($alerts as $alert) : ?>
            <div class="alert alert-success"><?php echo text($alert); ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error) : ?>
            <div class="alert alert-danger"><?php echo text($error); ?></div>
        <?php endforeach; ?>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><?php echo xlt('Leads Totais'); ?></div>
                    <div class="card-body">
                        <span class="crm-metric"><?php echo text((string) ($metrics['total_leads'] ?? 0)); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><?php echo xlt('Campanhas Ativas'); ?></div>
                    <div class="card-body">
                        <span class="crm-metric"><?php echo text((string) ($metrics['active_campaigns'] ?? 0)); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><?php echo xlt('Pontos Fidelidade Emitidos'); ?></div>
                    <div class="card-body">
                        <span class="crm-metric"><?php echo text((string) ($metrics['reward_points'] ?? 0)); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4 crm-kanban-panel">
            <div class="card-header"><?php echo xlt('Pipeline Kanban'); ?></div>
            <div class="card-body">
                <div class="crm-kanban-feedback alert d-none" role="alert"></div>
                <div class="crm-kanban-board"
                    data-csrf="<?php echo attr($csrfToken); ?>"
                    data-update-url="<?php echo attr($GLOBALS['webroot'] . '/modules/saude_estetica_crm/index.php'); ?>"
                    data-success-message="<?php echo attr(xlt('Pipeline atualizado.')); ?>"
                    data-error-message="<?php echo attr(xlt('Não foi possível atualizar o pipeline.')); ?>">
                    <?php foreach ($kanbanColumns as $stageKey => $column) : ?>
                        <div class="crm-kanban-column" data-stage="<?php echo attr($stageKey); ?>">
                            <div class="crm-kanban-column-header">
                                <span class="crm-kanban-column-title"><?php echo text($column['label']); ?></span>
                                <span class="crm-kanban-column-count badge badge-light" data-stage-count><?php echo text((string) count($column['leads'])); ?></span>
                            </div>
                            <div class="crm-kanban-column-body">
                                <div class="crm-kanban-empty<?php echo empty($column['leads']) ? '' : ' d-none'; ?>"><?php echo xlt('Nenhum lead nesta etapa.'); ?></div>
                                <?php foreach ($column['leads'] as $lead) : ?>
                                    <?php
                                        $cardStage = $lead['pipeline_stage'] ?? 'captured';
                                        if ($cardStage === '') {
                                            $cardStage = 'captured';
                                        }
                                        $loyaltyPoints = (int) ($lead['loyalty_points'] ?? 0);
                                    ?>
                                    <div class="crm-kanban-card" data-lead="<?php echo attr($lead['uuid']); ?>" data-stage="<?php echo attr($cardStage); ?>">
                                        <div class="crm-kanban-card-surface">
                                            <div class="crm-kanban-card-header">
                                                <span class="crm-kanban-card-title"><?php echo text($lead['full_name']); ?></span>
                                                <?php if (!empty($lead['status'])) : ?>
                                                    <span class="crm-kanban-card-status badge badge-light"><?php echo text($lead['status']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="crm-kanban-card-body">
                                                <?php if (!empty($lead['phone'])) : ?>
                                                    <div class="crm-kanban-card-line">
                                                        <span class="crm-kanban-card-label"><?php echo xlt('Telefone'); ?></span>
                                                        <span class="crm-kanban-card-value"><?php echo text($lead['phone']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($lead['email'])) : ?>
                                                    <div class="crm-kanban-card-line">
                                                        <span class="crm-kanban-card-label"><?php echo xlt('E-mail'); ?></span>
                                                        <span class="crm-kanban-card-value"><?php echo text($lead['email']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($lead['campaign_name']) || !empty($lead['source'])) : ?>
                                                    <div class="crm-kanban-card-tags">
                                                        <?php if (!empty($lead['campaign_name'])) : ?>
                                                            <span class="crm-kanban-card-chip"><?php echo text($lead['campaign_name']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($lead['source'])) : ?>
                                                            <span class="crm-kanban-card-chip crm-kanban-card-chip-source"><?php echo text($lead['source']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($loyaltyPoints > 0) : ?>
                                                    <div class="crm-kanban-card-line crm-kanban-card-line--points">
                                                        <span class="crm-kanban-card-label"><?php echo xlt('Pontos'); ?></span>
                                                        <span class="crm-kanban-card-value"><?php echo text((string) $loyaltyPoints); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($lead['notes'])) : ?>
                                                    <div class="crm-kanban-card-notes"><?php echo text($lead['notes']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="crm-kanban-card-footer">
                                                <small class="text-muted"><?php echo xlt('Atualizado em:'); ?> <?php echo text($lead['updated_at']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Captura de Leads'); ?></div>
                    <div class="card-body">
                        <form method="post" class="form-horizontal">
                            <input type="hidden" name="crm_csrf" value="<?php echo attr($csrfToken); ?>" />
                            <input type="hidden" name="action" value="create_lead" />
                            <div class="form-group">
                                <label class="form-label" for="full_name"><?php echo xlt('Nome completo'); ?></label>
                                <input type="text" required class="form-control" name="full_name" id="full_name" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email"><?php echo xlt('E-mail'); ?></label>
                                <input type="email" class="form-control" name="email" id="email" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="phone"><?php echo xlt('Telefone'); ?></label>
                                <input type="text" class="form-control" name="phone" id="phone" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="source"><?php echo xlt('Fonte'); ?></label>
                                <input type="text" class="form-control" name="source" id="source" placeholder="<?php echo attr(xlt('Ex: Instagram, Indicação, Landing Page')); ?>" />
                            </div>
                            <div class="form-row">
                                <div class="col">
                                    <label class="form-label" for="status"><?php echo xlt('Status'); ?></label>
                                    <select class="form-control" name="status" id="status">
                                        <option value="new"><?php echo xlt('Novo'); ?></option>
                                        <option value="active"><?php echo xlt('Ativo'); ?></option>
                                        <option value="won"><?php echo xlt('Convertido'); ?></option>
                                        <option value="lost"><?php echo xlt('Perdido'); ?></option>
                                    </select>
                                </div>
                                <div class="col">
                                    <label class="form-label" for="pipeline_stage"><?php echo xlt('Pipeline'); ?></label>
                                    <select class="form-control" name="pipeline_stage" id="pipeline_stage">
                                        <?php foreach ($pipelineStages as $key => $label) : ?>
                                            <option value="<?php echo attr($key); ?>"><?php echo text($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group mt-2">
                                <label class="form-label" for="campaign_uuid"><?php echo xlt('Campanha'); ?></label>
                                <select class="form-control" name="campaign_uuid" id="campaign_uuid">
                                    <option value=""><?php echo xlt('Selecione'); ?></option>
                                    <?php foreach ($campaignOptions as $campaign) : ?>
                                        <option value="<?php echo attr($campaign['uuid']); ?>"><?php echo text($campaign['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="notes"><?php echo xlt('Observações'); ?></label>
                                <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Salvar Lead'); ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Pontuação de Fidelidade'); ?></div>
                    <div class="card-body">
                        <ul class="list-group loyalty-list">
                            <?php foreach ($leaderboard as $entry) : ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?php echo text($entry['full_name']); ?></span>
                                    <span class="badge badge-primary badge-pill"><?php echo text((string) $entry['loyalty_points']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="post" class="form-inline mt-3">
                            <input type="hidden" name="crm_csrf" value="<?php echo attr($csrfToken); ?>" />
                            <input type="hidden" name="action" value="award_points" />
                            <div class="form-group mr-2">
                                <label class="mr-2" for="reward_lead_uuid"><?php echo xlt('Lead'); ?></label>
                                <select name="reward_lead_uuid" id="reward_lead_uuid" class="form-control">
                                    <?php foreach ($recentLeads as $lead) : ?>
                                        <option value="<?php echo attr($lead['uuid']); ?>"><?php echo text($lead['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-2">
                                <label class="mr-2" for="reward_points"><?php echo xlt('Pontos'); ?></label>
                                <input type="number" class="form-control" name="reward_points" id="reward_points" value="25" />
                            </div>
                            <div class="form-group mr-2">
                                <label class="sr-only" for="reward_reason"><?php echo xlt('Motivo'); ?></label>
                                <input type="text" class="form-control" name="reward_reason" id="reward_reason" placeholder="<?php echo attr(xlt('Motivo')); ?>" />
                            </div>
                            <input type="hidden" name="reward_type" value="manual" />
                            <button type="submit" class="btn btn-success"><?php echo xlt('Creditar'); ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Campanhas'); ?></div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="crm_csrf" value="<?php echo attr($csrfToken); ?>" />
                            <input type="hidden" name="action" value="create_campaign" />
                            <div class="form-group">
                                <label for="campaign_name" class="form-label"><?php echo xlt('Nome da campanha'); ?></label>
                                <input type="text" required class="form-control" name="campaign_name" id="campaign_name" />
                            </div>
                            <div class="form-row">
                                <div class="col">
                                    <label class="form-label" for="campaign_start"><?php echo xlt('Início'); ?></label>
                                    <input type="date" class="form-control" name="campaign_start" id="campaign_start" />
                                </div>
                                <div class="col">
                                    <label class="form-label" for="campaign_end"><?php echo xlt('Fim'); ?></label>
                                    <input type="date" class="form-control" name="campaign_end" id="campaign_end" />
                                </div>
                            </div>
                            <div class="form-row mt-2">
                                <div class="col">
                                    <label class="form-label" for="campaign_status"><?php echo xlt('Status'); ?></label>
                                    <select class="form-control" name="campaign_status" id="campaign_status">
                                        <option value="draft"><?php echo xlt('Rascunho'); ?></option>
                                        <option value="scheduled"><?php echo xlt('Agendada'); ?></option>
                                        <option value="active"><?php echo xlt('Ativa'); ?></option>
                                        <option value="completed"><?php echo xlt('Finalizada'); ?></option>
                                    </select>
                                </div>
                                <div class="col">
                                    <label class="form-label" for="campaign_budget"><?php echo xlt('Investimento (R$)'); ?></label>
                                    <input type="number" step="0.01" class="form-control" name="campaign_budget" id="campaign_budget" />
                                </div>
                            </div>
                            <div class="form-group mt-2">
                                <label class="form-label" for="campaign_description"><?php echo xlt('Descrição'); ?></label>
                                <textarea class="form-control" rows="3" name="campaign_description" id="campaign_description"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="automation_config"><?php echo xlt('Configuração de automação (JSON)'); ?></label>
                                <textarea class="form-control" rows="4" name="automation_config" id="automation_config" placeholder='{"schedule": [{"run_at": "2024-05-01 09:00:00", "channel": "email", "template": {"subject": "Bem-vindo", "body": "Olá {{lead_name}}"}}]}'></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Criar Campanha'); ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Campanhas Registradas'); ?></div>
                    <div class="card-body campaign-table">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Nome'); ?></th>
                                    <th><?php echo xlt('Status'); ?></th>
                                    <th><?php echo xlt('Período'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaignList as $campaign) : ?>
                                    <tr>
                                        <td><?php echo text($campaign['name']); ?></td>
                                        <td><?php echo text($campaign['status']); ?></td>
                                        <td><?php echo text(($campaign['start_date'] ?? '') . ' - ' . ($campaign['end_date'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($campaignList)) : ?>
                                    <tr><td colspan="3" class="text-center text-muted"><?php echo xlt('Nenhuma campanha cadastrada.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><?php echo xlt('Leads Recentes'); ?></div>
                    <div class="card-body">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Nome'); ?></th>
                                    <th><?php echo xlt('Status'); ?></th>
                                    <th><?php echo xlt('Pipeline'); ?></th>
                                    <th><?php echo xlt('Campanha'); ?></th>
                                    <th><?php echo xlt('Atualizado em'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLeads as $lead) : ?>
                                    <tr>
                                        <td><?php echo text($lead['full_name']); ?></td>
                                        <td><?php echo text($lead['status']); ?></td>
                                        <td><?php echo text($pipelineStages[$lead['pipeline_stage']] ?? $lead['pipeline_stage']); ?></td>
                                        <td><?php echo text($lead['campaign_name'] ?? ''); ?></td>
                                        <td><?php echo text($lead['updated_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentLeads)) : ?>
                                    <tr><td colspan="5" class="text-center text-muted"><?php echo xlt('Nenhum lead encontrado.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo attr($GLOBALS['webroot'] . '/modules/saude_estetica_crm/assets/js/crm.js'); ?>"></script>
</body>
</html>
