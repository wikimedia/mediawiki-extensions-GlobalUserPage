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
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Deferred\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\TitleGetEditNoticesHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\ObjectCache\WANObjectCache;

class Hooks implements
	TitleIsAlwaysKnownHook,
	ArticleFromTitleHook,
	LinksUpdateCompleteHook,
	PageSaveCompleteHook,
	ArticleDeleteCompleteHook,
	TitleGetEditNoticesHook,
	GetDoubleUnderscoreIDsHook,
	WikiPageFactoryHook
{
	private readonly Config $config;

	public function __construct(
		private readonly GlobalUserPageManager $manager,
		ConfigFactory $configFactory,
		private readonly Config $mainConfig,
		private readonly WANObjectCache $mainWANObjectCache,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly UrlUtils $urlUtils,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
		$this->config = $configFactory->makeConfig( 'globaluserpage' );
	}

	public static function onRegistration(): void {
		// Use globals instead of Config, accessing it so early does not work (T255704)
		global $wgGlobalUserPageDBname, $wgDBname;

		if ( defined( 'MW_PHPUNIT_TEST' ) || defined( 'MW_QUIBBLE_CI' ) ) {
			$wgGlobalUserPageDBname = $wgDBname;
		}

		if ( !$wgGlobalUserPageDBname ) {
			throw new ConfigException( 'GlobalUserPage requires $wgGlobalUserPageDBname to be set' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleFromTitle( $title, &$article, $context ): void {
		// If another extension's hook has already run, don't override it
		if ( $article === null
			&& $title->inNamespace( NS_USER ) && !$title->exists()
			&& $this->manager->shouldDisplayGlobalPage( $title )
		) {
			$article = new GlobalUserPage(
				$title,
				$this->config,
				$this->mainWANObjectCache,
				$this->manager,
				$this->httpRequestFactory,
				$this->urlUtils,
				$this->namespaceInfo
			);
		}
	}

	/**
	 * Mark global user pages as known so they appear in blue
	 *
	 * @inheritDoc
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ): void {
		if ( $this->manager->shouldDisplayGlobalPage( $title ) ) {
			$isKnown = true;
		}
	}

	/**
	 * After a LinksUpdate runs for a user page, queue remote squid purges
	 *
	 * @inheritDoc
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ): void {
		$title = $linksUpdate->getTitle();
		if ( $this->manager->isGlobalPage( $title ) ) {
			$inv = new CacheInvalidator(
				$this->jobQueueGroup,
				$this->mainConfig,
				$title->getText()
			);
			$inv->invalidate();
		}
	}

	private function invalidCacheIfGlobal( WikiPage $page ): void {
		$title = $page->getTitle();
		if ( $this->manager->isGlobalPage( $title ) ) {
			$inv = new CacheInvalidator(
				$this->jobQueueGroup,
				$this->mainConfig,
				$title->getText(),
				[ 'links' ],
			);
			$inv->invalidate();
		}
	}

	/**
	 * Invalidate cache on remote wikis when a new page is created
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$this->invalidCacheIfGlobal( $wikiPage );
	}

	/**
	 * Invalidate cache on remote wikis when a user page is deleted
	 *
	 * @inheritDoc
	 */
	public function onArticleDeleteComplete(
		$wikiPage, $user, $reason, $id, $content, $logEntry, $archivedRevisionCount
	): void {
		$this->invalidCacheIfGlobal( $wikiPage );
	}

	/**
	 * Show an edit notice on user pages which displays global user pages
	 * or on the central global user page.
	 *
	 * @inheritDoc
	 */
	public function onTitleGetEditNotices( $title, $oldid, &$notices ): void {
		if ( !$title->exists() && $this->manager->shouldDisplayGlobalPage( $title ) ) {
			$notices['globaluserpage'] = '<p><strong>' .
				wfMessage( 'globaluserpage-editnotice' )->parse()
				. '</strong></p>';
		} elseif ( $this->manager->isGlobalPage( $title ) ) {
			$notices['centraluserpage'] = wfMessage( 'globaluserpage-central-editnotice' )->parseAsBlock();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ): void {
		$doubleUnderscoreIDs[] = 'noglobal';
	}

	/**
	 * @inheritDoc
	 */
	public function onWikiPageFactory( $title, &$page ): bool {
		if ( $this->manager->shouldDisplayGlobalPage( $title ) ) {
			$page = new WikiGlobalUserPage(
				$title,
				$this->config,
				$this->mainWANObjectCache,
				$this->httpRequestFactory,
				$this->urlUtils
			);

			return false;
		}

		return true;
	}
}
