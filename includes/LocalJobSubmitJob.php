<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace MediaWiki\GlobalUserPage;

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;

/**
 * Job class that submits LocalCacheUpdateJob jobs
 */
class LocalJobSubmitJob extends Job {
	public function __construct(
		array $params,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct( 'GlobalUserPageLocalJobSubmitJob', $params );
	}

	/** @inheritDoc */
	public function run() {
		$job = new JobSpecification( 'LocalGlobalUserPageCacheUpdateJob', $this->params );
		foreach ( GlobalUserPage::getEnabledWikis() as $wiki ) {
			$this->jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $job );
		}
		return true;
	}
}
