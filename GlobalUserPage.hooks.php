<?php

class GlobalUserPageHooks {
	/**
	 * Adds the user option for using GlobalUserpage to Special:GlobalPreferences.
	 *
	 * @param User $user
	 * @param array $preferences Preference descriptions
	 * @return bool
	 */
	public static function onGetPreferences( User $user, &$preferences ) {
		if ( class_exists( 'GlobalPreferences' ) && GlobalPreferences::onGlobalPrefsPage() ) {
			$preferences['globaluserpage'] = array(
				'type' => 'toggle',
				'label-message' => 'globaluserpage-preferences',
				'section' => 'personal/info', // not the best place for it, but eh
			);
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @param Article|null $page
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
	 * Turn red links into blue in the navigation tabs (Monobook's p-cactions).
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array $links
	 * @return bool
	 */
	public static function onSkinTemplateNavigationUniversal( &$sktemplate, &$links ) {
		$title = $sktemplate->getTitle()->getSubjectPage(); // We want the user page

		if ( !$title->exists() && GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			// Removes ?action=edit&redlink=1
			$links['namespaces']['user']['href'] = $title->getFullURL();
			// Remove the "new" class:
			// "selected new" --> "selected"
			// "new" --> ""
			$links['namespaces']['user']['class'] = trim(
				str_replace( 'new', '', $links['namespaces']['user']['class'] )
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
	 * @param LinksUpdate $lu
	 * @return bool
	 */
	public static function onLinksUpdateComplete( LinksUpdate &$lu ) {
		$title = $lu->getTitle();
		if ( self::isGlobalUserPage( $title ) ) {
			$inv = new GlobalUserPageCacheInvalidator( $title->getText() );
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
			$inv = new GlobalUserPageCacheInvalidator( $title->getText(), array( 'links' ) );
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
	 * Show an edit notice on user pages which displays global user pages.
	 *
	 * @param Title $title
	 * @param integer $oldid
	 * @param array &$notices
	 */
	public static function onTitleGetEditNotices( Title $title, $oldid, array &$notices ) {
		if ( !$title->exists() && GlobalUserPage::shouldDisplayGlobalPage( $title ) ) {
			$notices['globaluserpage'] = '<p><strong>' .
				wfMessage( 'globaluserpage-editnotice' )->parse()
				. '</strong></p>';
		}
		return true;
	}
}
