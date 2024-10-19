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

use MediaWiki\Config\Config;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\WANObjectCache;
use WikiPage;

class WikiGlobalUserPage extends WikiPage {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	public function __construct( Title $title, Config $config ) {
		parent::__construct( $title );
		$this->config = $config;
		$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
	}

	public function isLocal() {
		return $this->getTitle()->exists();
	}

	/**
	 * @return string
	 */
	public function getWikiDisplayName() {
		$url = $this->getSourceURL();

		return wfParseUrl( $url )['host'];
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
	 * Returns a URL to the user page on the central wiki,
	 * attempts to use SiteConfiguration if possible, else
	 * falls back to using an API request
	 *
	 * @return string
	 */
	public function getSourceURL() {
		$wiki = WikiMap::getWiki( $this->config->get( 'GlobalUserPageDBname' ) );
		if ( $wiki ) {
			return $wiki->getCanonicalUrl(
				'User:' . $this->getUsername()
			);
		}

		// Fallback to the API
		return $this->getRemoteURLFromAPI();
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
		$cache = $this->cache;

		return $cache->getWithSetCallback(
			$cache->makeGlobalKey( 'globaluserpage-url', sha1( $this->getUsername() ) ),
			$cache::TTL_MONTH,
			function ( $oldValue, &$ttl ) use ( $cache ) {
				$resp = $this->makeAPIRequest( [
					'action' => 'query',
					'titles' => 'User:' . $this->getUsername(),
					'prop' => 'info',
					'inprop' => 'url',
					'formatversion' => '2',
				] );

				if ( $resp === false ) {
					$ttl = $cache::TTL_UNCACHEABLE;

					return '';
				}

				return $resp['query']['pages'][0]['canonicalurl'];
			}
		);
	}

	/**
	 * Makes an API request to the central wiki
	 *
	 * @param array $params
	 * @return array|bool false if the request failed
	 */
	public function makeAPIRequest( $params ) {
		$params['format'] = 'json';
		$url = wfAppendQuery( $this->config->get( 'GlobalUserPageAPIUrl' ), $params );
		wfDebugLog( 'GlobalUserPage', "Making a request to $url" );
		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$url,
			[ 'timeout' => $this->config->get( 'GlobalUserPageTimeout' ) ],
			__METHOD__
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			wfDebugLog(
				'GlobalUserPage', __METHOD__ . ' Error: ' . Status::wrap( $status )->getWikitext()
			);

			return false;
		}
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );

		return $decoded;
	}
}
