<?php

namespace MediaWiki\GlobalUserPage\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @coversNothing
 */
class GlobalUserPageExtensionJsonTest extends ExtensionJsonTestBase {

	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	/** @inheritDoc */
	protected static bool $testJobClasses = true;

}
