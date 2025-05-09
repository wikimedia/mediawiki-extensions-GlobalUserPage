<?php

namespace MediaWiki\GlobalUserPage;

use MapCacheLRU;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IConnectionProvider;

class GlobalUserPageManager {
	public const CONSTRUCTOR_OPTIONS = [ 'GlobalUserPageDBname' ];

	private IConnectionProvider $connectionProvider;
	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;
	private CentralIdLookup $centralIdLookup;
	private TitleFormatter $titleFormatter;
	private TitleFactory $titleFactory;
	private ServiceOptions $options;

	private MapCacheLRU $displayCache;
	private MapCacheLRU $touchedCache;

	public function __construct(
		IConnectionProvider $connectionProvider,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils,
		CentralIdLookup $centralIdLookup,
		TitleFormatter $titleFormatter,
		TitleFactory $titleFactory,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->connectionProvider = $connectionProvider;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
		$this->centralIdLookup = $centralIdLookup;
		$this->titleFormatter = $titleFormatter;
		$this->titleFactory = $titleFactory;
		$this->options = $options;

		// Do some instance caching since this can be
		// called frequently due do the Linker hook
		$this->displayCache = new MapCacheLRU( 100 );
		$this->touchedCache = new MapCacheLRU( 100 );
	}

	/**
	 * Given a Title, assuming it doesn't exist, should
	 * we display a global user page on it
	 *
	 * @param LinkTarget $title
	 * @return bool
	 */
	public function shouldDisplayGlobalPage( LinkTarget $title ): bool {
		if ( !$this->canBeGlobal( $title ) ) {
			return false;
		}

		$text = $this->titleFormatter->getPrefixedText( $title );
		if ( $this->displayCache->has( $text ) ) {
			return $this->displayCache->get( $text );
		}

		// Normalize the username
		$user = $this->userFactory->newFromName( $title->getText() );

		if ( !$user ) {
			$this->displayCache->set( $text, false );

			return false;
		}

		// Make sure that the username represents the same
		// user on both wikis.
		if (
			!$this->centralIdLookup->isAttached( $user ) ||
			!$this->centralIdLookup->isAttached( $user, $this->options->get( 'GlobalUserPageDBname' ) )
		) {
			$this->displayCache->set( $text, false );

			return false;
		}

		$touched = (bool)$this->getCentralTouched( $user );
		$this->displayCache->set( $text, $touched );

		return $touched;
	}

	/**
	 * Get the page_touched timestamp of the central user page.
	 *
	 * @param UserIdentity $user
	 * @return string|false MediaWiki timestamp, or `false` if the page does not exist or is excluded via
	 * the __NOGLOBAL__ magic word.
	 */
	public function getCentralTouched( UserIdentity $user ) {
		if ( $this->touchedCache->has( $user->getName() ) ) {
			return $this->touchedCache->get( $user->getName() );
		}

		$dbr = $this->connectionProvider->getReplicaDatabase( $this->options->get( 'GlobalUserPageDBname' ) );

		$userPage = new TitleValue( NS_USER, $user->getName() );

		$row = $dbr->newSelectQueryBuilder()
			->select( [ 'page_touched', 'pp_propname' ] )
			->from( 'page' )
			->leftJoin( 'page_props', null, [ 'page_id=pp_page', 'pp_propname' => 'noglobal' ] )
			->where( [
				'page_namespace' => NS_USER,
				'page_title' => $userPage->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( $row ) {
			if ( $row->pp_propname == 'noglobal' ) {
				$touched = false;
			} else {
				$touched = $row->page_touched;
			}
		} else {
			$touched = false;
		}

		$this->touchedCache->set( $user->getName(), $touched );

		return $touched;
	}

	/**
	 * Checks whether the given page can be global
	 * doesn't check the actual database
	 * @param LinkTarget $title
	 * @return bool
	 */
	private function canBeGlobal( LinkTarget $title ): bool {
		// Don't run this code for Hub.
		if ( WikiMap::getCurrentWikiId() === $this->options->get( 'GlobalUserPageDBname' ) ) {
			return false;
		}

		// Must be a user page
		if ( !$title->inNamespace( NS_USER ) ) {
			return false;
		}

		$title = $this->titleFactory->newFromLinkTarget( $title );
		// Check it's a root user page
		if ( $title->getRootText() !== $title->getText() ) {
			return false;
		}

		// Check valid username
		if ( !$this->userNameUtils->isValid( $title->getText() ) ) {
			return false;
		}

		// IPs don't get global userpages
		return !IPUtils::isIPAddress( $title->getText() );
	}
}
