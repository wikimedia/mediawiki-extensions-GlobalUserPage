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
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
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
