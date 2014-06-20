<?php

class GlobalUserpage extends Article {

	/**
	 * @var string
	 */
	protected $globalTitle;

	public function showMissingArticle() {
		$title = $this->getTitle();

		if ( !self::isGlobal( $title ) ) {
			parent::showMissingArticle();
			return;
		}

		$out = $this->getContext()->getOutput();
		$out->addHTML( $this->getGlobalText() );
		$out->addModuleStyles( 'ext.GlobalUserpage' );
	}

	/**
	 * Checks whether the given page can be global
	 * doesn't check the actual database
	 * @param Title $title
	 * @return bool
	 */
	protected static function canBeGlobal( Title $title ) {
		global $wgGlobalUserpageDBname;
		// Don't run this code for Hub.
		if ( wfWikiID() === $wgGlobalUserpageDBname ) {
			return false;
		}

		// Must be a user page
		if ( !$title->inNamespace( NS_USER ) ) {
			return false;
		}

		// Check it's a root user page
		if ( $title->getRootText() !== $title->getText() ) {
			return false;
		}

		// Check valid username
		if ( !User::newFromName( $title->getText() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Actually checks the database and user options
	 * @param Title $title
	 * @return bool
	 */
	public static function isGlobal( Title $title ) {
		global $wgGlobalUserpageDBname;

		if ( !self::canBeGlobal( $title ) ) {
			return false;
		}

		$user = User::newFromName( $title->getText() );

		if ( $user->getId() === 0 ) {
			return false;
		}

		if ( !$user->getOption( 'globaluserpage' ) ) {
			return false;
		}

		// Ewww, CentralAuth is terrible.
		/*
		if ( class_exists( 'CentralAuthUser' ) ) {
			$caUser = CentralAuthUser::getInstance( $user );
			if ( !$caUser->isAttached() || !$caUser->attachedOn( $wgGlobalUserpageDBname ) ) {
				return false;
			}
		}*/

		return self::exists( $user );
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	protected static function exists( User $user ) {
		// Would be nice if we didn't have to create a new article, but eh.
		$page = new self( $user->getUserPage() );
		return $page->getGlobalTitle() !== false;
	}

	/**
	 * Makes an API request to the central wiki
	 *
	 * @param $params array
	 * @return array
	 */
	protected static function makeAPIRequest( $params ) {
		global $wgGlobalUserpageAPIUrl;
		$params['format'] = 'json';
		$url = wfAppendQuery( $wgGlobalUserpageAPIUrl, $params );
		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param $title string
	 * @return array
	 */
	protected static function parseWikiText( $title ) {
		$params = array(
			'action' => 'parse',
			'page' => $title
		);
		$data = self::makeAPIRequest( $params );
		return $data['parse']['text']['*'];
	}

	/**
	 * Clear all the caches
	 */
	public function clearCache() {
		global $wgMemc;
		$username = $this->getTitle()->getText();
		$title = $this->getGlobalTitle();
		$wgMemc->delete( $this->getGlobalTextCacheKey( $title ) );
		$wgMemc->delete( $this->getGlobalTitleCacheKey( $username ) );
	}


	protected function getGlobalTitleCacheKey( $username ) {
		global $wgLanguageCode;
		return "globaluserpage:{$wgLanguageCode}:" . md5( $username );
	}

	/**
	 * @return string|bool false if there is no page
	 */
	public function getGlobalTitle() {
		if ( $this->globalTitle !== null ) {
			return $this->globalTitle;
		}

		$username = $this->getTitle()->getText();

		global $wgMemc, $wgLanguageCode, $wgGlobalUserpageCacheExpiry;
		$key = $this->getGlobalTitleCacheKey( $username );
		$data = $wgMemc->get( $key );
		if ( $data ) {
			$this->globalTitle = ( $data == '!!NOEXIST!!' ) ? false : $data;
		} else {
			$fallbacks = Language::getFallbacksFor( $wgLanguageCode );
			array_unshift( $fallbacks, $wgLanguageCode );
			$titles = array();
			$title = 'User:' . $username;

			foreach ( $fallbacks as $langCode ) {
				if ( $langCode === 'en' ) {
					$titles[$title] = $langCode;
				} else {
					$titles[$title . '/' . $langCode] = $langCode;
				}
			}

			$params = array(
				'action' => 'query',
				'titles' => implode( '|', array_keys( $titles ) )
			);
			$data = self::makeAPIRequest( $params );
			$pages = array();

			foreach ( $data['query']['pages'] as /* $id => */ $info ) {
				if ( isset( $info['missing'] ) ) {
					continue;
				}
				$lang = $titles[$info['title']];
				$pages[$lang] = $info['title'];
			}

			foreach ( $fallbacks as $langCode ) {
				if ( isset( $pages[$langCode] ) ) {
					$data = $pages[$langCode];
					$this->globalTitle = $data;
					$wgMemc->set( $key, $data, $wgGlobalUserpageCacheExpiry );
					break;
				}
			}
			if ( !$data ) {
				// Cache failure
				$this->globalTitle = false;
				$wgMemc->set( $key, '!!NOEXIST!!', $wgGlobalUserpageCacheExpiry );

			}
		}

		return $data;
	}

	protected function getGlobalTextCacheKey( $title ) {
		return 'globaluserpage:' . md5( $title );
	}

	/**
	 * @return string
	 */
	public function getGlobalText() {
		global $wgMemc, $wgGlobalUserpageCacheExpiry;
		$title = $this->getGlobalTitle();

		$key = $this->getGlobalTextCacheKey( $title );
		$data = $wgMemc->get( $key );
		if ( $data === false ) {
			$data = self::parseWikiText( $title );
			$wgMemc->set( $key, $data, $wgGlobalUserpageCacheExpiry );
		}

		return $data;
	}
}
