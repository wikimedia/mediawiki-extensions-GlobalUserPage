<?php

/**
 * Provides a ResourceLoaderModule to load MediaWiki:GlobalUserPage.css
 * from a central wiki on remote instances of the user page, and on
 * the central user page itself.
 *
 * Based off of ResourceLoaderGlobalSiteModule in Extension:GlobalCssJs
 */
class ResourceLoaderGlobalUserPageModule extends ResourceLoaderWikiModule {

	/**
	 * name of global wiki database
	 * @var string
	 */
	private $wiki;

	/**
	 * name of a ResourceLoader source pointing to the global wiki
	 * @var string
	 */
	private $source;

	/**
	 * Whether this module is enabled via configuration
	 * @var bool
	 */
	private $enabled = false;

	public function __construct( array $options ) {
		$this->wiki = $options['wiki'];
		$this->source = $options['source'];
		$this->enabled = $options['enabled'];
	}

	protected function getPages( ResourceLoaderContext $context ) {
		$config = $context->getResourceLoader()->getConfig();
		if ( $this->enabled && $config->get( 'UseSiteCss' ) ) {
			return array(
				'MediaWiki:GlobalUserPage.css' => array( 'type' => 'style' ),
			);
		} else {
			return array();
		}
	}

	/**
	 * @return string
	 */
	public function getSource() {
		return wfWikiID() === $this->wiki ? 'local' : $this->source;
	}

	/**
	 * @return DatabaseBase
	 */
	protected function getDB() {
		if ( $this->wiki === wfWikiID() ) {
			return wfGetDB( DB_SLAVE );
		} else {
			return wfGetDB( DB_SLAVE, array(), $this->wiki );
		}
	}

	public function getGroup() {
		return 'site';
	}
}
