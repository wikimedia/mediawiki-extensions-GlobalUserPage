<?php

class GlobalUserPage extends Article {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var BagOStuff
	 */
	private $cache;

	/**
	 * @var MapCacheLRU
	 */
	private static $displayCache;

	/**
	 * @var MapCacheLRU
	 */
	private static $touchedCache;

	public function __construct( Title $title, Config $config ) {
		global $wgMemc;
		parent::__construct( $title );
		$this->config = $config;
		$this->cache = $wgMemc;
	}

	public function showMissingArticle() {
		$title = $this->getTitle();

		if ( !self::shouldDisplayGlobalPage( $title ) ) {
			parent::showMissingArticle();
			return;
		}

		$user = User::newFromName( $this->getUsername() );

		$out = $this->getContext()->getOutput();
		$parsedOutput = $this->getRemoteParsedText( self::getCentralTouched( $user ) );

		// If the user page is empty or the API request failed, show the normal
		// missing article page
		if ( !$parsedOutput || !trim( $parsedOutput['text']['*'] ) ) {
			parent::showMissingArticle();
			return;
		}
		$out->addHTML( $parsedOutput['text']['*'] );
		$out->addModuleStyles( 'ext.GlobalUserPage' );

		$footerKey = $this->config->get( 'GlobalUserPageFooterKey' );
		if ( $footerKey ) {
			$out->addHTML( '<div class="mw-globaluserpage-footer plainlinks">' .
				"\n" . $out->msg( $footerKey )
					->params( $this->getUsername(), $this->getRemoteURL() )->parse() .
				"\n</div>"
			);
		}

		// Load ParserOutput modules...
		$this->loadModules( $out, $parsedOutput );
	}

	/**
	 * Attempts to load modules through the
	 * ParserOutput on the local wiki, if
	 * they exist.
	 *
	 * @param OutputPage $out
	 * @param array $parsedOutput
	 */
	private function loadModules( OutputPage $out, array $parsedOutput ) {
		$rl = $out->getResourceLoader();
		$map = array(
			'modules' => 'addModules',
			'modulestyles' => 'addModuleStyles',
			'modulescripts' => 'addModuleScripts',
		);
		foreach ( $map as $type => $func ) {
			foreach ( $parsedOutput[$type] as $module ) {
				if ( $rl->isModuleRegistered( $module ) ) {
					$out->$func( $module );
				}
			}
		}
	}

	/**
	 * Given a Title, assuming it doesn't exist, should
	 * we display a global user page on it
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function shouldDisplayGlobalPage( Title $title ) {
		global $wgGlobalUserPageDBname;
		if ( !self::canBeGlobal( $title ) ) {
			return false;
		}
		// Do some instance caching since this can be
		// called frequently due do the Linker hook
		if ( !self::$displayCache ) {
			self::$displayCache = new MapCacheLRU( 100 );
		}

		$text = $title->getPrefixedText();
		if ( self::$displayCache->has( $text ) ) {
			return self::$displayCache->get( $text );
		}


		$user = User::newFromName( $title->getText() );
		$user->load( User::READ_NORMAL );

		// Already validated that the username is fine in canBeGlobal
		if ( $user->getId() === 0 ) {
			self::$displayCache->set( $text, false );
			return false;
		}

		// Only check preferences if E:GlobalPreferences is installed
		if ( class_exists( 'GlobalPreferences' ) ) {
			if ( !$user->getOption( 'globaluserpage' ) ) {
				self::$displayCache->set( $text, false );
				return false;
			}
		}

		// Make sure that the username represents the same
		// user on both wikis.
		$lookup = CentralIdLookup::factory();
		if ( !$lookup->isAttached( $user ) || !$lookup->isAttached( $user, $wgGlobalUserPageDBname ) ) {
			self::$displayCache->set( $text, false );
			return false;
		}

		$touched = (bool)self::getCentralTouched( $user );
		self::$displayCache->set( $text, $touched );
		return $touched;
	}

	/**
	 * Get the page_touched of the central user page
	 *
	 * @todo this probably shouldn't be static
	 * @param User $user
	 * @return string|bool
	 */
	protected static function getCentralTouched( User $user ) {
		if ( !self::$touchedCache ) {
			self::$touchedCache = new MapCacheLRU( 100 );
		}
		if ( self::$touchedCache->has( $user->getName() ) ) {
			return self::$touchedCache->get( $user->getName() );
		}

		global $wgGlobalUserPageDBname;
		$lb = wfGetLB( $wgGlobalUserPageDBname );
		$dbr = $lb->getConnection( DB_SLAVE, array(), $wgGlobalUserPageDBname );
		$touched = $dbr->selectField(
			'page',
			'page_touched',
			array(
				'page_namespace' => NS_USER,
				'page_title' => $user->getUserPage()->getDBkey()
			),
			__METHOD__
		);
		$lb->reuseConnection( $dbr );

		self::$touchedCache->set( $user->getName(), $touched );

		return $touched;
	}

