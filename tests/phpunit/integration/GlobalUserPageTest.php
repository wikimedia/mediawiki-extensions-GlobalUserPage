<?php
namespace MediaWiki\GlobalUserPage\Tests\Integration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Context\RequestContext;
use MediaWiki\GlobalUserPage\GlobalUserPage;
use MediaWiki\GlobalUserPage\GlobalUserPageManager;
use MediaWiki\GlobalUserPage\WikiGlobalUserPage;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\Article;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\GlobalUserPage\Hooks::onArticleFromTitle
 * @covers \MediaWiki\GlobalUserPage\Hooks::onWikiPageFactory
 * @covers \MediaWiki\GlobalUserPage\GlobalUserPage
 * @covers \MediaWiki\GlobalUserPage\WikiGlobalUserPage
 *
 * @group Database
 */
class GlobalUserPageTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	private const WIKI_DEFINED_IN_SITECONFIGURATION = 'wiki_in_siteconfiguration';
	private const FOREIGN_WIKI = 'some_foreign_wiki';

	private const USER_WITH_GLOBAL_USERPAGE = 'UserWithGlobalUserPage';

	protected function setUp(): void {
		parent::setUp();

		$conf = new SiteConfiguration();
		$conf->settings = [
			'wgServer' => [
				self::WIKI_DEFINED_IN_SITECONFIGURATION => 'https://localwiki.example.com'
			],
			'wgArticlePath' => [
				self::WIKI_DEFINED_IN_SITECONFIGURATION => '/wiki/$1',
			],
		];
		$conf->suffixes = [ self::WIKI_DEFINED_IN_SITECONFIGURATION ];
		$this->setMwGlobals( 'wgConf', $conf );
	}

	public function addDBDataOnce() {
		parent::addDBDataOnce();

		$this->editPage(
			new TitleValue( NS_USER, self::USER_WITH_GLOBAL_USERPAGE ),
			'Global user page content'
		);
	}

	/**
	 * @dataProvider provideNotRelevantTitles
	 *
	 * @param LinkTarget $title
	 * @return void
	 */
	public function testShouldNotInterceptNotRelevantTitles( LinkTarget $title ): void {
		$title = $this->getServiceContainer()
			->getTitleFactory()
			->newFromLinkTarget( $title );

		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$article = Article::newFromTitle( $title, $context );

		$this->assertNotInstanceOf( GlobalUserPage::class, $article );
		$this->assertNotInstanceOf( WikiGlobalUserPage::class, $article->getPage() );
	}

	public static function provideNotRelevantTitles(): iterable {
		yield 'not a userpage' => [
			new TitleValue( NS_MAIN, 'MainPage' ),
		];

		yield 'userpage that exists locally' => [
			// NB.: We use this page to simulate a global userpage in testShowMissingArticle(),
			// but since all pages live on the same wiki in tests, it's suitable for emulating
			// an existing local userpage as well, saving us one edit during setup.
			new TitleValue( NS_USER, self::USER_WITH_GLOBAL_USERPAGE )
		];
	}

	/**
	 * @dataProvider provideShowMissingArticle
	 */
	public function testShowMissingArticle(
		string $globalUserPageDBname,
		string $expectedCanonicalUrl
	): void {
		$this->overrideConfigValue( 'GlobalUserPageDBname', $globalUserPageDBname );

		$this->installMockHttp( [
			$this->makeFakeHttpRequest( json_encode( [
				'parse' => [
					'text' => '<div>Global user page HTML</div>',
					'modules' => [],
					'modulestyles' => [],
					'jsconfigvars' => [
						'wgFoo' => 'bar',
					],
					'indicators' => [],
				]
			] ) ),
			// If the wiki storing global user pages isn't defined in $wgConf locally,
			// we should fetch it from its API.
			$this->makeFakeHttpRequest( json_encode( [
				'query' => [
					'pages' => [
						[ 'canonicalurl' => 'https://wiki.example.com/wiki/User:UserWithGlobalUserPage' ]
					]
				],
			] ) )
		] );

		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->method( 'isAttached' )
			->willReturn( true );

		// Use the local DB connection for simulated connections to the wiki storing global user pages,
		// since tests do not support more than one wiki.
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->method( 'getReplicaDatabase' )
			->with( $globalUserPageDBname )
			->willReturn( $this->getDb() );

		$globalUserPageManager = new GlobalUserPageManager(
			$connectionProvider,
			$this->getServiceContainer()->getUserFactory(),
			$this->getServiceContainer()->getUserNameUtils(),
			$centralIdLookup,
			$this->getServiceContainer()->getTitleFormatter(),
			$this->getServiceContainer()->getTitleFactory(),
			new ServiceOptions( GlobalUserPageManager::CONSTRUCTOR_OPTIONS, [
				'GlobalUserPageDBname' => $globalUserPageDBname,
			] )
		);

		$this->setService( 'GlobalUserPage.GlobalUserPageManager', $globalUserPageManager );

		// Set up a stubbed userpage title that doesn't exist.
		// This is needed because the "global" userpage lives on the local wiki in tests,
		// since tests do not support more than one wiki.
		$title = $this->createMock( Title::class );
		$title->method( 'inNamespace' )
			->with( NS_USER )
			->willReturn( true );
		$title->method( 'canExist' )
			->willReturn( true );
		$title->method( 'exists' )
			->willReturn( false );
		$title->method( 'getText' )
			->willReturn( self::USER_WITH_GLOBAL_USERPAGE );
		$title->method( 'getRootText' )
			->willReturn( self::USER_WITH_GLOBAL_USERPAGE );
		$title->method( 'getContentModel' )
			->willReturn( CONTENT_MODEL_WIKITEXT );
		$title->method( 'getWikiId' )
			->willReturn( $title::LOCAL );

		$context = RequestContext::getMain();
		$context->setTitle( $title );

		$article = Article::newFromTitle( $title, $context );
		$article->showMissingArticle();

		$this->assertInstanceOf( GlobalUserPage::class, $article );
		$this->assertInstanceOf( WikiGlobalUserPage::class, $article->getPage() );

		$this->assertStringContainsString(
			'<div>Global user page HTML</div>',
			$context->getOutput()->getHTML()
		);
		$this->assertSame(
			$expectedCanonicalUrl,
			$context->getOutput()->getCanonicalUrl()
		);
		$this->assertSame(
			[ 'wgFoo' => 'bar' ],
			$context->getOutput()->getJsConfigVars()
		);
	}

	public static function provideShowMissingArticle(): iterable {
		yield 'global userpage wiki defined in local $wgConf' => [
			self::WIKI_DEFINED_IN_SITECONFIGURATION,
			'https://localwiki.example.com/wiki/User:UserWithGlobalUserPage',
		];

		yield 'foreign global userpage wiki' => [
			self::FOREIGN_WIKI,
			'https://wiki.example.com/wiki/User:UserWithGlobalUserPage',
		];
	}
}
