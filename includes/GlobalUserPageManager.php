<?php

namespace MediaWiki\GlobalUserPage;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\GlobalUserPage\Hooks\HookRunner;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\MapCacheLRU;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

class GlobalUserPageManager {
	public const CONSTRUCTOR_OPTIONS = [
		'GlobalUserPageDBname',
		MainConfigNames::LocalDatabases,
	];

	private MapCacheLRU $displayCache;
	private MapCacheLRU $touchedCache;
	private readonly HookRunner $hookRunner;
	/** @var string[]|null */
	private ?array $enabledWikisList = null;
	private readonly bool $isCentralWiki;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly UserNameUtils $userNameUtils,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly ServiceOptions $options,
		HookContainer $hookContainer,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->hookRunner = new HookRunner( $hookContainer );

		// Do some instance caching since this can be
		// called frequently due do the Linker hook
		$this->displayCache = new MapCacheLRU( 100 );
		$this->touchedCache = new MapCacheLRU( 100 );

		$this->isCentralWiki = WikiMap::isCurrentWikiId( $options->get( 'GlobalUserPageDBname' ) );
	}

	/**
	 * Given a Title, assuming it doesn't exist, should
	 * we display a global user page on it
	 *
	 * @param LinkTarget $title
	 * @return bool
	 */
	public function shouldDisplayGlobalPage( LinkTarget $title ): bool {
		return $this->shouldDisplayGlobalPageBatched( [ $title ] )[0];
	}

	/**
	 * Batched variant of {@link shouldDisplayGlobalPage}, resolving many titles in a bounded
	 * number of queries (at most two CentralAuth attachment lookups and one central-touched query).
	 *
	 * @param iterable<LinkTarget> $titles
	 * @return bool[] One entry per input, preserving the input keys.
	 */
	public function shouldDisplayGlobalPageBatched( iterable $titles ): array {
		// Materialize so we can iterate more than once (input may be a generator).
		$titlesByKey = [];
		foreach ( $titles as $key => $title ) {
			$titlesByKey[$key] = $title;
		}

		$results = [];

		// Don't run this code for Hub.
		if ( $this->isCentralWiki ) {
			foreach ( $titlesByKey as $key => $title ) {
				$results[$key] = false;
			}
			return $results;
		}

		// Titles that still need a username -> attachment -> touched resolution, keyed the same
		// as the input. Value is the normalized username.
		$candidateUserNames = [];

		// First pass: apply the cheap cache and canBeGlobal checks, collecting the raw usernames
		// that still need resolving so we can look them all up in one query below.
		$namesToResolve = [];
		foreach ( $titlesByKey as $key => $title ) {
			$cacheKey = "{$title->getNamespace()}:{$title->getDBkey()}";
			if ( $this->displayCache->has( $cacheKey ) ) {
				$results[$key] = $this->displayCache->get( $cacheKey );
				continue;
			}

			if ( !$this->canBeGlobal( $title ) ) {
				$this->displayCache->set( $cacheKey, false );
				$results[$key] = false;
				continue;
			}

			$namesToResolve[$key] = $title->getText();
		}

		// Normalize all remaining usernames in a single query.
		foreach ( $this->getUserIdentities( $namesToResolve ) as $key => $user ) {
			if ( !$user ) {
				$title = $titlesByKey[$key];
				$this->displayCache->set( "{$title->getNamespace()}:{$title->getDBkey()}", false );
				$results[$key] = false;
				continue;
			}

			$candidateUserNames[$key] = $user->getName();
		}

		if ( !$candidateUserNames ) {
			return $results;
		}

		$uniqueNames = array_map(
			'strval',
			array_values( array_unique( $candidateUserNames ) )
		);

		// Make sure that the username represents the same user on both wikis.
		$seed = array_fill_keys( $uniqueNames, 0 );
		$attachedLocal = $this->centralIdLookup->lookupAttachedUserNames(
			$seed, CentralIdLookup::AUDIENCE_RAW );
		$attachedCentral = $this->centralIdLookup->lookupAttachedUserNames(
			$seed, CentralIdLookup::AUDIENCE_RAW, IDBAccessObject::READ_NORMAL,
			$this->options->get( 'GlobalUserPageDBname' ) );

		$attachedBoth = [];
		foreach ( $uniqueNames as $name ) {
			if ( $attachedLocal[$name] !== 0 && $attachedCentral[$name] !== 0 ) {
				$attachedBoth[] = $name;
			}
		}

		$touchedByName = $this->getCentralTouchedBatch( $attachedBoth );

		foreach ( $candidateUserNames as $key => $name ) {
			$display = isset( $touchedByName[$name] ) && (bool)$touchedByName[$name];
			$results[$key] = $display;

			$title = $titlesByKey[$key];
			$this->displayCache->set( "{$title->getNamespace()}:{$title->getDBkey()}", $display );
		}

		// Return in input order; the loops above populate non-candidates and candidates
		// in separate passes, so $results is not necessarily in the original order.
		$ordered = [];
		foreach ( array_keys( $titlesByKey ) as $key ) {
			$ordered[$key] = $results[$key];
		}
		return $ordered;
	}

	/**
	 * Get the page_touched timestamp of the central user page.
	 *
	 * @param UserIdentity $user
	 * @return string|false MediaWiki timestamp, or `false` if the page does not exist or is excluded via
	 * the __NOGLOBAL__ magic word.
	 */
	public function getCentralTouched( UserIdentity $user ) {
		return $this->getCentralTouchedBatch( [ $user->getName() ] )[$user->getName()];
	}

	/**
	 * Batched variant of {@link getCentralTouched}, resolving many usernames in one query.
	 *
	 * @param string[] $userNames
	 * @return array<string,string|false> Map of username to MediaWiki timestamp, or `false` if the
	 * page does not exist or is excluded via the __NOGLOBAL__ magic word.
	 */
	public function getCentralTouchedBatch( array $userNames ): array {
		$results = [];
		$dbKeyToName = [];

		foreach ( $userNames as $name ) {
			if ( $this->touchedCache->has( $name ) ) {
				$results[$name] = $this->touchedCache->get( $name );
				continue;
			}
			$dbKey = ( new TitleValue( NS_USER, $name ) )->getDBkey();
			$dbKeyToName[$dbKey] = $name;
		}

		if ( !$dbKeyToName ) {
			return $results;
		}

		$dbr = $this->connectionProvider->getReplicaDatabase( $this->options->get( 'GlobalUserPageDBname' ) );

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_title', 'page_touched', 'pp_propname' ] )
			->from( 'page' )
			->leftJoin( 'page_props', null, [ 'page_id=pp_page', 'pp_propname' => 'noglobal' ] )
			->where( [
				'page_namespace' => NS_USER,
				'page_title' => array_map( 'strval', array_keys( $dbKeyToName ) ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$touchedByDbKey = [];
		foreach ( $rows as $row ) {
			$touchedByDbKey[$row->page_title] = $row->pp_propname == 'noglobal' ? false : $row->page_touched;
		}

		foreach ( $dbKeyToName as $dbKey => $name ) {
			$touched = $touchedByDbKey[$dbKey] ?? false;
			$this->touchedCache->set( $name, $touched );
			$results[$name] = $touched;
		}

		return $results;
	}

	/**
	 * Whether a page is the global user page on the central wiki
	 */
	public function isGlobalPage( LinkTarget $title ): bool {
		// Global user page must be on the central wiki
		return $this->isCentralWiki
			// and match some more criteria
			&& $this->canBeGlobal( $title );
	}

	/**
	 * Checks whether the given page can be global
	 * doesn't check the actual database
	 * @param LinkTarget $title
	 * @return bool
	 */
	private function canBeGlobal( LinkTarget $title ): bool {
		return (
			// Must be a user page
			$title->inNamespace( NS_USER ) &&
			// Check valid username (also handles IP usernames and user subpages)
			$this->userNameUtils->isValid( $title->getText() ) &&
			// Temporary accounts cannot have global userpages (T326920).
			!$this->userNameUtils->isTemp( $title->getText() )
		);
	}

	public function getUserIdentity( string $userName ): ?UserIdentity {
		return $this->getUserIdentities( [ $userName ] )[0];
	}

	/**
	 * Batched variant of {@link getUserIdentity}, resolving many usernames in a single
	 * {@link UserIdentityLookup} query.
	 *
	 * @param string[] $userNames Raw user names (e.g. from {@link LinkTarget::getText()}).
	 * @return array<UserIdentity|null> One entry per input, preserving the input keys. The value is
	 * `null` for names that are not valid usernames.
	 */
	private function getUserIdentities( array $userNames ): array {
		// Canonicalize up front; invalid names resolve to null. UserIdentityLookup canonicalizes
		// internally too, but we need the canonical form both to map query rows back to inputs and
		// to build anonymous identities for users that don't exist locally.
		$canonicalByKey = [];
		foreach ( $userNames as $key => $userName ) {
			$canonical = $this->userNameUtils->getCanonical( $userName );
			$canonicalByKey[$key] = $canonical === false ? null : $canonical;
		}

		$uniqueNames = array_values( array_unique(
			array_filter( $canonicalByKey, static fn ( ?string $name ) => $name !== null )
		) );

		// Look up all locally-existing identities in a single query.
		$identitiesByName = [];
		if ( $uniqueNames ) {
			$identities = $this->userIdentityLookup->newSelectQueryBuilder()
				->whereUserNames( array_map( 'strval', $uniqueNames ) )
				->caller( __METHOD__ )
				->fetchUserIdentities();
			foreach ( $identities as $identity ) {
				$identitiesByName[$identity->getName()] = $identity;
			}
		}

		$results = [];
		foreach ( $canonicalByKey as $key => $canonical ) {
			if ( $canonical === null ) {
				$results[$key] = null;
			} else {
				// Fall back to an anonymous identity when the user does not exist locally.
				$results[$key] = $identitiesByName[$canonical]
					?? UserIdentityValue::newAnonymous( $canonical );
			}
		}
		return $results;
	}

	public function getEnabledWikis(): array {
		if ( $this->enabledWikisList === null ) {
			$list = [];
			if ( $this->hookRunner->onGlobalUserPageWikis( $list ) ) {
				// Fallback if no hook override
				$list = $this->options->get( MainConfigNames::LocalDatabases );
			}
			$this->enabledWikisList = $list;
		}

		return $this->enabledWikisList;
	}
}
