<?php

/**
 * Saúde & Estética CRM reports
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__DIR__, 2) . '/interface/globals.php');

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\Crm\CrmLeadService;

$leadService = new CrmLeadService();

$months = isset($_GET['months']) ? max(1, (int) $_GET['months']) : 6;
$statusSummary = $leadService->getStatusSummary();
$monthlySummary = $leadService->getMonthlyLeadSummary($months);

$campaignPerformance = QueryUtils::fetchRecords(
    'SELECT COALESCE(c.name, ?) AS campaign_name, COUNT(l.id) AS total_leads, '
    . 'SUM(l.loyalty_points) AS total_points FROM crm_leads l LEFT JOIN crm_campaigns c ON c.id = l.campaign_id '
    . 'GROUP BY campaign_name ORDER BY total_leads DESC LIMIT 10',
    [xlt('Sem campanha')]
);

$rewardBreakdown = QueryUtils::fetchRecords(
    'SELECT reward_type, COUNT(*) AS total_rewards, SUM(points) AS total_points FROM crm_rewards GROUP BY reward_type ORDER BY total_points DESC',
    []
);

Header::setupHeader(['jquery-ui']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title><?php echo xlt('Relatórios CRM'); ?></title>
    <link rel="stylesheet" href="<?php echo attr($GLOBALS['webroot'] . '/modules/saude_estetica_crm/assets/css/crm.css'); ?>" />
</head>
<body class="body_top">
    <div class="container-fluid crm-container">
        <h1 class="crm-title"><?php echo xlt('Relatórios CRM'); ?></h1>

        <form method="get" class="form-inline mb-3">
            <label for="months" class="mr-2"><?php echo xlt('Período (meses)'); ?></label>
            <input type="number" min="1" max="24" class="form-control mr-2" name="months" id="months" value="<?php echo attr((string) $months); ?>" />
            <button type="submit" class="btn btn-primary"><?php echo xlt('Atualizar'); ?></button>
        </form>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Evolução mensal de leads'); ?></div>
                    <div class="card-body">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Período'); ?></th>
                                    <th class="text-right"><?php echo xlt('Leads'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlySummary as $row) : ?>
                                    <tr>
                                        <td><?php echo text($row['period']); ?></td>
                                        <td class="text-right"><?php echo text((string) $row['total']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($monthlySummary)) : ?>
                                    <tr><td colspan="2" class="text-center text-muted"><?php echo xlt('Sem dados para o período.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Status dos leads'); ?></div>
                    <div class="card-body">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Status'); ?></th>
                                    <th class="text-right"><?php echo xlt('Quantidade'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusSummary as $row) : ?>
                                    <tr>
                                        <td><?php echo text($row['status']); ?></td>
                                        <td class="text-right"><?php echo text((string) $row['total']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($statusSummary)) : ?>
                                    <tr><td colspan="2" class="text-center text-muted"><?php echo xlt('Nenhum lead encontrado.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Campanhas com mais conversões'); ?></div>
                    <div class="card-body">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Campanha'); ?></th>
                                    <th class="text-right"><?php echo xlt('Leads'); ?></th>
                                    <th class="text-right"><?php echo xlt('Pontos'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaignPerformance as $row) : ?>
                                    <tr>
                                        <td><?php echo text($row['campaign_name']); ?></td>
                                        <td class="text-right"><?php echo text((string) $row['total_leads']); ?></td>
                                        <td class="text-right"><?php echo text((string) ($row['total_points'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($campaignPerformance)) : ?>
                                    <tr><td colspan="3" class="text-center text-muted"><?php echo xlt('Nenhuma campanha disponível.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header"><?php echo xlt('Resumo de recompensas'); ?></div>
                    <div class="card-body">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Tipo'); ?></th>
                                    <th class="text-right"><?php echo xlt('Qtd. Prêmios'); ?></th>
                                    <th class="text-right"><?php echo xlt('Pontos'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rewardBreakdown as $row) : ?>
                                    <tr>
                                        <td><?php echo text($row['reward_type']); ?></td>
                                        <td class="text-right"><?php echo text((string) $row['total_rewards']); ?></td>
                                        <td class="text-right"><?php echo text((string) ($row['total_points'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($rewardBreakdown)) : ?>
                                    <tr><td colspan="3" class="text-center text-muted"><?php echo xlt('Nenhum prêmio registrado.'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
