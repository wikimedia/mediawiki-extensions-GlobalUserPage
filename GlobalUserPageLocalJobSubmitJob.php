<?php

/**
 * Job class that submits LocalGlobalUserPageCacheUpdateJob jobs
 */
class GlobalUserPageLocalJobSubmitJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'GlobalUserPageLocalJobSubmitJob', $title, $params );
	}

	public function run() {
		$job = new LocalGlobalUserPageCacheUpdateJob(
			Title::newFromText( 'User:' . $this->params['username'] ),
			$this->params
		);
		foreach ( GlobalUserPage::getEnabledWikis() as $wiki ) {
			JobQueueGroup::singleton( $wiki )->push( $job );
		}
	}
}
