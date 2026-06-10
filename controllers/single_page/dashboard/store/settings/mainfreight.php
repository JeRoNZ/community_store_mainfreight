<?php
namespace Concrete\Package\CommunityStoreMainfreight\Controller\SinglePage\Dashboard\Store\Settings;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Routing\Redirect;
use TruckCentreBop\Unleashed;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Concrete\Core\Editor\LinkAbstractor;
use Concrete\Core\File\File;
use Concrete\Core\Http\Response;

class Mainfreight extends DashboardPageController {

	public function validate($args)
	{
		$e = $this->app->make('helper/validation/error');
//		$nv = $this->app->make('helper/validation/numbers');

		// 8-4-4-4-12 a-f 0-9
//		$key = trim($args['APIID']);
//		if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $key)) {
//			$e->add('API ID is not in GUID format');
//		}
//		if ($args['APIID']){
//			if (! preg_match('/^sk_(test|live)_[a-z0-9]{24,255}$/i',$args['unleashedSecretKey'] )){
//				$e->add('Secret key has an invalid format');
//			}
//		}
//
//		if ($args['APIKEY']){
//			if (! preg_match('/^price_[a-z0-9]{24}$/i',$args['unleashedSubProductPriceID'] )){
//				$e->add('Subscription price ID has an invalid format or length');
//			}
//		}
//
		return $e;
	}

	public function view() {
		$this->set('APIKey',Config::get('mainfreight.APIKey'));
		$this->set('accountID',Config::get('mainfreight.accountID'));
	}


	public function save()
	{
		$this->view();
		$args = $this->request->request->all();

		if ($args && $this->token->validate('settings')) {
			$errors = $this->validate($args);
			$this->error = $errors;

			if ($errors->has()) {
				$this->flash('errors', $errors);
			} else {
				Config::save('mainfreight.APIKey', trim($args['APIKey']));
				Config::save('mainfreight.accountID', trim($args['accountID']));

				$this->flash('success', t('Settings Saved'));

				return new RedirectResponse(\URL::to('/dashboard/store/settings/mainfreight'));
			}
		}

		return null;
	}
}