<?php
declare( strict_types = 1 );

namespace Parsoid\Html2Wt\ConstrainedText;

use DOMElement;
use stdClass;

use Parsoid\Config\Env;
use Parsoid\Config\SiteConfig;
use Parsoid\Utils\PHPUtils;

/**
 * An internal wiki link, like `[[Foo]]`.
 */
class WikiLinkText extends RegExpConstrainedText {
	/** @var bool */
	private $greedy = false;

	/**
	 * @param string $text
	 * @param DOMElement $node
	 * @param SiteConfig $siteConfig
	 * @param string $type
	 *   The type of the link, as described by the `rel` attribute.
	 */
	public function __construct(
		string $text, DOMElement $node,
		SiteConfig $siteConfig, string $type
	) {
		// category links/external links/images don't use link trails or prefixes
		$noTrails = preg_match( '/^mw:WikiLink(\/Interwiki)?$/', $type ) === 0;
		$badPrefix = '/(^|[^\[])(\[\[)*\[$/';
		$linkPrefixRegex = $siteConfig->linkPrefixRegex();
		if ( !$noTrails && $linkPrefixRegex ) {
			$badPrefix =
				'/(' . PHPUtils::reStrip( $linkPrefixRegex, '/' ) . ')' .
				'|(' . PHPUtils::reStrip( $badPrefix, '/' ) . ')/u';
		}
		parent::__construct( [
			'text' => $text,
			'node' => $node,
			'badPrefix' => $badPrefix,
			'badSuffix' => ( $noTrails ) ? null : $siteConfig->linkTrailRegex(),
		] );
		// We match link trails greedily when they exist.
		if ( !( $noTrails || preg_match( '/\]$/', $text ) ) ) {
			$this->greedy = true;
		}
	}

	/** @inheritDoc */
	public function escape( State $state ): Result {
		$r = parent::escape( $state );
		// If previous token was also a WikiLink, its linktrail will
		// eat up any possible linkprefix characters, so we don't need
		// a <nowiki> in this case.  (Eg: [[a]]-[[b]] in iswiki; the -
		// character is both a link prefix and a link trail, but it gets
		// preferentially associated with the [[a]] as a link trail.)
		$r->greedy = $this->greedy;
		return $r;
	}

	/**
	 * @param string $text
	 * @param DOMElement $node
	 * @param stdClass $dataParsoid
	 * @param Env $env
	 * @param array $opts
	 * @return ?WikiLinkText
	 */
	protected static function fromSelSerImpl(
		string $text, DOMElement $node, stdClass $dataParsoid,
		Env $env, array $opts
	): ?WikiLinkText {
		$type = $node->getAttribute( 'rel' ) ?? '';
		$stx = $dataParsoid->stx ?? '';
		// TODO: Leaving this for backwards compatibility, remove when 1.5 is no longer bound
		if ( $type === 'mw:ExtLink' ) {
			$type = 'mw:WikiLink/Interwiki';
		}
		if (
			preg_match( '/^mw:WikiLink(\/Interwiki)?$/', $type ) &&
			preg_match( '/^(simple|piped)$/', $stx )
		) {
			return new WikiLinkText( $text, $node, $env->getSiteConfig(), $type );
		}
		return null;
	}
}