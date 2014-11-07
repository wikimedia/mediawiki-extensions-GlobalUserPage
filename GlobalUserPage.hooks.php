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
			&& GlobalUserPage::displayGlobalPage( $title )
		) {
			$page = new GlobalUserPage(
				$title,
				ConfigFactory::getDefaultInstance()->makeConfig( 'globaluserpage' )
			);
		}

		return true;
	}

	/**
	 * Loads MediaWiki:GlobalUserPage.css on the central wikis
	 * root userpages
	 *
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage &$out ) {
		global $wgGlobalUserPageDBname;
		$title = $out->getTitle();
		if ( $wgGlobalUserPageDBname === wfWikiID() // On the central wiki,
			&& $title->inNamespace( NS_USER ) // a user page
			&& $title->exists() // that exists
			&& $title->getRootTitle()->equals( $title ) // and is a root page.
		) {
			$out->addModuleStyles( 'ext.GlobalUserPage.site' );
		}

		return true;
	}

	public static function onResourceLoaderRegisterModules( ResourceLoader &$resourceLoader ) {
		global $wgGlobalUserPageCSSRLSourceName, $wgGlobalUserPageDBname;

		$isEnabled = (bool)$wgGlobalUserPageCSSRLSourceName;

		// Always register the module, but if it is disabled via config,
		// pass it some dummy parameters.
		$resourceLoader->register( 'ext.GlobalUserPage.site', array(
			'class' => 'ResourceLoaderGlobalUserPageModule',
			'wiki' => $isEnabled ? $wgGlobalUserPageDBname : wfWikiID(),
			'source' => $isEnabled ? $wgGlobalUserPageCSSRLSourceName : 'local',
			'enabled' => $isEnabled,
		) );

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
		$title = $sktemplate->getTitle();

		if ( !$title->exists() && GlobalUserPage::displayGlobalPage( $title ) ) {
			// Removes ?action=edit&redlink=1
			$links['namespaces']['user']['href'] = $title->getFullURL();
			// "selected new" --> "selected"
			$links['namespaces']['user']['class'] = 'selected';
		}

		return true;
	}

	/**
	 * Turn red User: links into blue ones
	 *
	 * @param DummyLinker $linker for b/c
	 * @param Title $target
	 * @param string $text
	 * @param array $customAttribs custom attributes
	 * @param array $query
	 * @param array $options
	 * @param string $ret return value (link HTML)
	 * @return bool
	 */
	public static function brokenLink( $linker, $target, &$text, &$customAttribs, &$query, &$options, &$ret ) {
		if ( in_array( 'known', $options ) || $target->isKnown() ) {
			return true;
		}

		if ( GlobalUserPage::displayGlobalPage( $target ) ) {
			$options = array_merge(
				$options,
				array( 'known', 'noclasses' )
			);
		}

		return true;
	}
}
