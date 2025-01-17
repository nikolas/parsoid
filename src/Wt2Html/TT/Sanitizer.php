<?php

/**
 * General token sanitizer. Strips out (or encapsulates) unsafe and disallowed
 * tag types and attributes. Should run last in the third, synchronous
 * expansion stage.
 *
 * FIXME: This code was originally ported from PHP to JS in 2012
 * and periodically updated before being back to PHP. This code should be
 * (a) resynced with core sanitizer changes (b) updated to use HTML5 spec
 */

namespace Parsoid\Wt2Html\TT;

use DOMElement;
use InvalidArgumentException;
use Parsoid\Config\Env;
use Parsoid\Config\WikitextConstants;
use Parsoid\Tokens\EndTagTk;
use Parsoid\Tokens\KV;
use Parsoid\Tokens\SelfclosingTagTk;
use Parsoid\Tokens\TagTk;
use Parsoid\Tokens\Token;
use Parsoid\Utils\PHPUtils;
use Parsoid\Utils\TokenUtils;
use Parsoid\Wt2Html\TokenTransformManager;
use Wikimedia\Assert\Assert;

class Sanitizer extends TokenHandler {
	/** @var bool */
	private $inTemplate;

	const NO_END_TAG_SET = [ 'br' => true ];

	/**
	 * RDFa and microdata properties allow URLs, URIs and/or CURIs.
	 */
	const MICRODATA = [
		'rel' => true,
		'rev' => true,
		'about' => true,
		'property' => true,
		'resource' => true,
		'datatype' => true,
		'typeof' => true, // RDFa
		'itemid' => true,
		'itemprop' => true,
		'itemref' => true,
		'itemscope' => true,
		'itemtype' => true,
	];

	const UTF8_REPLACEMENT = "ï¿½";

	/**
	 * Regular expression to match various types of character references in
	 * Sanitizer::normalizeCharReferences and Sanitizer::decodeCharReferences
	 */
	const CHAR_REFS_RE_G = "/&([A-Za-z0-9\x80-\xff]+);
		|&\#([0-9]+);
		|&\#[xX]([0-9A-Fa-f]+);
		|(&)/x";

