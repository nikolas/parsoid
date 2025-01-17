<?php

namespace Parsoid\Language;

use DOMDocumentFragment;
use DOMElement;
use DOMNode;
use Parsoid\Config\Env;
use Parsoid\Utils\DOMCompat;
use Parsoid\Utils\DOMDataUtils;
use Parsoid\Utils\DOMTraverser;
use Parsoid\Utils\DOMUtils;
use Parsoid\Utils\Util;
use Wikimedia\Assert\Assert;
use Wikimedia\LangConv\ReplacementMachine;

class ConversionTraverser extends DOMTraverser {

	/** @var string */
	private $toLang;

	/** @var string */
	private $fromLang;

	/** @var LanguageGuesser */
	private $guesser;

	/** @var ReplacementMachine */
	private $machine;

	/**
	 * ConversionTraverser constructor.
	 * @param string $toLang target language for conversion
	 * @param LanguageGuesser $guesser oracle to determine "original language" for round-tripping
	 * @param ReplacementMachine $machine machine to do actual conversion
	 */
	public function __construct( $toLang, LanguageGuesser $guesser, ReplacementMachine $machine ) {
		parent::__construct();
		$this->toLang = $toLang;
		$this->guesser = $guesser;
		$this->machine = $machine;

		// No conversion inside <code>, <script>, <pre>, <cite>
		// (See adhoc regexps inside LanguageConverter.php::autoConvert)
		// XXX: <cite> ought to probably be handled more generically
		// as extension output, not special-cased as a HTML tag.
		foreach ( [ 'code', 'script', 'pre', 'cite' ] as $el ) {
			$this->addHandler( $el, function ( ...$args ) {
				return $this->noConvertHandler( ...$args );
			} );
		}
		// Setting/saving the language context
		$this->addHandler( null, function ( ...$args ) {
			return $this->anyHandler( ...$args );
		} );
		$this->addHandler( 'p', function ( ...$args ) {
			return $this->langContextHandler( ...$args );
		} );
		$this->addHandler( 'body', function ( ...$args ) {
			return $this->langContextHandler( ...$args );
		} );
		// Converting #text, <a> nodes, and title/alt attributes
		$this->addHandler( '#text', function ( ...$args ) {
			return $this->textHandler( ...$args );
		} );
		$this->addHandler( 'a', function ( ...$args ) {
			return $this->aHandler( ...$args );
		} );
		$this->addHandler( null, function ( ...$args ) {
			return $this->attrHandler( ...$args );
		} );
		// LanguageConverter markup
		foreach ( [ 'meta', 'div', 'span' ] as $el ) {
			$this->addHandler( $el, function ( ...$args ) {
				return $this->lcHandler( ...$args );
			} );
		}
	}

	private function noConvertHandler( DOMElement $el, $env, $atTopLevel, $tplInfo ) {
		// Don't touch the inside of this node!
		return $el->nextSibling;
	}

	private function anyHandler( DOMNode $node, $env, $atTopLevel, $tplInfo ) {
		/* Look for `lang` attributes */
		if ( DOMUtils::isElt( $node ) ) {
			DOMUtils::assertElt( $node );
			if ( $node->hasAttribute( 'lang' ) ) {
				$lang = $node->getAttribute( 'lang' );
				// XXX validate lang! override fromLang?
				// $this->>fromLang = $lang;
			}
		}
		return true; // Continue with other handlers
	}

	private function langContextHandler( DOMElement $el, $env, $atTopLevel, $tplInfo ) {
		$this->fromLang = $this->guesser->guessLang( $el );
		$el->setAttribute( 'data-mw-variant-lang', $this->fromLang );
		return true; // Continue with other handlers
	}

	private function textHandler( DOMNode $node, $env, $atTopLevel, $tplInfo ) {
		Assert::invariant( $this->fromLang !== null, 'Text w/o a context' );
		return $this->machine->replace( $node, $this->toLang, $this->fromLang );
	}

