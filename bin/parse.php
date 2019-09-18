<?php

/**
 * At present, this script is just used for testing the library and uses a
 * public MediaWiki API, which means it's expected to be slow.
 */

require_once __DIR__ . '/../tools/Maintenance.php';

use Parsoid\PageBundle;
use Parsoid\Parsoid;
use Parsoid\SelserData;

use Parsoid\Config\Api\ApiHelper;
use Parsoid\Config\Api\DataAccess;
use Parsoid\Config\Api\PageConfig;
use Parsoid\Config\Api\SiteConfig;

// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.WrongCase
class Parse extends \Parsoid\Tools\Maintenance {
	use \Parsoid\Tools\ExtendedOptsProcessor;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Omnibus script to convert between wikitext and HTML, and roundtrip wikitext or HTML. "
			. "Supports a number of options pertaining to pointing at a specific wiki "
			. "or enabling various features during these transformations.\n\n"
			. "If no options are provided, --wt2html is enabled by default.\n"
			. "See --help for detailed usage help." );
		$this->addOption( 'wt2html', 'Wikitext -> HTML' );
		$this->addOption( 'html2wt', 'HTML -> Wikitext' );
		$this->addOption( 'wt2wt', 'Wikitext -> Wikitext' );
		$this->addOption( 'html2html', 'HTML -> HTML' );
		$this->addOption( 'body_only',
						 'Just return the body, without any normalizations as in --normalize' );
		$this->addOption( 'selser',
						 'Use the selective serializer to go from HTML to Wikitext.' );
		$this->addOption(
			'oldtext',
			'The old page text for a selective-serialization (see --selser)',
			false,
			true
		);
		$this->addOption( 'oldtextfile',
						 'File containing the old page text for a selective-serialization (see --selser)',
						 false, true );
		$this->addOption( 'oldhtmlfile',
						 'File containing the old HTML for a selective-serialization (see --selser)',
						 false, true );
		$this->addOption( 'inputfile', 'File containing input as an alternative to stdin', false, true );
		$this->addOption(
			'pageName',
			'The page name, returned for {{PAGENAME}}. If no input is given ' .
			'(ie. empty/stdin closed), it downloads and parses the page. ' .
			'This should be the actual title of the article (that is, not ' .
			'including any URL-encoding that might be necessary in wikitext).',
			false,
			true
		);
		$this->addOption(
			'scrubWikitext',
			'Apply wikitext scrubbing while serializing.  This is also used ' .
			'for a mode of normalization (--normalize) applied when parsing.'
		);
		$this->addOption(
			'wrapSections',
			// Override the default in Env since the wrappers are annoying in dev-mode
			'Output <section> tags (default false)'
		);
		$this->addOption(
			'rtTestMode',
			'Test in rt test mode (changes some parse & serialization strategies)'
		);
		$this->addOption(
			'linting',
			'Parse with linter enabled.'
		);
		$this->addOption(
			'addHTMLTemplateParameters',
			'Parse template parameters to HTML and add them to template data'
		);
		$this->addOption(
			'domain',
			'Which wiki to use; e.g. "en.wikipedia.org" for English wikipedia, ' .
			'"es.wikipedia.org" for Spanish, "mediawiki.org" for mediawiki.org',
			false,
			true
		);
		$this->addOption(
			'apiURL',
			'http path to remote API, e.g. http://en.wikipedia.org/w/api.php',
			false,
			true
		);
		$this->addOption(
			'flamegraph',
			"Produce a flamegraph of CPU usage. " .
			"Assumes existence of Excimer ( https://www.mediawiki.org/wiki/Excimer ). " .
			"Looks for /usr/local/bin/flamegraph.pl (Set FLAMEGRAPH_PATH env var " .
			"to use different path). Outputs to /tmp (Set FLAMEGRAPH_OUTDIR " .
			"env var to output elsewhere)."
		);
		$this->setAllowUnregisteredOptions( false );
	}

	/**
	 * @param array $configOpts
	 * @param array $parsoidOpts
	 * @param string|null $wt
	 * @return PageBundle
	 */
	public function wt2Html(
		array $configOpts, array $parsoidOpts, ?string $wt
	) {
		if ( $wt !== null ) {
			$configOpts["pageContent"] = $wt;
		}

		$api = new ApiHelper( $configOpts );

		$siteConfig = new SiteConfig( $api, $configOpts );
		$dataAccess = new DataAccess( $api, $configOpts );

		$parsoid = new Parsoid( $siteConfig, $dataAccess );

		$pageConfig = new PageConfig( $api, $configOpts );

		return $parsoid->wikitext2html( $pageConfig, $parsoidOpts );
	}

	/**
	 * @param array $configOpts
	 * @param array $parsoidOpts
	 * @param PageBundle $pb
	 * @param SelserData|null $selserData
	 * @return string
	 */
	public function html2Wt(
		array $configOpts, array $parsoidOpts, PageBundle $pb,
		?SelserData $selserData = null
	): string {
		$api = new ApiHelper( $configOpts );

		$siteConfig = new SiteConfig( $api, $configOpts );
		$dataAccess = new DataAccess( $api, $configOpts );

		$parsoid = new Parsoid( $siteConfig, $dataAccess );

		$pageConfig = new PageConfig( $api, $configOpts );

		return $parsoid->html2wikitext(
			$pageConfig, $pb, $parsoidOpts, $selserData
		);
	}

	public function execute() {
		$this->maybeHelp();

		// Produce a CPU flamegraph via excimer's profiling
		if ( $this->hasOption( 'flamegraph' ) ) {
			$profiler = new ExcimerProfiler;
			$profiler->setPeriod( 0.01 );
			$profiler->setEventType( EXCIMER_CPU );
			$profiler->start();
			register_shutdown_function( function () use ( $profiler ) {
				$profiler->stop();
				$fgPath = getenv( 'FLAMEGRAPH_PATH' );
				if ( empty( $fgPath ) ) {
					$fgPath = "/usr/local/bin/flamegraph.pl";
				}
				$fgOutDir = getenv( 'FLAMEGRAPH_OUTDIR' );
				if ( empty( $fgOutDir ) ) {
					$fgOutDir = "/tmp";
				}
				// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.popen
				$pipe = popen( "$fgPath > $fgOutDir/profile.svg", "w" );
				fwrite( $pipe, $profiler->getLog()->formatCollapsed() );
				$report = sprintf( "%-79s %14s %14s\n", 'Function', 'Self', 'Inclusive' );
				foreach ( $profiler->getLog()->aggregateByFunction() as $id => $info ) {
					$report .= sprintf( "%-79s %14d %14d\n", $id, $info['self'], $info['inclusive'] );
				}
				file_put_contents( "$fgOutDir/aggregated.txt", $report );
			} );
		}

		if ( $this->hasOption( 'inputfile' ) ) {
			$input = file_get_contents( $this->getOption( 'inputfile' ) );
			if ( $input === false ) {
				return;
			}
		} else {
			$input = file_get_contents( 'php://stdin' );
			if ( strlen( $input ) === 0 ) {
				// Parse page if no input
				if ( $this->hasOption( 'html2wt' ) || $this->hasOption( 'html2html' ) ) {
					$this->error(
						'Fetching page content is only supported when starting at wikitext.'
					);
					return;
				} else {
					$input = null;
				}
			}
		}

		$apiURL = "https://en.wikipedia.org/w/api.php";
		if ( $this->hasOption( 'domain' ) ) {
			$apiURL = "https://" . $this->getOption( 'domain' ) . "/w/api.php";
		}
		if ( $this->hasOption( 'apiURL' ) ) {
			$apiURL = $this->getOption( 'apiURL' );
		}
		$configOpts = [
			"apiEndpoint" => $apiURL,
			"title" => $this->hasOption( 'pageName' ) ?
				$this->getOption( 'pageName' ) : "Api",
			"rtTestMode" => $this->hasOption( 'rtTestMode' ),
			"addHTMLTemplateParameters" => $this->hasOption( 'addHTMLTemplateParameters' ),
			"linting" => $this->hasOption( 'linting' )
		];

		$parsoidOpts = [
			"scrubWikitext" => $this->hasOption( 'scrubWikitext' ),
			"body_only" => $this->hasOption( 'body_only' ),
			"wrapSections" => $this->hasOption( 'wrapSections' ),
		];

		$startsAtHtml = $this->hasOption( 'html2wt' ) ||
			$this->hasOption( 'html2html' ) ||
			$this->hasOption( 'selser' );

		if ( $startsAtHtml ) {
			if ( $this->hasOption( 'selser' ) ) {
				if ( $this->hasOption( 'oldtext' ) ) {
					$oldText = $this->getOption( 'oldtext' );
				} elseif ( $this->hasOption( 'oldtextfile' ) ) {
					$oldText = file_get_contents( $this->getOption( 'oldtextfile' ) );
					if ( $oldText === false ) {
						return;
					}
				} else {
					$this->error(
						'Please provide original wikitext ' .
						'(--oldtext or --oldtextfile). Selser requires that.'
					);
					$this->maybeHelp();
					return;
				}
				$oldHTML = null;
				if ( $this->hasOption( 'oldhtmlfile' ) ) {
					$oldHTML = file_get_contents( $this->getOption( 'oldhtmlfile' ) );
					if ( $oldHTML === false ) {
						return;
					}
				}
				$selserData = new SelserData( $oldText, $oldHTML );
			} else {
				$selserData = null;
			}
			$pb = new PageBundle( $input );
			$wt = $this->html2Wt( $configOpts, $parsoidOpts, $pb, $selserData );
			if ( $this->hasOption( 'html2html' ) ) {
				$pb = $this->wt2Html( $configOpts, $parsoidOpts, $wt );
				$this->output( $pb->html . "\n" );
			} else {
				$this->output( $wt );
			}
		} else {
			$pb = $this->wt2Html( $configOpts, $parsoidOpts, $input );
			if ( $this->hasOption( 'wt2wt' ) ) {
				$wt = $this->html2Wt( $configOpts, $parsoidOpts, $pb );
				$this->output( $wt );
			} else {
				$this->output( $pb->html . "\n" );
			}
		}
	}
}

$maintClass = Parse::class;
require_once PARSOID_RUN_MAINTENANCE_IF_MAIN;
