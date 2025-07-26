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
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Content\Content;
use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\TitleGetEditNoticesHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\Article;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\WikiMap\WikiMap;
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
	private GlobalUserPageManager $manager;
	private Config $config;
	private WANObjectCache $mainWANObjectCache;
	private HttpRequestFactory $httpRequestFactory;
	private UrlUtils $urlUtils;
	private NamespaceInfo $namespaceInfo;

	public function __construct(
		GlobalUserPageManager $manager,
		ConfigFactory $configFactory,
		WANObjectCache $mainWANObjectCache,
		HttpRequestFactory $httpRequestFactory,
		UrlUtils $urlUtils,
		NamespaceInfo $namespaceInfo
	) {
		$this->manager = $manager;
		$this->config = $configFactory->makeConfig( 'globaluserpage' );
		$this->mainWANObjectCache = $mainWANObjectCache;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->urlUtils = $urlUtils;
		$this->namespaceInfo = $namespaceInfo;
	}

	/**
	 * @param Title $title
	 * @param Article|null &$page
	 * @param IContextSource $context
	 */
	public function onArticleFromTitle( $title, &$page, $context ) {
		// If another extension's hook has already run, don't override it
		if ( $page === null
			&& $title->inNamespace( NS_USER ) && !$title->exists()
			&& $this->manager->shouldDisplayGlobalPage( $title )
		) {
			$page = new GlobalUserPage(
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
	 * @param Title $title title to check
	 * @param bool &$isKnown Whether the page should be considered known
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		if ( $this->manager->shouldDisplayGlobalPage( $title ) ) {
			$isKnown = true;
		}
	}

	/**
	 * Whether a page is the global user page on the central wiki
	 *
	 * @param Title $title
	 * @return bool
	 */
	protected static function isGlobalUserPage( Title $title ) {
		global $wgGlobalUserPageDBname;

		// On the central wiki
		return $wgGlobalUserPageDBname === WikiMap::getCurrentWikiId()
			// is a user page
			&& $title->inNamespace( NS_USER )
			// and is a root page.
			&& $title->getRootTitle()->equals( $title );
	}

	/**
	 * After a LinksUpdate runs for a user page, queue remote squid purges
	 *
	 * @param LinksUpdate $lu
	 * @param mixed $ticket
	 */
	public function onLinksUpdateComplete( $lu, $ticket ) {
		$title = $lu->getTitle();
		if ( self::isGlobalUserPage( $title ) ) {
			$inv = new CacheInvalidator( $title->getText() );
			$inv->invalidate();
		}
	}

	private function invalidCacheIfGlobal( WikiPage $page ): void {
		$title = $page->getTitle();
		if ( self::isGlobalUserPage( $title ) ) {
			$inv = new CacheInvalidator( $title->getText(), [ 'links' ] );
			$inv->invalidate();
		}
	}

	/**
	 * Invalidate cache on remote wikis when a new page is created
	 *
	 * @param WikiPage $page
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 */
	public function onPageSaveComplete( $page, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$this->invalidCacheIfGlobal( $page );
	}

	/**
	 * Invalidate cache on remote wikis when a user page is deleted
	 *
	 * @param WikiPage $page
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @param Content|null $content
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public function onArticleDeleteComplete(
		$page, $user, $reason, $id, $content, $logEntry, $archivedRevisionCount
	) {
		$this->invalidCacheIfGlobal( $page );
	}

	/**
	 * Show an edit notice on user pages which displays global user pages
	 * or on the central global user page.
	 *
	 * @param Title $title
	 * @param int $oldid
	 * @param array &$notices
	 */
	public function onTitleGetEditNotices( $title, $oldid, &$notices ) {
		if ( !$title->exists() && $this->manager->shouldDisplayGlobalPage( $title ) ) {
			$notices['globaluserpage'] = '<p><strong>' .
				wfMessage( 'globaluserpage-editnotice' )->parse()
				. '</strong></p>';
		} elseif ( self::isGlobalUserPage( $title ) ) {
			$notices['centraluserpage'] = wfMessage( 'globaluserpage-central-editnotice' )->parseAsBlock();
		}
	}

	/**
	 * @param array &$ids
	 */
	public function onGetDoubleUnderscoreIDs( &$ids ) {
		$ids[] = 'noglobal';
	}

	/**
	 * @param Title $title
	 * @param WikiPage &$page
	 * @return bool
	 */
	public function onWikiPageFactory( $title, &$page ) {
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
