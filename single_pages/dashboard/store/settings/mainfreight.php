<?php
$form = Core::make('helper/form');

/* @var $form \Concrete\Core\Form\Service\Form */

use Concrete\Core\File\Type\Type as FileType;
use Concrete\Core\Support\Facade\Config;
use \Concrete\Core\Support\Facade\Url;
use Wem\Entity\Config as WemConfig;

$al = Core::make('helper/concrete/asset_library');
/* @var $al Concrete\Core\Application\Service\FileManager */


$dash = Core::make('helper/concrete/dashboard');
/* @var $dash Concrete\Core\Application\Service\Dashboard */
$ui = Core::make('helper/concrete/ui');

$editor = Core::make('editor');
/* @var $editor  Concrete\Core\Editor\CkeditorEditor */


?>

<div class="ccm-dashboard-header-buttons">
    <a href="<?= Url::to('/dashboard/store/settings'); ?>" class="btn btn-primary"><i
                class="fa fa-file-pdf-o fa-flip-horizontal"></i> <?= t('Settings'); ?></a>
</div>

<div class="ccm-pane-body">
    <form method="post" action="<?= $view->action('save'); ?>">
        <?= $token->output('settings'); ?>

        <div class="row">
            <div class="col-md-12">
                <div class="form-group">
                    <?= $form->label('APIKey', 'APIKey'); ?>
                    <?= $form->text('APIKey', $APIKey) ?>
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
        </div>
        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <button class="pull-right btn btn-success" type="submit"><?= t('Save Settings'); ?></button>
            </div>
        </div>
    </form>
</div>