<?php

namespace Concrete\Package\CommunityStoreMainfreight;

use Concrete\Core\Package\Package;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodType;

class Controller extends Package {
	protected $pkgHandle = 'community_store_mainfreight';
	protected $appVersionRequired = '9.0.0';
	protected $pkgVersion = '0.1';

	public function getPackageVersion () {
		return parent::getPackageVersion() . '.' . time();
	}

	protected $pkgAutoloaderRegistries = [
		'src' => 'CommunityStoreMainfreight',
		'src/CommunityStore' => '\Concrete\Package\CommunityStoreMainfreight\Src\CommunityStore',
	];

	public function getPackageName () {
		return t('Community Store Mainfreight');
	}

	public function getPackageDescription () {
		return t('Mainfreight shipping method for Community Store');
	}

	protected $singlePages = array(
		'/dashboard/store/settings/mainfreight' => array('name' => 'Mainfreight', 'nav' => true),
	);

	public function install () {
		$pkg = parent::install();
		$this->shippingMethods($pkg);
		$this->singlePages($pkg);
	}

	public function upgrade () {
		$pkg = app(PackageService::class)->getByHandle($this->pkgHandle);
		$this->shippingMethods($pkg);
		$this->singlePages($pkg);
		parent::upgrade();
	}

	private function shippingMethods ($pkg) {
		$smt = ShippingMethodType::getByHandle('mainfreight');
		if (!is_object($smt)) {
			ShippingMethodType::add('mainfreight', 'Mainfreight', $pkg);
		}
	}


	private function singlePages ($pkg) {
		foreach ($this->singlePages as $path => $data) {
			$page = Page::getByPath($path);
			if ($page->getCollectionID() <= 0) {
				$page = SinglePage::add($path, $pkg);
			} else {
				SinglePage::refresh($page);
			}
			$page->update(array('cName' => $data['name']));
			if ($data['nav']) {
				$page->clearAttribute('exclude_nav');
			} else {
				$page->setAttribute('exclude_nav', true);
			}
		}
	}
}