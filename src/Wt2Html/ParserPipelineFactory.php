<?php
declare( strict_types = 1 );

namespace Parsoid\Wt2Html;

use DOMDocument;
use Parsoid\Config\Env;
use Parsoid\InternalException;
use Parsoid\Utils\PHPUtils;
use Wikimedia\Assert\Assert;

/**
 * This class assembles parser pipelines from parser stages
 */
class ParserPipelineFactory {
	private static $globalPipelineId = 0;

	private static $stages = [
		"Tokenizer" => [
			"class" => PegTokenizer::class,
		],
		"TokenTransform1" => [
			"class" => TokenTransformManager::class,
			"transformers" => [
				 'OnlyInclude',
				 'IncludeOnly',
				 'NoInclude',
			],
		],
		"TokenTransform2" => [
			"class" => TokenTransformManager::class,
			"transformers" => [
				'TemplateHandler',
				'ExtensionHandler',

				// Expand attributes after templates to avoid expanding unused branches
				// No expansion of quotes, paragraphs etc in attributes, as in
				// PHP parser- up to text/x-mediawiki/expanded only.
				'AttributeExpander',

				// now all attributes expanded to tokens or string

				// more convenient after attribute expansion
				'WikiLinkHandler',
				'ExternalLinkHandler',
				'LanguageVariantHandler',

				// This converts dom-fragment-token tokens all the way to DOM
				// and wraps them in DOMFragment wrapper tokens which will then
				// get unpacked into the DOM by a dom-fragment unpacker.
				'DOMFragmentBuilder'
			],
		],
		"TokenTransform3" => [
			"class" => TokenTransformManager::class,
			"transformers" => [
				 'TokenStreamPatcher',
				// add <pre>s
				 'PreHandler',
				 'QuoteTransformer',
				// add before transforms that depend on behavior switches
				// examples: toc generation, edit sections
				 'BehaviorSwitchHandler',

				 'ListHandler',
				 'SanitizerHandler',
				// Wrap tokens into paragraphs post-sanitization so that
				// tags that converted to text by the sanitizer have a chance
				// of getting wrapped into paragraphs.  The sanitizer does not
				// require the existence of p-tags for its functioning.
				 'ParagraphWrapper'
			],
		],
		"TreeBuilder" => [
			// Build a tree out of the fully processed token stream
			"class" => HTML5TreeBuilder::class,
		],
		"DOMPP" => [
			 // Generic DOM transformer.
			 // This performs a lot of post-processing of the DOM
			 // (Template wrapping, broken wikitext/html detection, etc.)
			"class" => DOMPostProcessor::class,
		],
	];

	private static $pipelineRecipes = [
		// This pipeline takes wikitext as input and emits a fully
		// processed DOM as output. This is the pipeline used for
		// all top-level documents.
		// Stages 1-6 of the pipeline
		"text/x-mediawiki/full" => [
			"outType" => "DOM",
			"stages" => [
				"Tokenizer", "TokenTransform1", "TokenTransform2", "TokenTransform3", "TreeBuilder", "DOMPP"
			]
		],

		// This pipeline takes wikitext as input and emits
		// tokens that have had all templates and extensions processed.
		// Stages 1-3 of the pipeline
		"text/x-mediawiki" => [
			"outType" => "Tokens",
			"stages" => [ "Tokenizer", "TokenTransform1", "TokenTransform2"
			]
		],

		// This pipeline takes tokens from the PEG tokenizer and emits
		// tokens that have had all templates and extensions processed.
		// Stages 2-3 of the pipeline
		"tokens/x-mediawiki" => [
			"outType" => "Tokens",
			"stages" => [ "TokenTransform1", "TokenTransform2"
			]
		],

		// This pipeline takes tokens from stage 3 and emits a fully
		// processed DOM as output.
		// Stages 4-6 of the pipeline
		"tokens/x-mediawiki/expanded" => [
			"outType" => "DOM",
			"stages" => [ "TokenTransform3", "TreeBuilder", "DOMPP"
			]
		],
	];

	private static $supportedOptions = [
		// If true, templates found in content will have its contents expanded
		'expandTemplates',

		// If true, indicates pipeline is processing the expanded content of a
		// template or its arguments
		'inTemplate',

		// If true, indicates that we are in a <includeonly> context
		// (in current usage, isInclude === inTemplate)
		'isInclude',

		// The extension tag that is being processed (Ex: ref, references)
		// (in current usage, only used for native tag implementation)
		'extTag',

		// Extension-specific options
		'extTagOpts',

		// Content being parsed is used in an inline context
		'inlineContext',

		// FIXME: Related to PHP parser doBlockLevels side effect.
		// Primarily exists for backward compatibility reasons.
		// Might eventually go away in favor of something else.
		'inPHPBlock',

		// Are we processing content of attributes?
		// (in current usage, used for transcluded attr. keys/values)
		'attrExpansion'
	];

	private static $initialized = false;

