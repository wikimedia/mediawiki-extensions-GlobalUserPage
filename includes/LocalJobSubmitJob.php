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

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Job class that submits LocalCacheUpdateJob jobs
 */
class LocalJobSubmitJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'GlobalUserPageLocalJobSubmitJob', $title, $params );
	}

	public function run() {
		$job = new LocalCacheUpdateJob(
			Title::newFromText( 'User:' . $this->params['username'] ),
			$this->params
		);
		$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
		foreach ( GlobalUserPage::getEnabledWikis() as $wiki ) {
			$jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $job );
		}
		return true;
	}
}
