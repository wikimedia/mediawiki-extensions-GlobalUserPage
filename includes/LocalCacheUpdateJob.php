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

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\JobQueue\Job;
use MediaWiki\Title\Title;

/**
 * A job that runs on local wikis to purge squid and possibly
 * queue local HTMLCacheUpdate jobs
 */
class LocalCacheUpdateJob extends Job {
	/**
	 * @param array $params Should have 'username' and 'touch' keys
	 */
	public function __construct(
		array $params,
		private readonly HTMLCacheUpdater $htmlCacheUpdater,
	) {
		parent::__construct( 'LocalGlobalUserPageCacheUpdateJob', $params );
	}

	/** @inheritDoc */
	public function run() {
		$title = Title::makeTitleSafe( NS_USER, $this->params['username'] );
		// We want to purge the cache of the accompanying page so the tabs change colors
		$other = $title->getOtherPage();
		$this->htmlCacheUpdater->purgeTitleUrls( [ $title, $other ], HTMLCacheUpdater::PURGE_INTENT_TXROUND_REFLECTED );
		if ( $this->params['touch'] ) {
			$title->touchLinks();
		}
		return true;
	}
}
