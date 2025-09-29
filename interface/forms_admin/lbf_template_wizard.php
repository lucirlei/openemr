<?php

/**
 * Administrative wizard to clone or create LBF templates for aesthetic procedures.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../globals.php';
require_once $GLOBALS['srcdir'] . '/registry.inc.php';
require_once $GLOBALS['srcdir'] . '/options.inc.php';
require_once $GLOBALS['srcdir'] . '/api.inc.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\LBF\LBFTemplateCatalog;
use OpenEMR\Services\LBF\LBFTemplateInstaller;

if (!AclMain::aclCheckCore('admin', 'forms')) {
    echo xlt('Você não possui permissão para acessar o assistente LBF.');
    exit;
}

$catalog = new LBFTemplateCatalog($GLOBALS['srcdir'] . '/../interface/forms/LBF/templates');
$installer = new LBFTemplateInstaller();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'template') {
            $templateSlug = trim((string) ($_POST['template_slug'] ?? ''));
            $targetFormId = trim((string) ($_POST['target_form_id'] ?? ''));
            $targetTitle = trim((string) ($_POST['target_title'] ?? ''));
            $targetCategory = trim((string) ($_POST['target_category'] ?? ''));

            if ($templateSlug === '' || $targetFormId === '') {
                throw new \RuntimeException(xlt('Selecione um modelo e defina o identificador da nova ficha.'));
            }

            $template = $catalog->getTemplate($templateSlug);
            $result = $installer->installFromTemplate(
                $template,
                $targetFormId,
                [
                    'title' => $targetTitle,
                    'category' => $targetCategory,
                ]
            );
            $message = sprintf(xlt('Modelo %s importado como %s.'), text($template['title']), text($result['form_id']));
        } elseif ($action === 'duplicate') {
            $sourceForm = trim((string) ($_POST['source_form_id'] ?? ''));
            $targetFormId = trim((string) ($_POST['duplicate_form_id'] ?? ''));
            $targetTitle = trim((string) ($_POST['duplicate_title'] ?? ''));
            $targetCategory = trim((string) ($_POST['duplicate_category'] ?? ''));

            if ($sourceForm === '' || $targetFormId === '') {
                throw new \RuntimeException(xlt('Informe a ficha origem e o identificador da nova cópia.'));
            }

            $result = $installer->duplicateLayout(
                $sourceForm,
                $targetFormId,
                [
                    'title' => $targetTitle,
                    'category' => $targetCategory,
                ]
            );
            $message = sprintf(xlt('Layout %s duplicado para %s.'), text($sourceForm), text($result['form_id']));
        }
    } catch (\Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$templates = $catalog->listTemplates();
$existingForms = sqlStatement(
    "SELECT grp_form_id, grp_title, grp_mapping FROM layout_group_properties WHERE grp_group_id = '' ORDER BY grp_title"
);
$formOptions = [];
while ($row = sqlFetchArray($existingForms)) {
    $formOptions[] = $row;
}

?>
<html>
<head>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="title"><?php echo xlt('Assistente LBF para Estética e Emagrecimento'); ?></h2>
            <p class="text-muted"><?php echo xlt('Importe modelos pré-configurados, duplique formulários existentes e conecte-os ao fluxo de assinatura eletrônica.'); ?></p>
        </div>
    </div>

    <?php if ($message) { ?>
        <div class="alert alert-success" role="alert"><?php echo text($message); ?></div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="alert alert-danger" role="alert"><?php echo text($error); ?></div>
    <?php } ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <strong><?php echo xlt('Criar ficha a partir de modelo'); ?></strong>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                        <input type="hidden" name="action" value="template">
                        <div class="form-group">
                            <label for="template_slug"><?php echo xlt('Modelo'); ?></label>
                            <select id="template_slug" name="template_slug" class="form-control" required>
                                <option value=""><?php echo xlt('Selecione'); ?></option>
                                <?php foreach ($templates as $template) { ?>
                                    <option value="<?php echo attr($template['slug']); ?>">
                                        <?php echo text($template['title']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <small class="form-text text-muted"><?php echo xlt('Os modelos incluem checklists de riscos e termos de consentimento prontos para assinatura.'); ?></small>
                        </div>
                        <div class="form-group">
                            <label for="target_form_id"><?php echo xlt('Identificador (ex.: LBFbioimp_local)'); ?></label>
                            <input type="text" id="target_form_id" name="target_form_id" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="target_title"><?php echo xlt('Título da ficha'); ?></label>
                            <input type="text" id="target_title" name="target_title" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="target_category"><?php echo xlt('Categoria/Mapeamento'); ?></label>
                            <input type="text" id="target_category" name="target_category" class="form-control" placeholder="<?php echo attr(xl('Estética')); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo xlt('Importar modelo'); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <strong><?php echo xlt('Duplicar ficha existente'); ?></strong>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                        <input type="hidden" name="action" value="duplicate">
                        <div class="form-group">
                            <label for="source_form_id"><?php echo xlt('Layout origem'); ?></label>
                            <select id="source_form_id" name="source_form_id" class="form-control" required>
                                <option value=""><?php echo xlt('Selecione'); ?></option>
                                <?php foreach ($formOptions as $option) { ?>
                                    <option value="<?php echo attr($option['grp_form_id']); ?>">
                                        <?php echo text($option['grp_title']); ?> (<?php echo text($option['grp_form_id']); ?>)
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="duplicate_form_id"><?php echo xlt('Novo identificador'); ?></label>
                            <input type="text" id="duplicate_form_id" name="duplicate_form_id" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="duplicate_title"><?php echo xlt('Novo título (opcional)'); ?></label>
                            <input type="text" id="duplicate_title" name="duplicate_title" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="duplicate_category"><?php echo xlt('Nova categoria (opcional)'); ?></label>
                            <input type="text" id="duplicate_category" name="duplicate_category" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-secondary"><?php echo xlt('Duplicar'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <strong><?php echo xlt('Termos de consentimento dos modelos'); ?></strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                            <tr>
                                <th><?php echo xlt('Modelo'); ?></th>
                                <th><?php echo xlt('Consentimentos'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($templates as $template) { ?>
                                <tr>
                                    <td>
                                        <strong><?php echo text($template['title']); ?></strong><br>
                                        <small class="text-muted"><?php echo text($template['description']); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($template['consents'])) { ?>
                                            <ul class="pl-3 mb-0">
                                                <?php foreach ($template['consents'] as $consent) { ?>
                                                    <li>
                                                        <strong><?php echo text($consent['title']); ?></strong>
                                                        <?php if (!empty($consent['risks'])) { ?>
                                                            <ul class="pl-3">
                                                                <?php foreach ($consent['risks'] as $risk) { ?>
                                                                    <li><?php echo text($risk); ?></li>
                                                                <?php } ?>
                                                            </ul>
                                                        <?php } ?>
                                                    </li>
                                                <?php } ?>
                                            </ul>
                                        <?php } else { ?>
                                            <span class="text-muted"><?php echo xlt('Nenhum termo cadastrado.'); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
