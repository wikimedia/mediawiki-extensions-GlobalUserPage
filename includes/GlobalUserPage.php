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
use Config;
use Hooks as MWHooks;
use MapCacheLRU;
use MediaWiki\MediaWikiServices;
use OutputPage;
use ParserOutput;
use Title;
use User;
use WANObjectCache;
use WikiMap;
use Wikimedia\IPUtils;

/**
 * @property WikiGlobalUserPage $mPage Set by overwritten newPage() in this class
 */
class GlobalUserPage extends Article {

	/**
	 * Cache version of action=parse
	 * output
	 */
	private const PARSED_CACHE_VERSION = 4;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var WANObjectCache
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
		$this->config = $config;
		$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		parent::__construct( $title );
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
		if ( !$parsedOutput || !trim( $parsedOutput['text'] ) ) {
			parent::showMissingArticle();

			return;
		}
		$out->addHTML( $parsedOutput['text'] );
		$out->addModuleStyles( 'ext.GlobalUserPage' );

		// Set canonical URL to point to the source
		$sourceURL = $this->mPage->getSourceURL();
		$out->setCanonicalUrl( $sourceURL );
		$footerKey = $this->config->get( 'GlobalUserPageFooterKey' );
		if ( $footerKey ) {
			$out->addHTML( '<div class="mw-globaluserpage-footer plainlinks">' .
				"\n" . $out->msg( $footerKey )
					->params( $this->getUsername(), $sourceURL )->parse() .
				"\n</div>"
			);
		}

		// Load ParserOutput modules...
		$this->loadModules( $out, $parsedOutput );

		// Add indicators (T149286)
		$out->setIndicators( $parsedOutput['indicators'] );

		// Make sure we set the correct robot policy
		$policy = $this->getRobotPolicy( 'view' );
		$out->setIndexPolicy( $policy['index'] );
		$out->setFollowPolicy( $policy['follow'] );
	}

	/**
	 * Override robot policy to always set noindex (T177159)
	 *
	 * @param string $action
	 * @param ParserOutput|null $pOutput
	 * @return array
	 */
	public function getRobotPolicy( $action, ParserOutput $pOutput = null ) {
		$policy = parent::getRobotPolicy( $action, $pOutput );
		if ( self::shouldDisplayGlobalPage( $this->getTitle() ) ) {
			// Set noindex if this page is global
			$policy['index'] = 'noindex';
		}

		return $policy;
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
		foreach ( $parsedOutput['modules'] as $module ) {
			if ( $rl->isModuleRegistered( $module ) ) {
				$out->addModules( $module );
			}
		}
		foreach ( $parsedOutput['modulestyles'] as $module ) {
			if ( $rl->isModuleRegistered( $module ) ) {
				$out->addModuleStyles( $module );
			}
		}

		$out->addJsConfigVars( $parsedOutput['jsconfigvars'] );
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

		// Normalize the username
		$user = User::newFromName( $title->getText() );

		if ( !$user ) {
			self::$displayCache->set( $text, false );

			return false;
		}

		// Make sure that the username represents the same
		// user on both wikis.
		$lookup = MediaWikiServices::getInstance()->getCentralIdLookupFactory()->getLookup();
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
		$factory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$mainLB = $factory->getMainLB( $wgGlobalUserPageDBname );

		$dbr = $mainLB->getConnectionRef( DB_REPLICA, [], $wgGlobalUserPageDBname );
		$row = $dbr->selectRow(
			[ 'page', 'page_props' ],
			[ 'page_touched', 'pp_propname' ],
			[
				'page_namespace' => NS_USER,
				'page_title' => $user->getUserPage()->getDBkey(),
			],
			__METHOD__,
			[],
			[ 'page_props' =>
				[ 'LEFT JOIN', [ 'page_id=pp_page', 'pp_propname' => 'noglobal' ] ],
			]
		);
		if ( $row ) {
			if ( $row->pp_propname == 'noglobal' ) {
				$touched = false;
			} else {
				$touched = $row->page_touched;
			}
		} else {
			$touched = false;
		}

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
		if ( WikiMap::getCurrentWikiId() !== $this->config->get( 'GlobalUserPageDBname' ) ) {
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
		return $this->mPage->getUsername();
	}

	/**
	 * We trust that the remote wiki has done proper HTML escaping and isn't
	 * crazy by having raw HTML enabled.
	 *
	 * @param string $touched The page_touched for the page
	 * @return array|bool
	 * @return-taint escaped
	 */
	public function getRemoteParsedText( $touched ) {
		$langCode = $this->getContext()->getLanguage()->getCode();

		$cache = $this->cache;

		return $cache->getWithSetCallback(
			// Need language code in the key since we pass &uselang= to the API
			$cache->makeGlobalKey(
				'globaluserpage-parsed',
				$touched,
				$langCode,
				md5( $this->getUsername() )
			),
			$this->config->get( 'GlobalUserPageCacheExpiry' ),
			function ( $oldValue, &$ttl ) use ( $langCode, $cache ) {
				$data = $this->parseWikiText( $this->getTitle(), $langCode );
				if ( !$data ) {
					// Cache failure for 10 seconds
					$ttl = $cache::TTL_UNCACHEABLE;
				}

				return $data;
			},
			[ 'version' => self::PARSED_CACHE_VERSION ]
		);
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
		if ( WikiMap::getCurrentWikiId() === $wgGlobalUserPageDBname ) {
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
		if ( !MediaWikiServices::getInstance()->getUserNameUtils()->isValid( $title->getText() ) ) {
			return false;
		}

		// IPs don't get global userpages
		return !IPUtils::isIPAddress( $title->getText() );
	}

	/**
	 * @param Title $title
	 * @return WikiGlobalUserPage
	 */
	public function newPage( Title $title ) {
		return new WikiGlobalUserPage( $title, $this->config );
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param Title $title
	 * @param string $langCode
	 * @return array|bool
	 */
	protected function parseWikiText( Title $title, $langCode ) {
		$unLocalizedName = MediaWikiServices::getInstance()
			->getNamespaceInfo()
			->getCanonicalName( NS_USER ) . ':' . $title->getText();
		$wikitext = '{{:' . $unLocalizedName . '}}';
		$params = [
			'action' => 'parse',
			'title' => $unLocalizedName,
			'text' => $wikitext,
			'disableeditsection' => 1,
			'disablelimitreport' => 1,
			'uselang' => $langCode,
			'prop' => 'text|modules|jsconfigvars|indicators',
			'formatversion' => 2,
		];
		$data = $this->mPage->makeAPIRequest( $params );

		return $data !== false ? $data['parse'] : false;
	}

	/**
	 * @return array
	 */
	public static function getEnabledWikis() {
		static $list = null;
		if ( $list === null ) {
			$list = [];
			if ( MWHooks::run( 'GlobalUserPageWikis', [ &$list ] ) ) {
				// Fallback if no hook override
				global $wgLocalDatabases;
				$list = $wgLocalDatabases;
			}
		}

		return $list;
	}
}
