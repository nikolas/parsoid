<?php

namespace Test\Parsoid\Wt2Html;

use DOMDocument;
use Parsoid\Wt2Html\XMLSerializer;
use Wikimedia\TestingAccessWrapper;

/**
 * Test the entity encoding logic (which the JS version did not have as it called
 * on the entities npm package).
 * @coversDefaultClass \Parsoid\Wt2Html\XMLSerializer
 */
class XMLSerializerTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @covers ::serialize
	 */
	public function testSerialize() {
		$html = '<html><head><title>hi</title><body>'
			. '<div id="123">ok<div id="234">nope</div></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></body></html>';
		$expectedHtml = "<!DOCTYPE html>\n<html><head><title>hi</title></head><body>"
			. '<div id="123">ok<div id="234">nope</div></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></body></html>';
		$expectedInnerHtml = "<head><title>hi</title></head><body>"
			. '<div id="123">ok<div id="234">nope</div></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></body>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedHtml, $ret['html'] );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedHtml, $ret['html'] );

		$ret = XMLSerializer::serialize( $doc, [ 'innerXML' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedInnerHtml, $ret['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_captureOffsets() {
		$html = '<html><head><title>hi</title><body>'
			. '<div id="123">ok<div id="234">nope</div></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'offsets', $ret );
		$this->assertArrayHasKey( '123', $ret['offsets'] );
		$this->assertSame( [ 0, 62 ], $ret['offsets']['123']['html'] );
		$this->assertArrayNotHasKey( '234', $ret['offsets'] );
		$this->assertArrayHasKey( '345', $ret['offsets'] );
		$this->assertSame( [ 62, 85 ], $ret['offsets']['345']['html'] );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true, 'innerXML' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'offsets', $ret );
		$this->assertArrayHasKey( '123', $ret['offsets'] );
		$this->assertSame( [ 0, 62 ], $ret['offsets']['123']['html'] );
		$this->assertArrayNotHasKey( '234', $ret['offsets'] );
		$this->assertArrayHasKey( '345', $ret['offsets'] );
		$this->assertSame( [ 62, 85 ], $ret['offsets']['345']['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_captureOffsets_template() {
		$html = '<html><head><title>hi</title><body>'
			. '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">a</p>'
			. '<p about="#mwt1" id="justhappenstobehere">b</p>'
			. '<p id="mwAg">c</p>'
			. '</body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'offsets', $ret );
		$this->assertArrayHasKey( 'mwAQ', $ret['offsets'] );
		$this->assertArrayHasKey( 'mwAg', $ret['offsets'] );
		$this->assertArrayNotHasKey( 'justhappenstobehere', $ret['offsets'] );
		$this->assertSame( [ 0, 104 ], $ret['offsets']['mwAQ']['html'] );
		$this->assertSame( [ 104, 122 ], $ret['offsets']['mwAg']['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_captureOffsets_expandedAttrs() {
		$html = '<html><head><title>hi</title><body>'
			// phpcs:ignore Generic.Files.LineLength.TooLong
			. '<div style="color:red" about="#mwt2" typeof="mw:ExpandedAttrs" id="mwAQ" data-mw=\'{"attribs":[[{"txt":"style"},{"html":"&lt;span about=\"#mwt1\" typeof=\"mw:Transclusion\" data-parsoid=\"{&amp;quot;pi&amp;quot;:[[{&amp;quot;k&amp;quot;:&amp;quot;1&amp;quot;}]],&amp;quot;dsr&amp;quot;:[12,30,null,null]}\" data-mw=\"{&amp;quot;parts&amp;quot;:[{&amp;quot;template&amp;quot;:{&amp;quot;target&amp;quot;:{&amp;quot;wt&amp;quot;:&amp;quot;echo&amp;quot;,&amp;quot;href&amp;quot;:&amp;quot;./Template:Echo&amp;quot;},&amp;quot;params&amp;quot;:{&amp;quot;1&amp;quot;:{&amp;quot;wt&amp;quot;:&amp;quot;color:red&amp;quot;}},&amp;quot;i&amp;quot;:0}}]}\">color:red&lt;/span>"}]]}\'>boo</div>'
			. '<p id="mwAg">next!</p>'
			. '</body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'offsets', $ret );
		$this->assertArrayHasKey( 'mwAQ', $ret['offsets'] );
		$this->assertArrayHasKey( 'mwAg', $ret['offsets'] );
		$this->assertSame( [ 0, 684 ], $ret['offsets']['mwAQ']['html'] );
		$this->assertSame( [ 684, 706 ], $ret['offsets']['mwAg']['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_captureOffsets_extensionContentNestedInTemplates() {
		// Mostly scooped from, echo "{{Demografia/Apricale}}" | node tests/parse --prefix itwiki --dp
		$html = '<html><head><title>hi</title><body>'
			// phpcs:ignore Generic.Files.LineLength.TooLong
			. '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ" data-mw=\'{"parts":[{"template":{"target":{"wt":"Demografia/Apricale","href":"./Template:Demografia/Apricale"},"params":{},"i":0}}]}\'><i>Abitanti censiti</i></p>'
			// phpcs:ignore Generic.Files.LineLength.TooLong
			. '<map name="timeline" id="timeline" typeof="mw:Extension/timeline" data-mw=\'{"name":"timeline","attrs":{},"body":{"extsrc":"yadayadayada"}}\' about="#mwt1"></map>'
			. '</body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc, [ 'captureOffsets' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'offsets', $ret );
		$this->assertArrayHasKey( 'mwAQ', $ret['offsets'] );
		$this->assertArrayNotHasKey( 'timeline', $ret['offsets'] );
		$this->assertSame( [ 0, 372 ], $ret['offsets']['mwAQ']['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_uppercaseTagnames() {
		$html = '<HTML><HeAD><Title>hi</title><body>'
			. '<DIV ID="123">ok<div id="234">nope</DIV></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></Body></HTML>';
		$expectedHtml = "<!DOCTYPE html>\n<html><head><title>hi</title></head><body>"
			. '<div id="123">ok<div id="234">nope</div></div>'
			. "\n\n" . '<!--comment--><div id="345">end</div></body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedHtml, $ret['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_quotes() {
		$html = '<html><body>'
			. '<div attr="&quot;&apos;&quot;"></div>'
			. '<div attr=\'&quot;&apos;&quot;\'></div>'
			. '<div attr="&apos;&quot;&apos;"></div>'
			. '<div attr=\'&apos;&quot;&apos;\'></div>'
			. '</body></html>';
		$expectedNonSmart = "<!DOCTYPE html>\n<html><body>"
			. '<div attr="&quot;\'&quot;"></div>'
			. '<div attr="&quot;\'&quot;"></div>'
			. '<div attr="\'&quot;\'"></div>'
			. '<div attr="\'&quot;\'"></div>'
			. '</body></html>';
		$expectedSmart = "<!DOCTYPE html>\n<html><body>"
			. '<div attr=\'"&apos;"\'></div>'
			. '<div attr=\'"&apos;"\'></div>'
			. '<div attr="\'&quot;\'"></div>'
			. '<div attr="\'&quot;\'"></div>'
			. '</body></html>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html );

		$ret = XMLSerializer::serialize( $doc, [ 'smartQuote' => false ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedNonSmart, $ret['html'] );

		$ret = XMLSerializer::serialize( $doc, [ 'smartQuote' => true ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedSmart, $ret['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_emptyElements() {
		// Must have a single root node, otherwise libxml messes up parsing in NOIMPLIED mode.
		$html = '<div><span /><hr/></div>';
		$expectedHtml = '<div><span></span><hr/></div>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED );

		$ret = XMLSerializer::serialize( $doc, [ 'smartQuote' => false ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedHtml, $ret['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_rawContent() {
		$html = '<script>x</script>';
		$doc = new DOMDocument();
		$doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED );

		$ret = XMLSerializer::serialize( $doc, [ 'smartQuote' => false ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $html, $ret['html'] );
	}

	/**
	 * @covers ::serialize
	 */
	public function testSerialize_newlineStrippingElements() {
		// Must have a single root node, otherwise libxml messes up parsing in NOIMPLIED mode.
		// This test looks confusing because DOMDocument::loadHTML doesn't fully follow the spec;
		// it should strip the first newline within a pre block.
		$html = "<div><pre>\n</pre><div>\n</div></div>";
		$expectedHtml = "<div><pre>\n\n</pre><div>\n</div></div>";
		$doc = new DOMDocument();
		$doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED );

		$ret = XMLSerializer::serialize( $doc, [ 'smartQuote' => false ] );
		$this->assertInternalType( 'array', $ret );
		$this->assertArrayHasKey( 'html', $ret );
		$this->assertSame( $expectedHtml, $ret['html'] );
	}

	/**
	 * @covers ::encodeHtmlEntities
	 * @dataProvider provideEncodeHtmlEntities
	 */
	public function testEncodeHtmlEntities( $raw, $whitelist, $expected ) {
		$XMLSerializer = TestingAccessWrapper::newFromClass( XMLSerializer::class );
		$actual = $XMLSerializer->encodeHtmlEntities( $raw, $whitelist );
		$this->assertEquals( $expected, $actual );
	}

	public function provideEncodeHtmlEntities() {
		return [
			[ 'ab&cd<>e"f\'g&h"j', '&<\'"', 'ab&amp;cd&lt;>e&quot;f&apos;g&amp;h&quot;j' ],
			[ 'ab&cd<>e"f\'g&h"j', '&<"', 'ab&amp;cd&lt;>e&quot;f\'g&amp;h&quot;j' ],
			[ 'ab&cd<>e"f\'g&h"j', '&<', 'ab&amp;cd&lt;>e"f\'g&amp;h"j' ],
		];
	}

}
