<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\GlobalUserPage\GlobalUserPageManager;
use MediaWiki\MediaWikiServices;

// PHPUnit doesn't understand code coverage for code outside of classes/functions,
// like service wiring files. see T310509
// @codeCoverageIgnoreStart
return [
	'GlobalUserPage.GlobalUserPageManager' => static function ( MediaWikiServices $services ): GlobalUserPageManager {
		$config = $services->getConfigFactory()->makeConfig( 'globaluserpage' );

		return new GlobalUserPageManager(
			$services->getConnectionProvider(),
			$services->getUserFactory(),
			$services->getUserNameUtils(),
			$services->getCentralIdLookup(),
			$services->getTitleFormatter(),
			$services->getTitleFactory(),
			new ServiceOptions( GlobalUserPageManager::CONSTRUCTOR_OPTIONS, $config )
		);
	}
];
