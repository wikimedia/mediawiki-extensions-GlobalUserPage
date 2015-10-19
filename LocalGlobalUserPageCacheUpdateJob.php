<?php

/**
 * A job that runs on local wikis to purge squid and possibly
 * queue local HTMLCacheUpdate jobs
 */
class LocalGlobalUserPageCacheUpdateJob extends Job {
	/**
	 * @param Title $title
	 * @param array $params Should have 'username' and 'touch' keys
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'LocalGlobalUserPageCacheUpdateJob', $title, $params );
	}

	public function run() {
		$title = Title::makeTitleSafe( NS_USER, $this->params['username'] );
		// We want to purge the cache of the accompanying page so the tabs change colors
		$other = $title->getOtherPage();

		$title->purgeSquid();
		$other->purgeSquid();
		HTMLFileCache::clearFileCache( $title );
		HTMLFileCache::clearFileCache( $other );
		if ( $this->params['touch'] ) {
			$title->touchLinks();
		}
	}
}
