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

use BagOStuff;
use Config;
use FormatJson;
use MWHttpRequest;
use Status;
use Title;
use WikiMap;
use WikiPage;

class WikiGlobalUserPage extends WikiPage {

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
		$key = 'globaluserpage:url:' . md5( $this->getUsername() );
		$data = $this->cache->get( $key );
		if ( $data === false ) {
			$params = [
				'action' => 'query',
				'titles' => 'User:' . $this->getUsername(),
				'prop' => 'info',
				'inprop' => 'url',
				'formatversion' => '2',
			];
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
	 * Makes an API request to the central wiki
	 *
	 * @param array $params
	 * @return array|bool false if the request failed
	 */
	public function makeAPIRequest( $params ) {
		$params['format'] = 'json';
		$url = wfAppendQuery( $this->config->get( 'GlobalUserPageAPIUrl' ), $params );
		wfDebugLog( 'GlobalUserPage', "Making a request to $url" );
		$req = MWHttpRequest::factory(
			$url,
			[ 'timeout' => $this->config->get( 'GlobalUserPageTimeout' ) ]
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
