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
use ConfigFactory;
use ExtensionRegistry;
use IContextSource;
use LinksUpdate;
use MediaWiki\MediaWikiServices;
use Title;
use User;
use WikiPage;

class Hooks {
	/**
	 * Adds the user option for using GlobalUserpage to Special:GlobalPreferences.
	 *
	 * @param User $user
	 * @param array &$preferences Preference descriptions
	 * @return bool
	 */
	public static function onGetPreferences( User $user, &$preferences ) {
		$preferencesFactory = MediaWikiServices::getInstance()->getPreferencesFactory();
		if ( ExtensionRegistry::getInstance()->isLoaded( 'GlobalPreferences' )
			&& $preferencesFactory instanceof \GlobalPreferences\GlobalPreferencesFactory
			&& $preferencesFactory->onGlobalPrefsPage()
		) {
			$preferences['globaluserpage'] = [
				'type' => 'toggle',
				'label-message' => 'globaluserpage-preferences',
				'section' => 'personal/info', // not the best place for it, but eh
			];
		}

		return true;
	}

	/**
	 * @param Title &$title
	 * @param Article|null &$page
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function onArticleFromTitle( Title &$title, &$page, $context ) {
		// If another extension's hook has already run, don't override it
		if ( $page === null
			&& $title->inNamespace( NS_USER ) && !$title->exists()
			&& GlobalUserPage::shouldDisplayGlobalPage( $title )
		) {
			$page = new GlobalUserPage(
				$title,
				ConfigFactory::getDefaultInstance()->makeConfig( 'globaluserpage' )
			);
		}

		return true;
	}

	/**
	 * Mark global user pages as known so they appear in blue
	 *
	 * @param Title $title title to check
	 * @param bool &$isKnown Whether the page should be considered known
	 * @return bool
	 */
	public static function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		if ( GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			$isKnown = true;
		}

		return true;
	}

	/**
	 * Whether a page is the global user page on the central wiki
	 *
	 * @param Title $title
	 * @return bool
	 */
	protected static function isGlobalUserPage( Title $title ) {
		global $wgGlobalUserPageDBname;

		return $wgGlobalUserPageDBname === wfWikiID() // On the central wiki
			&& $title->inNamespace( NS_USER ) // is a user page
			&& $title->getRootTitle()->equals( $title ); // and is a root page.
	}

	/**
	 * After a LinksUpdate runs for a user page, queue remote squid purges
	 *
	 * @param LinksUpdate &$lu
	 * @return bool
	 */
	public static function onLinksUpdateComplete( LinksUpdate &$lu ) {
		$title = $lu->getTitle();
		if ( self::isGlobalUserPage( $title ) ) {
			$inv = new CacheInvalidator( $title->getText() );
			$inv->invalidate();
		}

		return true;
	}

	/**
	 * Invalidate cache on remote wikis when a new page is created
	 * Also handles the ArticleDeleteComplete hook
	 *
	 * @param WikiPage $page
	 * @return bool
	 */
	public static function onPageContentInsertComplete( WikiPage $page ) {
		$title = $page->getTitle();
		if ( self::isGlobalUserPage( $title ) ) {
			$inv = new CacheInvalidator( $title->getText(), [ 'links' ] );
			$inv->invalidate();
		}

		return true;
	}

	/**
	 * Invalidate cache on remote wikis when a user page is deleted
	 *
	 * @param WikiPage $page
	 * @return bool
	 */
	public static function onArticleDeleteComplete( WikiPage $page ) {
		return self::onPageContentInsertComplete( $page );
	}

	/**
	 * Show an edit notice on user pages which displays global user pages
	 * or on the central global user page.
	 *
	 * @param Title $title
	 * @param int $oldid
	 * @param array &$notices
	 * @return true
	 */
	public static function onTitleGetEditNotices( Title $title, $oldid, array &$notices ) {
		if ( !$title->exists() && GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			$notices['globaluserpage'] = '<p><strong>' .
				wfMessage( 'globaluserpage-editnotice' )->parse()
				. '</strong></p>';
		} elseif ( self::isGlobalUserPage( $title ) ) {
			$notices['centraluserpage'] = wfMessage( 'globaluserpage-central-editnotice' )->parseAsBlock();
		}

		return true;
	}

	/**
	 * @param array &$ids
	 */
	public static function onGetDoubleUnderscoreIDs( array &$ids ) {
		$ids[] = 'noglobal';
	}

	/**
	 * @param Title $title
	 * @param WikiPage &$page
	 * @return bool
	 */
	public static function onWikiPageFactory( Title $title, &$page ) {
		if ( GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			$page = new WikiGlobalUserPage(
				$title,
				ConfigFactory::getDefaultInstance()->makeConfig( 'globaluserpage' )
			);

			return false;
		}

		return true;
	}
}
