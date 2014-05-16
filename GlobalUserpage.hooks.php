<?php

class GlobalUserpageHooks {
	/**
	 * Adds the user option for using GlobalUserpage to Special:Preferences.
	 *
	 * @param $user User
	 * @param $preferences Array: Preference descriptions
	 * @return bool
	 */
	public static function onGetPreferences( $user, &$preferences ) {
		$preferences['globaluserpage'] = array(
			'type' => 'toggle',
			'label-message' => 'globaluserpage-preferences',
			'section' => 'personal/info', // not the best place for it, but eh
		);
		return true;
	}

	/**
	 * If the requested page doesn't exist, try to see if we can render a
	 * global user page instead.
	 *
	 * @param $article Article
	 * @return bool
	 */
	public static function onShowMissingArticle( $article ) {
		global $wgDBname, $wgSharedDB;

		// Don't run this code for Hub.
		if ( $wgDBname == $wgSharedDB ) {
			return true;
		}

		$context = $article->getContext();
		$output = $context->getOutput();
		$title = $article->getTitle();

		if ( $title->getNamespace() == NS_USER ) {
			// Try to construct a valid User object...
			$user = User::newFromName( $title->getText() );

			if ( !$user instanceof User ) {
				// Anon/nonexistent user/something else, so get out of here.
				return true;
			}

			$wantsGlobalUserpage = $user->getOption( 'globaluserpage' );

			// If the user does *not* want a global userpage...get out of here.
			if ( !$wantsGlobalUserpage ) {
				return true;
			}

			// Alright, everything should be good now...
			list( $text, $oldid ) = GlobalUserpage::getPagePlusFallbacks( 'User:' . $title->getText() );
			if ( $text ) {
				// Add a notice indicating that it was taken from ShoutWiki Hub
				// ashley 23 December 2013: on a second though, don't.
				// Right now it just looks outright bad.
				//$output->addHTML( $context->msg( 'globaluserpage-notice', $oldid )->parse() );
				$output->addHTML( $text );
				// Hide the "this page does not exist" notice and edit section links
				$output->addModuleStyles( 'ext.GlobalUserpage' );
			}
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
		global $wgDBname, $wgSharedDB;

		// Don't run this code for Hub.
		if ( $wgDBname == $wgSharedDB ) {
			return true;
		}

		$context = $sktemplate->getContext();
		$title = $sktemplate->getTitle();

		if ( $title->getNamespace() == NS_USER ) {
			$user = User::newFromName( $title->getText() );

			if ( !$user instanceof User ) {
				// Anon/nonexistent user/something else, so get out of here.
				return true;
			}

			$wantsGlobalUserpage = $user->getOption( 'globaluserpage' );

			// If the user does *not* want a global userpage...get out of here.
			if ( !$wantsGlobalUserpage ) {
				return true;
			}

			list( $text, $oldid ) = GlobalUserpage::getPagePlusFallbacks( 'User:' . $title->getText() );
			if ( $text ) {
				$links['namespaces']['user']['href'] = $title->getFullURL();
				$links['namespaces']['user']['class'] = 'selected';
				//$links['namespaces']['user_talk']['class'] = '';
				//$links['namespaces']['user_talk']['href'] = 'http://www.shoutwiki.com/wiki/User_talk:' . $title->getText();
				/*
				$links['views'] = array(); // Kill the 'Create' button @todo make this suck less
				$links['views'][] = array(
					'class' => false,
					'text' => $context->msg( 'globaluserpage-edit-tab' ),
					'href' => wfAppendQuery(
						'http://www.shoutwiki.com/w/index.php',
						array(
							'action' => 'edit',
							'title' => $title->getPrefixedText()
						)
					)
				);
				*/
			}
		}

		// When we are on the person's user talk page, we still need to pretend
		// that their user page exists.
		if ( $title->getNamespace() == NS_USER_TALK ) {
			$user = User::newFromName( $title->getSubjectPage()->getText() );

			if ( !$user instanceof User ) {
				// Anon/nonexistent user/something else, so get out of here.
				return true;
			}

			$wantsGlobalUserpage = $user->getOption( 'globaluserpage' );

			// Perform special processing for the user tab (namely turn it into
			// blue if it were normally red), but only if we have to do that.
			if ( $wantsGlobalUserpage ) {
				$links['namespaces']['user']['href'] = $title->getSubjectPage()->getFullURL();
				$links['namespaces']['user']['class'] = ''; // remove redness
			}
		}

		return true;
	}


	/**
	 * Use action=purge to clear cache
	 *
	 * @param $article Article
	 * @return bool
	 */
	public static function onArticlePurge( &$article ) {
		global $wgMemc;

		$title = $article->getContext()->getTitle();
		$key = GlobalUserpage::getCacheKey( $title );
		$wgMemc->delete( $key );

		return true;
	}

	/**
	 * Turn red User: links into blue ones
	 *
	 * @param $linker Linker
	 * @param $target Title
	 * @param $text String
	 * @param $customAtrribs Array: array of custom attributes [unused]
	 * @param $query [unused]
	 * @param $ret String: return value (link HTML)
	 * @return Boolean
	 */
	public static function brokenLink( $linker, $target, &$text, &$customAttribs, &$query, &$options, &$ret ) {
		global $wgDBname, $wgSharedDB;

		// Don't run this code for Hub.
		if ( $wgDBname == $wgSharedDB ) {
			return true;
		}

		if ( $target->getNamespace() == NS_USER ) {
			$user = User::newFromName( $target->getText() );

			if ( !$user instanceof User ) {
				// Anon/nonexistent user/something else, so get out of here.
				return true;
			}

			$wantsGlobalUserpage = $user->getOption( 'globaluserpage' );

			// If the user does *not* want a global userpage...get out of here.
			if ( !$wantsGlobalUserpage ) {
				return true;
			}

			// return immediately if we know it's real
			// this part "borrowed" from ^demon's RemoveRedlinks, dunno if
			// we really need it anymore, but idk
			if ( in_array( 'known', $options ) || $target->isKnown() ) {
				return true;
			} else {
				$ret = Linker::linkKnown( $target, $text );
				return false;
			}
		}

		return true;
	}
}