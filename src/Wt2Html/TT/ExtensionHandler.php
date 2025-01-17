<?php
declare( strict_types = 1 );

namespace Parsoid\Wt2Html\TT;

use DOMDocument;

use Parsoid\Config\ParsoidExtensionAPI;
use Parsoid\Tokens\Token;
use Parsoid\Utils\DOMCompat;
use Parsoid\Utils\DOMDataUtils;
use Parsoid\Utils\DOMUtils;
use Parsoid\Utils\PHPUtils;
use Parsoid\Utils\PipelineUtils;
use Parsoid\Utils\TokenUtils;
use Parsoid\Utils\Util;
use Parsoid\Wt2Html\TokenTransformManager;

use stdClass;

class ExtensionHandler extends TokenHandler {
	/**
	 * @param TokenTransformManager $manager
	 * @param array $options
	 */
	public function __construct( TokenTransformManager $manager, array $options ) {
		parent::__construct( $manager, $options );
	}

	/**
	 * Parse the extension HTML content and wrap it in a DOMFragment
	 * to be expanded back into the top-level DOM later.
	 */
	private function parseExtensionHTML( Token $extToken, DOMDocument $doc ): array {
		$logger = $this->env->getSiteConfig()->getLogger();
		$env = $this->env;

		if ( !empty( $env->dumpFlags['extoutput'] ) ) {
			$logger->warning( str_repeat( '=', 80 ) );
			$logger->warning( 'EXTENSION INPUT: ' . $extToken->getAttribute( 'source' ) );
			$logger->warning( str_repeat( '=', 80 ) );
			$logger->warning( "EXTENSION OUTPUT:\n" );
			$logger->warning( DOMCompat::getOuterHTML( DOMCompat::getBody( $doc ) ) );
			$logger->warning( str_repeat( '-', 80 ) );
		}

		// document -> html -> body -> children
		$state = [
			'token' => $extToken,
			'wrapperName' => $extToken->getAttribute( 'name' ),
			// We are always wrapping extensions with the DOMFragment mechanism.
			'wrappedObjectId' => $this->env->newObjectId(),
			'wrapperType' => 'mw:Extension/' . $extToken->getAttribute( 'name' ),
			'isHtmlExt' => ( $extToken->getAttribute( 'name' ) === 'html' )
		];

		// DOMFragment-based encapsulation.
		return $this->onDocument( $state, $doc );
	}

	private static function normalizeExtOptions( array $options ): array {
		// Mimics Sanitizer::decodeTagAttributes from the PHP parser
		//
		// Extension options should always be interpreted as plain text. The
		// tokenizer parses them to tokens in case they are for an HTML tag,
		// but here we use the text source instead.
		$n = count( $options );
		for ( $i = 0; $i < $n; $i++ ) {
			$o = $options[$i];
			if ( $o->v !== [] && !$o->v && !$o->vsrc ) {
				continue;
			}

			// Use the source if present. If not use the value, but ensure it's a
			// string, as it can be a token stream if the parser has recognized it
			// as a directive.
			$v = $o->vsrc ?? TokenUtils::tokensToString( $o->v, false, [ 'includeEntities' => true ] );
			// Normalize whitespace in extension attribute values
			// FIXME: If the option is parsed as wikitext, this normalization
			// can mess with src offsets.
			$o->v = trim( preg_replace( '/[\t\r\n ]+/', ' ', $v ) );
			// Decode character references
			$o->v = Util::decodeWtEntities( $o->v );
		}
		return $options;
	}

	private function mangleParserResponse( Token $token, array $ret ): string {
		$env = $this->env;
		$html = $ret['html'];

		// Strip a paragraph wrapper, if any
		$html = preg_replace( '#(^<p>)|(\n</p>$)#D', '', $html );

		// Add the modules to the page data
		$env->addOutputProperty( 'modules', $ret['modules'] );
		$env->addOutputProperty( 'modulescripts', $ret['modulescripts'] );
		$env->addOutputProperty( 'modulestyles', $ret['modulestyles'] );

		/*  - categories: (array) [ Category name => sortkey ] */
		// Add the categories which were added by extensions directly into the
		// page and not as in-text links
		if ( $ret['categories'] ) {
			foreach ( $ret['categories'] as $name => $sortkey ) {
				$dummyDoc = $env->createDocument( '' );
				$link = $dummyDoc->createElement( "link" );
				$link->setAttribute( "rel", "mw:PageProp/Category" );
				$href = $env->getSiteConfig()->relativeLinkPrefix() .
					"Category:" . PHPUtils::encodeURIComponent( (string)$name );
				if ( $sortkey ) {
					$href .= "#" . PHPUtils::encodeURIComponent( $sortkey );
				}
				$link->setAttribute( "href", $href );

				$html .= "\n" . DOMCompat::getOuterHTML( $link );
			}
		}

		return $html;
	}

