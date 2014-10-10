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

	public function __construct( Title $title, Config $config ) {
		global $wgMemc;
		parent::__construct( $title );
		$this->config = $config;
		$this->cache = $wgMemc;
	}

	public function showMissingArticle() {
		$title = $this->getTitle();

		if ( !self::displayGlobalPage( $title ) ) {
			parent::showMissingArticle();
			return;
		}

		$user = User::newFromName( $this->getUsername() );

		$out = $this->getContext()->getOutput();
		$parsedOutput = $this->getRemoteParsedText( self::getCentralTouched( $user ) );

		// If the user page is empty, don't use it
		if ( !trim( $parsedOutput['text']['*'] ) ) {
			parent::showMissingArticle();
			return;
		}
		$out->addHTML( $parsedOutput['text']['*'] );
		$out->addModuleStyles( array( 'ext.GlobalUserPage', 'ext.GlobalUserPage.site' ) );

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
			'modulemessages' => 'addModuleMessages',
		);
		foreach ( $map as $type => $func ) {
			foreach ( $parsedOutput[$type] as $module ) {
				if ( $rl->getModule( $module ) !== null ) {
					$out->$func( $module );
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
	 * Given a Title, assuming it doesn't exist, should
	 * we display a global user page on it
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function displayGlobalPage( Title $title ) {
		global $wgGlobalUserPageDBname;
		static $cache = array();
		$text = $title->getPrefixedText();
		// Do some instance caching since this can be
		// called frequently due do the Linker hook
		if ( isset( $cache[$text] ) ) {
			return $cache[$text];
		}
		if ( !self::canBeGlobal( $title ) ) {
			$cache[$text] = false;
			return false;
		}

		$user = User::newFromName( $title->getText() );

		if ( !$user || $user->getId() === 0 ) {
			$cache[$text] = false;
			return false;
		}

		if ( !$user->getOption( 'globaluserpage' ) ) {
			$cache[$text] = false;
			return false;
		}

		// Allow for authorization extensions to determine
		// whether User:A@foowiki === User:A@centralwiki.
		// This hook intentionally functions the same
		// as the one in Extension:GlobalCssJs.
		if ( !wfRunHooks( 'LoadGlobalUserPage', array( $user, $wgGlobalUserPageDBname, wfWikiID() ) ) ) {
			$cache[$text] = false;
			return false;
		}

		$cache[$text] = (bool)self::getCentralTouched( $user );
		return $cache[$text];
	}

	/**
	 * Get the page_touched of the central user page
	 *
	 * @todo this probably shouldn't be static
	 * @param User $user
	 * @return string|bool
	 */
	protected static function getCentralTouched( User $user ) {
		static $cache = array();
		if ( !isset( $cache[$user->getName()] ) ) {
			$cache[$user->getName()] = self::getRemoteDB( DB_SLAVE )->selectField(
				'page',
				'page_touched',
				array(
					'page_namespace' => NS_USER,
					'page_title' => $user->getUserPage()->getDBkey()
				),
				__METHOD__
			);
		}

		return $cache[$user->getName()];
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
		$langCode = $this->getContext()->getConfig()->get( 'LanguageCode' );

		// Need language code in the key since we pass &uselang= to the API.
		$key = "globaluserpage:parsed:$touched:$langCode:{$this->getUsername()}";
		$data = $this->cache->get( $key );
		if ( $data === false ){
			$data = $this->parseWikiText( $this->getTitle(), $langCode );
			$this->cache->set( $key, $data, $this->config->get( 'GlobalUserPageCacheExpiry' ) );
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
	protected function makeAPIRequest( $params ) {
		$params['format'] = 'json';
		$url = wfAppendQuery( $this->config->get( 'GlobalUserPageAPIUrl' ), $params );
		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Returns a URL to the user page on the central wiki;
	 * if MW >= 1.24, this will be the cannonical url, otherwise
	 * it will be using whatever protocol was specified in
	 * $wgGlobalUserPageAPIUrl.
	 *
	 * @return string
	 */
	protected function getRemoteURL() {
		$key = 'globaluserpage:url:' . md5( $this->getUsername() );
		$data = $this->cache->get( $key );
		if ( $data === false ) {
			$params = array(
				'action' => 'query',
				'titles' => 'User:' . $this->getUsername(),
				'prop' => 'info',
				'inprop' => 'url',
				'indexpageids' => '1',
			);
			$resp = $this->makeAPIRequest( $params );
			$pageInfo = $resp['query']['pages'][$resp['query']['pageids'][0]];
			if ( isset( $pageInfo['canonicalurl'] ) ) {
				// New in 1.24
				$data = $pageInfo['canonicalurl'];
			} else {
				// This is dependent upon PROTO_CURRENT, which is hardcoded in config,
				// but it's good enough for back-compat
				$data = $pageInfo['fullurl'];
			}
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
		return $data['parse'];
	}
}
