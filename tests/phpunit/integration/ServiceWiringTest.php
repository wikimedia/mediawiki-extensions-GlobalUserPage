<?php

/**
 * Verify that all services in this extension can be instantiated.
 */

namespace MediaWiki\GlobalUserPage\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	/** @dataProvider provideServices */
	public function testService( string $name ): void {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideServices(): iterable {
		$wiring = require __DIR__ . '/../../../includes/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