	private static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$supportedOptions = array_flip( self::$supportedOptions );
	}

	/** @var array */
	private $pipelineCache;

	/** @var Env */
	private $env;

	/**
	 * @param Env $env
	 */
	public function __construct( Env $env ) {
		$this->pipelineCache = [];
		$this->env = $env;
		self::init();
	}

	// Default options processing
	private function defaultOptions( array $options ): array {
		if ( !$options ) {
			$options = [];
		}

		foreach ( $options as $k => $v ) {
			Assert::invariant( isset( self::$supportedOptions[$k] ), 'Invalid cacheKey option: ' . $k );
		}

		// default: not an include context
		if ( empty( $options['isInclude'] ) ) {
			$options['isInclude'] = false;
		}

		// default: wrap templates
		if ( empty( $options['expandTemplates'] ) ) {
			$options['expandTemplates'] = true;
		}

		return $options;
	}

	/**
	 * Generic pipeline creation from the above recipes.
	 * @param string $type
	 * @param array $options
	 * @return ParserPipeline
	 */
	private function makePipeline( string $type, string $cacheKey, array $options ): ParserPipeline {
		$options = $this->defaultOptions( $options );

		if ( !isset( self::$pipelineRecipes[$type] ) ) {
			throw new InternalException( 'Unsupported Pipeline: ' . $type );
		}
		$recipe = self::$pipelineRecipes[ $type ];
		$stages = [];
		$prevStage = null;
		$recipeStages = $recipe["stages"];
		for ( $i = 0,  $l = count( $recipeStages );  $i < $l;  $i++ ) {
			// create the stage
			$stageId = $recipeStages[$i];
			$stageData = $stages[$stageId];
			$stage = new $stageData["class"]( $this->env, $options, $this, $stageId, $prevStage );
			if ( isset( $stageData["transformers"] ) ) {
				foreach ( $stageData["transformers"] as $tName ) {
					$stage->addTransformer( new $tName( $stage, $options ) );
				}
			}

			$prevStage = $stage;
			$stages[] = $stage;
		}

		return new ParserPipeline(
			$type,
			$recipe["outType"],
			$cacheKey,
			$stages,
			$this->env
		);
	}

	/**
	 * @param string $cacheKey
	 * @param array $options
	 * @return string
	 */
	private function getCacheKey( string $cacheKey, array $options ): string {
		$cacheKey = $cacheKey ?? '';
		if ( empty( $options['isInclude'] ) ) {
			$cacheKey .= '::noInclude';
		}
		if ( empty( $options['expandTemplates'] ) ) {
			$cacheKey .= '::noExpand';
		}
		if ( !empty( $options['inlineContext'] ) ) {
			$cacheKey .= '::inlineContext';
		}
		if ( !empty( $options['inPHPBlock'] ) ) {
			$cacheKey .= '::inPHPBlock';
		}
		if ( !empty( $options['inTemplate'] ) ) {
			$cacheKey .= '::inTemplate';
		}
		if ( !empty( $options['attrExpansion'] ) ) {
			$cacheKey .= '::attrExpansion';
		}
		if ( isset( $options['extTag'] ) ) {
			$cacheKey .= '::' . $options['extTag'];
			// FIXME: This is not the best strategy. But, instead of
			// premature complexity, let us see how extensions want to
			// use this and then figure out what constraints are needed.
			if ( isset( $options['extTagOpts'] ) ) {
				$cacheKey .= '::' . PHPUtils::jsonEncode( $options['extTagOpts'] );
			}
		}
		return $cacheKey;
	}

	/**
	 * @param string $src
	 * @return DOMDocument
	 */
	public function parse( string $src ): DOMDocument {
		return $this->getPipeline( 'text/x-mediawiki/full' )->parseToplevelDoc( $src );
	}

	/**
	 * Get a subpipeline (not the top-level one) of a given type.
	 * Subpipelines are cached as they are frequently created.
	 *
	 * @param string $type
	 * @param array $options
	 * @return ParserPipeline
	 */
	public function getPipeline( string $type, array $options = [] ): ParserPipeline {
		$options = $this->defaultOptions( $options );

		$cacheKey = $this->getCacheKey( $type, $options );
		if ( empty( $this->pipelineCache[ $cacheKey ] ) ) {
			$this->pipelineCache[ $cacheKey ] = [];
		}

		$pipe = null;
		if ( count( $this->pipelineCache[ $cacheKey ] ) ) {
			$pipe = array_pop( $this->pipelineCache[ $cacheKey ] );
			$pipe->resetState();
		} else {
			$pipe = $this->makePipeline( $type, $cacheKey, $options );
		}

		// Debugging aid: Assign unique id to the pipeline
		$pipe->setPipelineId( self::$globalPipelineId++ );

		// Init the frame for this pipeline.
		// If this is a nested pipeline, the caller will
		// update the frame appropriately.
		// FIXME: Temporary till we refactor this frame bit to
		// deal with it in the pipeline's constructor
		$pipe->setFrame( null, null, [] );

		return $pipe;
	}

	/**
	 * Callback called by a pipeline at the end of its processing. Returns the
	 * pipeline to the cache.
	 *
	 * @param ParserPipeline $pipe
	 */
	public function returnPipeline( ParserPipeline $pipe ): void {
		$cacheKey = $pipe->getCacheKey();
		if ( empty( $this->pipelineCache[ $cacheKey ] ) ) {
			$this->pipelineCache[ $cacheKey ] = [];
		}
		if ( count( $this->pipelineCache[ $cacheKey ] ) < 100 ) {
			$this->pipelineCache[ $cacheKey ][] = $pipe;
		}
	}
}