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
            $uuid = $_POST['lead_uuid'] ?? '';
            $stage = $_POST['new_stage'] ?? '';
            if ($uuid && $stage) {
                $result = $leadService->updateLead($uuid, ['pipeline_stage' => $stage]);
                if ($result->hasErrors()) {
                    $errors = array_merge($errors, crm_format_messages($result));
                } else {
                    $alerts[] = xlt('Pipeline atualizado.');
                }
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
$pipelineSummary = $leadService->getPipelineSummary();
$leaderboard = $leadService->getLoyaltyLeaderboard();
$campaignOptions = $campaignService->getActiveCampaignOptions();
$recentLeadsResult = $leadService->listLeads(['limit' => 15]);
if ($recentLeadsResult->hasErrors()) {
    $errors = array_merge($errors, crm_format_messages($recentLeadsResult));
}
$recentLeads = $recentLeadsResult->getData();
$campaignListResult = $campaignService->listCampaigns();
if ($campaignListResult->hasErrors()) {
    $errors = array_merge($errors, crm_format_messages($campaignListResult));
}
$campaignList = $campaignListResult->getData();

$pipelineStages = [
    'captured' => xlt('Captado'),
    'qualified' => xlt('Qualificado'),
    'consultation' => xlt('Consulta'),
    'proposal' => xlt('Proposta'),
    'won' => xlt('Ganho'),
    'lost' => xlt('Perdido'),
];

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

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Captura de Leads'); ?></div>
                    <div class="card-body">
                        <form method="post" class="form-horizontal">
                            <input type="hidden" name="crm_csrf" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
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
                    <div class="card-header"><?php echo xlt('Pipeline e Fidelidade'); ?></div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Etapa'); ?></th>
                                    <th class="text-right"><?php echo xlt('Quantidade'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pipelineSummary as $stageRow) : ?>
                                    <tr>
                                        <td><?php echo text($pipelineStages[$stageRow['pipeline_stage']] ?? $stageRow['pipeline_stage']); ?></td>
                                        <td class="text-right"><?php echo text((string) $stageRow['total']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <form method="post" class="form-inline mt-3">
                            <input type="hidden" name="crm_csrf" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                            <input type="hidden" name="action" value="update_pipeline" />
                            <div class="form-group mr-2">
                                <label class="mr-2" for="lead_uuid"><?php echo xlt('Lead'); ?></label>
                                <select name="lead_uuid" id="lead_uuid" class="form-control">
                                    <?php foreach ($recentLeads as $lead) : ?>
                                        <option value="<?php echo attr($lead['uuid']); ?>"><?php echo text($lead['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-2">
                                <label class="mr-2" for="new_stage"><?php echo xlt('Nova etapa'); ?></label>
                                <select class="form-control" name="new_stage" id="new_stage">
                                    <?php foreach ($pipelineStages as $key => $label) : ?>
                                        <option value="<?php echo attr($key); ?>"><?php echo text($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-secondary"><?php echo xlt('Atualizar'); ?></button>
                        </form>
                    </div>
                </div>

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
                            <input type="hidden" name="crm_csrf" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
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
                            <input type="hidden" name="crm_csrf" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
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
