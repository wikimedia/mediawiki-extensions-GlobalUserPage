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

use JobQueueGroup;
use Title;

class CacheInvalidator {
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

		JobQueueGroup::singleton()->push( new LocalJobSubmitJob(
			Title::newFromText( 'User:' . $this->username ),
			[
				'username' => $this->username,
				'touch' => in_array( 'links', $this->options ),
			]
		) );
	}
}
