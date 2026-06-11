<?php
$form = Core::make('helper/form');
/* @var $form \Concrete\Core\Form\Service\Form */

$ui = Core::make('helper/concrete/ui');

?>

<div class="ccm-dashboard-header-buttons">
    <a href="<?= Url::to('/dashboard/store/settings/shipping'); ?>" class="btn btn-primary"><i class="fa fa-truck fa-flip-horizontal"></i> <?= t("Shipping Methods"); ?></a>
    <a href="<?= Url::to('/dashboard/store/settings/settings'); ?>" class="btn btn-primary"><?= t('Settings'); ?></a>
</div>

<div class="ccm-pane-body">
    <form method="post" action="<?= $view->action('save'); ?>">
        <?= $token->output('settings'); ?>
        <?php
        $tabs = [
            ['mainfreight', t('Mainfreight'), true],
            ['boxsizes', t('Box Sizes')],
            ['pickupaddress', t('Pickup Address')],
        ];
        echo $ui->tabs($tabs);
        ?>

        <div class="tab-content">
            <div class="tab-pane active" id="mainfreight" role="tabpanel">
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <?= $form->label('APIKey', 'APIKey'); ?>
                            <?= $form->text('APIKey', $APIKey) ?>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <?= $form->label('accountID', 'AccountID'); ?>
                            <?= $form->text('accountID', $accountID) ?>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <?= $form->label('publicHolidaysAPIKey', t('Public Holidays API Key')); ?>
                            <?= $form->text('publicHolidaysAPIKey', $publicHolidaysAPIKey) ?>
                            <small class="form-text text-muted"><a href="https://public-holidays.nz/" target="_blank">public-holidays.nz</a></small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane" id="boxsizes" role="tabpanel">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-secondary" id="mf-add-box">
                        <i class="fas fa-plus"></i> <?= t('Add Box Size') ?>
                    </button>
                </div>
                <table class="table table-sm table-bordered align-middle" id="mf-boxes-table">
                    <thead class="table-light">
                        <tr>
                            <th><?= t('Length (m)') ?></th>
                            <th><?= t('Width (m)') ?></th>
                            <th><?= t('Height (m)') ?></th>
                            <th><?= t('Max Weight (kg)') ?></th>
                            <th style="width:3rem"></th>
                        </tr>
                    </thead>
                    <tbody id="mf-boxes-tbody">
                        <?php foreach ($boxSizes as $i => $box): ?>
                        <tr>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[<?= $i ?>][l]" value="<?= h(number_format((float)$box['l'], 2)) ?>"></td>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[<?= $i ?>][w]" value="<?= h(number_format((float)$box['w'], 2)) ?>"></td>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[<?= $i ?>][h]" value="<?= h(number_format((float)$box['h'], 2)) ?>"></td>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[<?= $i ?>][k]" value="<?= h(number_format((float)$box['k'], 2)) ?>"></td>
                            <td class="text-center"><button type="button" class="btn btn-sm btn-danger mf-delete-box"><i class="fas fa-times"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="tab-pane" id="pickupaddress" role="tabpanel">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <?= $form->label('street', t('Street Address')); ?>
                            <?= $form->text('street', $pickupAddress['street'] ?? '') ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <?= $form->label('suburb', t('Suburb')); ?>
                            <?= $form->text('suburb', $pickupAddress['suburb'] ?? '') ?>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <?= $form->label('city', t('City')); ?>
                            <?= $form->text('city', $pickupAddress['city'] ?? '') ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <?= $form->label('postcode', t('Postcode')); ?>
                            <?= $form->text('postcode', $pickupAddress['postcode'] ?? '') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <button class="float-end btn btn-success" type="submit"><?= t('Save Settings'); ?></button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var counter = <?= count($boxSizes) ?>;
    var tbody = document.getElementById('mf-boxes-tbody');

    function makeRow(idx) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[' + idx + '][l]" value=""></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[' + idx + '][w]" value=""></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[' + idx + '][h]" value=""></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="boxes[' + idx + '][k]" value=""></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-danger mf-delete-box"><i class="fas fa-times"></i></button></td>';
        tr.querySelector('.mf-delete-box').addEventListener('click', function () {
            tr.remove();
        });
        return tr;
    }

    document.getElementById('mf-add-box').addEventListener('click', function () {
        tbody.appendChild(makeRow(counter++));
    });

    tbody.querySelectorAll('.mf-delete-box').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('tr').remove();
        });
    });
})();
</script>