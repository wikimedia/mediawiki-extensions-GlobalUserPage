<?php

class GlobalUserPageCacheInvalidator {
	/**
	 * Username of the user who's userpage needs to be invalidated
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Array of string options
	 *
	 * @var array
	 */
	private $options;

	public function __construct( $username, array $options = [] ) {
		$this->username = $username;
		$this->options = $options;
	}

	public function invalidate() {
		global $wgUseSquid, $wgUseFileCache;

		if ( !$wgUseSquid && !$wgUseFileCache && !$this->options ) {
			// No squid and no options means nothing to do!
			return;
		}

		JobQueueGroup::singleton()->push( new GlobalUserPageLocalJobSubmitJob(
			Title::newFromText( 'User:' . $this->username ),
			[
				'username' => $this->username,
				'touch' => in_array( 'links', $this->options ),
			]
		) );
	}
}
