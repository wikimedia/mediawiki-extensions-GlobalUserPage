<?php

class GlobalUserPage extends Article {

	/**
	 * @var string
	 */
	protected $globalTitle;

	public function showMissingArticle() {
		global $wgGlobalUserPageLoadRemoteModules;
		$title = $this->getTitle();

		if ( !self::displayGlobalPage( $title ) ) {
			parent::showMissingArticle();
			return;
		}

		$out = $this->getContext()->getOutput();
		list( $langCode, $touched ) = $this->getRemoteTitle();
		$parsedOutput = $this->getRemoteParsedText( $langCode, $touched );
		$out->addHTML( $parsedOutput['text']['*'] );
		$out->addModuleStyles( 'ext.GlobalUserPage' );

		// Scary ResourceLoader things...
		if ( $wgGlobalUserPageLoadRemoteModules ) {
			$rl = $out->getResourceLoader();
			$map = array(
				'modules' => 'addModules',
				'modulestyles' => 'addModuleStyles',
				'modulescripts' => 'addModuleScripts',
				//'modulemessages' => 'addModuleMessages', // @todo how does this work?
			);
			foreach ( $map as $type => $func ) {
				foreach ( $parsedOutput[$type] as $module ) {
					if ( strpos( $module, 'ext.' ) === 0 && $rl->getModule( $module ) !== null ) {
						$out->$func( $module );
					}
				}
			}
		}
	}

	/**
	 * @param int $type DB_SLAVE or DB_MASTER
	 * @return DatabaseBase
	 */
	protected static function getRemoteDB( $type ) {
		global $wgGlobalUserPageDBname;
		return wfGetDB( $type, array(), $wgGlobalUserPageDBname );
	}

	/**
	 * @param string $username
	 * @return string
	 */
	private static function getEnabledCacheKey( $username ) {
		return 'globaluserpage:enabled:' . md5( $username );
	}

	/**
	 * Given a Title, assuming it doesn't exist, should
	 * we display a global user page on it
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function displayGlobalPage( Title $title ) {
		global $wgMemc;

		if ( !self::canBeGlobal( $title ) ) {
			return false;
		}

		$user = User::newFromName( $title->getText() );

		if ( !$user || $user->getId() === 0 ) {
			return false;
		}

		if ( !$user->getOption( 'globaluserpage' ) ) {
			return false;
		}

		// TODO: Add a hook here for things like CentralAuth
		// to check User:A@foowiki === User:A@centralwiki

		$key = self::getEnabledCacheKey( $user->getName() );
		$data = $wgMemc->get( $key );
		if ( $data === false ) {
			// Ugh, no cache. Open up a database connection to check if at least the root user page exists
			$dbr = self::getRemoteDB( DB_SLAVE );
			$row = $dbr->selectRow(
				'page',
				array( 'page_id' ),
				array(
					'page_title' => $user->getName(),
					'page_namespace' => NS_USER
				)
			);
			if ( $row === false ) {
				// We cache `null` to indicate boolean false
				$data = null;
			} else {
				$data = true;
			}

			$wgMemc->set( $key, $data );
		}

		return (bool)$data;
	}

	/**
	 * Given a Title, is it a source page we might
	 * be "transcluding" on another site
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function isSourcePage( Title $title ) {
		global $wgGlobalUserPageDBname;
		if ( wfWikiID() !== $wgGlobalUserPageDBname ) {
			return false;
		}

		if ( !$title->inNamespace( NS_USER ) ) {
			return false;
		}

		if ( $title->getRootTitle()->equals( $title ) ) {
			// Root user page
			return true;
		}

		// Ensure that the given title has one and only one subpage part
		$subjTitle = $title->getRootTitle()->getSubpage( $title->getSubpageText() );
		if ( !$title->equals( $subjTitle ) ) {
			return false;
		}

		return Language::isValidCode( $title->getSubpageText() );
	}

	/**
	 * Username for the given global user page
	 *
	 * @return string
	 */
	public function getUsername() {
		return $this->getTitle()->getText();
	}

	private function getMapCacheKey(){
		return 'globaluserpage:map:' . md5( $this->getUsername() );
	}

	public function updateMap( $langCode, $touched ) {
		global $wgMemc;
		$key = $this->getMapCacheKey();
		$data = $wgMemc->get( $key );
		if ( $data ) {
			// If $data === false, don't bother trying to re-cache,
			// it'll automatically happen the next time it is loaded
			// if there is cache though, update it!
			$data[$langCode] = $touched;
			$wgMemc->set( $key, $data );
		}
	}

