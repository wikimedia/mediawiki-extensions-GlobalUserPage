<?php

namespace MediaWiki\GlobalUserPage\Hooks;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "GlobalUserPageWikis" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface GlobalUserPageWikisHook {
	/**
	 * Return a list of enabled wikis
	 *
	 * @param array &$list
	 * @return bool Return false when list gets set
	 */
	public function onGlobalUserPageWikis( array &$list ): bool;
}
