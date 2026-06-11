<?php
namespace Concrete\Package\CommunityStoreMainfreight\Controller\SinglePage\Dashboard\Store\Settings;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Page\Controller\DashboardPageController;
use Symfony\Component\HttpFoundation\RedirectResponse;

class Mainfreight extends DashboardPageController {

	public function validate($args)
	{
		return $this->app->make('helper/validation/error');
	}

	public function view() {
		$this->set('APIKey', Config::get('mainfreight.APIKey'));
		$this->set('accountID', Config::get('mainfreight.accountID'));
		$this->set('publicHolidaysAPIKey', Config::get('mainfreight.publicHolidaysAPIKey'));
		$this->set('boxSizes', Config::get('mainfreight.box_sizes') ?: []);
		$this->set('pickupAddress', Config::get('mainfreight.pickup_address') ?: []);
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
				Config::save('mainfreight.APIKey', trim($args['APIKey'] ?? ''));
				Config::save('mainfreight.accountID', trim($args['accountID'] ?? ''));
				Config::save('mainfreight.publicHolidaysAPIKey', trim($args['publicHolidaysAPIKey'] ?? ''));

				$boxSizes = [];
				if (!empty($args['boxes']) && is_array($args['boxes'])) {
					foreach ($args['boxes'] as $box) {
						$l = round((float) ($box['l'] ?? 0), 2);
						$w = round((float) ($box['w'] ?? 0), 2);
						$h = round((float) ($box['h'] ?? 0), 2);
						$k = round((float) ($box['k'] ?? 0), 2);
						if ($l > 0 && $w > 0 && $h > 0 && $k > 0) {
							$boxSizes[] = ['l' => $l, 'w' => $w, 'h' => $h, 'k' => $k];
						}
					}
				}
				Config::save('mainfreight.box_sizes', $boxSizes);

				Config::save('mainfreight.pickup_address', [
					'street'   => trim($args['street'] ?? ''),
					'suburb'   => trim($args['suburb'] ?? ''),
					'city'     => trim($args['city'] ?? ''),
					'postcode' => trim($args['postcode'] ?? ''),
				]);

				$this->flash('success', t('Settings Saved'));

				return new RedirectResponse(\URL::to('/dashboard/store/settings/mainfreight'));
			}
		}

		return null;
	}
}