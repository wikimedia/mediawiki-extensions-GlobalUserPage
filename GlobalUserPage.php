<?php
/**
 * GlobalUserpage extension
 * Fetches user pages from ShoutWiki Hub if they don't exist locally
 *
 * Based on the HelpPages extension by Kunal Mehta, which is also in the
 * public domain.
 *
 * @file
 * @ingroup Extensions
 * @version 0.4
 * @date 8 April 2014
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license Public domain
 * @todo The WantedPages::getQueryInfo hook -- would be lovely to remove the User:
 * pages of GlobalUserpage users from Special:WantedPages, but alas, it's not
 * that simple because there probably isn't an efficient way to check who is and
 * who isn't a GlobalUserpage user, so it's most likely "all or nothing".
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

/**
 * How long to cache the rendered HTML for
 *
 * Note that the extension should invalidate
 * the cache whenever the base page is touched,
 * but this is a safeguard against invalidation
 * issues.
 */
$wgGlobalUserPageCacheExpiry = 60 * 60 * 24 * 7; // One week

/**
 * API endpoint of the central wiki
 */
$wgGlobalUserPageAPIUrl = 'https://example.org/w/api.php';

/**
 * By default enables global userpage for all users
 * @see https://www.mediawiki.org/wiki/Manual:$wgDefaultUserOptions
 */
$wgDefaultUserOptions['globaluserpage'] = true;

/**
 * Database name of the central wiki
 */
$wgGlobalUserPageDBname = 'examplewiki';

/**
 * Optionally add a footer message to the
 * bottom of every global user page. Should
 * be set to the name of a message key, or
 * false if no footer is wanted.
 *
 * @var string|bool
 */
$wgGlobalUserPageFooterKey = 'globaluserpage-footer';

/**
 * The name of the ResourceLoaderSource referring
 * to the central wiki to load MediaWiki:GlobalUserPage.css
 * from. Setting this variable to false disables that
 * feature. This requires MediaWiki 1.24 to work properly.
 *
 * @var string|bool
 */
$wgGlobalUserPageCSSRLSourceName = false;

/**
 * Timeout for internal API requests. To use $wgHTTPTimeout,
 * set this to 'default'
 *
 * @var string|int
 */
$wgGlobalUserPageTimeout = 10;

// Extension credits that will show up on Special:Version
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'GlobalUserPage',
	'version' => '0.10.0',
	'author' => array( 'Kunal Mehta', 'Jack Phoenix' ),
	'url' => 'https://www.mediawiki.org/wiki/Extension:GlobalUserPage',
	'descriptionmsg' => 'globaluserpage-desc',
);

$wgAutoloadClasses['GlobalUserPage'] = __DIR__ . '/GlobalUserPage.body.php';
$wgAutoloadClasses['GlobalUserPageHooks'] = __DIR__ . '/GlobalUserPage.hooks.php';
$wgAutoloadClasses['ResourceLoaderGlobalUserPageModule'] = __DIR__ . '/ResourceLoaderGlobalUserPageModule.php';

// i18n
$wgMessagesDirs['GlobalUserPage'] = __DIR__ . '/i18n';
// extra message for the central wiki
$wgExtensionFunctions[] = 'efGlobalUserPage';
function efGlobalUserPage() {
	global $wgGlobalUserPageDBname, $wgGlobalUserPageCSSRLSourceName;
	global $wgMessagesDirs;
	if ( $wgGlobalUserPageDBname === wfWikiID() && $wgGlobalUserPageCSSRLSourceName ) {
		$wgMessagesDirs['GlobalUserPageCentral'] = __DIR__ . '/i18n-central';
	}
}


$wgConfigRegistry['globaluserpage'] = 'GlobalVarConfig::newInstance';

$wgHooks['GetPreferences'][] = 'GlobalUserPageHooks::onGetPreferences';
$wgHooks['SkinTemplateNavigation::Universal'][] = 'GlobalUserPageHooks::onSkinTemplateNavigationUniversal';
$wgHooks['LinkBegin'][] = 'GlobalUserPageHooks::brokenLink';
$wgHooks['ArticleFromTitle'][] = 'GlobalUserPageHooks::onArticleFromTitle';
$wgHooks['BeforePageDisplay'][] = 'GlobalUserPageHooks::onBeforePageDisplay';
$wgHooks['ResourceLoaderRegisterModules'][] = 'GlobalUserPageHooks::onResourceLoaderRegisterModules';

// Register the CSS as a module with ResourceLoader
$wgResourceModules['ext.GlobalUserPage'] = array(
	'styles' => 'ext.GlobalUserPage.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'GlobalUserPage',
);
