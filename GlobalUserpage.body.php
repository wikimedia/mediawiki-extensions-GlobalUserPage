<?php

class GlobalUserpage {

	static $apiURL = 'http://www.shoutwiki.com/w/api.php';

	/**
	 * Makes an API request to ShoutWiki Hub
	 *
	 * @param $params array
	 * @return array
	 */
	public static function makeAPIRequest( $params ) {
		$params['format'] = 'json';
		$url = wfAppendQuery( self::$apiURL, $params );
		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Get the cache key for a certain title
	 *
	 * @param Title|string $title
	 * @return string
	 */
	public static function getCacheKey( $title ) {
		global $wgLanguageCode;
		return wfMemcKey( 'globaluserpage', $wgLanguageCode, md5( $title ), 'v2' );
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param $title string
	 * @return array
	 */
	public static function parseWikiText( $title ) {
		$params = array(
			'action' => 'parse',
			'page' => $title
		);
		$data = self::makeAPIRequest( $params );
		$parsed = $data['parse']['text']['*'];
		$oldId = $data['parse']['revid'];
		return array( $parsed, $oldId );
	}

	/**
	 * Get the page text in the content language or a fallback
	 *
	 * @param $title string page name
	 * @return string|bool false if couldn't be found
	 */
	public static function getPagePlusFallbacks( $title ) {
		global $wgLanguageCode, $wgMemc, $wgGlobalUserpageCacheExpiry;

		$key = self::getCacheKey( $title );
		$cached = $wgMemc->get( $key );
		if ( $cached !== false ) {
			return $cached;
		}

		$fallbacks = Language::getFallbacksFor( $wgLanguageCode );
		array_unshift( $fallbacks, $wgLanguageCode );
		$titles = array();

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
				$html = self::parseWikiText( $pages[$langCode] );
				$wgMemc->set( $key, $html, $wgGlobalUserpageCacheExpiry );
				return $html;
			}
		}

		return false;
	}

}