	private function onExtension( $token ): array {
		$env = $this->env;
		$extensionName = $token->getAttribute( 'name' );
		$nativeExt = $env->getSiteConfig()->getNativeExtTagImpl( $extensionName );
		$cachedExpansion = $env->extensionCache[$token->dataAttribs->src] ?? null;

		$options = $token->getAttribute( 'options' );
		$token->setAttribute( 'options', self::normalizeExtOptions( $options ) );

		if ( $nativeExt !== null ) {
			$extContent = Util::extractExtBody( $token );
			$extArgs = $token->getAttribute( 'options' );
			$extApi = new ParsoidExtensionAPI( $env,
				$this->manager->getFrame(), $token, $this->options );
			$doc = $nativeExt->toDOM( $extApi, $extContent, $extArgs );
			if ( $doc !== false ) {
				if ( $doc !== null ) {
					$toks = $this->parseExtensionHTML( $token, $doc );
					return( [ 'tokens' => $toks ] );
				} else {
					// The extension dropped this instance completely (!!)
					// Should be a rarity and presumably the extension
					// knows what it is doing. Ex: nested refs are dropped
					// in some scenarios.
					return [ 'tokens' => [] ];
				}
			}
			// Fall through: this extension is electing not to use
			// a custom toDOM method (by returning false from toDOM).
		}

		if ( $cachedExpansion ) {
			// WARNING: THIS HAS BEEN UNUSED SINCE 2015, SEE T98995.
			// THIS CODE WAS WRITTEN BUT APPARENTLY NEVER TESTED.
			// NO WARRANTY.  MAY HALT AND CATCH ON FIRE.
			$toks = PipelineUtils::encapsulateExpansionHTML( $env, $token, $cachedExpansion, [
				'fromCache' => true
			] );
		} elseif ( $env->noDataAccess() ) {
			$doc = $this->env->createDocument(
				'<span>Fetches disabled. Cannot expand non-native extensions.</span>'
			);
			$toks = $this->parseExtensionHTML( $token, $doc );
		} else {
			$pageConfig = $env->getPageConfig();
			$ret = $env->getDataAccess()->parseWikitext( $pageConfig, $token->getAttribute( 'source' ) );
			$html = $this->mangleParserResponse( $token, $ret );
			$doc = $env->createDocument( $html );
			$toks = $this->parseExtensionHTML( $token, $doc );
		}
		return( [ 'tokens' => $toks ] );
	}

	private function onDocument( array $state, DOMDocument $doc ): array {
		$env = $this->env;

		$argDict = Util::getExtArgInfo( $state['token'] )->dict;
		$extTagOffsets = $state['token']->dataAttribs->extTagOffsets;
		if ( $extTagOffsets->closeWidth === 0 ) {
			unset( $argDict->body ); // Serialize to self-closing.
		}

		// Give native extensions a chance to manipulate the argDict
		$extensionName = $state['wrapperName'];
		$nativeExt = $env->getSiteConfig()->getNativeExtTagImpl( $extensionName );
		if ( $nativeExt ) {
			$extApi = new ParsoidExtensionAPI( $env );
			$nativeExt->modifyArgDict( $extApi, $argDict );
		}

		$opts = [
			'setDSR' => true, // FIXME: This is the only place that sets this ...
			'wrapperName' => $state['wrapperName'],
		];

		// Check if the tag wants its DOM fragment not to be unwrapped.
		// The default setting is to unwrap the content DOM fragment automatically.
		$extConfig = $env->getSiteConfig()->getNativeExtTagConfig( $extensionName );
		if ( isset( $extConfig['fragmentOptions'] ) ) {
			$opts += $extConfig['fragmentOptions'];
		}

		$body = DOMCompat::getBody( $doc );

		// This special case is only because, from the beginning, Parsoid has
		// treated <nowiki>s as core functionality with lean markup (no about,
		// no data-mw, custom typeof).
		//
		// We'll keep this hardcoded to avoid exposing the functionality to
		// other native extensions until it's needed.
		if ( $state['wrapperName'] !== 'nowiki' ) {
			if ( !$body->hasChildNodes() ) {
				// RT extensions expanding to nothing.
				$body->appendChild( $body->ownerDocument->createElement( 'link' ) );
			}

			// Wrap the top-level nodes so that we have a firstNode element
			// to annotate with the typeof and to apply about ids.
			PipelineUtils::addSpanWrappers( $body->childNodes );

			// Now get the firstNode
			$firstNode = $body->firstChild;

			DOMUtils::assertElt( $firstNode );

			// Adds the wrapper attributes to the first element
			$firstNode->setAttribute( 'typeof', $state['wrapperType'] );

			// Add about to all wrapper tokens.
			$about = $env->newAboutId();
			$n = $firstNode;
			while ( $n ) {
				$n->setAttribute( 'about', $about );
				$n = $n->nextSibling;
			}

			// Set data-mw
			DOMDataUtils::setDataMw( $firstNode, $argDict );

			// Update data-parsoid
			$dp = DOMDataUtils::getDataParsoid( $firstNode );
			$dp->tsr = Util::clone( $state['token']->dataAttribs->tsr );
			$dp->src = $state['token']->dataAttribs->src;
			DOMDataUtils::setDataParsoid( $firstNode, $dp );
		}

		$toks = PipelineUtils::tunnelDOMThroughTokens( $env, $state['token'], $body, $opts );

		if ( $state['isHtmlExt'] ) {
			$toks[0]->dataAttribs->tmp = $toks[0]->dataAttribs->tmp ?? new stdClass;
			$toks[0]->dataAttribs->tmp->isHtmlExt = true;
		}

		return $toks;
	}

	/**
	 * @inheritDoc
	 */
	public function onTag( Token $token ) {
		return $token->getName() === 'extension' ? $this->onExtension( $token ) : $token;
	}
}
