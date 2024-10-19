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

use Article;
use ManualLogEntry;
use MediaWiki\Content\Content;
use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\TitleGetEditNoticesHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use WikiPage;

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

	/**
	 * @param Title $title
	 * @param Article|null &$page
	 * @param IContextSource $context
	 */
	public function onArticleFromTitle( $title, &$page, $context ) {
		// If another extension's hook has already run, don't override it
		if ( $page === null
			&& $title->inNamespace( NS_USER ) && !$title->exists()
			&& GlobalUserPage::shouldDisplayGlobalPage( $title )
		) {
			$page = new GlobalUserPage(
				$title,
				MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'globaluserpage' )
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
		if ( GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
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

		return $wgGlobalUserPageDBname === WikiMap::getCurrentWikiId() // On the central wiki
			&& $title->inNamespace( NS_USER ) // is a user page
			&& $title->getRootTitle()->equals( $title ); // and is a root page.
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
		if ( !$title->exists() && GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
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
		if ( GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			$page = new WikiGlobalUserPage(
				$title,
				MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'globaluserpage' )
			);

			return false;
		}

		return true;
	}
}