	private function aHandler( DOMElement $el, $env, $atTopLevel, $tplInfo ) {
		// Is this a wikilink?  If so, extract title & convert it
		$rel = $el->getAttribute( 'rel' ) ?? '';
		if ( $rel === 'mw:WikiLink' ) {
			$href = preg_replace( '#^(\.\.?/)+#', '', $el->getAttribute( 'href' ), 1 );
			$fromPage = Util::decodeURI( $href );
			$toPageFrag = $this->machine->convert(
				$el->ownerDocument, $fromPage, $this->toLang, $this->fromLang
			);
			$toPage = $this->docFragToString( $toPageFrag );
			if ( $toPage === null ) {
				// Non-reversible transform (sigh); mark this for rt.
				$el->setAttribute( 'data-mw-variant-orig', $fromPage );
				$toPage = $this->docFragToString( $toPageFrag, true/* force */ );
			}
			if ( $el->hasAttribute( 'title' ) ) {
				$el->setAttribute( 'title', preg_replace( '/_/', ' ', $toPage ) );
			}
			$el->setAttribute( 'href', "./{$toPage}" );
		} elseif ( $rel === 'mw:WikiLink/Interwiki' ) {
			// Don't convert title or children of interwiki links
			return $el->nextSibling;
		} elseif ( $rel === 'mw:ExtLink' ) {
			// WTUtils.usesURLLinkSyntax uses data-parsoid, so don't use it,
			// but syntactic free links should also have class="external free"
			if ( DOMCompat::getClassList( $el )->contains( 'free' ) ) {
				// Don't convert children of syntactic "free links"
				return $el->nextSibling;
			}
			// Other external link text is protected from conversion iff
			// (a) it doesn't starts/end with -{ ... }-
			if ( $el->firstChild &&
				DOMDataUtils::hasTypeOf( $el->firstChild, 'mw:LanguageVariant' ) ) {
				return true;
			}
			// (b) it looks like a URL (protocol-relative links excluded)
			$linkText = $el->textContent; // XXX: this could be expensive
			if ( Util::isProtocolValid( $linkText, $env )
				 && substr( $linkText, 0, 2 ) !== '//'
			) {
				return $el->nextSibling;
			}
		}
		return true;
	}

	private function attrHandler( DOMNode $node, Env $env, $atTopLevel, $tplInfo ) {
		// Convert `alt` and `title` attributes on elements
		// (Called before aHandler, so the `title` might get overwritten there)
		if ( !DOMUtils::isElt( $node ) ) {
			return true;
		}
		DOMUtils::assertElt( $node );
		foreach ( [ 'title', 'alt' ] as $attr ) {
			if ( !$node->hasAttribute( $attr ) ) {
				continue;
			}
			if ( $attr === 'title' && $node->getAttribute( 'rel' ) === 'mw:WikiLink' ) {
				// We've already converted the title in aHandler above.
				continue;
			}
			$orig = $node->getAttribute( $attr );
			if ( preg_match( '#://#', $orig ) ) {
				continue; /* Don't convert URLs */
			}
			$toFrag = $this->machine->convert(
				$node->ownerDocument, $orig, $this->toLang, $this->fromLang
			);
			$to = $this->docFragToString( $toFrag );
			if ( $to === null ) {
				// Non-reversible transform (sigh); mark for rt.
				$node->setAttribute( "data-mw-variant-{$attr}", $orig );
				$to = $this->docFragToString( $toFrag, true/* force */ );
			}
			$node->setAttribute( $attr, $to );
		}
		return true;
	}

	/** Handler for LanguageConverter markup */
	private function lcHandler( DOMElement $el, $env, $atTopLevel, $tplInfo ) {
		if ( !DOMDataUtils::hasTypeOf( $el, 'mw:LanguageVariant' ) ) {
			return true; /* not language converter markup */
		}
		$dmv = DOMDataUtils::getJSONAttribute( $el, 'data-mw-variant', [] );
		if ( isset( $dmv->disabled ) ) {
			DOMCompat::setInnerHTML( $el, $dmv->disabled->t );
			// XXX check handling of embedded data-parsoid
			// XXX check handling of nested constructs
			return $el->nextSibling;
		} elseif ( isset( $dmv->twoway ) ) {
			// FIXME
		} elseif ( isset( $dmv->oneway ) ) {
			// FIXME
		} elseif ( isset( $dmv->name ) ) {
			// FIXME
		} elseif ( isset( $dmv->filter ) ) {
			// FIXME
		} elseif ( isset( $dmv->describe ) ) {
			// FIXME
		}
		return true;
	}

	private function docFragToString( DOMDocumentFragment $docFrag, $force = false ) {
		if ( !$force ) {
			for ( $child = $docFrag->firstChild; $child; $child = $child->nextSibling ) {
				if ( !DOMUtils::isText( $child ) ) {
					return null; /* unsafe */
				}
			}
		}
		return $docFrag->textContent;
	}
}
