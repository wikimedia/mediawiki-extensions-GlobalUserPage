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
use BagOStuff;
use CentralIdLookup;
use Config;
use ExtensionRegistry;
use Hooks as MWHooks;
use MapCacheLRU;
use MediaWiki\MediaWikiServices;
use MWNamespace;
use OutputPage;
use Title;
use User;

class GlobalUserPage extends Article {

	/**
	 * Cache version of action=parse
	 * output
	 */
	const PARSED_CACHE_VERSION = 2;

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
		$this->config = $config;
		$this->cache = $wgMemc;
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
		$map = [
			'modules' => 'addModules',
			'modulestyles' => 'addModuleStyles',
			'modulescripts' => 'addModuleScripts',
		];
		foreach ( $map as $type => $func ) {
			foreach ( $parsedOutput[$type] as $module ) {
				if ( $rl->isModuleRegistered( $module ) ) {
					$out->$func( $module );
				}
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

		$user = User::newFromName( $title->getText() );
		$user->load( User::READ_NORMAL );

		// Already validated that the username is fine in canBeGlobal
		if ( $user->getId() === 0 ) {
			self::$displayCache->set( $text, false );

			return false;
		}

		// Only check preferences if E:GlobalPreferences is installed
		if ( ExtensionRegistry::getInstance()->isLoaded( 'GlobalPreferences' ) ) {
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
		return $this->mPage->getUsername();
	}

	/**
	 * @param string $touched The page_touched for the page
	 * @return array
	 */
	public function getRemoteParsedText( $touched ) {
		$langCode = $this->getContext()->getLanguage()->getCode();

		// Need language code in the key since we pass &uselang= to the API.
		$key = $this->cache->makeGlobalKey( 'globaluserpage', 'parsed',
			self::PARSED_CACHE_VERSION, $touched, $langCode, md5( $this->getUsername() )
		);
		$data = $this->cache->get( $key );
		if ( $data === false ) {
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
		$unLocalizedName = MWNamespace::getCanonicalName( NS_USER ) . ':' . $title->getText();
		$wikitext = '{{:' . $unLocalizedName . '}}';
		$params = [
			'action' => 'parse',
			'title' => $unLocalizedName,
			'text' => $wikitext,
			'disableeditsection' => 1,
			'disablelimitreport' => 1,
			'uselang' => $langCode,
			'prop' => 'text|modules|jsconfigvars',
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
