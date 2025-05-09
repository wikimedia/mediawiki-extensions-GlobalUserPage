<?php
namespace MediaWiki\GlobalUserPage\Tests\Integration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\GlobalUserPage\GlobalUserPageManager;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\GlobalUserPage\GlobalUserPageManager
 * @group Database
 */
class GlobalUserPageManagerTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	private const TEST_TIMESTAMP = '20230101000000';

	private const IP_WITH_GLOBAL_USERPAGE = '127.0.0.2';
	private const TEMP_ACCOUNT_WITH_GLOBAL_USERPAGE = '~2025-3';
	private const USER_WITH_GLOBAL_USERPAGE = 'UserWithGlobalUserPage';
	private const USER_WITH_DISABLED_GLOBAL_USERPAGE = 'UserWithDisabledGlobalUserPage';

	private IConnectionProvider $connectionProvider;
	private CentralIdLookup $centralIdLookup;

	protected function setUp(): void {
		parent::setUp();
		$this->enableAutoCreateTempUser( [
			'genPattern' => '~$1',
			'reservedPattern' => '~$1',
		] );

		$this->connectionProvider = $this->createMock( IConnectionProvider::class );
		$this->centralIdLookup = $this->createMock( CentralIdLookup::class );
	}

	public function addDBDataOnce(): void {
		try {
			// Ensure the page_touched timestamp is consistent.
			ConvertibleTimestamp::setFakeTime( self::TEST_TIMESTAMP );

			$this->editPage(
				new TitleValue( NS_USER, self::USER_WITH_GLOBAL_USERPAGE ),
				'Global user page content'
			);
			$this->editPage(
				new TitleValue( NS_USER, self::IP_WITH_GLOBAL_USERPAGE ),
				'Global user page content'
			);
			$this->editPage(
				new TitleValue( NS_USER, self::TEMP_ACCOUNT_WITH_GLOBAL_USERPAGE ),
				'Global user page content'
			);
			$this->editPage(
				new TitleValue( NS_USER, self::USER_WITH_DISABLED_GLOBAL_USERPAGE ),
				"__NOGLOBAL__\nGlobal user page content"
			);
		} finally {
			ConvertibleTimestamp::setFakeTime( false );
		}
	}

	private function getObjectUnderTest( string $globalUserPageDBname ): GlobalUserPageManager {
		return new GlobalUserPageManager(
			$this->connectionProvider,
			$this->getServiceContainer()->getUserFactory(),
			$this->getServiceContainer()->getUserNameUtils(),
			$this->centralIdLookup,
			new ServiceOptions( GlobalUserPageManager::CONSTRUCTOR_OPTIONS, [
				'GlobalUserPageDBname' => $globalUserPageDBname,
			] )
		);
	}

	/**
	 * @dataProvider provideShouldDisplayGlobalPage
	 *
	 * @param LinkTarget $title Title of the page to check
	 * @param string|null $globalUserPageDBname The wiki where global user pages are stored,
	 * or `null` to use the current wiki
	 * @param bool $userAttachedLocally Whether the user is attached on the local wiki
	 * @param bool $userAttachedOnGlobalWiki Whether the user is attached on the wiki
	 * where global user pages are stored.
	 * @param bool $expected
	 * @return void
	 */
	public function testShouldDisplayGlobalPage(
		LinkTarget $title,
		?string $globalUserPageDBname,
		bool $userAttachedLocally,
		bool $userAttachedOnGlobalWiki,
		bool $expected
	): void {
		$globalUserPageDBname ??= WikiMap::getCurrentWikiId();
		$globalUserPageManager = $this->getObjectUnderTest( $globalUserPageDBname );
		$localUser = new UserIdentityValue( 1, $title->getText() );

		$this->centralIdLookup->method( 'isAttached' )
			->willReturnCallback(
				function ( UserIdentity $user, $wikiId ) use (
					$localUser,
					$userAttachedLocally,
					$userAttachedOnGlobalWiki
				): bool {
					$this->assertTrue(
						$localUser->equals( $user ),
						'Incorrect user passed to isAttached()'
					);

					return $wikiId === $user::LOCAL ? $userAttachedLocally : $userAttachedOnGlobalWiki;
				}
			);

		$this->connectionProvider->method( 'getReplicaDatabase' )
			->with( $globalUserPageDBname )
			->willReturn( $this->getDb() );

		$shouldDisplay = $globalUserPageManager->shouldDisplayGlobalPage( $title );

		$this->assertSame( $expected, $shouldDisplay );
	}

	public static function provideShouldDisplayGlobalPage(): iterable {
		$validGlobalUserPage = new TitleValue( NS_USER, self::USER_WITH_GLOBAL_USERPAGE );

		yield 'local userpage on configured global wiki' => [
			$validGlobalUserPage,
			null,
			true,
			true,
			false
		];

		yield 'non-userpage' => [
			new TitleValue( NS_MAIN, self::USER_WITH_GLOBAL_USERPAGE ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'user subpage' => [
			new TitleValue( NS_USER, self::USER_WITH_GLOBAL_USERPAGE . '/Test' ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'IP userpage' => [
			new TitleValue( NS_USER, self::IP_WITH_GLOBAL_USERPAGE ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'user not attached locally' => [
			$validGlobalUserPage,
			'some_other_wiki',
			false,
			true,
			false
		];

		yield 'user not attached on global wiki' => [
			$validGlobalUserPage,
			'some_other_wiki',
			true,
			false,
			false
		];

		yield 'user with disabled global userpage' => [
			new TitleValue( NS_USER, self::USER_WITH_DISABLED_GLOBAL_USERPAGE ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'user with no global userpage' => [
			new TitleValue( NS_USER, 'OtherUser' ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'temporary account userpage' => [
			new TitleValue( NS_USER, self::TEMP_ACCOUNT_WITH_GLOBAL_USERPAGE ),
			'some_other_wiki',
			true,
			true,
			false
		];

		yield 'user with global userpage' => [
			$validGlobalUserPage,
			'some_other_wiki',
			true,
			true,
			true
		];
	}

	/**
	 * @dataProvider provideGetCentralTouched
	 *
	 * @param UserIdentity $user
	 * @param string|false $expected
	 * @return void
	 */
	public function testGetCentralTouched( UserIdentity $user, $expected ): void {
		$globalUserPageDBname = 'some_other_wiki';

		$this->connectionProvider->method( 'getReplicaDatabase' )
			->with( $globalUserPageDBname )
			->willReturn( $this->getDb() );

		$globalUserPageManager = $this->getObjectUnderTest( $globalUserPageDBname );

		$touched = $globalUserPageManager->getCentralTouched( $user );

		$this->assertSame( $expected, $touched );
	}

	public static function provideGetCentralTouched(): iterable {
		yield 'user with disabled global userpage' => [
			new UserIdentityValue( 1, self::USER_WITH_DISABLED_GLOBAL_USERPAGE ),
			false
		];

		yield 'user with no global userpage' => [
			new UserIdentityValue( 1, 'OtherUser' ),
			false
		];

		yield 'user with global userpage' => [
			new UserIdentityValue( 1, self::USER_WITH_GLOBAL_USERPAGE ),
			self::TEST_TIMESTAMP
		];
	}
}
