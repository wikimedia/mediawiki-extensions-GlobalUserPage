<?php

namespace MediaWiki\GlobalUserPage\Hooks;

use MediaWiki\HookContainer\HookContainer;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner implements
	GlobalUserPageWikisHook
{
	public function __construct( private readonly HookContainer $hookContainer ) {
	}

	/**
	 * @inheritDoc
	 */
	public function onGlobalUserPageWikis( array &$list ): bool {
		return $this->hookContainer->run(
			'GlobalUserPageWikis',
			[ &$list ]
		);
	}
}