	const INSECURE_RE = '! expression
		| filter\s*:
		| accelerator\s*:
		| -o-link\s*:
		| -o-link-source\s*:
		| -o-replace\s*:
		| url\s*\(
		| image\s*\(
		| image-set\s*\(
		| attr\s*\([^)]+[\s,]+url
	!ix';

	/**
	 * Blacklist for evil uris like javascript:
	 * WARNING: DO NOT use this in any place that actually requires blacklisting
	 * for security reasons. There are NUMEROUS[1] ways to bypass blacklisting, the
	 * only way to be secure from javascript: uri based xss vectors is to whitelist
	 * things that you know are safe and deny everything else.
	 * [1]: http://ha.ckers.org/xss.html
	 */
	const EVIL_URI_PATTERN = '!(^|\s|\*/\s*)(javascript|vbscript)([^\w]|$)!iD';
	const XMLNS_ATTRIBUTE_PATTERN = "/^xmlns:[:A-Z_a-z-.0-9]+$/D";

	/**
	 * Tells escapeUrlForHtml() to encode the ID using the wiki's primary encoding.
	 *
	 * @since 1.30
	 */
	const ID_PRIMARY = 0;

	/**
	 * Tells escapeUrlForHtml() to encode the ID using the fallback encoding, or return false
	 * if no fallback is configured.
	 *
	 * @since 1.30
	 */
	const ID_FALLBACK = 1;

	/** Characters that will be ignored in IDNs.
	 * https://tools.ietf.org/html/rfc3454#section-3.1
	 * Strip them before further processing so blacklists and such work.
	 * Part of Sanitizer::cleanUrl in core.
	 */
	const IDN_RE_G = "/
				\\s|          # general whitespace
				\xc2\xad|     # 00ad SOFT HYPHEN
				\xe1\xa0\x86| # 1806 MONGOLIAN TODO SOFT HYPHEN
				\xe2\x80\x8b| # 200b ZERO WIDTH SPACE
				\xe2\x81\xa0| # 2060 WORD JOINER
				\xef\xbb\xbf| # feff ZERO WIDTH NO-BREAK SPACE
				\xcd\x8f|     # 034f COMBINING GRAPHEME JOINER
				\xe1\xa0\x8b| # 180b MONGOLIAN FREE VARIATION SELECTOR ONE
				\xe1\xa0\x8c| # 180c MONGOLIAN FREE VARIATION SELECTOR TWO
				\xe1\xa0\x8d| # 180d MONGOLIAN FREE VARIATION SELECTOR THREE
				\xe2\x80\x8c| # 200c ZERO WIDTH NON-JOINER
				\xe2\x80\x8d| # 200d ZERO WIDTH JOINER
				[\xef\xb8\x80-\xef\xb8\x8f] # fe00-fe0f VARIATION SELECTOR-1-16
				/xuD";

	const GET_ATTRIBS_RE = '/^[:_\p{L}\p{N}][:_\.\-\p{L}\p{N}]*$/uD';

	/** Character entity aliases accepted by MediaWiki */
	const HTML_ENTITY_ALIASES = [
		"רלמ" => 'rlm',
		"رلم" => 'rlm'
	];

	/**
	 * FIXME: Might need a HTML5 update.
	 * List of all named character entities defined in HTML 4.01
	 * http://www.w3.org/TR/html4/sgml/entities.html
	 * As well as &apos; which is only defined starting in XHTML1.
	 */
	const HTML_ENTITIES = [
		'Aacute' => 193,
		'aacute' => 225,
		'Acirc' => 194,
		'acirc' => 226,
		'acute' => 180,
		'AElig' => 198,
		'aelig' => 230,
		'Agrave' => 192,
		'agrave' => 224,
		'alefsym' => 8501,
		'Alpha' => 913,
		'alpha' => 945,
		'amp' => 38,
		'and' => 8743,
		'ang' => 8736,
		'apos' => 39, // New in XHTML & HTML 5; avoid in output for compatibility with IE.
		'Aring' => 197,
		'aring' => 229,
		'asymp' => 8776,
		'Atilde' => 195,
		'atilde' => 227,
		'Auml' => 196,
		'auml' => 228,
		'bdquo' => 8222,
		'Beta' => 914,
		'beta' => 946,
		'brvbar' => 166,
		'bull' => 8226,
		'cap' => 8745,
		'Ccedil' => 199,
		'ccedil' => 231,
		'cedil' => 184,
		'cent' => 162,
		'Chi' => 935,
		'chi' => 967,
		'circ' => 710,
		'clubs' => 9827,
		'cong' => 8773,
		'copy' => 169,
		'crarr' => 8629,
		'cup' => 8746,
		'curren' => 164,
		'dagger' => 8224,
		'Dagger' => 8225,
		'darr' => 8595,
		'dArr' => 8659,
		'deg' => 176,
		'Delta' => 916,
		'delta' => 948,
		'diams' => 9830,
		'divide' => 247,
		'Eacute' => 201,
		'eacute' => 233,
		'Ecirc' => 202,
		'ecirc' => 234,
		'Egrave' => 200,
		'egrave' => 232,
		'empty' => 8709,
		'emsp' => 8195,
		'ensp' => 8194,
		'Epsilon' => 917,
		'epsilon' => 949,
		'equiv' => 8801,
		'Eta' => 919,
		'eta' => 951,
		'ETH' => 208,
		'eth' => 240,
		'Euml' => 203,
		'euml' => 235,
		'euro' => 8364,
		'exist' => 8707,
		'fnof' => 402,
		'forall' => 8704,
		'frac12' => 189,
		'frac14' => 188,
		'frac34' => 190,
		'frasl' => 8260,
		'Gamma' => 915,
		'gamma' => 947,
		'ge' => 8805,
		'gt' => 62,
		'harr' => 8596,
		'hArr' => 8660,
		'hearts' => 9829,
		'hellip' => 8230,
		'Iacute' => 205,
		'iacute' => 237,
		'Icirc' => 206,
		'icirc' => 238,
		'iexcl' => 161,
		'Igrave' => 204,
		'igrave' => 236,
		'image' => 8465,
		'infin' => 8734,
		'int' => 8747,
		'Iota' => 921,
		'iota' => 953,
		'iquest' => 191,
		'isin' => 8712,
		'Iuml' => 207,
		'iuml' => 239,
		'Kappa' => 922,
		'kappa' => 954,
		'Lambda' => 923,
		'lambda' => 955,
		'lang' => 9001,
		'laquo' => 171,
		'larr' => 8592,
		'lArr' => 8656,
		'lceil' => 8968,
		'ldquo' => 8220,
		'le' => 8804,
		'lfloor' => 8970,
		'lowast' => 8727,
		'loz' => 9674,
		'lrm' => 8206,
		'lsaquo' => 8249,
		'lsquo' => 8216,
		'lt' => 60,
		'macr' => 175,
		'mdash' => 8212,
		'micro' => 181,
		'middot' => 183,
		'minus' => 8722,
		'Mu' => 924,
		'mu' => 956,
		'nabla' => 8711,
		'nbsp' => 160,
		'ndash' => 8211,
		'ne' => 8800,
		'ni' => 8715,
		'not' => 172,
		'notin' => 8713,
		'nsub' => 8836,
		'Ntilde' => 209,
		'ntilde' => 241,
		'Nu' => 925,
		'nu' => 957,
		'Oacute' => 211,
		'oacute' => 243,
		'Ocirc' => 212,
		'ocirc' => 244,
		'OElig' => 338,
		'oelig' => 339,
		'Ograve' => 210,
		'ograve' => 242,
		'oline' => 8254,
		'Omega' => 937,
		'omega' => 969,
		'Omicron' => 927,
		'omicron' => 959,
		'oplus' => 8853,
		'or' => 8744,
		'ordf' => 170,
		'ordm' => 186,
		'Oslash' => 216,
		'oslash' => 248,
		'Otilde' => 213,
		'otilde' => 245,
		'otimes' => 8855,
		'Ouml' => 214,
		'ouml' => 246,
		'para' => 182,
		'part' => 8706,
		'permil' => 8240,
		'perp' => 8869,
		'Phi' => 934,
		'phi' => 966,
		'Pi' => 928,
		'pi' => 960,
		'piv' => 982,
		'plusmn' => 177,
		'pound' => 163,
		'prime' => 8242,
		'Prime' => 8243,
		'prod' => 8719,
		'prop' => 8733,
		'Psi' => 936,
		'psi' => 968,
		'quot' => 34,
		'radic' => 8730,
		'rang' => 9002,
		'raquo' => 187,
		'rarr' => 8594,
		'rArr' => 8658,
		'rceil' => 8969,
		'rdquo' => 8221,
		'real' => 8476,
		'reg' => 174,
		'rfloor' => 8971,
		'Rho' => 929,
		'rho' => 961,
		'rlm' => 8207,
		'rsaquo' => 8250,
		'rsquo' => 8217,
		'sbquo' => 8218,
		'Scaron' => 352,
		'scaron' => 353,
		'sdot' => 8901,
		'sect' => 167,
		'shy' => 173,
		'Sigma' => 931,
		'sigma' => 963,
		'sigmaf' => 962,
		'sim' => 8764,
		'spades' => 9824,
		'sub' => 8834,
		'sube' => 8838,
		'sum' => 8721,
		'sup' => 8835,
		'sup1' => 185,
		'sup2' => 178,
		'sup3' => 179,
		'supe' => 8839,
		'szlig' => 223,
		'Tau' => 932,
		'tau' => 964,
		'there4' => 8756,
		'Theta' => 920,
		'theta' => 952,
		'thetasym' => 977,
		'thinsp' => 8201,
		'THORN' => 222,
		'thorn' => 254,
		'tilde' => 732,
		'times' => 215,
		'trade' => 8482,
		'Uacute' => 218,
		'uacute' => 250,
		'uarr' => 8593,
		'uArr' => 8657,
		'Ucirc' => 219,
		'ucirc' => 251,
		'Ugrave' => 217,
		'ugrave' => 249,
		'uml' => 168,
		'upsih' => 978,
		'Upsilon' => 933,
		'upsilon' => 965,
		'Uuml' => 220,
		'uuml' => 252,
		'weierp' => 8472,
		'Xi' => 926,
		'xi' => 958,
		'Yacute' => 221,
		'yacute' => 253,
		'yen' => 165,
		'Yuml' => 376,
		'yuml' => 255,
		'Zeta' => 918,
		'zeta' => 950,
		'zwj' => 8205,
		'zwnj' => 8204
	];

	/**
	 * Fetch the whitelist of acceptable attributes for a given element name.
	 *
	 * @param string $element
	 * @return array
	 */
	public static function attributeWhitelist( string $element ): array {
		// PORT-FIXME: this method is private in core, but used by Gallery
		$lists = self::setupAttributeWhitelist();
		$list = $lists[$element] ?? [];
		return array_flip( $list );
	}

	/**
	 * Foreach array key (an allowed HTML element), return an array
	 * of allowed attributes
	 * @return array
	 */
	private static function setupAttributeWhitelist(): array {
		static $whitelist;

		if ( $whitelist !== null ) {
			return $whitelist;
		}

		$common = [
			# HTML
			'id',
			'class',
			'style',
			'lang',
			'dir',
			'title',

			# WAI-ARIA
			'aria-describedby',
			'aria-flowto',
			'aria-label',
			'aria-labelledby',
			'aria-owns',
			'role',

			# RDFa
			# These attributes are specified in section 9 of
			# https://www.w3.org/TR/2008/REC-rdfa-syntax-20081014
			'about',
			'property',
			'resource',
			'datatype',
			'typeof',

			# Microdata. These are specified by
			# https://html.spec.whatwg.org/multipage/microdata.html#the-microdata-model
			'itemid',
			'itemprop',
			'itemref',
			'itemscope',
			'itemtype',
		];

		$block = array_merge( $common, [ 'align' ] );
		$tablealign = [ 'align', 'valign' ];
		$tablecell = [
			'abbr',
			'axis',
			'headers',
			'scope',
			'rowspan',
			'colspan',
			'nowrap', # deprecated
			'width', # deprecated
			'height', # deprecated
			'bgcolor', # deprecated
		];

		# Numbers refer to sections in HTML 4.01 standard describing the element.
		# See: https://www.w3.org/TR/html4/
		$whitelist = [
			# 7.5.4
			'div'        => $block,
			'center'     => $common, # deprecated
			'span'       => $common,

			# 7.5.5
			'h1'         => $block,
			'h2'         => $block,
			'h3'         => $block,
			'h4'         => $block,
			'h5'         => $block,
			'h6'         => $block,

			# 7.5.6
			# address

			# 8.2.4
			'bdo'        => $common,

			# 9.2.1
			'em'         => $common,
			'strong'     => $common,
			'cite'       => $common,
			'dfn'        => $common,
			'code'       => $common,
			'samp'       => $common,
			'kbd'        => $common,
			'var'        => $common,
			'abbr'       => $common,
			# acronym

			# 9.2.2
			'blockquote' => array_merge( $common, [ 'cite' ] ),
			'q'          => array_merge( $common, [ 'cite' ] ),

			# 9.2.3
			'sub'        => $common,
			'sup'        => $common,

			# 9.3.1
			'p'          => $block,

			# 9.3.2
			'br'         => array_merge( $common, [ 'clear' ] ),

			# https://www.w3.org/TR/html5/text-level-semantics.html#the-wbr-element
			'wbr'        => $common,

			# 9.3.4
			'pre'        => array_merge( $common, [ 'width' ] ),

			# 9.4
			'ins'        => array_merge( $common, [ 'cite', 'datetime' ] ),
			'del'        => array_merge( $common, [ 'cite', 'datetime' ] ),

			# 10.2
			'ul'         => array_merge( $common, [ 'type' ] ),
			'ol'         => array_merge( $common, [ 'type', 'start', 'reversed' ] ),
			'li'         => array_merge( $common, [ 'type', 'value' ] ),

			# 10.3
			'dl'         => $common,
			'dd'         => $common,
			'dt'         => $common,

			# 11.2.1
			'table'      => array_merge( $common,
								[ 'summary', 'width', 'border', 'frame',
										'rules', 'cellspacing', 'cellpadding',
										'align', 'bgcolor',
								] ),

			# 11.2.2
			'caption'    => $block,

			# 11.2.3
			'thead'      => $common,
			'tfoot'      => $common,
			'tbody'      => $common,

			# 11.2.4
			'colgroup'   => array_merge( $common, [ 'span' ] ),
			'col'        => array_merge( $common, [ 'span' ] ),

			# 11.2.5
			'tr'         => array_merge( $common, [ 'bgcolor' ], $tablealign ),

			# 11.2.6
			'td'         => array_merge( $common, $tablecell, $tablealign ),
			'th'         => array_merge( $common, $tablecell, $tablealign ),

			# 12.2
			# NOTE: <a> is not allowed directly, but the attrib
			# whitelist is used from the Parser object
			'a'          => array_merge( $common, [ 'href', 'rel', 'rev' ] ), # rel/rev esp. for RDFa

			# 13.2
			# Not usually allowed, but may be used for extension-style hooks
			# such as <math> when it is rasterized, or if $wgAllowImageTag is
			# true
			'img'        => array_merge( $common, [ 'alt', 'src', 'width', 'height', 'srcset' ] ),
			# Attributes for A/V tags added in T163583 / T133673
			'audio'      => array_merge( $common, [ 'controls', 'preload', 'width', 'height' ] ),
			'video'      => array_merge( $common, [ 'poster', 'controls', 'preload', 'width', 'height' ] ),
			'source'     => array_merge( $common, [ 'type', 'src' ] ),
			'track'      => array_merge( $common, [ 'type', 'src', 'srclang', 'kind', 'label' ] ),

			# 15.2.1
			'tt'         => $common,
			'b'          => $common,
			'i'          => $common,
			'big'        => $common,
			'small'      => $common,
			'strike'     => $common,
			's'          => $common,
			'u'          => $common,

			# 15.2.2
			'font'       => array_merge( $common, [ 'size', 'color', 'face' ] ),
			# basefont

			# 15.3
			'hr'         => array_merge( $common, [ 'width' ] ),

			# HTML Ruby annotation text module, simple ruby only.
			# https://www.w3.org/TR/html5/text-level-semantics.html#the-ruby-element
			'ruby'       => $common,
			# rbc
			'rb'         => $common,
			'rp'         => $common,
			'rt'         => $common, # array_merge( $common, array( 'rbspan' ) ),
			'rtc'        => $common,

			# MathML root element, where used for extensions
			# 'title' may not be 100% valid here; it's XHTML
			# https://www.w3.org/TR/REC-MathML/
			'math'       => [ 'class', 'style', 'id', 'title' ],

			// HTML 5 section 4.5
			'figure'     => $common,
			'figure-inline' => $common, # T118520
			'figcaption' => $common,

			# HTML 5 section 4.6
			'bdi' => $common,

			# HTML5 elements, defined by:
			# https://html.spec.whatwg.org/multipage/semantics.html#the-data-element
			'data' => array_merge( $common, [ 'value' ] ),
			'time' => array_merge( $common, [ 'datetime' ] ),
			'mark' => $common,

			// meta and link are only permitted by removeHTMLtags when Microdata
			// is enabled so we don't bother adding a conditional to hide these
			// Also meta and link are only valid in WikiText as Microdata elements
			// (ie: validateTag rejects tags missing the attributes needed for Microdata)
			// So we don't bother including $common attributes that have no purpose.
			'meta' => [ 'itemprop', 'content' ],
			'link' => [ 'itemprop', 'href', 'title' ],
		];

		return $whitelist;
	}

	/**
	 * Returns true if a given Unicode codepoint is a valid character in
	 * both HTML5 and XML.
	 * @param int $codepoint
	 * @return bool
	 */
	private static function validateCodepoint( int $codepoint ): bool {
		# U+000C is valid in HTML5 but not allowed in XML.
		# U+000D is valid in XML but not allowed in HTML5.
		# U+007F - U+009F are disallowed in HTML5 (control characters).
		return $codepoint == 0x09
			|| $codepoint == 0x0a
			|| ( $codepoint >= 0x20 && $codepoint <= 0x7e )
			|| ( $codepoint >= 0xa0 && $codepoint <= 0xd7ff )
			|| ( $codepoint >= 0xe000 && $codepoint <= 0xfffd )
			|| ( $codepoint >= 0x10000 && $codepoint <= 0x10ffff );
	}

	/**
	 * Returns a string from the provided code point.
	 *
	 * @param int $cp
	 * @return string
	 */
	private static function codepointToUtf8( int $cp ) {
		$chr = mb_chr( $cp, 'UTF-8' );
		Assert::invariant( $chr !== false, "Getting char failed!" );
		return $chr;
	}

	/**
	 * Returns the code point at the first position of the string.
	 *
	 * @param string $str
	 * @return int
	 */
	private static function utf8ToCodepoint( string $str ) {
		$ord = mb_ord( $str );
		Assert::invariant( $ord !== false, "Getting code point failed!" );
		return $ord;
	}

	/**
	 * @param string $host
	 * @return string
	 */
	private static function stripIDNs( string $host ) {
		// This code is part of Sanitizer::cleanUrl in core
		return preg_replace( self::IDN_RE_G, '', $host );
	}

	/**
	 * @param Env $env
	 * @param string $href
	 * @param string $mode
	 * @return string|null
	 */
	public static function cleanUrl( Env $env, string $href, string $mode ): ?string {
		if ( $mode !== 'wikilink' ) {
			$href = preg_replace_callback(
				'/([\][<>"\x00-\x20\x7F\|])/', function ( $matches ) {
					return urlencode( $matches[0] );
				}, $href
			);
		}

		$matched = preg_match( '#^((?:[a-zA-Z][^:/]*:)?(?://)?)([^/]+)(/?.*)#', $href, $bits );
		if ( $matched === 1 ) {
			$proto = $bits[1];
			// if ( $proto && !$env->conf->wiki->hasValidProtocol( $proto ) ) {
			if ( $proto && !$env->getSiteConfig()->hasValidProtocol( $proto ) ) {
				// invalid proto, disallow URL
				return null;
			}
			$host = self::stripIDNs( $bits[2] );
			preg_match( '/^%5B([0-9A-Fa-f:.]+)%5D((:\d+)?)$/D', $host, $match );
			if ( $match ) {
				// IPv6 host names
				$host = '[' . $match[1] . ']' . $match[2];
			}
			$path = $bits[3];
		} else {
			$proto = '';
			$host = '';
			$path = $href;
		}
		return $proto . $host . $path;
	}

	/**
	 * If the named entity is defined in the HTML 4.0/XHTML 1.0 DTD,
	 * return the UTF-8 encoding of that character. Otherwise, returns
	 * pseudo-entity source (eg "&foo;").
	 * @param string $name
	 * @return string
	 */
	private static function decodeEntity( string $name ): string {
		if ( !empty( self::HTML_ENTITY_ALIASES[$name] ) ) {
			$name = self::HTML_ENTITY_ALIASES[$name];
		}
		$e = self::HTML_ENTITIES[$name] ?? null;
		return $e ? self::codepointToUtf8( $e ) : '&' . $name . ';';
	}

	/**
	 * Return UTF-8 string for a codepoint if that is a valid
	 * character reference, otherwise U+FFFD REPLACEMENT CHARACTER.
	 * @param int $codepoint
	 * @return string
	 */
	private static function decodeChar( int $codepoint ): string {
		if ( self::validateCodepoint( $codepoint ) ) {
			return self::codepointToUtf8( $codepoint );
		} else {
			return self::UTF8_REPLACEMENT;
		}
	}

	/**
	 * Decode any character references, numeric or named entities,
	 * in the text and return a UTF-8 string.
	 * @param string $text
	 * @return string
	 */
	public static function decodeCharReferences( string $text ): string {
		return preg_replace_callback(
			self::CHAR_REFS_RE_G,
			function ( $matches ) {
				if ( $matches[1] !== '' ) {
					return self::decodeEntity( $matches[1] );
				} elseif ( $matches[2] !== '' ) {
					return self::decodeChar( intval( $matches[2] ) );
				} elseif ( $matches[3] !== '' ) {
					return self::decodeChar( hexdec( $matches[3] ) );
				}
				# Last case should be an ampersand by itself
				return $matches[4];
			},
			$text
		);
	}

	/**
	 * Normalize CSS into a format we can easily search for hostile input
	 *  - decode character references
	 *  - decode escape sequences
	 *  - convert characters that IE6 interprets into ascii
	 *  - remove comments, unless the entire value is one single comment
	 * @param string $value the css string
	 * @return string normalized css
	 */
	public static function normalizeCss( string $value ): string {
		// Decode character references like &#123;
		$value = self::decodeCharReferences( $value );

		// Decode escape sequences and line continuation
		// See the grammar in the CSS 2 spec, appendix D.
		// This has to be done AFTER decoding character references.
		// This means it isn't possible for this function to return
		// unsanitized escape sequences. It is possible to manufacture
		// input that contains character references that decode to
		// escape sequences that decode to character references, but
		// it's OK for the return value to contain character references
		// because the caller is supposed to escape those anyway.
		static $decodeRegex;
		if ( !$decodeRegex ) {
			$space = '[\\x20\\t\\r\\n\\f]';
			$nl = '(?:\\n|\\r\\n|\\r|\\f)';
			$backslash = '\\\\';
			$decodeRegex = "/ $backslash
				(?:
					($nl) |  # 1. Line continuation
					([0-9A-Fa-f]{1,6})$space? |  # 2. character number
					(.) | # 3. backslash cancelling special meaning
					() | # 4. backslash at end of string
				)/xu";
		}
		// @phan-suppress-next-line PhanParamSuspiciousOrder
		$value = preg_replace_callback( $decodeRegex,
			[ self::class, 'cssDecodeCallback' ], $value );

		// Normalize Halfwidth and Fullwidth Unicode block that IE6 might treat as ascii
		$value = preg_replace_callback(
			'/[！-［］-ｚ]/u', // U+FF01 to U+FF5A, excluding U+FF3C (T60088)
			function ( $matches ) {
				$cp = self::utf8ToCodepoint( $matches[0] );
				if ( $cp === false ) {
					return '';
				}
				return chr( $cp - 65248 ); // ASCII range \x21-\x7A
			},
			$value
		);

		// Convert more characters IE6 might treat as ascii
		// U+0280, U+0274, U+207F, U+029F, U+026A, U+207D, U+208D
		$value = str_replace(
			[ 'ʀ', 'ɴ', 'ⁿ', 'ʟ', 'ɪ', '⁽', '₍' ],
			[ 'r', 'n', 'n', 'l', 'i', '(', '(' ],
			$value
		);

		// Let the value through if it's nothing but a single comment, to
		// allow other functions which may reject it to pass some error
		// message through.
		if ( !preg_match( '! ^ \s* /\* [^*\\/]* \*/ \s* $ !xD', $value ) ) {
			// Remove any comments; IE gets token splitting wrong
			// This must be done AFTER decoding character references and
			// escape sequences, because those steps can introduce comments
			// This step cannot introduce character references or escape
			// sequences, because it replaces comments with spaces rather
			// than removing them completely.
			$value = self::delimiterReplace( '/*', '*/', ' ', $value );

			// Remove anything after a comment-start token, to guard against
			// incorrect client implementations.
			$commentPos = strpos( $value, '/*' );
			if ( $commentPos !== false ) {
				$value = substr( $value, 0, $commentPos );
			}
		}

		// S followed by repeat, iteration, or prolonged sound marks,
		// which IE will treat as "ss"
		$value = preg_replace(
			'/s(?:
				\xE3\x80\xB1 | # U+3031
				\xE3\x82\x9D | # U+309D
				\xE3\x83\xBC | # U+30FC
				\xE3\x83\xBD | # U+30FD
				\xEF\xB9\xBC | # U+FE7C
				\xEF\xB9\xBD | # U+FE7D
				\xEF\xBD\xB0   # U+FF70
			)/ix',
			'ss',
			$value
		);

		return $value;
	}

	// PORT_FIXME - The delimiterReplace code below is from StringUtils in core

	/**
	 * Perform an operation equivalent to `preg_replace_callback()`
	 *
	 * Matches this code:
	 *
	 *     preg_replace_callback( "!$startDelim(.*)$endDelim!s$flags", $callback, $subject );
	 *
	 * If the start delimiter ends with an initial substring of the end delimiter,
	 * e.g. in the case of C-style comments, the behavior differs from the model
	 * regex. In this implementation, the end must share no characters with the
	 * start, so e.g. `/*\/` is not considered to be both the start and end of a
	 * comment. `/*\/xy/*\/` is considered to be a single comment with contents `/xy/`.
	 *
	 * The implementation of delimiterReplaceCallback() is slower than hungryDelimiterReplace()
	 * but uses far less memory. The delimiters are literal strings, not regular expressions.
	 *
	 * @param string $startDelim Start delimiter
	 * @param string $endDelim End delimiter
	 * @param callable $callback Function to call on each match
	 * @param string $subject
	 * @param string $flags Regular expression flags
	 * @throws InvalidArgumentException
	 * @return string
	 */
	private static function delimiterReplaceCallback(
		string $startDelim, string $endDelim, callable $callback, string $subject, string $flags = ''
	): string {
		$inputPos = 0;
		$outputPos = 0;
		$contentPos = 0;
		$output = '';
		$foundStart = false;
		$encStart = preg_quote( $startDelim, '!' );
		$encEnd = preg_quote( $endDelim, '!' );
		$strcmp = strpos( $flags, 'i' ) === false ? 'strcmp' : 'strcasecmp';
		$endLength = strlen( $endDelim );
		$m = [];
		while ( $inputPos < strlen( $subject ) &&
			preg_match( "!($encStart)|($encEnd)!S$flags", $subject, $m, PREG_OFFSET_CAPTURE, $inputPos )
		) {
			$tokenOffset = $m[0][1];
			if ( $m[1][0] !== '' ) {
				if ( $foundStart &&
					$strcmp( $endDelim, substr( $subject, $tokenOffset, $endLength ) ) === 0
				) {
					# An end match is present at the same location
					$tokenType = 'end';
					$tokenLength = $endLength;
				} else {
					$tokenType = 'start';
					$tokenLength = strlen( $m[0][0] );
				}
			} elseif ( $m[2][0] !== '' ) {
				$tokenType = 'end';
				$tokenLength = strlen( $m[0][0] );
			} else {
				throw new InvalidArgumentException( 'Invalid delimiter given to ' . __METHOD__ );
			}
			if ( $tokenType === 'start' ) {
				# Only move the start position if we haven't already found a start
				# This means that START START END matches outer pair
				if ( !$foundStart ) {
					# Found start
					$inputPos = $tokenOffset + $tokenLength;
					# Write out the non-matching section
					$output .= substr( $subject, $outputPos, $tokenOffset - $outputPos );
					$outputPos = $tokenOffset;
					$contentPos = $inputPos;
					$foundStart = true;
				} else {
					# Move the input position past the *first character* of START,
					# to protect against missing END when it overlaps with START
					$inputPos = $tokenOffset + 1;
				}
			} elseif ( $tokenType === 'end' ) {
				if ( $foundStart ) {
					# Found match
					$output .= $callback( [
						substr( $subject, $outputPos, $tokenOffset + $tokenLength - $outputPos ),
						substr( $subject, $contentPos, $tokenOffset - $contentPos )
					] );
					$foundStart = false;
				} else {
					# Non-matching end, write it out
					$output .= substr( $subject, $inputPos, $tokenOffset + $tokenLength - $outputPos );
				}
				$inputPos = $outputPos = $tokenOffset + $tokenLength;
			} else {
				throw new InvalidArgumentException( 'Invalid delimiter given to ' . __METHOD__ );
			}
		}
		if ( $outputPos < strlen( $subject ) ) {
			$output .= substr( $subject, $outputPos );
		}
		return $output;
	}

	/**
	 * Perform an operation equivalent to `preg_replace()` with flags.
	 *
	 * Matches this code:
	 *
	 *     preg_replace( "!$startDelim(.*)$endDelim!$flags", $replace, $subject );
	 *
	 * @param string $startDelim Start delimiter regular expression
	 * @param string $endDelim End delimiter regular expression
	 * @param string $replace Replacement string. May contain $1, which will be
	 *  replaced by the text between the delimiters
	 * @param string $subject String to search
	 * @param string $flags Regular expression flags
	 * @return string The string with the matches replaced
	 */
	private static function delimiterReplace(
		string $startDelim, string $endDelim, string $replace, string $subject, string $flags = ''
	): string {
		return self::delimiterReplaceCallback(
			$startDelim, $endDelim,
			function ( array $matches ) use ( $replace ) {
				return strtr( $replace, [ '$0' => $matches[0], '$1' => $matches[1] ] );
			},
			$subject, $flags
		);
	}

	/**
	 * SSS FIXME: There is a test in mediawiki.environment.js that doles out
	 * and tests about ids. There are probably some tests in Util.php as well.
	 * We should move all these kind of tests somewhere else.
	 * @param string $k
	 * @param string $v
	 * @param KV[] $attrs
	 * @return bool
	 */
	private static function isParsoidAttr( string $k, string $v, array $attrs ): bool {
		// NOTES:
		// 1. Currently the tokenizer unconditionally escapes typeof and about
		// attributes from wikitxt to data-x-typeof and data-x-about. So,
		// this check will only pass through Parsoid inserted attrs.
		// 2. But, if we fix the over-aggressive escaping in the tokenizer to
		// not escape non-Parsoid typeof and about, then this will return
		// true for something like typeof='mw:Foo evilScriptHere'. But, that
		// is safe since this check is only used to see if we should
		// unconditionally discard the entire attribute or process it further.
		// That further processing will catch and discard any dangerous
		// strings in the rest of the attribute
		return preg_match( ( '/^(?:typeof|property|rel)$/D' ), $k )
			&& preg_match( '/(?:^|\s)mw:.+?(?=$|\s)/D', $v )
			|| $k === 'about' && preg_match( '/^#mwt\d+$/D', $v )
			|| $k === 'content'
			&& preg_match( '/(?:^|\s)mw:.+?(?=$|\s)/D', KV::lookup( $attrs, 'property' ) );
	}

	/**
	 * Given an attribute name, checks whether it is a reserved data attribute
	 * (such as data-mw-foo) which is unavailable to user-generated HTML so MediaWiki
	 * core and extension code can safely use it to communicate with frontend code.
	 * @param string $attr Attribute name.
	 * @return bool
	 */
	public static function isReservedDataAttribute( string $attr ): bool {
		// data-ooui is reserved for ooui.
		// data-mw and data-parsoid are reserved for parsoid.
		// data-mw-<name here> is reserved for extensions (or core) if
		// they need to communicate some data to the client and want to be
		// sure that it isn't coming from an untrusted user.
		// We ignore the possibility of namespaces since user-generated HTML
		// can't use them anymore.
		if ( preg_match( '/^data-(mw|parsoid)/', $attr ) ) {
			return false; // PARSOID SPECIFIC
		}
		return (bool)preg_match( '/^data-(ooui|mw|parsoid)/i', $attr );
	}

	/**
	 * @param Env $env
	 * @param string|null $tagName
	 * @param Token|null $token
	 * @param array $attrs
	 * @return array
	 */
	private static function sanitizeTagAttrs(
		Env $env, ?string $tagName, ?Token $token, array $attrs
	): array {
		$tag = $tagName ?: $token->getName();

		$wlist = self::attributeWhitelist( $tag );
		$newAttrs = [];
		$n = count( $attrs );
		for ( $i = 0;  $i < $n;  $i++ ) {
			$a = $attrs[$i];
			if ( !isset( $a->v ) ) {
				$a->v = '';
			}

			// Convert attributes to string, if necessary.
			$a->k = TokenUtils::tokensToString( $a->k );
			$a->v = TokenUtils::tokensToString( $a->v, false, [
					'unpackDOMFragments' => true,
					// FIXME: Sneaking in `env` to avoid changing the signature
					'env' => $env
				]
			);

			$origK = $a->ksrc ?? $a->k;
			// $a->k can be uppercase
			$k = mb_strtolower( $a->k );
			$v = $a->v;
			$origV = $a->vsrc ?? $v;
			$psdAttr = self::isParsoidAttr( $k, $v, $attrs );

			// Bypass RDFa/whitelisting checks for Parsoid-inserted attrs
			// Safe to do since the tokenizer renames about/typeof attrs.
			// unconditionally. FIXME: The escaping solution in the tokenizer
			// may be aggressive. There is no need to escape typeof strings
			// that or about ids that don't resemble Parsoid tokens/about ids.
			if ( !$psdAttr ) {
				if ( !preg_match( self::GET_ATTRIBS_RE, $k ) ) {
					$newAttrs[$k] = [ null, $origV, $origK ];
					continue;
				}

				# Allow XML namespace declaration to allow RDFa
				if ( preg_match( self::XMLNS_ATTRIBUTE_PATTERN, $k ) ) {
					if ( !preg_match( self::EVIL_URI_PATTERN, $v ) ) {
						$newAttrs[$k] = [ $v, $origV, $origK ];
					} else {
						$newAttrs[$k] = [ null, $origV, $origK ];
					}
					continue;
				}

				# Allow any attribute beginning with "data-"
				# However:
				# * Disallow data attributes used by MediaWiki code
				# * Ensure that the attribute is not namespaced by banning
				#   colons.
				if ( ( !preg_match( '/^data-[^:]*$/iD', $k )
					 && !isset( $wlist[$k] ) )
					 || self::isReservedDataAttribute( $k )
				) {
					$newAttrs[$k] = [ null, $origV, $origK ];
					continue;
				}
			}

			# Strip javascript "expression" from stylesheets.
			# http://msdn.microsoft.com/workshop/author/dhtml/overview/recalc.asp
			if ( $k === 'style' ) {
				$v = self::checkCss( $v );
			}

			# Escape HTML id attributes
			if ( $k === 'id' ) {
				$v = self::escapeIdForAttribute( $v, self::ID_PRIMARY );
			}

			# Escape HTML id reference lists
			if ( $k === 'aria-describedby'
				|| $k === 'aria-flowto'
				|| $k === 'aria-labelledby'
				|| $k === 'aria-owns'
			) {
				$v = self::escapeIdReferenceList( $v );
			}

			// RDFa and microdata properties allow URLs, URIs and/or CURIs.
			// Check them for sanity.
			if ( $k === 'rel' || $k === 'rev'
				# RDFa
				|| $k === 'about' || $k === 'property'
				|| $k === 'resource' || $k === 'datatype'
				|| $k === 'typeof'
				# HTML5 microdata
				|| $k === 'itemid' || $k === 'itemprop'
				|| $k === 'itemref' || $k === 'itemscope'
				|| $k === 'itemtype'
			) {
				// Paranoia. Allow "simple" values but suppress javascript
				if ( preg_match( self::EVIL_URI_PATTERN, $v ) ) {
					// Retain the Parsoid typeofs for Parsoid attrs
					$newV = $psdAttr ? trim( preg_replace( '/(?:^|\s)(?!mw:\w)[^\s]*/', '', $origV ) ) : null;
					$newAttrs[$k] = [ $newV, $origV, $origK ];
					continue;
				}
			}

			# NOTE: even though elements using href/src are not allowed directly, supply
			#       validation code that can be used by tag hook handlers, etc
			if ( $token && ( $k === 'href' || $k === 'src' || $k === 'poster' ) ) { // T163583
				// `origV` will always be `v`, because `a.vsrc` isn't set, since
				// this attribute didn't come from source.  However, in the
				// LinkHandler, we may have already shadowed this value so use
				// that instead.
				$rel = $token->getAttributeShadowInfo( 'rel' );
				$mode = ( $k === 'href' &&
					$rel &&
					preg_match( '#^mw:WikiLink(/Interwiki)?$#', $rel['value'] )
				) ? 'wikilink' : 'external';
				$origHref = $token->getAttributeShadowInfo( $k )['value'];
				$newHref = self::cleanUrl( $env, $v, $mode );
				if ( $newHref !== $v ) {
					$newAttrs[$k] = [ $newHref, $origHref, $origK ];
					continue;
				}
			}

			// SSS FIXME: This logic is not RT-friendly.
			// If this attribute was previously set, override it.
			// Output should only have one attribute of each name.
			$newAttrs[$k] = [ $v, $origV, $origK ];
		}

		# itemtype, itemid, itemref don't make sense without itemscope
		if ( !array_key_exists( 'itemscope', $newAttrs ) ) {
			// SSS FIXME: This logic is not RT-friendly.
			unset( $newAttrs['itemtype'] );
			unset( $newAttrs['itemid'] );
			unset( $newAttrs['itemref'] );
		}
		# TODO: Strip itemprop if we aren't descendants of an itemscope or pointed to by an itemref.

		return $newAttrs;
	}

	/**
	 * Sanitize and apply attributes to a wrapper element.
	 *
	 * Used primarily when we're applying tokenized attributes directly to
	 * dom elements, which wouldn't have had a chance to be sanitized before
	 * tree building.
	 * @param Env $env environment
	 * @param DOMElement $wrapper wrapper
	 * @param array $attrs attributes
	 */
	public static function applySanitizedArgs( Env $env, DOMElement $wrapper, array $attrs ): void {
		// We can switch to a different DOM library that can return uppercase node name
		$nodeName = strtolower( $wrapper->nodeName );
		$sanitizedAttrs = self::sanitizeTagAttrs( $env, $nodeName, null, $attrs );
		foreach ( $sanitizedAttrs as $k => $v ) {
			if ( isset( $v[0] ) ) {
				$wrapper->setAttribute( $k, $v[0] );
			}
		}
	}

	/**
	 * @param string $text
	 * @return string
	 */
	public static function checkCss( string $text ): string {
		$text = self::normalizeCss( $text );
		// \000-\010\013\016-\037\177 are the octal escape sequences
		if ( preg_match( '/[\000-\010\013\016-\037\177]/', $text )
			|| strpos( $text, self::UTF8_REPLACEMENT ) !== false
		) {
			return '/* invalid control char */';
		} elseif ( preg_match( self::INSECURE_RE, $text ) ) {
			return '/* insecure input */';
		} else {
			return $text;
		}
	}

	/**
	 * @param array $matches
	 * @return string
	 */
	public static function cssDecodeCallback( $matches ) {
		if ( $matches[1] !== '' ) {
			// Line continuation
			return '';
		} elseif ( $matches[2] !== '' ) {
			$char = self::codepointToUtf8( hexdec( $matches[2] ) );
		} elseif ( $matches[3] !== '' ) {
			$char = $matches[3];
		} else {
			$char = '\\';
		}
		if ( $char == "\n" || $char == '"' || $char == "'" || $char == '\\' ) {
			// These characters need to be escaped in strings
			// Clean up the escape sequence to avoid parsing errors by clients
			return '\\' . dechex( ord( $char ) ) . ' ';
		} else {
			// Decode unnecessary escape
			return $char;
		}
	}

	/**
	 * Sanitize a token.
	 *
	 * XXX: Make attribute sanitation reversible by storing round-trip info in
	 * token.dataAttribs object (which is serialized as JSON in a data-parsoid
	 * attribute in the DOM).
	 * @param Env $env
	 * @param Token|string $token
	 * @param bool $inTemplate
	 * @return Token|string
	 */
	private static function sanitizeToken( Env $env, $token, bool $inTemplate ) {
		$i = null;
		$l = null;
		$kv = null;
		$attribs = $token->attribs ?? null;
		$tagWhiteList = WikitextConstants::$Sanitizer['TagWhiteList'];

		if ( TokenUtils::isHTMLTag( $token )
			&& ( empty( $tagWhiteList[$token->getName()] )
				|| ( $token instanceof EndTagTk && !empty( self::NO_END_TAG_SET[$token->getName()] ) )
			)
		) { // unknown tag -- convert to plain text
			if ( !$inTemplate && !empty( $token->dataAttribs->tsr ) ) {
				// Just get the original token source, so that we can avoid
				// whitespace differences.
				$token = $token->getWTSource( $env->topFrame );
			} elseif ( !$token instanceof EndTagTk ) {
				// Handle things without a TSR: For example template or extension
				// content. Whitespace in these is not necessarily preserved.
				$buf = '<' . $token->getName();
				for ( $i = 0, $l = count( $attribs );  $i < $l;  $i++ ) {
					$kv = $attribs[$i];
					$buf .= ' ' . TokenUtils::tokensToString( $kv->k ) .
						"='" . TokenUtils::tokensToString( $kv->v ) . "'";
				}
				if ( $token instanceof SelfclosingTagTk ) {
					$buf .= ' /';
				}
				$buf .= '>';
				$token = $buf;
			} else {
				$token = '</' . $token->getName() . '>';
			}
		} elseif ( $attribs && count( $attribs ) > 0 ) {
			// Sanitize attributes
			if ( $token instanceof TagTk || $token instanceof SelfclosingTagTk ) {
				$newAttrs = self::sanitizeTagAttrs( $env, null, $token, $attribs );

				// Reset token attribs and rebuild
				$token->attribs = [];

				// SSS FIXME: We are right now adding shadow information for all sanitized
				// attributes.  This is being done to minimize dirty diffs for the first
				// cut.  It can be reasonably argued that we can permanently delete dangerous
				// and unacceptable attributes in the interest of safety/security and the
				// resultant dirty diffs should be acceptable.  But, this is something to do
				// in the future once we have passed the initial tests of parsoid acceptance.
				// Object::keys( $newAttrs )->forEach( function ( $j ) use ( &$newAttrs, &$token ) {
				foreach ( $newAttrs as $k => $v ) {
					// explicit check against null to prevent discarding empty strings
					if ( $v[0] !== null ) {
						$token->addNormalizedAttribute( $k, $v[0], $v[1] );
					} else {
						$token->setShadowInfo( $v[2], $v[0], $v[1] );
					}
				}
			} else {
				// EndTagTk, drop attributes
				$token->attribs = [];
			}
		}

		return $token;
	}

	/**
	 * Sanitize a title to be used in a URI?
	 * @param string $title
	 * @param bool $isInterwiki
	 * @return string
	 */
	public static function sanitizeTitleURI( string $title, bool $isInterwiki = false ): string {
		$bits = explode( '#', $title );
		$anchor = null;
		if ( count( $bits ) > 1 ) { // split at first '#'
			$anchor = substr( $title, strlen( $bits[0] ) + 1 );
			$title = $bits[0];
		}
		$title = preg_replace_callback(
			'/[%? \[\]#|<>]/', function ( $matches ) {
				return PHPUtils::encodeURIComponent( $matches[0] );
			}, $title );
		if ( $anchor !== null ) {
			$title .= '#' . ( $isInterwiki
					? self::escapeIdForExternalInterwiki( $anchor )
					: self::escapeIdForLink( $anchor ) );
		}
		return $title;
	}

	/**
	 * Given a section name or other user-generated or otherwise unsafe string, escapes it to be
	 * a valid HTML id attribute.
	 *
	 * WARNING: unlike escapeId(), the output of this function is not guaranteed to be HTML safe,
	 * be sure to use proper escaping.
	 *
	 * In Parsoid, proper escaping is usually handled for us by the HTML
	 * serialization algorithm, but be careful of corner cases (such as
	 * emitting attributes in wikitext).
	 *
	 * @param string $id String to escape
	 * @param int $mode One of ID_* constants, specifying whether the primary or fallback encoding
	 *     should be used.
	 * @return string|bool Escaped ID or false if fallback encoding is requested but it's not
	 *     configured.
	 *
	 * @since 1.30
	 */
	public static function escapeIdForAttribute( string $id, $mode = self::ID_PRIMARY ): string {
		// For consistency with PHP's API, we accept "primary" or "fallback" as
		// the mode in 'options'.  This (slightly) abstracts the actual details
		// of the id encoding from the Parsoid code which handles ids; we could
		// swap primary and fallback here, or even transition to a new HTML6
		// encoding (!), without touching all the call sites.
		$internalMode = $mode === self::ID_FALLBACK ? 'legacy' : 'html5';
		return self::escapeIdInternal( $id, $internalMode );
	}

	/**
	 * Given a section name or other user-generated or otherwise unsafe string, escapes it to be
	 * a valid URL fragment.
	 *
	 * WARNING: unlike escapeId(), the output of this function is not guaranteed to be HTML safe,
	 * be sure to use proper escaping.
	 *
	 * @param string $id String to escape
	 * @return string Escaped ID
	 *
	 * @since 1.30
	 */
	public static function escapeIdForLink( string $id ): string {
		return self::escapeIdInternal( $id, 'html5' );
	}

	/**
	 * Given a section name or other user-generated or otherwise unsafe string, escapes it to be
	 * a valid URL fragment for external interwikis.
	 *
	 * @param string $id String to escape
	 * @return string Escaped ID
	 *
	 * @since 1.30
	 */
	private static function escapeIdForExternalInterwiki( string $id ): string {
		// Assume $wgExternalInterwikiFragmentMode = 'legacy'
		return self::escapeIdInternal( $id, 'legacy' );
	}

	/**
	 * Helper for escapeIdFor*() functions. Performs most of the actual escaping.
	 *
	 * @param string $id String to escape
	 * @param string $mode One of modes from $wgFragmentMode ('html5' or 'legacy')
	 * @return string
	 */
	private static function escapeIdInternal( string $id, string $mode ): string {
		switch ( $mode ) {
			case 'html5':
				$id = str_replace( ' ', '_', $id );
				break;

			case 'legacy':
				// This corresponds to 'noninitial' mode of the old escapeId
				static $replace = [
					'%3A' => ':',
					'%' => '.'
				];

				$id = urlencode( str_replace( ' ', '_', $id ) );
				$id = strtr( $id, $replace );
				break;

			default:
				throw new InvalidArgumentException( "Invalid mode '$mode' passed to '" . __METHOD__ );
		}

		return $id;
	}

	/**
	 * Given a string containing a space delimited list of ids, escape each id
	 * to match ids escaped by the escapeIdForAttribute() function.
	 *
	 * @since 1.27
	 *
	 * @param string $referenceString Space delimited list of ids
	 * @return string
	 */
	public static function escapeIdReferenceList( string $referenceString ): string {
		# Explode the space delimited list string into an array of tokens
		$references = preg_split( '/\s+/', "{$referenceString}", -1, PREG_SPLIT_NO_EMPTY );

		# Escape each token as an id
		foreach ( $references as &$ref ) {
			$ref = self::escapeIdForAttribute( $ref );
		}

		# Merge the array back to a space delimited list string
		# If the array is empty, the result will be an empty string ('')
		$referenceString = implode( ' ', $references );

		return $referenceString;
	}

	/**
	 * @param string $id
	 * @return string
	 */
	public static function normalizeSectionIdWhiteSpace( string $id ): string {
		return trim( preg_replace( '/[ _]+/', ' ', $id ) );
	}

	/**
	 * @param TokenTransformManager $manager manager enviroment
	 * @param array $options various configuration options
	 */
	public function __construct( TokenTransformManager $manager, array $options ) {
		parent::__construct( $manager, $options );
		$this->inTemplate = !empty( $options['inTemplate'] );
	}

	/**
	 * @inheritDoc
	 */
	public function onAny( $token ) {
		$env = $this->manager->env;
		$env->log( 'trace/sanitizer', $this->manager->pipelineId, function () use ( $token ) {
			return PHPUtils::jsonEncode( $token );
		} );

		// Pass through a transparent line meta-token
		if ( TokenUtils::isEmptyLineMetaToken( $token ) ) {
			$env->log( 'trace/sanitizer', $this->manager->pipelineId, '--unchanged--' );
			return [ 'tokens' => [ $token ] ];
		}

		$token = self::sanitizeToken( $env, $token, $this->inTemplate );

		$env->log( 'trace/sanitizer', $this->manager->pipelineId, function () use ( $token ) {
			return ' ---> ' . PHPUtils::jsonEncode( $token );
		} );
		return [ 'tokens' => [ $token ] ];
	}
}
