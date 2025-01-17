<?php

namespace Parsoid\Language;

/**
 * Base class for Language objects.
 */
class Language {

	/** @var LanguageConverter */
	private $converter;

	/**
	 * @return LanguageConverter
	 */
	public function getConverter() {
		return $this->converter;
	}

	/**
	 * @param LanguageConverter $converter
	 */
	public function setConverter( LanguageConverter $converter ) {
		$this->converter = $converter;
	}

	/**
	 * Returns true if a language code string is of a valid form, whether or not it exists.
	 * This includes codes which are used solely for customisation via the MediaWiki namespace.
	 * @param string $code
	 * @return bool
	 */
	public static function isValidCode( $code ) {
		static $validityCache = [];
		if ( !isset( $validityCache[$code] ) ) {
			// XXX PHP version also checks against
			// MediaWikiTitleCodex::getTitleInvalidRegex()
			$validityCache[$code] = preg_match( '/^[^:\\/\\\\\\000&<>\'"]+$/D', $code );
		}
		return $validityCache[$code];
	}

	/**
	 * Get an array of language names, indexed by code.
	 * @param string $inLanguage Code of language in which to return the names.
	 *   Use null for autonyms (native names)
	 * @param string $include One of:
	 *   * `all` all available languages
	 *   * `mw` only if the language is defined in MediaWiki or `wgExtraLanguageNames` (default)
	 *   * `mwfile` only if the language is in `mw` *and* has a message file
	 * @return array
	 */
	public function fetchLanguageNames( $inLanguage, $include ) {
		return [];
	}

}