	/**
	 * Given a Title, is it a source page we might
	 * be "transcluding" on another site
	 *
	 * @return bool
	 */
	public function isSourcePage() {
		if ( wfWikiID() !== $this->config->get( 'GlobalUserPageDBname' ) ) {
			return false;
		}

		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_USER ) ) {
			return false;
		}

		// Root user page
		return $title->getRootTitle()->equals( $title );
	}

	/**
	 * Username for the given global user page
	 *
	 * @return string
	 */
	public function getUsername() {
		return $this->getTitle()->getText();
	}

	/**
	 * @param string $touched The page_touched for the page
	 * @return array
	 */
	public function getRemoteParsedText( $touched ) {
		$langCode = $this->getContext()->getLanguage()->getCode();

		// Need language code in the key since we pass &uselang= to the API.
		$key = "globaluserpage:parsed:$touched:$langCode:" . md5( $this->getUsername() );
		$data = $this->cache->get( $key );
		if ( $data === false ){
			$data = $this->parseWikiText( $this->getTitle(), $langCode );
			if ( $data ) {
				$this->cache->set( $key, $data, $this->config->get( 'GlobalUserPageCacheExpiry' ) );
			} else {
				// Cache failure for 10 seconds
				$this->cache->set( $key, null, 10 );
			}
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
		return User::isValidUserName( $title->getText() );
	}

	/**
	 * Makes an API request to the central wiki
	 *
	 * @param $params array
	 * @return array|bool false if the request failed
	 */
	protected function makeAPIRequest( $params ) {
		$params['format'] = 'json';
		$url = wfAppendQuery( $this->config->get( 'GlobalUserPageAPIUrl' ), $params );
		wfDebugLog( 'GlobalUserPage', "Making a request to $url" );
		$req = MWHttpRequest::factory(
			$url,
			array( 'timeout' => $this->config->get( 'GlobalUserPageTimeout' ) )
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			wfDebugLog( 'GlobalUserPage', __METHOD__ . " Error: {$status->getWikitext()}" );
			return false;
		}
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Returns a URL to the user page on the central wiki,
	 * attempts to use SiteConfiguration if possible, else
	 * falls back to using an API request
	 *
	 * @return string
	 */
	protected function getRemoteURL() {
		$url = WikiMap::getForeignURL(
			$this->config->get( 'GlobalUserPageDBname' ),
			'User:' . $this->getUsername()
		);

		if ( $url !== false ) {
			return $url;
		} else {
			// Fallback to the API
			return $this->getRemoteURLFromAPI();
		}
	}

	/**
	 * Returns a URL to the user page on the central wiki;
	 * if MW >= 1.24, this will be the cannonical url, otherwise
	 * it will be using whatever protocol was specified in
	 * $wgGlobalUserPageAPIUrl.
	 *
	 * @return string
	 */
	protected function getRemoteURLFromAPI() {
		$key = 'globaluserpage:url:' . md5( $this->getUsername() );
		$data = $this->cache->get( $key );
		if ( $data === false ) {
			$params = array(
				'action' => 'query',
				'titles' => 'User:' . $this->getUsername(),
				'prop' => 'info',
				'inprop' => 'url',
				'formatversion' => '2',
			);
			$resp = $this->makeAPIRequest( $params );
			if ( $resp === false ) {
				// Don't cache upon failure
				return '';
			}
			$data = $resp['query']['pages'][0]['canonicalurl'];
			// Don't set an expiry since we expect people not to change the
			// url to their wiki without clearing their caches!
			$this->cache->set( $key, $data );
		}

		return $data;
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param Title $title
	 * @param string $langCode
	 * @return array
	 */
	protected function parseWikiText( Title $title, $langCode ) {
		$unLocalizedName = MWNamespace::getCanonicalName( NS_USER ) . ':' . $title->getText();
		$wikitext = '{{:' . $unLocalizedName . '}}';
		$params = array(
			'action' => 'parse',
			'title' => $unLocalizedName,
			'text' => $wikitext,
			'disableeditsection' => 1,
			'disablepp' => 1,
			'uselang' => $langCode,
			'prop' => 'text|modules'
		);
		$data = $this->makeAPIRequest( $params );
		return $data !== false ? $data['parse'] : false;
	}

	/**
	 * @return array
	 */
	public static function getEnabledWikis() {
		static $list = null;
		if ( $list === null ) {
			$list = array();
			if ( Hooks::run( 'GlobalUserPageWikis', array( &$list ) ) ) {
				// Fallback if no hook override
				global $wgLocalDatabases;
				$list = $wgLocalDatabases;
			}
		}

		return $list;
	}
}
