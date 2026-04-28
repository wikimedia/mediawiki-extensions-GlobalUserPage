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

use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\MainConfigNames;

class CacheInvalidator {
	/**
	 * @param JobQueueGroup $jobQueueGroup
	 * @param Config $mainConfig
	 * @param string $username Username of the user who's userpage needs to be invalidated
	 * @param string[] $options Array of string options
	 */
	public function __construct(
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly Config $mainConfig,
		private readonly string $username,
		private readonly array $options = [],
	) {
	}

	public function invalidate(): void {
		if ( !$this->mainConfig->get( MainConfigNames::UseCdn ) &&
			!$this->mainConfig->get( MainConfigNames::UseFileCache ) &&
			!$this->options
		) {
			// No CDN and no options means nothing to do!
			return;
		}

		$this->jobQueueGroup->push( new JobSpecification(
			'GlobalUserPageLocalJobSubmitJob',
			[
				'username' => $this->username,
				'touch' => in_array( 'links', $this->options ),
			]
		) );
	}
}