	public function removeMap( $langCode ) {
		global $wgMemc;
		$key = $this->getMapCacheKey();
		$data = $wgMemc->get( $key );
		if ( $data ) {
			// If $data === false, don't bother trying to re-cache,
			// it'll automatically happen the next time it is loaded
			// if there is cache though, update it!
			unset( $data[$langCode] );
			$wgMemc->set( $key, $data );
		}

	}

	/**
	 * Returns title, touched timestamp that points to
	 * the remote site including language fallback
	 *
	 * @todo REGEXP is MySQL-specific
	 * @throws MWException
	 * @return array
	 */
	public function getRemoteTitle() {
		global $wgMemc, $wgLanguageCode;
		$key = $this->getMapCacheKey();
		$data = $wgMemc->get( $key );
		if ( $data === false ) {
			$dbr = self::getRemoteDB( DB_SLAVE );
			$langCodes = implode( '|', Language::fetchLanguageNames() );
			$rows = $dbr->select(
				'page',
				array( 'page_title', 'page_touched' ),
				array(
					'page_title REGEXP ' . $dbr->addQuotes( "{$this->getUsername()}(/($langCodes))?$" ),
					'page_namespace' => NS_USER,
				),
				__METHOD__
			);
			$data = array();
			foreach ( $rows as $row ) {
				if ( strpos( $row->page_title, '/' ) !== false ) {
					list( , $langCode ) = explode( '/', $row->page_title );
				} else {
					$langCode = 'en'; // Assume main userpage is in English
				}

				$data[$langCode] = $row->page_touched;
			}
			$wgMemc->set( $key, $data );
		}

		$fallbacks = Language::getFallbacksFor( $wgLanguageCode );
		foreach( $fallbacks as $fallback ) {
			if ( isset( $data[$fallback] ) ) {
				// array( $langCode, $touched );
				return array( $fallback, $data[$fallback] );
			}
		}

		// Should be unreachable if displayGlobalTitle was called first.
		throw new MWException( __METHOD__ . " could not find a title for {$this->getUsername()}" );
	}

	/**
	 * Return the associated language code
	 *
	 * @param Title $title
	 * @return string
	 */
	public static function getLangCodeForTitle( Title $title ) {
		if ( $title->getRootTitle()->equals( $title ) ) {
			return 'en';
		} else {
			return $title->getSubpageText();
		}
	}

	private function getUserPageName( $langCode ) {
		if ( $langCode === 'en ') {
			return "User:{$this->getUsername()}";
		} else {
			return "User:{$this->getUsername()}/{$langCode}";
		}
	}

	/**
	 * @param string $langCode A language code
	 * @param string $touched
	 * @return string
	 */
	public function getRemoteParsedText( $langCode, $touched ) {
		global $wgMemc, $wgLanguageCode, $wgGlobalUserPageCacheExpiry;

		// Need $wgLanguageCode in the key since we pass &uselang= to the API.
		$key = "globaluserpage:parsed:$langCode:$wgLanguageCode:$touched";
		$data = $wgMemc->get( $key );
		if ( $data === false ){
			$data = self::parseWikiText( $this->getUserPageName( $langCode ) );
			$wgMemc->set( $data, $wgGlobalUserPageCacheExpiry );
		}

		return $data;
	}

	/**
	 * Checks whether the given page can be global
	 * doesn't check the actual database
	 * @param Title $title
	 * @return bool
	 */
	protected static function canBeGlobal( Title $title ) {
		global $wgGlobalUserPageDBname;
		// Don't run this code for Hub.
		if ( wfWikiID() === $wgGlobalUserPageDBname ) {
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
	 * Makes an API request to the central wiki
	 *
	 * @param $params array
	 * @return array
	 */
	protected static function makeAPIRequest( $params ) {
		global $wgGlobalUserPageAPIUrl;
		$params['format'] = 'json';
		$url = wfAppendQuery( $wgGlobalUserPageAPIUrl, $params );
		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param string $title
	 * @return array
	 */
	protected static function parseWikiText( $title ) {
		global $wgLanguageCode;
		$params = array(
			'action' => 'parse',
			'title' => $title,
			'disableeditsection' => 1,
			'uselang' => $wgLanguageCode,
			'prop' => 'text|modules'
		);
		$data = self::makeAPIRequest( $params );
		return $data['parse'];
	}

	public function clearEnabledCache() {
		global $wgMemc;
		$wgMemc->delete( self::getEnabledCacheKey( $this->getUsername()) );
	}

	/**
	 * Clear all the caches
	 */
	public function clearCache() {
		global $wgMemc;
		// Whether to use a global userpage flag
		$this->clearEnabledCache();
		// Huge map of $langCode => $touched
		$wgMemc->delete( $this->getMapCacheKey() );

		// Note we don't need to clear globaluserpage:parsed:* since that
		// relies on page_touched, which should update if anything changes
	}
}
