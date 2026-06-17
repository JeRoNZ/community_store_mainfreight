<?php

use Concrete\Package\CommunityStoreMainfreight\Src\CommunityStore\Shipping\Method\Types\MainfreightShippingMethod;

defined('C5_EXECUTE') or die("Access Denied.");
extract($vars);

/** @var $smtm MainfreightShippingMethod */
?>

<div class="row">
    <div class="col-sm-6">
        <div class="form-group">
            <?= $form->label('countries', t("Which Countries does this Apply to?")); ?>
            <?= $form->select('countries', array('all' => t("All Countries"), 'selected' => t("Certain Countries")), $smtm->getCountries()); ?>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="form-group">
            <?= $form->label('countriesSelected', t("If Certain Countries, which?")); ?>
            <select class="form-control" multiple name="countriesSelected[]">
                <?php $selectedCountries = explode(',', $smtm->getCountriesSelected()); ?>
                <?php foreach ($countryList as $code => $country) { ?>
                    <option value="<?= $code ?>"<?php if (in_array($code, $selectedCountries)) {
                        echo " selected";
                    } ?>><?= $country ?></option>
                <?php } ?>
            </select>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-sm-4">
        <div class="form-group">
            <?= $form->label('serviceTypeDOM', t('Domestic service type ')); ?>
            <div class="input-group">
                <?= $form->select(
                        'serviceTypeDOM',
                        [
                                'B2B' => 'B2B Business to Business',
                                'EXP' => 'EXP Express',
                                'FTL' => 'FTL Full Truck Load',
                                'LCL' => 'LCL Less than Container Load',
                                'M2H' => 'M2H Mainfreight to Home',
                                'MOV' => 'MOV 0800 Move It',
                                'MSD' => 'MSD Metro Same Day',
                                'PLA' => 'PLA Platinum',
                                'PRM' => 'PRM Premium',
                                'WHF' => 'WHF Wharf',
                        ],
                        $smtm->getServiceTypeDom()
                ) ?>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            <?= $form->label('serviceTypeB2B', t('B2B service type')); ?>
            <div class="input-group">
                <?= $form->select(
                        'serviceTypeB2B',
                        [
                                'B2B' => 'B2B Business to Business',
                                'EXP' => 'EXP Express',
                                'FTL' => 'FTL Full Truck Load',
                                'LCL' => 'LCL Less than Container Load',
                                'M2H' => 'M2H Mainfreight to Home',
                                'MOV' => 'MOV 0800 Move It',
                                'MSD' => 'MSD Metro Same Day',
                                'PLA' => 'PLA Platinum',
                                'PRM' => 'PRM Premium',
                                'WHF' => 'WHF Wharf',
                        ],
                        $smtm->getServiceTypeB2B()
                ) ?>
            </div>
        </div>
    </div>


    <div class="col-sm-2">
        <div class="form-group">
            <?= $form->label('packageType', t('Package type')); ?>
            <div class="input-group">
                <?= $form->select(
                        'packageType',
                        [
                                'BAG' => 'BAG Bag',
                                'BDL' => 'BDL Bundle',
                                'CTN' => 'CTN Carton',
                                'DRM' => 'DRM Drum',
                                'IBC' => 'IBC IBC',
                                'ITEM' => 'ITEM Item',
                                'PAIL' => 'PAIL Pail',
                                'PLT' => 'PLT Pallet',
                                'ROLL' => 'ROLL Roll',
                                'ST' => 'ST Stillage'
                        ],
                        $smtm->getPackageType()
                ) ?>
            </div>
        </div>
    </div>
</div>
<?php
$timeOptions = [];
for ($minutes = 0; $minutes < 24 * 60; $minutes += 15) {
    $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $timeOptions[$time] = $time;
}
?>
<div class="row">
    <div class="col-sm-3">
        <div class="form-group">
            <?= $form->label('orderCutoffTime', t('Order cut off time')); ?>
            <?= $form->select('orderCutoffTime', $timeOptions, $smtm->getOrderCutoffTime()); ?>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="form-group">
            <?= $form->label('collectionTime', t('Collection time')); ?>
            <?= $form->select('collectionTime', $timeOptions, $smtm->getCollectionTime()); ?>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="form-group">
            <label>&nbsp;</label>
            <div class="form-check">
                <?= $form->checkbox('saturdayEnabled', 1, $smtm->getSaturdayEnabled()); ?>
                <?= $form->label('saturdayEnabled', t('Collect on Saturdays'), ['class' => 'form-check-label']); ?>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="form-group">
            <label>&nbsp;</label>
            <div class="form-check">
                <?= $form->checkbox('sundayEnabled', 1, $smtm->getSundayEnabled()); ?>
                <?= $form->label('sundayEnabled', t('Collect on Sundays'), ['class' => 'form-check-label']); ?>
            </div>
        </div>
    </div>
</div>